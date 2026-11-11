<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** Durable provider attempts: acceptance is not proof of delivery to a device. */
final class WorkTimeNotificationService
{
    public const AUTOMATIC_ATTEMPTS = 3;
    public const TOTAL_ATTEMPTS = 5;
    private ?Closure $pushSender;
    private SmsProvider $smsProvider;

    public function __construct(private DatabaseConnection $connection, private WorkTimePolicyService $policies, ?callable $pushSender = null, ?SmsProvider $smsProvider = null)
    {
        $this->pushSender = $pushSender === null ? null : Closure::fromCallable($pushSender);
        $this->smsProvider = $smsProvider ?? new MockSmsProvider();
    }

    public function queue(string $type, array $entry, array $policy, DateTimeImmutable $eventAt, ?int $proposalId = null, bool $dryRun = false): int
    {
        if (!$this->policies->enabled()) { return 0; }
        $employeeId = (int) $entry['user_id'];
        if (!in_array($type, ['work_time_checkout_proposal', 'work_time_outside_window_started'], true)) {
            throw new InvalidArgumentException('Unbekanntes Arbeitszeit-Versandereignis.');
        }
        $sourceHash = substr(hash('sha256', $entry['work_date'] . ':' . $entry['start_time'] . ':' . $policy['policy_version_id']), 0, 16);
        $key = $type === 'work_time_checkout_proposal' ? 'checkout-proposal:' . $proposalId : 'outside-start:' . $entry['id'] . ':' . $sourceHash;
        $recipients = [$employeeId => ['push' => !empty($policy['notify_employee_push']), 'sms' => !empty($policy['notify_employee_sms'])]];
        foreach ($policy['supervisor_user_ids'] ?? [] as $id) {
            $id = (int) $id;
            $recipients[$id] = ['push' => !empty($recipients[$id]['push']) || !empty($policy['notify_supervisor_push']), 'sms' => !empty($recipients[$id]['sms']) || !empty($policy['notify_supervisor_sms'])];
        }
        $count = 0;
        foreach ($recipients as $id => $channels) {
            foreach ($channels as $channel => $selected) {
                if (!$selected) { continue; }
                if ($this->connection->fetchOne('SELECT id FROM work_time_notification_events WHERE event_key = :event_key AND recipient_user_id = :recipient AND channel = :channel', ['event_key' => $key, 'recipient' => $id, 'channel' => $channel]) !== null) { continue; }
                // A corrected start must not notify a device again after an earlier
                // accepted attempt for the same actual work session.
                if ($type === 'work_time_outside_window_started' && $this->connection->fetchOne('SELECT id FROM work_time_notification_events WHERE timesheet_id = :timesheet AND event_type = :event_type AND recipient_user_id = :recipient AND channel = :channel AND status IN ("provider_accepted", "uncertain", "sending") LIMIT 1', ['timesheet' => $entry['id'], 'event_type' => $type, 'recipient' => $id, 'channel' => $channel]) !== null) { continue; }
                ++$count;
                if ($dryRun) { continue; }
                $prefix = $id === $employeeId ? '' : 'Mitarbeiter #' . $employeeId . ': ';
                $message = [
                    'title' => $type === 'work_time_checkout_proposal' ? 'Arbeitszeitfenster beendet' : 'Arbeit ausserhalb des Zeitfensters',
                    'body' => $prefix . ($type === 'work_time_checkout_proposal'
                        ? 'Das Arbeitszeitfenster endete um ' . $eventAt->format('H:i') . ' Uhr. Der tatsaechliche Arbeitsabschluss ist noch zu bestaetigen.'
                        : 'Seit ' . substr((string) $entry['start_time'], 0, 5) . ' Uhr laeuft ein Arbeitseinsatz ausserhalb des Arbeitszeitfensters. Die erfasste Arbeitszeit bleibt erhalten.'),
                    'url' => $id === $employeeId ? '/app/zeiten' : '/admin/work-time?user_id=' . $employeeId,
                    'type' => $type, 'proposal_id' => $proposalId, 'employee_user_id' => $employeeId,
                    'timesheet_id' => (int) $entry['id'], 'work_date' => $entry['work_date'], 'start_time' => $entry['start_time'],
                    'tag' => 'work-time-' . substr(hash('sha256', $key), 0, 24),
                ];
                $this->connection->execute('INSERT INTO work_time_notification_events (event_key, recipient_user_id, employee_user_id, timesheet_id, proposal_id, policy_version_id, event_type, channel, status, payload_json, created_at, updated_at) VALUES (:event_key, :recipient, :employee, :timesheet, :proposal, :version, :event_type, :channel, "waiting", :payload, NOW(), NOW()) ON DUPLICATE KEY UPDATE event_key = VALUES(event_key)', ['event_key' => $key, 'recipient' => $id, 'employee' => $employeeId, 'timesheet' => $entry['id'], 'proposal' => $proposalId, 'version' => $policy['policy_version_id'], 'event_type' => $type, 'channel' => $channel, 'payload' => json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                $row = $this->connection->fetchOne('SELECT id FROM work_time_notification_events WHERE event_key = :event_key AND recipient_user_id = :recipient AND channel = :channel', ['event_key' => $key, 'recipient' => $id, 'channel' => $channel]);
                if ($row !== null) { $this->history((int) $row['id'], 'queued', null, 'waiting'); }
            }
        }
        return $count;
    }

    public function dispatch(?DateTimeImmutable $now = null, int $limit = 100): array
    {
        $summary = ['provider_accepted' => 0, 'failed' => 0, 'not_configured' => 0, 'uncertain' => 0, 'cancelled' => 0, 'test_simulated' => 0, 'attempted' => 0, 'locked' => false];
        if (!$this->policies->enabled()) { return $summary; }
        $now = $this->now($now);
        $lock = 'work-time-notifications:' . substr(hash('sha256', (string) $this->connection->fetchColumn('SELECT DATABASE()')), 0, 16);
        if ((int) $this->connection->fetchColumn('SELECT GET_LOCK(:name, 0)', ['name' => $lock]) !== 1) { $summary['locked'] = true; return $summary; }
        try {
            // Only the holder of this batch lock may perform I/O. An old sending
            // row consequently belongs to an interrupted process, not a live sender.
            foreach ($this->connection->fetchAll('SELECT id FROM work_time_notification_events WHERE status = "sending" ORDER BY id') as $abandoned) {
                $this->connection->transaction(function () use ($abandoned, $now): void {
                    $this->transition((int) $abandoned['id'], 'sending', 'uncertain', 'Versand nach Prozessunterbrechung unklar; kein automatischer Neuversand.', $now);
                    $this->connection->execute('UPDATE work_time_notification_attempts SET status = "uncertain", error_message = "Providerannahme nach Unterbrechung unklar.", finished_at = :finished WHERE notification_event_id = :event AND status = "sending"', ['finished' => $now->format('Y-m-d H:i:s'), 'event' => $abandoned['id']]);
                });
            }
            $limit = max(1, min(500, $limit));
            $events = $this->connection->fetchAll('SELECT e.* FROM work_time_notification_events e WHERE ((e.status IN ("waiting", "manual_retry_requested")) OR (e.status = "failed" AND e.retryable = 1 AND e.attempts < ' . self::AUTOMATIC_ATTEMPTS . ')) AND (e.next_attempt_at IS NULL OR e.next_attempt_at <= :now) ORDER BY e.next_attempt_at, e.id LIMIT ' . $limit, ['now' => $now->format('Y-m-d H:i:s')]);
            foreach ($events as $event) {
                $payload = json_decode((string) $event['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                $claim = (new TimesheetWriteGuard($this->connection))->withAccountingWriteLock(function () use ($event, $payload, $now): ?array {
                    return $this->connection->transaction(function () use ($event, $payload, $now): ?array {
                        $current = $this->connection->fetchOne('SELECT * FROM work_time_notification_events WHERE id = :id FOR UPDATE', ['id' => $event['id']]);
                        if ($current === null || !in_array($current['status'], ['waiting', 'manual_retry_requested', 'failed'], true)) { return null; }
                        $recipient = $this->connection->fetchOne('SELECT phone, employment_status, is_deleted FROM users WHERE id = :id', ['id' => $current['recipient_user_id']]);
                        if (!$this->active($current, $payload, $now, $recipient)) {
                            $this->transition((int) $event['id'], $current['status'], 'cancelled', 'Empfaenger oder fachliches Ereignis ist nicht mehr aktiv.', $now);
                            return ['terminal_status' => 'cancelled'];
                        }
                        if (($event['channel'] === 'sms' && !$this->smsProvider->enabled()) || ($event['channel'] === 'push' && $this->pushSender === null)) {
                            $this->transition((int) $event['id'], $current['status'], 'not_configured', 'Der Versandkanal ist nicht konfiguriert. SMS bleibt ohne produktiven Adapter deaktiviert.', $now);
                            return ['terminal_status' => 'not_configured'];
                        }
                        $number = (int) $current['attempts'] + 1;
                        if ($number > self::TOTAL_ATTEMPTS) { return null; }
                        $retryRequest = $current['status'] === 'manual_retry_requested' ? $this->connection->fetchOne('SELECT id FROM work_time_notification_retry_requests WHERE notification_event_id = :id ORDER BY id DESC LIMIT 1', ['id' => $event['id']]) : null;
                        // Keep the provider key stable across retries when a future adapter
                        // implements real provider-side idempotency; tags alone are no proof.
                        $key = $event['event_key'] . ':' . $event['recipient_user_id'] . ':' . $event['channel'];
                        $this->connection->execute('INSERT INTO work_time_notification_attempts (notification_event_id, attempt_number, trigger_type, retry_request_id, provider_request_key, status, created_at) VALUES (:event, :number, :trigger_type, :retry_request, :request_key, "sending", :created)', ['event' => $event['id'], 'number' => $number, 'trigger_type' => $retryRequest !== null ? 'manual' : 'automatic', 'retry_request' => $retryRequest['id'] ?? null, 'request_key' => $key, 'created' => $now->format('Y-m-d H:i:s')]);
                        $attemptId = $this->connection->lastInsertId();
                        $this->connection->execute('UPDATE work_time_notification_events SET status = "sending", attempts = :attempts, retryable = 0, next_attempt_at = NULL, last_attempt_at = :attempted, updated_at = :updated WHERE id = :id', ['attempts' => $number, 'attempted' => $now->format('Y-m-d H:i:s'), 'updated' => $now->format('Y-m-d H:i:s'), 'id' => $event['id']]);
                        $this->history((int) $event['id'], 'attempt_started', $current['status'], 'sending');
                        return ['attempt_id' => $attemptId, 'attempt_number' => $number, 'request_key' => $key, 'phone' => $recipient['phone'] ?? null];
                    });
                });
                if ($claim === null) { continue; }
                if (isset($claim['terminal_status'])) { ++$summary[$claim['terminal_status']]; continue; }
                ++$summary['attempted'];
                try {
                    if ($event['channel'] === 'sms') {
                        try { $phone = PhoneNumber::normalize((string) ($claim['phone'] ?? '')); }
                        catch (InvalidArgumentException) { $phone = null; }
                        $result = $phone === null ? ['ok' => false, 'definitive_not_accepted' => true, 'retryable' => false] : $this->smsProvider->send($phone, (string) ($payload['body'] ?? ''), $claim['request_key']);
                    } else {
                        $result = ($this->pushSender)((int) $event['recipient_user_id'], $payload, $claim['request_key']);
                    }
                } catch (Throwable) {
                    $result = ['ok' => false];
                }
                $status = !empty($result['simulated']) ? 'test_simulated' : (!empty($result['not_configured']) ? 'not_configured' : (!empty($result['ok']) ? 'provider_accepted' : (!empty($result['definitive_not_accepted']) ? 'failed' : 'uncertain')));
                $retryable = $status === 'failed' && !empty($result['retryable']) && (int) $claim['attempt_number'] < self::AUTOMATIC_ATTEMPTS;
                $error = match ($status) {
                    'provider_accepted' => null,
                    'test_simulated' => !empty($result['simulation_failed']) ? 'Testsimulation meldet einen Fehler; kein echter Versand.' : 'Testsimulation ohne echten Versand.',
                    'not_configured' => 'Versandkanal oder Empfaengergeraet ist nicht konfiguriert.',
                    'failed' => 'Der Provider hat die Annahme nachweislich abgelehnt oder die Empfaengerangabe ist ungueltig.',
                    default => 'Providerannahme oder Teilversand ist unklar; kein automatischer Neuversand.',
                };
                $providerId = isset($result['provider_message_id']) && is_scalar($result['provider_message_id']) ? mb_substr((string) $result['provider_message_id'], 0, 200) : null;
                $this->connection->transaction(function () use ($event, $claim, $now, $status, $error, $retryable, $providerId): void {
                    $next = $retryable ? $now->modify('+' . (30 * (4 ** ((int) $claim['attempt_number'] - 1))) . ' seconds')->format('Y-m-d H:i:s') : null;
                    $acceptedAt = $status === 'provider_accepted' ? $now->format('Y-m-d H:i:s') : null;
                    $this->connection->execute('UPDATE work_time_notification_attempts SET status = :status, provider_message_id = :provider, error_message = :error, accepted_at = :accepted, finished_at = :finished WHERE id = :id AND status = "sending"', ['status' => $status, 'provider' => $providerId, 'error' => $error, 'accepted' => $acceptedAt, 'finished' => $now->format('Y-m-d H:i:s'), 'id' => $claim['attempt_id']]);
                    $this->connection->execute('UPDATE work_time_notification_events SET status = :status, provider_message_id = :provider, error_message = :error, accepted_at = :accepted, sent_at = :legacy_sent, next_attempt_at = :next, retryable = :retryable, updated_at = :updated WHERE id = :id AND status = "sending"', ['status' => $status, 'provider' => $providerId, 'error' => $error, 'accepted' => $acceptedAt, 'legacy_sent' => $acceptedAt, 'next' => $next, 'retryable' => $retryable ? 1 : 0, 'updated' => $now->format('Y-m-d H:i:s'), 'id' => $event['id']]);
                    $this->history((int) $event['id'], 'attempt_finished', 'sending', $status, $error);
                });
                ++$summary[$status];
            }
            $summary['uncertain_total'] = (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM work_time_notification_events WHERE status = "uncertain"');
            return $summary;
        } finally { $this->connection->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $lock]); }
    }

    public function find(int $id): ?array
    {
        return $this->connection->fetchOne('SELECT e.*, t.work_date, CONCAT(recipient.first_name, " ", recipient.last_name) AS recipient_name, CONCAT(employee.first_name, " ", employee.last_name) AS employee_name FROM work_time_notification_events e JOIN users recipient ON recipient.id = e.recipient_user_id LEFT JOIN users employee ON employee.id = e.employee_user_id LEFT JOIN timesheets t ON t.id = e.timesheet_id WHERE e.id = :id', ['id' => $id]);
    }

    public function list(?int $employeeId = null, int $limit = 100): array
    {
        if (!$this->policies->enabled()) { return []; }
        $limit = max(1, min(200, $limit));
        $rows = $this->connection->fetchAll('SELECT e.*, t.work_date, CONCAT(recipient.first_name, " ", recipient.last_name) AS recipient_name, CONCAT(employee.first_name, " ", employee.last_name) AS employee_name FROM work_time_notification_events e JOIN users recipient ON recipient.id = e.recipient_user_id LEFT JOIN users employee ON employee.id = e.employee_user_id LEFT JOIN timesheets t ON t.id = e.timesheet_id' . ($employeeId !== null ? ' WHERE e.employee_user_id = :employee' : '') . ' ORDER BY e.id DESC LIMIT ' . $limit, $employeeId !== null ? ['employee' => $employeeId] : []);
        if ($rows === []) { return []; }
        $ids = implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $attempts = $this->connection->fetchAll('SELECT a.*, r.actor_user_id, r.reason AS retry_reason FROM work_time_notification_attempts a LEFT JOIN work_time_notification_retry_requests r ON r.id = a.retry_request_id WHERE a.notification_event_id IN (' . $ids . ') ORDER BY a.id');
        $history = $this->connection->fetchAll('SELECT * FROM work_time_notification_history WHERE notification_event_id IN (' . $ids . ') ORDER BY id');
        foreach ($rows as &$row) {
            $row['attempt_history'] = array_values(array_filter($attempts, static fn (array $a): bool => (int) $a['notification_event_id'] === (int) $row['id']));
            $row['status_history'] = array_values(array_filter($history, static fn (array $a): bool => (int) $a['notification_event_id'] === (int) $row['id']));
            $row['manual_retry_allowed'] = $this->manualRetryAllowed($row);
        }
        unset($row);
        return $rows;
    }

    /** Controller must additionally enforce permission, employee scope and CSRF. */
    public function requestRetry(int $id, int $actorId, string $reason, string $requestId): array
    {
        if (!$this->policies->enabled()) { throw new InvalidArgumentException('Arbeitszeitfenster sind deaktiviert.'); }
        $reason = trim($reason);
        if ($actorId <= 0 || mb_strlen($reason) < 3 || mb_strlen($reason) > 2000 || $requestId === '' || strlen($requestId) > 80) { throw new InvalidArgumentException('Eine Begruendung und eindeutige Request-ID sind erforderlich.'); }
        return $this->connection->transaction(function () use ($id, $actorId, $reason, $requestId): array {
            $old = $this->connection->fetchOne('SELECT * FROM work_time_notification_retry_requests WHERE actor_user_id = :actor AND request_id = :request', ['actor' => $actorId, 'request' => $requestId]);
            if ($old !== null) {
                if ((int) $old['notification_event_id'] !== $id || $old['reason'] !== $reason) { throw new InvalidArgumentException('Die Request-ID gehoert zu einer anderen Wiederholung.'); }
                return $old;
            }
            $event = $this->connection->fetchOne('SELECT * FROM work_time_notification_events WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($event === null || !$this->manualRetryAllowed($event)) { throw new InvalidArgumentException('Eine Wiederholung ist nur bei nachweislicher Nichtannahme oder fehlender Konfiguration zulaessig, niemals bei unklarem oder angenommenem Versand.'); }
            $this->connection->execute('INSERT INTO work_time_notification_retry_requests (notification_event_id, actor_user_id, request_id, reason, previous_status) VALUES (:event, :actor, :request, :reason, :status)', ['event' => $id, 'actor' => $actorId, 'request' => $requestId, 'reason' => $reason, 'status' => $event['status']]);
            $retryId = $this->connection->lastInsertId();
            $this->connection->execute('UPDATE work_time_notification_events SET status = "manual_retry_requested", next_attempt_at = NULL, retryable = 0, updated_at = NOW() WHERE id = :id', ['id' => $id]);
            $this->history($id, 'manual_retry_requested', $event['status'], 'manual_retry_requested', $reason, $actorId);
            return $this->connection->fetchOne('SELECT * FROM work_time_notification_retry_requests WHERE id = :id', ['id' => $retryId]) ?? [];
        });
    }

    public function manualRetryAllowed(array $row): bool
    {
        return (int) ($row['attempts'] ?? 0) < self::TOTAL_ATTEMPTS
            && in_array($row['status'] ?? '', ['failed', 'not_configured'], true);
    }

    public function smsStatus(): string
    {
        if ($this->smsProvider instanceof MockSmsProvider) {
            return $this->smsProvider->enabled() ? 'SMS: Nur Testsimulation aktiviert; kein echter SMS-Versand.' : 'SMS: Kein produktiver Provider konfiguriert. Der Testadapter ist deaktiviert.';
        }
        return $this->smsProvider->enabled() ? 'SMS: Adapter konfiguriert. Providerannahme ist kein Zustellnachweis.' : 'SMS: Versandkanal ist deaktiviert.';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'waiting', 'pending' => 'Wartend', 'sending' => 'Versand laeuft',
            'provider_accepted', 'sent' => 'Vom Provider angenommen (Zustellung nicht nachgewiesen)',
            'failed' => 'Definitiv nicht angenommen', 'not_configured', 'disabled' => 'Nicht konfiguriert',
            'uncertain' => 'Zustellung / Providerannahme unklar', 'manual_retry_requested' => 'Manuell erneut angefordert',
            'cancelled' => 'Unterdrueckt / Ereignis beendet', 'test_simulated' => 'Testsimulation ohne echten Versand', default => $status,
        };
    }

    private function active(array $event, array $payload, DateTimeImmutable $now, ?array $recipient): bool
    {
        if (!in_array($event['channel'], ['push', 'sms'], true)) { return false; }
        if ($recipient === null || $recipient['employment_status'] !== 'active' || (int) $recipient['is_deleted'] === 1) { return false; }
        $entry = $this->connection->fetchOne('SELECT t.*, u.employment_status, u.is_deleted AS employee_deleted FROM timesheets t JOIN users u ON u.id = t.user_id WHERE t.id = :id', ['id' => $event['timesheet_id'] ?? 0]);
        if ($entry === null || $entry['entry_type'] !== 'work' || !empty($entry['end_time']) || (int) $entry['is_deleted'] === 1 || $entry['employment_status'] !== 'active' || (int) $entry['employee_deleted'] === 1) { return false; }
        if (isset($payload['work_date'], $payload['start_time']) && ($payload['work_date'] !== $entry['work_date'] || $payload['start_time'] !== $entry['start_time'])) { return false; }
        if ((int) ($event['employee_user_id'] ?? 0) !== (int) $entry['user_id']) { return false; }
        // The request-scoped service may have loaded this assignment before an
        // administrator changed notification overrides or its supervisor list.
        $policy = (new WorkTimePolicyService($this->connection, $this->policies->enabled()))->policyFor((int) $entry['user_id'], $entry['work_date']);
        if ($policy === null || (isset($event['policy_version_id']) && (int) $event['policy_version_id'] !== (int) $policy['policy_version_id'])) { return false; }
        $employeeRecipient = (int) $event['recipient_user_id'] === (int) $entry['user_id'];
        if ($employeeRecipient) {
            if (empty($policy['notify_employee_' . $event['channel']])) { return false; }
        } elseif (!in_array((int) $event['recipient_user_id'], array_map('intval', $policy['supervisor_user_ids'] ?? []), true)
            || empty($policy['notify_supervisor_' . $event['channel']])) { return false; }
        $startedAt = WorkTimeLocalTime::parse($entry['work_date'] . ' ' . $entry['start_time']);
        if (($event['event_type'] ?? '') === 'work_time_checkout_proposal') {
            if (empty($policy['checkout_proposals_enabled'])) { return false; }
            $proposal = $this->connection->fetchOne('SELECT status, proposed_end_at FROM work_time_checkout_proposals WHERE id = :id AND timesheet_id = :timesheet', ['id' => $event['proposal_id'] ?? 0, 'timesheet' => $entry['id']]);
            $end = WorkTimeEventWindows::windowEnd($policy, $entry['work_date'], $startedAt);
            return ($proposal['status'] ?? '') === 'pending' && $end !== null && $end > $startedAt
                && $end->format('Y-m-d H:i:s') === $proposal['proposed_end_at']
                && $end->modify('+' . (int) ($policy['checkout_delay_minutes'] ?? 0) . ' minutes') <= $now;
        }
        return ($event['event_type'] ?? '') === 'work_time_outside_window_started'
            && !empty($policy['outside_window_notifications_enabled'])
            && WorkTimeEventWindows::startedOutsideAfterWindow($policy, $startedAt)
            && $startedAt->modify('+' . (int) ($policy['outside_window_delay_minutes'] ?? 5) . ' minutes') <= $now;
    }

    private function transition(int $id, string $before, string $after, ?string $reason, DateTimeImmutable $now): void
    {
        $this->connection->execute('UPDATE work_time_notification_events SET status = :status, error_message = :reason, retryable = 0, next_attempt_at = NULL, updated_at = :updated WHERE id = :id', ['status' => $after, 'reason' => $reason, 'updated' => $now->format('Y-m-d H:i:s'), 'id' => $id]);
        $this->history($id, 'status_changed', $before, $after, $reason);
    }

    private function history(int $id, string $action, ?string $before, string $after, ?string $reason = null, ?int $actor = null): void
    {
        $this->connection->execute('INSERT INTO work_time_notification_history (notification_event_id, actor_user_id, action, previous_status, new_status, reason) VALUES (:event, :actor, :action, :previous, :status, :reason)', ['event' => $id, 'actor' => $actor, 'action' => $action, 'previous' => $before, 'status' => $after, 'reason' => $reason]);
    }

    private function now(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('Europe/Berlin'));
    }
}
