<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Presence is evidence of attendance, never an inferred work start. */
final class WorkTimePresenceService
{
    public function __construct(private DatabaseConnection $connection, private WorkTimePolicyService $policies)
    {
    }

    public function enabled(): bool
    {
        return $this->policies->enabled();
    }

    public function appliesToUser(int $userId, string $date): bool
    {
        return $this->enabled() && ($this->policies->policyFor($userId, $date) !== null || $this->openForUser($userId) !== null);
    }

    public function openForUser(int $userId): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM work_time_presence_sessions WHERE user_id = :user_id AND ended_at IS NULL AND work_started_at IS NULL ORDER BY id DESC LIMIT 1',
            ['user_id' => $userId]
        );
    }

    /** Called only inside the central timesheet write lock and transaction. */
    public function handle(int $userId, array $payload, string $action, ?array $workEntry): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $open = $this->openForUser($userId);
        if ($action === 'presence_end') {
            if ($open === null) {
                throw new RuntimeException('Es gibt keine offene Anwesenheit. Bitte neu laden.');
            }
            $this->connection->execute('UPDATE work_time_presence_sessions SET ended_at = NOW() WHERE id = :id', ['id' => $open['id']]);

            return $this->response('presence_ended', null);
        }
        if ($action === 'work_start') {
            if ($open === null) {
                throw new RuntimeException('Bitte die offene Anwesenheit neu laden, bevor Sie den Arbeitsbeginn bestaetigen.');
            }
            if (($workEntry !== null && !empty($workEntry['start_time']) && empty($workEntry['end_time'])) || (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id AND entry_type = "work" AND start_time IS NOT NULL AND end_time IS NULL AND COALESCE(is_deleted, 0) = 0', ['user_id' => $userId]) > 0) {
                throw new RuntimeException('Es besteht bereits ein laufender Arbeitseinsatz. Bitte neu laden und den Eintrag pruefen.');
            }
            $start = trim((string) ($payload['start_time'] ?? ''));
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/D', $start)) {
                throw new RuntimeException('Bitte den tatsaechlichen Arbeitsbeginn ausdruecklich angeben.');
            }
            $startedAt = WorkTimeLocalTime::parse((string) ($payload['work_date'] ?? '') . ' ' . (strlen($start) === 5 ? $start . ':00' : $start));
            $presenceStarted = new DateTimeImmutable($open['started_at'], new DateTimeZone('Europe/Berlin'));
            if ($startedAt < $presenceStarted || $startedAt > new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')) || $startedAt->getTimestamp() - $presenceStarted->getTimestamp() > 86400) {
                throw new RuntimeException('Der Arbeitsbeginn muss innerhalb von 24 Stunden nach der Anwesenheit liegen und darf nicht in der Zukunft liegen.');
            }

            return null;
        }

        if ($open !== null && $action === 'check_in') {
            return $this->response('presence_only', $open);
        }
        if ($open !== null && in_array($action, ['upsert', 'check_out', 'day_close', 'pause_start', 'pause_end', 'pause'], true)) {
            throw new RuntimeException('Bitte zuerst den tatsaechlichen Arbeitsbeginn bestaetigen.');
        }
        if ($action !== 'check_in' || ($workEntry !== null && !empty($workEntry['start_time']) && empty($workEntry['end_time']))) {
            return null;
        }

        $date = (string) ($payload['work_date'] ?? date('Y-m-d'));
        $policy = $this->policies->policyFor($userId, $date);
        if ($policy === null || ($policy['early_check_in_mode'] ?? 'work_started') !== 'presence_only') {
            return null;
        }
        $startTime = (string) ($payload['start_time'] ?? date('H:i'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $startTime)) {
            throw new RuntimeException('Die Anwesenheitszeit ist ungueltig.');
        }
        $start = WorkTimeLocalTime::parse($date . ' ' . (strlen($startTime) === 5 ? $startTime . ':00' : $startTime));
        $previousDay = $start->modify('-1 day');
        foreach ($policy['windows'][(int) $previousDay->format('N')] ?? [] as $window) {
            if ($window['end'] <= $window['start']) {
                $carriedEnd = WorkTimeLocalTime::parse($date . ' ' . $window['end'] . ':00');
                if ($start < $carriedEnd) {
                    return null;
                }
            }
        }
        $windows = $policy['windows'][(int) $start->format('N')] ?? [];
        $earliest = $windows === [] ? null : min(array_column($windows, 'start'));
        if ($earliest !== null && $start->format('H:i') >= $earliest) {
            return null;
        }

        $this->connection->execute(
            'INSERT INTO work_time_presence_sessions (user_id, policy_version_id, work_date, started_at, request_id, source, project_id, created_at) VALUES (:user_id, :version_id, :work_date, :started_at, :request_id, :source, :project_id, NOW())',
            ['user_id' => $userId, 'version_id' => $policy['policy_version_id'], 'work_date' => $date, 'started_at' => $start->format('Y-m-d H:i:s'), 'request_id' => $payload['client_request_id'], 'source' => ($payload['source'] ?? '') === 'terminal' ? 'terminal' : 'app', 'project_id' => !empty($payload['project_id']) ? (int) $payload['project_id'] : null]
        );

        return $this->response('presence_only', $this->openForUser($userId));
    }

    public function markWorkStarted(int $userId, int $timesheetId, string $date, string $time): void
    {
        $open = $this->openForUser($userId);
        if ($open !== null) {
            $this->connection->execute('UPDATE work_time_presence_sessions SET work_started_at = :started_at, work_timesheet_id = :timesheet_id WHERE id = :id', ['started_at' => $date . ' ' . $time, 'timesheet_id' => $timesheetId, 'id' => $open['id']]);
        }
    }

    private function response(string $status, ?array $session): array
    {
        return ['ok' => true, 'status' => $status, 'presence_session' => $session, 'timesheet' => null, 'message' => $status === 'presence_only' ? 'Außerhalb der Kernarbeitszeit anwesend. Bitte den tatsaechlichen Arbeitsbeginn bestaetigen, sobald Sie arbeiten.' : 'Anwesenheit beendet.', 'server_time' => date(DATE_ATOM)];
    }
}
