<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\AppTimesheetSyncService;
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
    }

    public function statusForUser(int $userId): array
    {
        if (!$this->policies->enabled()) {
            return ['enabled' => false, 'checkout_proposals' => [], 'presence_session' => null];
        }
        // GET is read-only. Obsolete proposals are filtered and later audited by the worker.
        $proposals = $this->connection->fetchAll('SELECT p.*, t.work_date, t.start_time FROM work_time_checkout_proposals p JOIN timesheets t ON t.id = p.timesheet_id JOIN users u ON u.id = p.user_id WHERE p.user_id = :user_id AND p.status = "pending" AND t.end_time IS NULL AND t.start_time IS NOT NULL AND COALESCE(t.is_deleted, 0) = 0 AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0 ORDER BY p.proposed_end_at, p.id', ['user_id' => $userId]);
        $activeEntries = $this->connection->fetchAll('SELECT t.id, t.user_id, t.work_date, t.start_time, t.project_id FROM timesheets t JOIN users u ON u.id = t.user_id WHERE t.user_id = :user_id AND t.entry_type = "work" AND t.start_time IS NOT NULL AND t.end_time IS NULL AND COALESCE(t.is_deleted, 0) = 0 AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0 ORDER BY t.work_date, t.id', ['user_id' => $userId]);
        $assignedEntries = array_filter($activeEntries, fn (array $row): bool => $this->policies->policyFor($userId, $row['work_date']) !== null);
        $conflict = $assignedEntries !== [] && count($activeEntries) > 1;
        $activeEntry = null;
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        if (count($activeEntries) === 1 && $assignedEntries !== [] && $activeEntries[0]['work_date'] < $today) {
            $activeEntry = $activeEntries[0];
            foreach (['id', 'user_id'] as $field) { $activeEntry[$field] = (int) $activeEntry[$field]; }
            $activeEntry['project_id'] = $activeEntry['project_id'] !== null ? (int) $activeEntry['project_id'] : null;
            $activeEntry['current_break'] = $this->connection->fetchOne('SELECT id, break_started_at, break_ended_at FROM timesheet_breaks WHERE timesheet_id = :id AND break_ended_at IS NULL ORDER BY id DESC LIMIT 1', ['id' => $activeEntry['id']]);
        }

        return ['enabled' => true, 'checkout_proposals' => $proposals, 'presence_session' => $this->sync->openPresenceForUser($userId), 'active_work_entry' => $activeEntry, 'active_work_entry_conflict' => $conflict];
    }

    public function notificationEventsForEmployee(int $userId): array
    {
        if (!$this->policies->enabled()) {
            return [];
        }
        return $this->connection->fetchAll('SELECT e.*, CONCAT(u.first_name, " ", u.last_name) AS recipient_name FROM work_time_notification_events e JOIN work_time_checkout_proposals p ON e.event_key = CONCAT("checkout-proposal:", p.id) JOIN users u ON u.id = e.recipient_user_id WHERE p.user_id = :user_id ORDER BY e.id DESC LIMIT 100', ['user_id' => $userId]);
    }

    public function workerLockName(): string
    {
        // Separate scratch databases (and independent app installations) must not
        // suppress one another's workers on a shared MariaDB server.
        return 'work-time-events-worker:' . substr(hash('sha256', (string) $this->connection->fetchColumn('SELECT DATABASE()')), 0, 16);
    }

    public function respond(int $userId, int $proposalId, string $action, ?string $actualEndAt = null, ?string $requestId = null): array
    {
        if (!$this->policies->enabled()) {
            throw new InvalidArgumentException('Arbeitszeitfenster sind deaktiviert.');
        }
        if (!in_array($action, ['confirm', 'correct', 'continue'], true) || $requestId === null || $requestId === '' || strlen($requestId) > 80) {
            throw new InvalidArgumentException('Eine gueltige Aktion und Request-ID sind erforderlich.');
        }
        $guard = new TimesheetWriteGuard($this->connection);

        return $guard->withAccountingWriteLock(function () use ($userId, $proposalId, $action, $actualEndAt, $requestId): array {
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
            $entry = $this->connection->fetchOne('SELECT t.*, u.employment_status, u.is_deleted AS user_deleted FROM timesheets t JOIN users u ON u.id = t.user_id WHERE t.id = :id AND t.user_id = :user_id', ['id' => $proposal['timesheet_id'], 'user_id' => $userId]);
            if ($proposal['status'] !== 'pending') {
                return ['ok' => true, 'status' => 'already_resolved', 'proposal' => $proposal];
            }
            if ($entry === null || !empty($entry['end_time']) || (int) $entry['is_deleted'] === 1 || $entry['employment_status'] !== 'active' || (int) $entry['user_deleted'] === 1) {
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

            return ['ok' => true, 'status' => $resultStatus, 'proposal' => $completed, 'timesheet' => $sync['timesheet'] ?? null];
        });
    }

    public function process(bool $dryRun = false, ?DateTimeImmutable $now = null): array
    {
        $summary = ['enabled' => $this->policies->enabled(), 'dry_run' => $dryRun, 'proposals' => 0, 'stale' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0, 'uncertain' => 0, 'errors' => 0, 'locked' => false];
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
                $stale = $this->connection->fetchAll('SELECT p.* FROM work_time_checkout_proposals p JOIN timesheets t ON t.id = p.timesheet_id JOIN users u ON u.id = p.user_id WHERE p.status = "pending" AND (t.end_time IS NOT NULL OR COALESCE(t.is_deleted, 0) = 1 OR u.employment_status <> "active" OR COALESCE(u.is_deleted, 0) = 1)');
                foreach ($stale as $proposal) {
                    $summary['stale']++;
                    if (!$dryRun) {
                        $this->connection->transaction(function () use ($proposal): void {
                            $this->finishProposal((int) $proposal['id'], (int) $proposal['user_id'], 'stale', 'stale:' . $proposal['id'], null, null);
                        });
                    }
                }
                $entries = $this->connection->fetchAll('SELECT t.* FROM timesheets t JOIN users u ON u.id = t.user_id WHERE t.entry_type = "work" AND t.start_time IS NOT NULL AND t.end_time IS NULL AND COALESCE(t.is_deleted, 0) = 0 AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0 AND t.work_date <= :today ORDER BY t.id', ['today' => $now->format('Y-m-d')]);
                foreach ($entries as $entry) {
                    try {
                        try {
                            $guard->assertAccountingPeriodOpen((int) $entry['user_id'], isset($entry['project_id']) ? (int) $entry['project_id'] : null, (string) $entry['work_date']);
                        } catch (InvalidArgumentException) {
                            continue;
                        }
                        $policy = $this->policies->policyFor((int) $entry['user_id'], $entry['work_date']);
                        if ($policy === null || empty($policy['checkout_proposals_enabled'])) {
                            continue;
                        }
                        $startedAt = new DateTimeImmutable($entry['work_date'] . ' ' . $entry['start_time'], new DateTimeZone('Europe/Berlin'));
                        $proposedEnd = $this->windowEnd($policy, $entry['work_date'], $startedAt);
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
                            $this->queueNotifications($proposalId, (int) $entry['user_id'], $policy, $proposedEnd);
                        });
                    } catch (Throwable $exception) {
                        $summary['errors']++;
                        error_log('Work-time proposal failed for timesheet ' . (int) $entry['id'] . ': ' . get_class($exception));
                    }
                }
            });
            if (!$dryRun && $this->previews !== null) {
                $dates = $this->connection->fetchAll('SELECT t.user_id, MIN(t.work_date) AS date_from, MAX(t.work_date) AS date_to FROM timesheets t JOIN users u ON u.id = t.user_id WHERE t.entry_type = "work" AND t.end_time IS NOT NULL AND COALESCE(t.is_deleted, 0) = 0 AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0 AND t.work_date <= :date_to AND EXISTS (SELECT 1 FROM employee_work_time_assignments a WHERE a.user_id = t.user_id AND a.effective_from <= t.work_date AND (a.effective_to IS NULL OR a.effective_to >= t.work_date)) GROUP BY t.user_id', ['date_to' => $now->format('Y-m-d')]);
                foreach ($dates as $date) {
                    try {
                        $this->previews->reconcileUser((int) $date['user_id'], $date['date_from'], $date['date_to']);
                    } catch (Throwable $exception) {
                        $summary['errors']++;
                        error_log('Work-time reconciliation failed for user ' . (int) $date['user_id'] . ': ' . get_class($exception));
                    }
                }
            }
            if (!$dryRun) {
                $this->dispatchNotifications($summary);
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

    private function windowEnd(array $policy, string $date, DateTimeImmutable $startedAt): ?DateTimeImmutable
    {
        $day = new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin'));
        $previous = $day->modify('-1 day');
        foreach ($policy['windows'][(int) $previous->format('N')] ?? [] as $window) {
            if ($window['end'] <= $window['start']) {
                $carriedEnd = WorkTimeLocalTime::parse($date . ' ' . $window['end'] . ':00');
                if ($startedAt < $carriedEnd) {
                    return $carriedEnd;
                }
            }
        }
        $windows = $policy['windows'][(int) $day->format('N')] ?? [];
        $end = null;
        foreach ($windows as $window) {
            $endDate = $window['end'] <= $window['start'] ? $day->modify('+1 day')->format('Y-m-d') : $date;
            $candidate = WorkTimeLocalTime::parse($endDate . ' ' . $window['end'] . ':00');
            if ($end === null || $candidate > $end) {
                $end = $candidate;
            }
        }
        return $end;
    }

    private function queueNotifications(int $proposalId, int $employeeId, array $policy, DateTimeImmutable $end): void
    {
        $recipients = [$employeeId => ['push' => !empty($policy['notify_employee_push']), 'sms' => !empty($policy['notify_employee_sms'])]];
        foreach ($policy['supervisor_user_ids'] ?? [] as $id) {
            $id = (int) $id;
            $recipients[$id] = ['push' => !empty($recipients[$id]['push']) || !empty($policy['notify_supervisor_push']), 'sms' => !empty($recipients[$id]['sms']) || !empty($policy['notify_supervisor_sms'])];
        }
        foreach ($recipients as $id => $channels) {
            foreach ($channels as $channel => $selected) {
                if (!$selected) {
                    continue;
                }
                $message = ['title' => 'Arbeitszeitfenster beendet', 'body' => ($id === $employeeId ? '' : 'Mitarbeiter #' . $employeeId . ': ') . 'Das Arbeitszeitfenster endete um ' . $end->format('H:i') . ' Uhr. Der tatsaechliche Arbeitsabschluss ist noch zu bestaetigen.', 'url' => $id === $employeeId ? '/app/zeiten' : '/admin/work-time?user_id=' . $employeeId, 'type' => 'work_time_checkout_proposal', 'proposal_id' => $proposalId, 'employee_user_id' => $employeeId, 'tag' => 'work-time-proposal-' . $proposalId];
                $this->connection->execute('INSERT INTO work_time_notification_events (event_key, recipient_user_id, channel, status, payload_json, created_at, updated_at) VALUES (:event_key, :recipient_id, :channel, "pending", :payload, NOW(), NOW()) ON DUPLICATE KEY UPDATE event_key = VALUES(event_key)', ['event_key' => 'checkout-proposal:' . $proposalId, 'recipient_id' => $id, 'channel' => $channel, 'payload' => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            }
        }
    }

    private function dispatchNotifications(array &$summary): void
    {
        // Holding the worker lock proves that no previous sender is still running.
        // A previous crash cannot tell us whether a provider received its request.
        $this->connection->execute('UPDATE work_time_notification_events SET status = "uncertain", error_message = "Versandstatus nach Unterbrechung ungeklaert; kein automatischer Neuversand.", updated_at = NOW() WHERE status = "sending"');
        $events = $this->connection->fetchAll('SELECT e.*, u.phone, u.employment_status, u.is_deleted AS user_deleted FROM work_time_notification_events e JOIN users u ON u.id = e.recipient_user_id WHERE e.status = "pending" AND e.attempts = 0 ORDER BY e.id LIMIT 500');
        foreach ($events as $event) {
            $status = 'failed';
            $error = null;
            $payload = json_decode($event['payload_json'], true) ?: [];
            $proposal = $this->connection->fetchOne('SELECT status FROM work_time_checkout_proposals WHERE id = :id', ['id' => (int) ($payload['proposal_id'] ?? 0)]);
            if ($event['employment_status'] !== 'active' || (int) $event['user_deleted'] === 1 || ($proposal['status'] ?? '') !== 'pending') {
                $status = 'disabled';
                $error = 'Empfaenger oder fachliches Ereignis ist nicht mehr aktiv.';
            } elseif (($event['channel'] === 'sms' && !$this->smsProvider->enabled()) || ($event['channel'] === 'push' && $this->pushSender === null)) {
                $status = 'disabled';
                $error = 'Der Versandkanal ist deaktiviert oder nicht konfiguriert.';
            } else {
                // Persist the attempt before any external I/O. A crash leaves sending/uncertain;
                // automatic retries could send a second message after a successful first send.
                $this->connection->execute('UPDATE work_time_notification_events SET status = "sending", attempts = attempts + 1, last_attempt_at = NOW(), updated_at = NOW() WHERE id = :id AND status = "pending" AND attempts = 0', ['id' => $event['id']]);
                try {
                    $key = $event['event_key'] . ':' . $event['recipient_user_id'] . ':' . $event['channel'];
                    $result = $event['channel'] === 'sms'
                        ? $this->smsProvider->send(PhoneNumber::normalize((string) $event['phone']), $payload['body'] ?? '', $key)
                        : ($this->pushSender)((int) $event['recipient_user_id'], $payload, $key);
                    $status = !empty($result['ok']) ? 'sent' : 'failed';
                    $error = $status === 'sent' ? null : 'Der Versandkanal hat den Versand nicht bestaetigt.';
                } catch (Throwable) {
                    $error = 'Versand fehlgeschlagen; bitte Kanal und Empfaenger pruefen.';
                }
            }
            $this->connection->execute('UPDATE work_time_notification_events SET status = :status, error_message = :error, sent_at = CASE WHEN :is_sent = 1 THEN NOW() ELSE NULL END, updated_at = NOW() WHERE id = :id', ['status' => $status, 'error' => $error, 'is_sent' => $status === 'sent' ? 1 : 0, 'id' => $event['id']]);
            $summary[$status]++;
        }
    }
}
