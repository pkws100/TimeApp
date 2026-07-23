<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\Calendar\CalendarPolicyProvider;
use App\Domain\Users\UserWorkdayPolicy;
use App\Infrastructure\Database\DatabaseConnection;

final class AttendanceService
{
    private const ABSENCE_TYPES = ['sick', 'vacation', 'holiday', 'absent'];
    private UserWorkdayPolicy $userWorkdayPolicy;

    public function __construct(
        private DatabaseConnection $connection,
        private ?CalendarPolicyProvider $calendarPolicyService = null,
        ?UserWorkdayPolicy $userWorkdayPolicy = null
    )
    {
        $this->userWorkdayPolicy = $userWorkdayPolicy ?? new UserWorkdayPolicy();
    }

    public function todaySummary(?string $today = null): array
    {
        $today = $today ?: (new \DateTimeImmutable('today'))->format('Y-m-d');

        if ($this->connection->tableExists('timesheets') && $this->connection->tableExists('users')) {
            $absenceReasonSelect = $this->connection->columnExists('timesheets', 'absence_reason_code')
                ? 'timesheets.absence_reason_code'
                : 'NULL AS absence_reason_code';
            $rows = $this->connection->fetchAll(
                'SELECT
                    timesheets.id,
                    timesheets.user_id,
                    timesheets.project_id,
                    timesheets.work_date,
                    timesheets.entry_type,
                    ' . $absenceReasonSelect . ',
                    timesheets.start_time,
                    timesheets.end_time,
                    timesheets.net_minutes,
                    timesheets.note,
                    timesheets.updated_at,
                    users.employee_number,
                    users.first_name,
                    users.last_name,
                    users.email,
                    users.employment_status,
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
        return (int) $this->todaySummary($today)['currently_present_count'];
    }

    public function summarizeRows(array $rows, string $today, array $activeUsers = []): array
    {
        $currentlyPresent = [];
        $legacyPresent = [];
        $completed = [];
        $statuses = [];
        $workRowsByUser = [];
        $latestAbsenceRows = [];
        $archivedUsers = [];
        $legacySeenUsers = [];
        $usersWithStatus = [];
        $activeUserIds = [];

        foreach ($activeUsers as $activeUser) {
            $activeUserId = (int) ($activeUser['id'] ?? 0);
            if ($activeUserId > 0) {
                $activeUserIds[$activeUserId] = true;
            }
        }

        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);

            if ($userId <= 0 || isset($archivedUsers[$userId])) {
                continue;
            }

            if ((int) ($row['user_is_deleted'] ?? 0) === 1) {
                $archivedUsers[$userId] = true;
                unset($workRowsByUser[$userId], $latestAbsenceRows[$userId], $legacySeenUsers[$userId], $legacyPresent[$userId]);

                continue;
            }

            $entryType = $this->semanticEntryType($row);

            // The legacy V1 fields intentionally retain their former meaning,
            // including historical rows of users who have since become inactive.
            if (!isset($legacySeenUsers[$userId])) {
                $legacySeenUsers[$userId] = true;

                if ($entryType === 'work') {
                    $legacyPresent[$userId] = $this->mapRow($row);
                }
            }

            if ($activeUserIds !== [] && !isset($activeUserIds[$userId])) {
                continue;
            }

            if (array_key_exists('employment_status', $row) && (string) $row['employment_status'] !== 'active') {
                continue;
            }

            $usersWithStatus[$userId] = true;

            if ($entryType === 'work') {
                $workRowsByUser[$userId][] = $row;

                continue;
            }

            if (in_array($entryType, self::ABSENCE_TYPES, true) && !isset($latestAbsenceRows[$userId])) {
                $latestAbsenceRows[$userId] = [
                    'row' => $row,
                    'entry_type' => $entryType,
                ];
            }
        }

        foreach ($workRowsByUser as $userId => $workRows) {
            $openWorkRows = array_values(array_filter(
                $workRows,
                fn (array $workRow): bool => $this->hasStartTime($workRow) && !$this->hasEndTime($workRow)
            ));
            $completedWorkRows = array_values(array_filter(
                $workRows,
                fn (array $workRow): bool => $this->hasStartTime($workRow) && $this->hasEndTime($workRow)
            ));

            if ($openWorkRows !== []) {
                $currentlyPresent[] = $this->mapRow($openWorkRows[0]);

                continue;
            }

            if ($completedWorkRows !== []) {
                $completed[] = $this->completedWorkday($completedWorkRows);
            }
        }

        foreach ($latestAbsenceRows as $userId => $absence) {
            if (isset($workRowsByUser[$userId])) {
                continue;
            }

            $statuses[] = [
                ...$this->mapRow($absence['row']),
                'entry_type' => $absence['entry_type'],
                'is_derived' => false,
                'status_source' => 'stored',
            ];
        }

        foreach ($this->derivedMissingStatuses($activeUsers, $usersWithStatus, $today) as $missingStatus) {
            $statuses[] = $missingStatus;
        }

        $derivedMissingCount = count(array_filter(
            $statuses,
            static fn (array $status): bool => (string) ($status['status_source'] ?? '') === 'derived_missing'
        ));
        $statusCounts = $this->statusCounts($statuses);

        return [
            'today' => $today,
            'calendar_policy' => $this->dayPolicy($today),
            // Keep the original API fields stable for existing integrations.
            'present_count' => count($legacyPresent),
            'present' => array_values($legacyPresent),
            'currently_present_count' => count($currentlyPresent),
            'currently_present' => $currentlyPresent,
            'completed_count' => count($completed),
            'completed' => $completed,
            'status_counts' => $statusCounts,
            'derived_missing_count' => $derivedMissingCount,
            'statuses' => $statuses,
            'chart' => $this->chartSummary($currentlyPresent, $completed, $statuses, $activeUsers, $statusCounts),
        ];
    }

