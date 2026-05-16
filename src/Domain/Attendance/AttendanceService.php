<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Infrastructure\Database\DatabaseConnection;

final class AttendanceService
{
    private const ABSENCE_TYPES = ['sick', 'vacation', 'holiday', 'absent'];

    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function todaySummary(?string $today = null): array
    {
        $today = $today ?: (new \DateTimeImmutable('today'))->format('Y-m-d');

        if ($this->connection->tableExists('timesheets') && $this->connection->tableExists('users')) {
            $rows = $this->connection->fetchAll(
                'SELECT
                    timesheets.id,
                    timesheets.user_id,
                    timesheets.project_id,
                    timesheets.work_date,
                    timesheets.entry_type,
                    timesheets.start_time,
                    timesheets.end_time,
                    timesheets.net_minutes,
                    timesheets.note,
                    timesheets.updated_at,
                    users.employee_number,
                    users.first_name,
                    users.last_name,
                    users.email,
                    users.is_deleted AS user_is_deleted,
                    projects.name AS project_name,
                    COALESCE(projects.is_deleted, 0) AS project_is_deleted
                 FROM timesheets
                 INNER JOIN users ON users.id = timesheets.user_id
                 LEFT JOIN projects ON projects.id = timesheets.project_id
                 WHERE timesheets.work_date = :today
                   AND COALESCE(timesheets.is_deleted, 0) = 0
                   AND timesheets.entry_type IN ("work", "sick", "vacation", "holiday", "absent")
                 ORDER BY timesheets.user_id ASC, timesheets.updated_at DESC, timesheets.id DESC',
                ['today' => $today]
            );
            $activeUsers = $this->activeUsers();

            return $this->summarizeRows($rows, $today, $activeUsers);
        }

        return $this->fallbackSummary($today);
    }

    public function presentCount(?string $today = null): int
    {
        return (int) $this->todaySummary($today)['present_count'];
    }

    public function summarizeRows(array $rows, string $today, array $activeUsers = []): array
    {
        $present = [];
        $statuses = [];
        $seenUsers = [];
        $usersWithStatus = [];

        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);

            if ($userId <= 0 || isset($seenUsers[$userId])) {
                continue;
            }

            if ((int) ($row['user_is_deleted'] ?? 0) === 1) {
                $seenUsers[$userId] = true;

                continue;
            }

            $seenUsers[$userId] = true;
            $usersWithStatus[$userId] = true;
            $entryType = (string) ($row['entry_type'] ?? '');
            $mapped = $this->mapRow($row);

            if ($entryType === 'work') {
                $present[] = $mapped;

                continue;
            }

