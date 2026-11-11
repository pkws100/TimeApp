<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Durable, revision-bound work. Source triggers enqueue in the source transaction. */
final class WorkTimeReconciliationQueue
{
    private ?Closure $beforeCommit;
    private ?bool $schemaAvailable = null;

    public function __construct(
        private DatabaseConnection $connection,
        private WorkTimePreviewService $preview,
        private ?WorkTimePolicyService $policies = null,
        ?callable $beforeCommit = null
    ) {
        $this->beforeCommit = $beforeCommit === null ? null : Closure::fromCallable($beforeCommit);
    }

    public function workerLockName(): string
    {
        return 'work-time-reconciliation:' . substr(hash('sha256', (string) $this->connection->fetchColumn('SELECT DATABASE()')), 0, 16);
    }

    public function processBatch(bool $dryRun = false, ?DateTimeImmutable $now = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) { throw new InvalidArgumentException('Die Batchgroesse muss zwischen 1 und 1000 liegen.'); }
        $summary = ['enabled' => $this->preview->enabled(), 'dry_run' => $dryRun, 'claimed' => 0, 'processed' => 0, 'failed' => 0, 'requeued' => 0, 'recovered' => 0, 'frozen' => 0, 'remaining' => 0, 'locked' => false];
        if (!$summary['enabled']) { return $summary; }
        $this->assertSchema();
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('Europe/Berlin'));
        if ($dryRun) {
            $summary['remaining'] = $this->remaining();
            $summary['ready'] = (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_queue WHERE status = "pending" OR (status = "failed" AND attempts < 5 AND available_at <= :now)', ['now' => $now->format('Y-m-d H:i:s')]);
            return $summary;
        }
        $lock = $this->workerLockName();
        if ((int) $this->connection->fetchColumn('SELECT GET_LOCK(:name, 0)', ['name' => $lock]) !== 1) {
            return [...$summary, 'locked' => true];
        }
        try {
            // Owning the DB-specific singleton proves that every old processing
            // claim belonged to a dead worker. No age guess or duplicate worker.
            $summary['recovered'] = $this->recoverAbandoned($now);
            $claims = $this->claim($limit, $now);
            $summary['claimed'] = count($claims);
            foreach ($claims as $claim) {
                try {
                    $prepared = $this->preview->prepareTimesheet((int) $claim['timesheet_id']);
                    if ($this->beforeCommit !== null) { ($this->beforeCommit)($claim, $prepared); }
                    $result = (new TimesheetWriteGuard($this->connection))->withAccountingWriteLock(function () use ($claim, $prepared, $now): array {
                        return $this->connection->transaction(function () use ($claim, $prepared, $now): array {
                            $current = $this->connection->fetchOne('SELECT * FROM work_time_reconciliation_queue WHERE timesheet_id = :id FOR UPDATE', ['id' => $claim['timesheet_id']]);
                            if ($current === null || $current['lock_token'] !== $claim['lock_token'] || $current['status'] !== 'processing') {
                                throw new RuntimeException('Die Arbeitszeitpruefung besitzt keine gueltige Verarbeitungssperre mehr.');
                            }
                            $result = (int) $current['revision'] !== (int) $claim['revision']
                                ? ['changed' => true, 'created' => 0, 'superseded' => 0, 'frozen' => false, 'calculation_status' => 'source_changed']
                                : $this->preview->applyPreparedTimesheet($prepared);
                            $requeued = (bool) $result['changed'];
                            $this->connection->execute('UPDATE work_time_reconciliation_queue SET status = :status, processed_revision = :processed_revision, lock_token = NULL, locked_at = NULL, attempts = :attempts, available_at = :now, last_error = NULL, last_source_hash = :source_hash, last_calculation_hash = :calculation_hash, processed_at = :processed_at, updated_at = :updated_at WHERE timesheet_id = :id AND lock_token = :token', [
                                'status' => $requeued ? 'pending' : 'done',
                                'processed_revision' => $requeued ? $current['processed_revision'] : $claim['revision'],
                                'attempts' => $requeued ? 0 : $current['attempts'],
                                'now' => $now->format('Y-m-d H:i:s'),
                                'source_hash' => $requeued ? $current['last_source_hash'] : $prepared['source_hash'],
                                'calculation_hash' => $requeued ? $current['last_calculation_hash'] : ($prepared['row']['calculation_hash'] ?? null),
                                'processed_at' => $requeued ? $current['processed_at'] : $now->format('Y-m-d H:i:s'),
                                'updated_at' => $now->format('Y-m-d H:i:s'),
                                'id' => $claim['timesheet_id'], 'token' => $claim['lock_token'],
                            ]);
                            $auditResult = $result + ['preview' => $this->auditPreview($prepared['row']['calculation'] ?? [])];
                            $this->finishRun($claim, $requeued ? 'requeued' : 'done', $prepared, $auditResult, null, $now);
                            return $result;
                        });
                    });
                    ++$summary[$result['changed'] ? 'requeued' : 'processed'];
                    if (!empty($result['frozen'])) { ++$summary['frozen']; }
                } catch (Throwable $error) {
                    $this->recordFailure($claim, $error, $now);
                    ++$summary['failed'];
                }
            }
            $summary['remaining'] = $this->remaining();
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $lock]);
        }
        return $summary;
    }

    /** Explicit maintenance only; the recurring worker never scans the old history. */
    public function enqueueFullReconciliation(?int $userId = null, bool $dryRun = false): int
    {
        if (!$this->preview->enabled()) { return 0; }
        $this->assertSchema();
        if ($userId !== null && $userId < 1) { throw new InvalidArgumentException('Ungueltiger Mitarbeiter.'); }
        $where = '((t.entry_type = "work" AND EXISTS (SELECT 1 FROM employee_work_time_assignments a WHERE a.user_id = t.user_id AND a.effective_from <= t.work_date AND (a.effective_to IS NULL OR a.effective_to >= t.work_date))) OR EXISTS (SELECT 1 FROM work_time_deviations d WHERE d.timesheet_id = t.id))';
        $params = [];
        if ($userId !== null) { $where .= ' AND t.user_id = :user_id'; $params['user_id'] = $userId; }
        $count = (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM timesheets t WHERE ' . $where, $params);
        if (!$dryRun) {
            $this->connection->execute('INSERT INTO work_time_reconciliation_queue (timesheet_id) SELECT t.id FROM timesheets t WHERE ' . $where . ' ON DUPLICATE KEY UPDATE revision = revision + 1, status = IF(status = "processing", "processing", "pending"), attempts = IF(status = "processing", attempts, 0), available_at = NOW(), last_error = NULL, updated_at = NOW()', $params);
        }
        return $count;
    }

    private function claim(int $limit, DateTimeImmutable $now): array
    {
        return $this->connection->transaction(function () use ($limit, $now): array {
            $rows = $this->connection->fetchAll('SELECT * FROM work_time_reconciliation_queue WHERE status = "pending" OR (status = "failed" AND attempts < 5 AND available_at <= :now) ORDER BY available_at, timesheet_id LIMIT ' . $limit . ' FOR UPDATE', ['now' => $now->format('Y-m-d H:i:s')]);
            foreach ($rows as &$row) {
                $row['lock_token'] = bin2hex(random_bytes(24));
                $this->connection->execute('UPDATE work_time_reconciliation_queue SET status = "processing", lock_token = :token, locked_at = :now, attempts = attempts + 1, updated_at = :updated_at WHERE timesheet_id = :id', ['token' => $row['lock_token'], 'now' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'), 'id' => $row['timesheet_id']]);
                $this->connection->execute('INSERT INTO work_time_reconciliation_runs (timesheet_id, queue_revision, lock_token, status, started_at) VALUES (:id, :revision, :token, "processing", :now)', ['id' => $row['timesheet_id'], 'revision' => $row['revision'], 'token' => $row['lock_token'], 'now' => $now->format('Y-m-d H:i:s')]);
                $row['run_id'] = $this->connection->lastInsertId();
            }
            unset($row);
            // This transaction commits before any accounting lock is requested.
            return $rows;
        });
    }

    private function recoverAbandoned(DateTimeImmutable $now): int
    {
        return $this->connection->transaction(function () use ($now): int {
            $count = (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_queue WHERE status = "processing"');
            if ($count === 0) { return 0; }
            $this->connection->execute('UPDATE work_time_reconciliation_runs SET status = "abandoned", error_code = "worker_interrupted", finished_at = :now WHERE status = "processing"', ['now' => $now->format('Y-m-d H:i:s')]);
            $this->connection->execute('UPDATE work_time_reconciliation_queue SET status = "pending", lock_token = NULL, locked_at = NULL, available_at = :now, updated_at = :updated_at WHERE status = "processing"', ['now' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s')]);
            return $count;
        });
    }

    private function recordFailure(array $claim, Throwable $error, DateTimeImmutable $now): void
    {
        $this->connection->transaction(function () use ($claim, $error, $now): void {
            $current = $this->connection->fetchOne('SELECT * FROM work_time_reconciliation_queue WHERE timesheet_id = :id FOR UPDATE', ['id' => $claim['timesheet_id']]);
            if ($current === null || $current['lock_token'] !== $claim['lock_token']) { return; }
            $newSource = (int) $current['revision'] !== (int) $claim['revision'];
            $delay = min(300, 30 * (2 ** min(4, max(0, (int) $current['attempts'] - 1))));
            // Exception messages can contain SQL or personal data. Persist only the class.
            $code = mb_substr(get_class($error), 0, 100);
            $this->connection->execute('UPDATE work_time_reconciliation_queue SET status = :status, attempts = :attempts, lock_token = NULL, locked_at = NULL, available_at = :available, last_error = :error, updated_at = :now WHERE timesheet_id = :id', ['status' => $newSource ? 'pending' : 'failed', 'attempts' => $newSource ? 0 : $current['attempts'], 'available' => $newSource ? $now->format('Y-m-d H:i:s') : $now->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s'), 'error' => $code, 'now' => $now->format('Y-m-d H:i:s'), 'id' => $claim['timesheet_id']]);
            $this->finishRun($claim, 'failed', null, null, $code, $now);
        });
    }

    private function finishRun(array $claim, string $status, ?array $prepared, ?array $result, ?string $error, DateTimeImmutable $now): void
    {
        $this->connection->execute('UPDATE work_time_reconciliation_runs SET status = :status, source_hash = :source_hash, calculation_hash = :calculation_hash, result_json = :result, error_code = :error, finished_at = :now WHERE id = :id AND lock_token = :token AND status = "processing"', ['status' => $status, 'source_hash' => $prepared['source_hash'] ?? null, 'calculation_hash' => $prepared['row']['calculation_hash'] ?? null, 'result' => $result !== null ? json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null, 'error' => $error, 'now' => $now->format('Y-m-d H:i:s'), 'id' => $claim['run_id'], 'token' => $claim['lock_token']]);
    }

    private function auditPreview(array $calculation): array
    {
        return array_intersect_key($calculation, array_flip(['actual_work_minutes', 'inside_window_minutes', 'outside_before_minutes', 'outside_after_minutes', 'outside_gap_minutes', 'outside_pending_minutes', 'outside_approved_minutes', 'outside_rejected_minutes', 'outside_disputed_minutes', 'accountable_work_minutes', 'calculation_status', 'clarification_reason', 'unplaced_break_minutes']));
    }

    private function remaining(): int
    {
        return (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_queue WHERE status IN ("pending", "processing", "failed")');
    }

    private function assertSchema(): void
    {
        if (!($this->schemaAvailable ??= $this->connection->tableExists('work_time_reconciliation_queue'))) {
            throw new RuntimeException('Die Arbeitszeit-Warteschlange ist noch nicht migriert.');
        }
    }
}