    private function semanticEntryType(array $row): string
    {
        $entryType = (string) ($row['entry_type'] ?? '');

        if ($entryType === 'vacation' && (string) ($row['absence_reason_code'] ?? '') === 'unpaid_leave') {
            return 'absent';
        }

        return $entryType;
    }

    private function fallbackSummary(string $today): array
    {
        $completed = [
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

        $statusCounts = $this->statusCounts($statuses);

        return [
            'today' => $today,
            'calendar_policy' => $this->dayPolicy($today),
            'present_count' => count($completed),
            'present' => $completed,
            'currently_present_count' => 0,
            'currently_present' => [],
            'completed_count' => count($completed),
            'completed' => $completed,
            'status_counts' => $statusCounts,
            'derived_missing_count' => 0,
            'statuses' => $statuses,
            'chart' => $this->chartSummary([], $completed, $statuses, [], $statusCounts),
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
        $workdaysSelect = $this->connection->columnExists('users', 'workdays_mask')
            ? 'COALESCE(NULLIF(TRIM(workdays_mask), ""), "' . UserWorkdayPolicy::DEFAULT_MASK . '")'
            : '"' . UserWorkdayPolicy::DEFAULT_MASK . '"';

        return $this->connection->fetchAll(
            'SELECT id, employee_number, first_name, last_name, email, created_at,
                    ' . $timeTrackingSelect . ' AS time_tracking_required,
                    ' . $workdaysSelect . ' AS workdays_mask
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

            if (!$this->userExistedOnDate($user, $today)) {
                continue;
            }

            if (!$this->userWorkdayPolicy->isScheduledWorkday($user, $today)) {
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
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        $errors = \DateTimeImmutable::getLastErrors();

        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $today
            || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
            return false;
        }

        $currentDay = new \DateTimeImmutable('today');

        if ($date > $currentDay) {
            return false;
        }

        return !$this->calendarPolicyService instanceof CalendarPolicyProvider
            || $this->calendarPolicyService->requiresTimeTracking($today);
    }

    private function userExistedOnDate(array $user, string $date): bool
    {
        $createdAt = trim((string) ($user['created_at'] ?? ''));

        if ($createdAt === '') {
            return true;
        }

        try {
            return new \DateTimeImmutable($createdAt) <= new \DateTimeImmutable($date . ' 23:59:59');
        } catch (\Exception) {
            return false;
        }
    }

    private function dayPolicy(string $today): array
    {
        if (!$this->calendarPolicyService instanceof CalendarPolicyProvider) {
            return [
                'date' => $today,
                'is_public_holiday' => false,
                'holiday_name' => null,
                'is_company_closure' => false,
                'closure_titles' => [],
                'time_tracking_required' => true,
            ];
        }

        return $this->calendarPolicyService->dayPolicy($today);
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

    private function completedWorkday(array $workRows): array
    {
        $latestRow = $workRows[0];
        $firstStart = null;
        $lastEnd = null;
        $netMinutes = 0;

        foreach ($workRows as $workRow) {
            $startTime = $this->nullableTrimmed($workRow['start_time'] ?? null);
            $endTime = $this->nullableTrimmed($workRow['end_time'] ?? null);

            if ($startTime !== null && ($firstStart === null || $startTime < $firstStart)) {
                $firstStart = $startTime;
            }

            if ($endTime !== null && ($lastEnd === null || $endTime > $lastEnd || ($endTime === $lastEnd && $this->isLaterUpdatedRow($workRow, $latestRow)))) {
                $lastEnd = $endTime;
                $latestRow = $workRow;
            }

            $netMinutes += max(0, (int) ($workRow['net_minutes'] ?? 0));
        }

        return [
            ...$this->mapRow($latestRow),
            'start_time' => $firstStart,
            'end_time' => $lastEnd,
            'net_minutes' => $netMinutes,
            'work_entry_count' => count($workRows),
        ];
    }

    private function hasEndTime(array $row): bool
    {
        return $this->nullableTrimmed($row['end_time'] ?? null) !== null;
    }

    private function hasStartTime(array $row): bool
    {
        return $this->nullableTrimmed($row['start_time'] ?? null) !== null;
    }

    private function isLaterUpdatedRow(array $candidate, array $current): bool
    {
        $candidateUpdatedAt = (string) ($candidate['updated_at'] ?? '');
        $currentUpdatedAt = (string) ($current['updated_at'] ?? '');

        if ($candidateUpdatedAt !== $currentUpdatedAt) {
            return $candidateUpdatedAt > $currentUpdatedAt;
        }

        return (int) ($candidate['id'] ?? 0) > (int) ($current['id'] ?? 0);
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

    private function chartSummary(array $currentlyPresent, array $completed, array $statuses, array $activeUsers, array $statusCounts): array
    {
        $knownUsers = [];

        foreach ([$currentlyPresent, $completed, $statuses] as $group) {
            foreach ($group as $person) {
                $userId = (int) ($person['user_id'] ?? 0);
                if ($userId > 0) {
                    $knownUsers[$userId] = true;
                }
            }
        }

        $workforceCount = $activeUsers === [] ? count($knownUsers) : count($activeUsers);
        $unreportedCount = max(0, $workforceCount - count($knownUsers));
        $preventedCount = array_sum($statusCounts);
        $currentlyPresentCount = count($currentlyPresent);

        return [
            'workforce_count' => $workforceCount,
            'currently_present_count' => $currentlyPresentCount,
            'completed_count' => count($completed),
            'sick_count' => (int) ($statusCounts['sick'] ?? 0),
            'vacation_count' => (int) ($statusCounts['vacation'] ?? 0),
            'holiday_count' => (int) ($statusCounts['holiday'] ?? 0),
            'absent_count' => (int) ($statusCounts['absent'] ?? 0),
            'unreported_count' => $unreportedCount,
            'prevented_count' => $preventedCount,
            'readiness_percent' => $workforceCount > 0 ? round(($currentlyPresentCount / $workforceCount) * 100, 1) : null,
            'prevented_percent' => $workforceCount > 0 ? round(($preventedCount / $workforceCount) * 100, 1) : null,
        ];
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
