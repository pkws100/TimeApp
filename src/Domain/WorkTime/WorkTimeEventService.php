<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\LiveWorkSession;
use App\Domain\Timesheets\TimesheetPauseIntervals;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class WorkTimeEventService
{
    private ?Closure $pushSender;
    private SmsProvider $smsProvider;
    private WorkTimeNotificationService $notifications;
    private ProjectAccessService $projectAccess;
    private ?WorkTimeReconciliationQueue $reconciliationQueue = null;

    public function __construct(
        private DatabaseConnection $connection,
        private WorkTimePolicyService $policies,
        private AppTimesheetSyncService $sync,
        ?callable $pushSender = null,
        ?SmsProvider $smsProvider = null,
        private ?WorkTimePreviewService $previews = null
    ) {
        $this->pushSender = $pushSender === null ? null : Closure::fromCallable($pushSender);
        $this->smsProvider = $smsProvider ?? new MockSmsProvider();
        $this->notifications = new WorkTimeNotificationService($connection, $policies, $pushSender, $this->smsProvider);
        $this->projectAccess = new ProjectAccessService($connection);
    }

    public function notifications(): WorkTimeNotificationService { return $this->notifications; }

    public function setReconciliationQueue(WorkTimeReconciliationQueue $queue): void { $this->reconciliationQueue = $queue; }

    public function statusForUser(int $userId, ?string $today = null, ?array $viewer = null): array
    {
        if (!$this->policies->enabled()) {
            return ['enabled' => false, 'checkout_proposals' => [], 'presence_session' => null];
        }
        $today ??= LiveWorkSession::referenceDate();
        $liveParams = ['user_id' => $userId, 'live_reference_date' => $today];
        // GET is read-only. Obsolete proposals are filtered and later audited by the worker.
        $proposals = $this->connection->fetchAll('SELECT p.*, t.work_date, t.start_time FROM work_time_checkout_proposals p
            JOIN timesheets t ON t.id = p.timesheet_id AND t.user_id = p.user_id JOIN users u ON u.id = p.user_id
            WHERE p.user_id = :user_id AND p.status = "pending" AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0
            AND ' . LiveWorkSession::isOpenSql('t') . ' ORDER BY p.proposed_end_at, p.id', $liveParams);
        $activeEntries = $this->connection->fetchAll('SELECT t.id, t.user_id, t.work_date, t.start_time, t.project_id FROM timesheets t
            JOIN users u ON u.id = t.user_id WHERE t.user_id = :user_id AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0
            AND ' . LiveWorkSession::isOpenSql('t') . ' ORDER BY t.work_date, t.id', $liveParams);
        $assignedEntries = array_filter($activeEntries, fn (array $row): bool => $this->policies->policyFor($userId, $row['work_date']) !== null);
        $conflict = $assignedEntries !== [] && count($activeEntries) > 1;
        $activeEntry = null;
        if (count($activeEntries) === 1 && $assignedEntries !== [] && $activeEntries[0]['work_date'] < $today) {
            $activeEntry = $activeEntries[0];
            foreach (['id', 'user_id'] as $field) { $activeEntry[$field] = (int) $activeEntry[$field]; }
            $activeEntry['project_id'] = $activeEntry['project_id'] !== null ? (int) $activeEntry['project_id'] : null;
            $activeEntry['current_break'] = (new TimesheetPauseIntervals($this->connection))->currentForTimesheet($activeEntry['id']);
        }

        $presence = $this->sync->openPresenceForUser($userId, $today);
        if ($viewer !== null) {
            $activeEntry = $this->withVisibleProject($viewer, $activeEntry);
            $presence = $this->withVisibleProject($viewer, $presence);
        }

        return ['enabled' => true, 'checkout_proposals' => $proposals, 'presence_session' => $presence, 'active_work_entry' => $activeEntry, 'active_work_entry_conflict' => $conflict];
    }

    private function withVisibleProject(array $viewer, ?array $entry): ?array
    {
        if (isset($entry['project_id']) && !$this->projectAccess->canAccess($viewer, (int) $entry['project_id'])) {
            $entry['project_id'] = null;
            $entry['project_unavailable'] = true;
            unset($entry['project_number'], $entry['project_name'], $entry['project_customer_name'],
                $entry['customer_signature_name'], $entry['customer_signature_required']);
        }

        return $entry;
    }

    public function notificationEventsForEmployee(int $userId): array
    {
        if (!$this->policies->enabled()) {
            return [];
        }
        return $this->notifications->list($userId);
    }

    public function workerLockName(): string
    {
        // Separate scratch databases (and independent app installations) must not
        // suppress one another's workers on a shared MariaDB server.
        return 'work-time-events-worker:' . substr(hash('sha256', (string) $this->connection->fetchColumn('SELECT DATABASE()')), 0, 16);
    }

    public function respond(int $userId, int $proposalId, string $action, ?string $actualEndAt = null, ?string $requestId = null, ?callable $currentViewer = null): array
    {
        if (!$this->policies->enabled()) {
            throw new InvalidArgumentException('Arbeitszeitfenster sind deaktiviert.');
        }
        if (!in_array($action, ['confirm', 'correct', 'continue'], true) || $requestId === null || $requestId === '' || strlen($requestId) > 80) {
            throw new InvalidArgumentException('Eine gueltige Aktion und Request-ID sind erforderlich.');
        }
        $guard = new TimesheetWriteGuard($this->connection);

        return $guard->withAccountingWriteLock(function () use ($userId, $proposalId, $action, $actualEndAt, $requestId, $currentViewer): array {
            $previous = $this->connection->fetchOne('SELECT * FROM work_time_checkout_proposal_actions WHERE user_id = :user_id AND request_id = :request_id', ['user_id' => $userId, 'request_id' => $requestId]);
            if ($previous !== null) {
                if ((int) $previous['proposal_id'] !== $proposalId || $previous['action'] !== $action) {
                    throw new InvalidArgumentException('Die Request-ID gehoert zu einer anderen Aktion.');
                }
                if ($action === 'correct') {
                    $oldProposal = $this->proposal($proposalId, $userId);
                    $oldEntry = $oldProposal === null ? null : $this->connection->fetchOne('SELECT * FROM timesheets WHERE id = :id', ['id' => $oldProposal['timesheet_id']]);
                    if ($oldEntry === null || $this->parseActualEnd($actualEndAt, $oldEntry)->format('Y-m-d H:i:s') !== $previous['actual_end_at']) {
                        throw new InvalidArgumentException('Die Request-ID wurde bereits fuer eine andere Endzeit verwendet.');
                    }
                }
                return ['ok' => true, 'status' => 'already_resolved', 'proposal' => $this->proposal($proposalId, $userId)];
            }
            $proposal = $this->proposal($proposalId, $userId);
            if ($proposal === null) {
                throw new InvalidArgumentException('Der Vorschlag wurde nicht gefunden.');
            }
            if ($proposal['status'] !== 'pending') {
                return ['ok' => true, 'status' => 'already_resolved', 'proposal' => $proposal];
            }
            $entry = $this->connection->fetchOne('SELECT t.* FROM timesheets t JOIN users u ON u.id = t.user_id
                WHERE t.id = :id AND t.user_id = :user_id AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0
                AND ' . LiveWorkSession::isOpenSql('t'), ['id' => $proposal['timesheet_id'], 'user_id' => $userId, 'live_reference_date' => LiveWorkSession::referenceDate()]);
            if ($entry === null) {
                $this->connection->transaction(function () use ($proposalId, $userId, $requestId, $action): void {
                    $this->finishProposal($proposalId, $userId, 'stale', $requestId, null, null, $action);
                });

                return ['ok' => true, 'status' => 'stale', 'proposal' => $this->proposal($proposalId, $userId)];
            }
            if ($action === 'continue') {
                $this->connection->transaction(function () use ($proposalId, $userId, $requestId): void {
                    $this->finishProposal($proposalId, $userId, 'continued', $requestId, null, null, 'continue');
                });

                return ['ok' => true, 'status' => 'continued', 'proposal' => $this->proposal($proposalId, $userId)];
            }
            $end = $action === 'confirm' ? WorkTimeLocalTime::parse($proposal['proposed_end_at']) : $this->parseActualEnd($actualEndAt, $entry);
            $start = WorkTimeLocalTime::parse($entry['work_date'] . ' ' . $entry['start_time']);
            $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
            if ($end <= $start || $end > $now || $end->getTimestamp() - $start->getTimestamp() > 86400) {
                throw new InvalidArgumentException('Das bestaetigte Ende muss nach dem Arbeitsbeginn liegen, darf nicht in der Zukunft liegen und muss innerhalb von 24 Stunden erfolgen.');
            }
            $resultStatus = $action === 'confirm' ? 'confirmed' : 'corrected';
            // Central sync validates the exact session again under its accounting lock.
            // Its transaction includes the immutable confirmation, so neither can commit alone.
            $sync = $this->sync->sync($userId, [
                'client_request_id' => 'wt:' . hash('sha256', $userId . ':' . $requestId),
                'action' => 'check_out', 'work_date' => $entry['work_date'],
                'end_time' => $end->format('H:i'), 'actual_end_at' => $end->format('Y-m-d\TH:i'),
                'expected_timesheet_id' => (int) $entry['id'],
            ], function (array $result) use ($proposalId, $userId, $requestId, $resultStatus, $end, $action): void {
                $this->finishProposal($proposalId, $userId, $resultStatus, $requestId, $end->format('Y-m-d H:i:s'), (int) ($result['timesheet']['id'] ?? 0), $action);
            });
            $completed = $this->proposal($proposalId, $userId);
            if (($completed['status'] ?? '') !== $resultStatus || (int) ($completed['result_timesheet_id'] ?? 0) !== (int) $entry['id']) {
                throw new InvalidArgumentException('Die Buchungs-Request-ID wurde bereits verwendet. Bitte den aktuellen Vorschlag neu laden.');
            }

            $timesheet = $sync['timesheet'] ?? null;
            if (isset($timesheet['project_id']) && $currentViewer !== null) {
                $timesheet = $this->withVisibleProject($currentViewer() ?? [], $timesheet);
            }

            return [
                'ok' => true,
                'status' => $resultStatus,
                'proposal' => $completed,
                'timesheet' => $timesheet,
            ];
        });
    }

    public function process(bool $dryRun = false, ?DateTimeImmutable $now = null, int $batchSize = 100): array
    {
        $summary = ['enabled' => $this->policies->enabled(), 'dry_run' => $dryRun, 'proposals' => 0, 'stale' => 0, 'outside_notifications_queued' => 0, 'provider_accepted' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0, 'not_configured' => 0, 'test_simulated' => 0, 'cancelled' => 0, 'uncertain' => 0, 'errors' => 0, 'locked' => false];
        if (!$summary['enabled']) {
            return $summary;
        }
        $workerLock = $this->workerLockName();
        $locked = (int) $this->connection->fetchColumn('SELECT GET_LOCK(:lock_name, 0)', ['lock_name' => $workerLock]) === 1;
        if (!$locked) {
            $summary['locked'] = true;
            return $summary;
        }
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('Europe/Berlin'));
        try {
            $guard = new TimesheetWriteGuard($this->connection);
            $guard->withAccountingWriteLock(function () use (&$summary, $dryRun, $now, $guard): void {
                $liveParams = ['live_reference_date' => LiveWorkSession::referenceDate(now: $now)];
                $stale = $this->connection->fetchAll('SELECT p.* FROM work_time_checkout_proposals p
                    JOIN timesheets t ON t.id = p.timesheet_id JOIN users u ON u.id = p.user_id WHERE p.status = "pending"
                    AND NOT (t.user_id = p.user_id AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0
                    AND ' . LiveWorkSession::isOpenSql('t') . ')', $liveParams);
                foreach ($stale as $proposal) {
                    $summary['stale']++;
                    if (!$dryRun) {
                        $this->connection->transaction(function () use ($proposal): void {
                            $this->finishProposal((int) $proposal['id'], (int) $proposal['user_id'], 'stale', 'stale:' . $proposal['id'], null, null);
                        });
                    }
                }
                $entries = $this->connection->fetchAll('SELECT t.* FROM timesheets t JOIN users u ON u.id = t.user_id
                    WHERE u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0 AND t.work_date <= :today
                    AND ' . LiveWorkSession::isOpenSql('t') . ' ORDER BY t.id', ['today' => $liveParams['live_reference_date']] + $liveParams);
                foreach ($entries as $entry) {
                    try {
                        try {
                            $guard->assertAccountingPeriodOpen((int) $entry['user_id'], isset($entry['project_id']) ? (int) $entry['project_id'] : null, (string) $entry['work_date']);
                        } catch (InvalidArgumentException) {
                            continue;
                        }
                        $policy = $this->policies->policyFor((int) $entry['user_id'], $entry['work_date']);
                        if ($policy === null) {
                            continue;
                        }
                        $startedAt = WorkTimeLocalTime::parse($entry['work_date'] . ' ' . $entry['start_time']);
                        if ($startedAt->getTimestamp() < (int) ($policy['live_from_unix'] ?? 0)) {
                            continue;
                        }
                        if (!empty($policy['outside_window_notifications_enabled'])
                            && WorkTimeEventWindows::startedOutsideAfterWindow($policy, $startedAt)
                            && $startedAt->modify('+' . (int) ($policy['outside_window_delay_minutes'] ?? 5) . ' minutes') <= $now) {
                            $queued = $dryRun
                                ? $this->notifications->queue('work_time_outside_window_started', $entry, $policy, $startedAt, null, true)
                                : $this->connection->transaction(fn (): int => $this->notifications->queue('work_time_outside_window_started', $entry, $policy, $startedAt));
                            $summary['outside_notifications_queued'] += $queued;
                        }
                        if (empty($policy['checkout_proposals_enabled'])) { continue; }
                        $proposedEnd = WorkTimeEventWindows::windowEnd($policy, $entry['work_date'], $startedAt);
                        if ($proposedEnd === null || $proposedEnd <= $startedAt || $proposedEnd->modify('+' . (int) ($policy['checkout_delay_minutes'] ?? 0) . ' minutes') > $now || $this->connection->fetchOne('SELECT id FROM work_time_checkout_proposals WHERE timesheet_id = :id', ['id' => $entry['id']]) !== null) {
                            continue;
                        }
                        $summary['proposals']++;
                        if ($dryRun) {
                            continue;
                        }
                        $this->connection->transaction(function () use ($entry, $policy, $proposedEnd): void {
                            $this->connection->execute('INSERT INTO work_time_checkout_proposals (user_id, timesheet_id, policy_version_id, proposed_end_at, status, created_at) VALUES (:user_id, :timesheet_id, :version_id, :end_at, "pending", NOW())', ['user_id' => $entry['user_id'], 'timesheet_id' => $entry['id'], 'version_id' => $policy['policy_version_id'], 'end_at' => $proposedEnd->format('Y-m-d H:i:s')]);
                            $proposalId = $this->connection->lastInsertId();
                            $this->notifications->queue('work_time_checkout_proposal', $entry, $policy, $proposedEnd, $proposalId);
                        });
                    } catch (Throwable $exception) {
                        $summary['errors']++;
                        error_log('Work-time event processing failed: ' . get_class($exception));
                    }
                }
            });
            if ($this->previews !== null || $this->reconciliationQueue !== null) {
                $queue = $this->reconciliationQueue ??= new WorkTimeReconciliationQueue($this->connection, $this->previews, $this->policies);
                $summary['reconciliation'] = $queue->processBatch($dryRun, $now, $batchSize);
                $summary['errors'] += (int) ($summary['reconciliation']['failed'] ?? 0);
            }
            if (!$dryRun) {
                $notifications = $this->notifications->dispatch($now);
                foreach (['provider_accepted', 'failed', 'not_configured', 'test_simulated', 'cancelled'] as $key) { $summary[$key] += (int) ($notifications[$key] ?? 0); }
                // Compatibility counters; "sent" means provider acceptance, not delivery.
                $summary['sent'] = $summary['provider_accepted'];
                $summary['disabled'] = $summary['not_configured'] + $summary['cancelled'];
            }
            $summary['uncertain'] = (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM work_time_notification_events WHERE status = "uncertain"');
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $workerLock]);
        }

        return $summary;
    }

    private function proposal(int $id, int $userId): ?array
    {
        return $this->connection->fetchOne('SELECT * FROM work_time_checkout_proposals WHERE id = :id AND user_id = :user_id', ['id' => $id, 'user_id' => $userId]);
    }

    private function finishProposal(int $id, int $userId, string $status, string $requestId, ?string $endAt, ?int $timesheetId, ?string $action = null): void
    {
        $this->connection->execute('INSERT INTO work_time_checkout_proposal_actions (proposal_id, user_id, actor_user_id, request_id, action, actual_end_at, created_at) VALUES (:id, :user_id, :actor_id, :request_id, :action, :end_at, NOW())', ['id' => $id, 'user_id' => $userId, 'actor_id' => $status === 'stale' && $action === null ? null : $userId, 'request_id' => $requestId, 'action' => $action ?? $status, 'end_at' => $endAt]);
        $this->connection->execute('UPDATE work_time_checkout_proposals SET status = :status, resolved_at = NOW(), result_timesheet_id = :timesheet_id WHERE id = :id AND status = "pending"', ['status' => $status, 'timesheet_id' => $timesheetId ?: null, 'id' => $id]);
    }

    private function parseActualEnd(?string $value, array $entry): DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException('Bitte die tatsaechliche Endzeit eingeben.');
        }
        $zone = new DateTimeZone('Europe/Berlin');
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) {
            $endDate = $entry['work_date'];
            if ($value < substr($entry['start_time'], 0, 5)) {
                $endDate = (new DateTimeImmutable($entry['work_date'], $zone))->modify('+1 day')->format('Y-m-d');
            }
            $end = WorkTimeLocalTime::parse($endDate . ' ' . $value . ':00');
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:[+-]\d{2}:\d{2}|Z)?$/D', $value)) {
                throw new InvalidArgumentException('Die Endzeit ist ungueltig.');
            }
            try {
                if (!preg_match('/(?:[+-]\d{2}:\d{2}|Z)$/D', $value)) {
                    $local = str_replace('T', ' ', $value);
                    $end = WorkTimeLocalTime::parse(strlen($local) === 16 ? $local . ':00' : $local);
                } else {
                    $end = (new DateTimeImmutable($value, $zone))->setTimezone($zone);
                }
            } catch (Throwable) {
                throw new InvalidArgumentException('Die Endzeit ist ungueltig.');
            }
        }
        if ($end->format('s') !== '00') {
            throw new InvalidArgumentException('Die Endzeit muss minutengenau eingegeben werden.');
        }
        return WorkTimeLocalTime::parse($end->format('Y-m-d H:i:s'));
    }

}