            if (in_array($entryType, self::ABSENCE_TYPES, true)) {
                $statuses[] = [
                    ...$mapped,
                    'entry_type' => $entryType,
                    'is_derived' => false,
                    'status_source' => 'stored',
                ];
            }
        }

        foreach ($this->derivedMissingStatuses($activeUsers, $usersWithStatus, $today) as $missingStatus) {
            $statuses[] = $missingStatus;
        }

        $derivedMissingCount = count(array_filter(
            $statuses,
            static fn (array $status): bool => (string) ($status['status_source'] ?? '') === 'derived_missing'
        ));

        return [
            'today' => $today,
            'present_count' => count($present),
            'present' => $present,
            'status_counts' => $this->statusCounts($statuses),
            'derived_missing_count' => $derivedMissingCount,
            'statuses' => $statuses,
        ];
    }

    private function fallbackSummary(string $today): array
    {
        $present = [
            [
                'user_id' => 1,
                'employee_number' => 'MA-0001',
                'user_name' => 'Claudia Werner',
                'location' => 'Neubau Kita Nord',
                'project_name' => 'Neubau Kita Nord',
                'start_time' => '07:00',
                'end_time' => '16:00',
                'net_minutes' => 495,
                'note' => 'Disposition und Materialannahme',
                'updated_at' => $today . ' 16:00:00',
            ],
        ];
        $statuses = [
            [
                'user_id' => 2,
                'employee_number' => 'MA-0002',
                'user_name' => 'Leonie Kurz',
                'location' => 'Nicht zugeordnet',
                'project_name' => null,
                'start_time' => null,
                'end_time' => null,
                'net_minutes' => 0,
                'note' => 'Urlaub genehmigt',
                'updated_at' => $today . ' 08:00:00',
                'entry_type' => 'vacation',
                'is_derived' => false,
                'status_source' => 'stored',
            ],
        ];

        return [
            'today' => $today,
            'present_count' => count($present),
            'present' => $present,
            'status_counts' => $this->statusCounts($statuses),
            'derived_missing_count' => 0,
            'statuses' => $statuses,
        ];
    }

    private function activeUsers(): array
    {
        if (!$this->connection->tableExists('users')) {
            return [];
        }

        $timeTrackingSelect = $this->connection->columnExists('users', 'time_tracking_required')
            ? 'COALESCE(time_tracking_required, 1)'
            : '1';

        return $this->connection->fetchAll(
            'SELECT id, employee_number, first_name, last_name, email, ' . $timeTrackingSelect . ' AS time_tracking_required
             FROM users
             WHERE COALESCE(is_deleted, 0) = 0
               AND employment_status = "active"
             ORDER BY last_name ASC, first_name ASC, id ASC'
        );
    }

    private function derivedMissingStatuses(array $activeUsers, array $usersWithStatus, string $today): array
    {
        if (!$this->shouldDeriveMissing($today)) {
            return [];
        }

        $statuses = [];

        foreach ($activeUsers as $user) {
            $userId = (int) ($user['id'] ?? 0);

            if ($userId <= 0 || isset($usersWithStatus[$userId])) {
                continue;
            }

            if ((int) ($user['time_tracking_required'] ?? 1) !== 1) {
                continue;
            }

            $statuses[] = [
                'user_id' => $userId,
                'employee_number' => $this->nullableTrimmed($user['employee_number'] ?? null),
                'user_name' => $this->userName($user),
                'location' => 'Nicht zugeordnet',
                'project_name' => null,
                'start_time' => null,
                'end_time' => null,
                'net_minutes' => 0,
                'note' => 'Keine Tagesbuchung',
                'updated_at' => '',
                'entry_type' => 'absent',
                'is_derived' => true,
                'status_source' => 'derived_missing',
            ];
        }

        return $statuses;
    }

    private function shouldDeriveMissing(string $today): bool
    {
        try {
            $date = new \DateTimeImmutable($today);
        } catch (\Exception) {
            return false;
        }

        $currentDay = new \DateTimeImmutable('today');

        return (int) $date->format('N') <= 5 && $date <= $currentDay;
    }

    private function mapRow(array $row): array
    {
        $projectDeleted = (int) ($row['project_is_deleted'] ?? 0) === 1;
        $projectName = trim((string) ($row['project_name'] ?? ''));
        $location = $projectDeleted || $projectName === '' ? 'Nicht zugeordnet' : $projectName;

        return [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'employee_number' => $this->nullableTrimmed($row['employee_number'] ?? null),
            'user_name' => $this->userName($row),
            'location' => $location,
            'project_name' => $projectDeleted || $projectName === '' ? null : $projectName,
            'start_time' => $this->nullableTrimmed($row['start_time'] ?? null),
            'end_time' => $this->nullableTrimmed($row['end_time'] ?? null),
            'net_minutes' => (int) ($row['net_minutes'] ?? 0),
            'note' => $this->nullableTrimmed($row['note'] ?? null),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    private function statusCounts(array $statuses): array
    {
        $counts = [
            'sick' => 0,
            'vacation' => 0,
            'holiday' => 0,
            'absent' => 0,
        ];

        foreach ($statuses as $status) {
            $entryType = (string) ($status['entry_type'] ?? '');

            if (array_key_exists($entryType, $counts)) {
                $counts[$entryType]++;
            }
        }

        return $counts;
    }

    private function userName(array $row): string
    {
        $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));

        return $name !== '' ? $name : (string) ($row['email'] ?? 'Unbekannt');
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
