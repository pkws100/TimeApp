<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** One read-only calculation source for PWA, admin, accounts and exports. */
final class WorkTimePreviewService
{
    private WorkTimeWindowCalculator $calculator;

    public function __construct(private DatabaseConnection $connection, private WorkTimePolicyService $policies, ?WorkTimeWindowCalculator $calculator = null)
    {
        $this->calculator = $calculator ?? new WorkTimeWindowCalculator();
    }

    public function enabled(): bool { return $this->policies->enabled(); }

    public function forUserPeriod(int $userId, string $dateFrom, string $dateTo): array
    {
        $summary = ['enabled' => $this->enabled(), 'preview_only' => true, 'calculation_status' => $this->enabled() ? 'no_policy' : 'disabled', 'actual_work_minutes' => 0, 'recorded_net_minutes' => 0, 'inside_window_minutes' => 0, 'outside_before_minutes' => 0, 'outside_after_minutes' => 0, 'outside_gap_minutes' => 0, 'outside_pending_minutes' => 0, 'outside_approved_minutes' => 0, 'outside_rejected_minutes' => 0, 'outside_disputed_minutes' => 0, 'accountable_work_minutes' => 0, 'unresolved' => false, 'rows' => []];
        if (!$this->enabled()) { return $summary; }
        $this->assertDate($dateFrom); $this->assertDate($dateTo);
        if ($dateFrom > $dateTo) { throw new InvalidArgumentException('Der Auswertungsbeginn liegt nach dem Ende.'); }
        $entries = $this->connection->fetchAll('SELECT *, row_start AS work_time_source_revision FROM timesheets WHERE user_id = :user_id AND work_date BETWEEN :date_from AND :date_to AND entry_type = "work" AND COALESCE(is_deleted,0) = 0 ORDER BY work_date, start_time, id', ['user_id' => $userId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
        $hasPolicy = false;
        foreach ($entries as $entry) {
            $policy = $this->policies->policyFor($userId, (string) $entry['work_date']);
            $row = $this->forTimesheet($entry, $policy);
            $hasPolicy = $hasPolicy || $policy !== null;
            $summary['rows'][] = $row;
            $summary['recorded_net_minutes'] += (int) ($entry['net_minutes'] ?? 0);
            $calculation = $row['calculation'];
            foreach (['actual_work_minutes', 'inside_window_minutes', 'outside_before_minutes', 'outside_after_minutes', 'outside_gap_minutes', 'outside_pending_minutes', 'outside_approved_minutes', 'outside_rejected_minutes', 'outside_disputed_minutes', 'accountable_work_minutes'] as $key) {
                if ($summary[$key] === null || ($calculation[$key] ?? null) === null) { $summary[$key] = null; }
                else { $summary[$key] += (int) $calculation[$key]; }
            }
            $summary['unresolved'] = $summary['unresolved'] || (bool) $calculation['unresolved'];
            if ($calculation['calculation_status'] === 'needs_clarification') { $summary['calculation_status'] = 'needs_clarification'; }
            elseif ($summary['calculation_status'] !== 'needs_clarification' && (bool) $calculation['unresolved']) { $summary['calculation_status'] = $calculation['calculation_status']; }
        }
        if ($hasPolicy && !$summary['unresolved']) { $summary['calculation_status'] = 'complete'; }
        return $summary;
    }

    public function forTimesheet(array $entry, ?array $policy = null, ?array $sourceData = null): array
    {
        if ($sourceData === null) {
            $policy ??= $this->policies->policyFor((int) $entry['user_id'], (string) $entry['work_date']);
        }
        $row = ['timesheet_id' => (int) $entry['id'], 'user_id' => (int) $entry['user_id'], 'work_date' => $entry['work_date'], 'policy' => $policy, 'recorded_net_minutes' => (int) ($entry['net_minutes'] ?? 0), 'calculation_hash' => null];
        if ($policy === null) {
            $row['calculation'] = ['actual_work_minutes' => (int) ($entry['net_minutes'] ?? 0), 'inside_window_minutes' => (int) ($entry['net_minutes'] ?? 0), 'outside_before_minutes' => 0, 'outside_after_minutes' => 0, 'outside_gap_minutes' => 0, 'outside_pending_minutes' => 0, 'outside_approved_minutes' => 0, 'outside_rejected_minutes' => 0, 'outside_disputed_minutes' => 0, 'accountable_work_minutes' => (int) ($entry['net_minutes'] ?? 0), 'calculation_status' => 'no_policy', 'unresolved' => false, 'preview_only' => true, 'outside_segments' => []];
            return $row;
        }
        $breaks = $sourceData['breaks'] ?? $this->connection->fetchAll('SELECT break_started_at, break_ended_at FROM timesheet_breaks WHERE timesheet_id = :id ORDER BY break_started_at, id', ['id' => $entry['id']]);
        $hash = hash('sha256', json_encode(['id' => (int) $entry['id'], 'date' => $entry['work_date'], 'start' => $entry['start_time'], 'end' => $entry['end_time'], 'break_minutes' => (int) $entry['break_minutes'], 'net_minutes' => (int) $entry['net_minutes'], 'breaks' => $breaks, 'source_revision' => $entry['work_time_source_revision'] ?? null, 'policy' => array_intersect_key($policy, array_flip(['windows', 'timezone', 'early_check_in_mode', 'policy_version_id', 'assignment_id']))], JSON_THROW_ON_ERROR));
        $row['calculation_hash'] = $hash;
        $fallback = ['actual_work_minutes' => null, 'inside_window_minutes' => null, 'outside_before_minutes' => null, 'outside_after_minutes' => null, 'outside_gap_minutes' => null, 'outside_pending_minutes' => null, 'outside_approved_minutes' => null, 'outside_rejected_minutes' => null, 'outside_disputed_minutes' => null, 'accountable_work_minutes' => null, 'calculation_status' => 'needs_clarification', 'unresolved' => true, 'preview_only' => true, 'outside_segments' => []];
        if (empty($entry['start_time']) || empty($entry['end_time'])) {
            $row['calculation'] = [...$fallback, 'calculation_status' => empty($entry['end_time']) && !empty($entry['start_time']) ? 'open' : 'needs_clarification'];
            return $row;
        }
        foreach ($breaks as $break) {
            if (empty($break['break_ended_at'])) { $row['calculation'] = [...$fallback, 'clarification_reason' => 'Eine Pause hat kein dokumentiertes Ende.']; return $row; }
        }
        $date = (string) $entry['work_date'];
        $endDate = (string) $entry['end_time'] < (string) $entry['start_time'] ? (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') : $date;
        $decisions = $sourceData !== null
            ? array_map(static fn (array $deviation): array => ['start_at' => $deviation['segment_start_at'], 'end_at' => $deviation['segment_end_at'], 'status' => $deviation['status']], array_values(array_filter($sourceData['deviations'], static fn (array $deviation): bool => $deviation['calculation_hash'] === $hash)))
            : ($this->connection->tableExists('work_time_deviations') ? $this->connection->fetchAll('SELECT segment_start_at AS start_at, segment_end_at AS end_at, status FROM work_time_deviations WHERE timesheet_id = :id AND calculation_hash = :hash AND superseded_at IS NULL', ['id' => $entry['id'], 'hash' => $hash]) : []);
        try {
            if ((string) $entry['start_time'] === (string) $entry['end_time']) {
                // Existing zero-duration bookings are not invented as a 24-hour workday.
                $row['calculation'] = [...$fallback, 'actual_work_minutes' => 0, 'inside_window_minutes' => 0, 'outside_before_minutes' => 0, 'outside_after_minutes' => 0, 'outside_gap_minutes' => 0, 'outside_pending_minutes' => 0, 'outside_approved_minutes' => 0, 'outside_rejected_minutes' => 0, 'outside_disputed_minutes' => 0, 'accountable_work_minutes' => 0, 'calculation_status' => 'complete', 'unresolved' => false];
            } else {
                $row['calculation'] = $this->calculator->calculate([['start' => $date . ' ' . $entry['start_time'], 'end' => $endDate . ' ' . $entry['end_time']]], array_map(static fn (array $b): array => ['start' => $b['break_started_at'], 'end' => $b['break_ended_at']], $breaks), $policy, $date, $decisions, max(0, (int) $entry['break_minutes']));
                if ($row['calculation']['unplaced_break_minutes'] > 0) {
                    // Legacy break_minutes also includes automatic legal deductions. Without
                    // interval evidence we cannot label the resulting net as proven actual work.
                    $row['calculation']['duration_based_work_minutes'] = $row['calculation']['actual_work_minutes'];
                    $row['calculation']['actual_work_minutes'] = null;
                    $row['calculation']['clarification_reason'] = 'Die gespeicherte Pausendauer kann automatische Abzuege enthalten; tatsaechliche Dauer und Lage sind noch zu klaeren.';
                }
            }
        } catch (InvalidArgumentException $error) {
            $row['calculation'] = [...$fallback, 'clarification_reason' => $error->getMessage()];
        }
        return $row;
    }

    /** Read/compute one source outside the global accounting lock. */
    public function prepareTimesheet(int $timesheetId): array
    {
        $snapshot = $this->snapshotTimesheet($timesheetId);
        $entry = $snapshot['entry'];
        $snapshot['row'] = $entry !== null && (string) $entry['entry_type'] === 'work' && (int) ($entry['is_deleted'] ?? 0) === 0
            ? $this->forTimesheet($entry, $snapshot['policy'], $snapshot)
            : null;

        return $snapshot;
    }

    /**
     * Internal queue commit primitive. The caller must hold the accounting lock
     * and transaction before locking its queue row; no lock is acquired here.
     */
    public function applyPreparedTimesheet(array $prepared): array
    {
        if (!$this->connection->pdo()?->inTransaction()
            || (int) $this->connection->fetchColumn('SELECT IS_USED_LOCK("accounting-timesheet-write") = CONNECTION_ID()') !== 1) {
            throw new \LogicException('Reconciliation requires the accounting write lock and transaction.');
        }
        $current = $this->snapshotTimesheet((int) $prepared['timesheet_id']);
        $result = ['changed' => false, 'created' => 0, 'superseded' => 0, 'frozen' => false, 'source_hash' => $current['source_hash'], 'calculation_hash' => $prepared['row']['calculation_hash'] ?? null, 'calculation_status' => $prepared['row']['calculation']['calculation_status'] ?? 'no_policy'];
        if (!hash_equals($prepared['source_hash'], $current['source_hash'])) {
            return [...$result, 'changed' => true];
        }
        $entry = $current['entry'];
        if ($entry === null) {
            // A missing source cannot justify modifying historical deviations.
            return [...$result, 'frozen' => $current['deviations'] !== [], 'calculation_status' => 'source_missing'];
        }
        try {
            (new TimesheetWriteGuard($this->connection))->assertAccountingPeriodOpen((int) $entry['user_id'], $entry['project_id'] !== null ? (int) $entry['project_id'] : null, (string) $entry['work_date']);
        } catch (InvalidArgumentException) {
            return [...$result, 'frozen' => true, 'calculation_status' => 'accounting_closed'];
        }
        $row = $prepared['row'];
        foreach ($current['deviations'] as $existing) {
            if ($row !== null && $row['calculation_hash'] === $existing['calculation_hash']) { continue; }
            $this->connection->execute('UPDATE work_time_deviations SET superseded_at = NOW() WHERE id = :id AND superseded_at IS NULL', ['id' => $existing['id']]);
            ++$result['superseded'];
        }
        if ($row === null || $row['policy'] === null) { return $result; }
        foreach ($row['calculation']['outside_segments'] ?? [] as $segment) {
            if ((int) $segment['minutes'] <= 0) { continue; }
            $zone = new DateTimeZone($row['policy']['timezone']);
            $start = (new DateTimeImmutable($segment['start_at']))->setTimezone($zone)->format('Y-m-d H:i:s');
            $end = (new DateTimeImmutable($segment['end_at']))->setTimezone($zone)->format('Y-m-d H:i:s');
            $existing = $this->connection->fetchOne('SELECT id FROM work_time_deviations WHERE timesheet_id = :timesheet AND calculation_hash = :hash AND kind = :kind AND segment_start_at = :start AND segment_end_at = :end', ['timesheet' => $row['timesheet_id'], 'hash' => $row['calculation_hash'], 'kind' => $segment['kind'], 'start' => $start, 'end' => $end]);
            if ($existing !== null) { continue; }
            $this->connection->execute('INSERT INTO work_time_deviations (timesheet_id, user_id, policy_version_id, assignment_id, segment_start_at, segment_end_at, minutes, kind, status, calculation_hash) VALUES (:timesheet, :user_id, :version, :assignment, :start, :end, :minutes, :kind, "pending", :hash)', ['timesheet' => $row['timesheet_id'], 'user_id' => $row['user_id'], 'version' => $row['policy']['policy_version_id'], 'assignment' => $row['policy']['assignment_id'], 'start' => $start, 'end' => $end, 'minutes' => $segment['minutes'], 'kind' => $segment['kind'], 'hash' => $row['calculation_hash']]);
            ++$result['created'];
        }

        return $result;
    }

    /** Explicit single-item maintenance entry point; reads never call this. */
    public function reconcileTimesheet(int $timesheetId): array
    {
        if (!$this->enabled()) { return ['created' => 0, 'superseded' => 0, 'changed' => false]; }
        $prepared = $this->prepareTimesheet($timesheetId);
        return (new TimesheetWriteGuard($this->connection))->withAccountingWriteLock(fn (): array => $this->connection->transaction(fn (): array => $this->applyPreparedTimesheet($prepared)));
    }

    private function snapshotTimesheet(int $timesheetId): array
    {
        $entry = $this->connection->fetchOne('SELECT *, row_start AS work_time_source_revision FROM timesheets WHERE id = :id', ['id' => $timesheetId]);
        // The worker can process several revisions in one process. Never reuse a
        // policy cache populated before another connection changed an assignment.
        $freshPolicies = new WorkTimePolicyService($this->connection, $this->enabled());
        $policy = $entry !== null ? $freshPolicies->policyFor((int) $entry['user_id'], (string) $entry['work_date']) : null;
        $breaks = $entry !== null ? $this->connection->fetchAll('SELECT break_started_at, break_ended_at FROM timesheet_breaks WHERE timesheet_id = :id ORDER BY break_started_at, id', ['id' => $timesheetId]) : [];
        $deviations = $this->connection->fetchAll('SELECT * FROM work_time_deviations WHERE timesheet_id = :id AND superseded_at IS NULL ORDER BY id', ['id' => $timesheetId]);
        $sourceHash = hash('sha256', json_encode([$entry, $policy, $breaks, $deviations], JSON_THROW_ON_ERROR));

        return ['timesheet_id' => $timesheetId, 'entry' => $entry, 'policy' => $policy, 'breaks' => $breaks, 'deviations' => $deviations, 'source_hash' => $sourceHash];
    }

    /** Worker-only materialization. Reading a preview never creates or changes audit records. */
    public function reconcileUser(int $userId, string $dateFrom, string $dateTo): array
    {
        if (!$this->enabled()) { return ['created' => 0, 'superseded' => 0]; }
        $guard = new TimesheetWriteGuard($this->connection);
        return $guard->withAccountingWriteLock(function () use ($userId, $dateFrom, $dateTo, $guard): array {
            return $this->connection->transaction(function () use ($userId, $dateFrom, $dateTo, $guard): array {
                $rows = $this->forUserPeriod($userId, $dateFrom, $dateTo)['rows'];
                $result = ['created' => 0, 'superseded' => 0];
                $current = [];
                foreach ($rows as $row) { $current[$row['timesheet_id']] = $row; }
                $previous = $this->connection->fetchAll('SELECT d.*, t.work_date, t.project_id FROM work_time_deviations d JOIN timesheets t ON t.id = d.timesheet_id WHERE d.user_id = :user_id AND t.work_date BETWEEN :date_from AND :date_to AND d.superseded_at IS NULL', ['user_id' => $userId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
                foreach ($previous as $existing) {
                    $row = $current[(int) $existing['timesheet_id']] ?? null;
                    if ($row !== null && $row['calculation_hash'] === $existing['calculation_hash']) { continue; }
                    try { $guard->assertAccountingPeriodOpen($userId, isset($existing['project_id']) ? (int) $existing['project_id'] : null, (string) $existing['work_date']); }
                    catch (InvalidArgumentException) { continue; }
                    $this->connection->execute('UPDATE work_time_deviations SET superseded_at = NOW() WHERE id = :id AND superseded_at IS NULL', ['id' => $existing['id']]);
                    ++$result['superseded'];
                }
                foreach ($rows as $row) {
                    if ($row['policy'] === null || empty($row['calculation']['outside_segments'])) { continue; }
                    $entry = $this->connection->fetchOne('SELECT project_id FROM timesheets WHERE id = :id', ['id' => $row['timesheet_id']]);
                    try { $guard->assertAccountingPeriodOpen($userId, isset($entry['project_id']) ? (int) $entry['project_id'] : null, (string) $row['work_date']); }
                    catch (InvalidArgumentException) { continue; }
                    foreach ($row['calculation']['outside_segments'] as $segment) {
                        if ((int) $segment['minutes'] <= 0) { continue; }
                        $start = (new DateTimeImmutable($segment['start_at']))->setTimezone(new DateTimeZone($row['policy']['timezone']))->format('Y-m-d H:i:s');
                        $end = (new DateTimeImmutable($segment['end_at']))->setTimezone(new DateTimeZone($row['policy']['timezone']))->format('Y-m-d H:i:s');
                        $existing = $this->connection->fetchOne('SELECT id FROM work_time_deviations WHERE timesheet_id = :timesheet AND calculation_hash = :hash AND kind = :kind AND segment_start_at = :start AND segment_end_at = :end', ['timesheet' => $row['timesheet_id'], 'hash' => $row['calculation_hash'], 'kind' => $segment['kind'], 'start' => $start, 'end' => $end]);
                        if ($existing !== null) { continue; }
                        $this->connection->execute('INSERT INTO work_time_deviations (timesheet_id, user_id, policy_version_id, assignment_id, segment_start_at, segment_end_at, minutes, kind, status, calculation_hash) VALUES (:timesheet, :user_id, :version, :assignment, :start, :end, :minutes, :kind, "pending", :hash)', ['timesheet' => $row['timesheet_id'], 'user_id' => $userId, 'version' => $row['policy']['policy_version_id'], 'assignment' => $row['policy']['assignment_id'], 'start' => $start, 'end' => $end, 'minutes' => $segment['minutes'], 'kind' => $segment['kind'], 'hash' => $row['calculation_hash']]);
                        ++$result['created'];
                    }
                }
                return $result;
            });
        });
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new InvalidArgumentException('Ungueltiges Auswertungsdatum.'); }
    }
}
