<?php

declare(strict_types=1);

namespace App\Domain\App;

use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class MobileAppService
{
    public function __construct(
        private DatabaseConnection $connection,
        private ProjectService $projectService,
        private CompanySettingsService $companySettingsService,
        private WorkdayStateCalculator $workdayStateCalculator,
        private FileAttachmentService $fileAttachmentService
    ) {
    }

    public function dayContext(array $user): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $todayWorkEntries = $this->findTodayWorkEntries((int) $user['id'], $today);
        $workEntry = $this->findLatestEntry((int) $user['id'], $today, 'work');
        $lastStatusEntry = $this->findLatestStatusEntry((int) $user['id'], $today);
        $isMissing = $this->isMissingWorkday($today, $workEntry, $lastStatusEntry);
        $breaksToday = $workEntry !== null ? $this->findBreaksForTimesheet((int) $workEntry['id']) : [];
        $currentBreak = $this->workdayStateCalculator->currentBreak($breaksToday);
        $status = $isMissing
            ? 'missing'
            : $this->workdayStateCalculator->status($workEntry, $lastStatusEntry, $currentBreak);
        $trackedMinutesLiveBasis = $this->workdayStateCalculator->trackedMinutesLiveBasis($today, $workEntry, $breaksToday);
        $attachments = $workEntry !== null ? $this->fileAttachmentService->listForTimesheet((int) $workEntry['id']) : [];
        $projectDaySummaries = $this->buildProjectDaySummaries($today, $todayWorkEntries);
        $projects = array_map(
            static fn (array $project): array => [
                'id' => (int) ($project['id'] ?? 0),
                'project_number' => (string) ($project['project_number'] ?? ''),
                'name' => (string) ($project['name'] ?? ''),
                'city' => (string) ($project['city'] ?? ''),
            ],
            $this->activeProjectsForUser($user)
        );
        $profile = $this->companySettingsService->publicProfile();

        return [
            'today' => $today,
            'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'user' => [
                'id' => (int) ($user['id'] ?? 0),
                'employee_number' => $user['employee_number'] ?? null,
                'display_name' => trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? ''))),
                'email' => (string) ($user['email'] ?? ''),
                'roles' => $user['roles'] ?? [],
            ],
            'today_state' => [
                'work_entry' => $workEntry !== null ? [
                    ...$workEntry,
                    'attachments' => $attachments,
                ] : null,
                'status_entry' => $lastStatusEntry,
                'current_break' => $currentBreak,
                'status' => $status,
                'is_missing' => $isMissing,
                'status_source' => $isMissing ? 'derived_missing' : ($lastStatusEntry !== null ? 'stored' : null),
            ],
            'current_break' => $currentBreak,
            'breaks_today' => $breaksToday,
            'tracked_minutes_live_basis' => $trackedMinutesLiveBasis,
            'attachments' => $attachments,
            'project_day_summaries' => $projectDaySummaries,
            'projects' => $projects,
            'sync' => [
                'server_pending_count' => 0,
            ],
            'geo_policy' => [
                'enabled' => (bool) ($profile['geo_capture_enabled'] ?? false),
                'notice_text' => (string) ($profile['geo_notice_text'] ?? ''),
                'requires_acknowledgement' => (bool) ($profile['geo_requires_acknowledgement'] ?? false),
            ],
            'company' => [
                'app_display_name' => (string) ($profile['app_display_name'] ?? ''),
                'company_name' => (string) ($profile['company_name'] ?? ''),
            ],
        ];
    }

    public function timesheetList(
        array $user,
        string $scope = 'project',
        ?int $projectId = null,
        ?string $month = null,
        string $entryType = 'all'
    ): array {
        $scope = $scope === 'all' ? 'all' : 'project';
        $month = $this->normalizeHistoryMonth($month);
        $entryType = $this->normalizeHistoryEntryType($entryType);
        [$monthStart, $monthEnd] = $this->timesheetHistoryBounds($month);
        $items = [];

        if ($this->connection->tableExists('timesheets')) {
            $sql = 'SELECT
                    timesheets.id,
                    timesheets.project_id,
                    timesheets.work_date,
                    timesheets.start_time,
                    timesheets.end_time,
                    timesheets.break_minutes,
                    timesheets.net_minutes,
                    timesheets.entry_type,
                    timesheets.note,
                    timesheets.updated_at,
                    projects.name AS project_name
                 FROM timesheets
                 LEFT JOIN projects ON projects.id = timesheets.project_id
                 WHERE timesheets.user_id = :user_id';
            $sql .= ' AND COALESCE(timesheets.is_deleted, 0) = 0';
            $bindings = [
                'user_id' => (int) ($user['id'] ?? 0),
            ];

            if ($monthStart !== null && $monthEnd !== null) {
                $sql .= ' AND timesheets.work_date BETWEEN :month_start AND :month_end';
                $bindings['month_start'] = $monthStart;
                $bindings['month_end'] = $monthEnd;
            }

            if ($entryType !== 'all') {
                $sql .= ' AND timesheets.entry_type = :entry_type';
                $bindings['entry_type'] = $entryType;
            }

            if ($scope === 'project') {
                if ($projectId === null) {
                    $sql .= ' AND timesheets.project_id IS NULL';
                } else {
                    $sql .= ' AND timesheets.project_id = :project_id';
                    $bindings['project_id'] = $projectId;
                }
            }

            $sql .= ' ORDER BY timesheets.work_date DESC, timesheets.start_time DESC, timesheets.id DESC';

            if ($month === null) {
                $sql .= ' LIMIT 500';
            }

            $rows = $this->connection->fetchAll($sql, $bindings);
            $timesheetIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
            $attachmentsByTimesheet = $this->fileAttachmentService->listForTimesheetsGrouped($timesheetIds);
            $breaksByTimesheet = $this->findBreaksForTimesheets($timesheetIds);
            $items = array_map(
                fn (array $row): array => $this->normalizeHistoryItem(
                    $row,
                    $attachmentsByTimesheet[(int) ($row['id'] ?? 0)] ?? [],
                    $breaksByTimesheet[(int) ($row['id'] ?? 0)] ?? []
                ),
                $rows
            );
        }

        $history = $this->buildTimesheetHistoryPayload($items);

        return [
            'items' => $items,
            'summary' => $history['summary'],
            'days' => $history['days'],
            'projects' => $history['projects'],
            'filters' => [
                'scope' => $scope,
                'project_id' => $scope === 'project' ? $projectId : null,
                'month' => $month,
                'entry_type' => $entryType,
            ],
            'scope' => $scope,
            'project_id' => $scope === 'project' ? $projectId : null,
            'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function normalizeHistoryItem(array $row, array $attachments, array $breaks): array
    {
        $id = (int) ($row['id'] ?? 0);
        $entryType = (string) ($row['entry_type'] ?? 'work');
        $projectName = trim((string) ($row['project_name'] ?? ''));

        return [
            'id' => $id,
            'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
            'project_name' => $projectName !== '' ? $projectName : 'Nicht zugeordnet',
            'project_label' => $projectName !== '' ? $projectName : 'Nicht zugeordnet',
            'work_date' => (string) ($row['work_date'] ?? ''),
            'date_label' => self::dateLabel($row['work_date'] ?? null),
            'weekday' => self::weekdayLabel($row['work_date'] ?? null),
            'start_time' => $row['start_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'break_minutes' => (int) ($row['break_minutes'] ?? 0),
            'net_minutes' => (int) ($row['net_minutes'] ?? 0),
            'entry_type' => $entryType,
            'entry_type_label' => self::entryTypeLabel($entryType),
            'note' => self::nullableTrimmed($row['note'] ?? null),
            'updated_at' => self::dateTimeOrNull($row['updated_at'] ?? null),
            'breaks' => $breaks,
            'attachments' => $attachments,
            'attachment_count' => count($attachments),
        ];
    }

    private function normalizeHistoryMonth(?string $month): ?string
    {
        $month = trim((string) ($month ?? ''));

        if ($month === '') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException('Bitte einen gueltigen Monat im Format JJJJ-MM angeben.');
        }

        try {
            $date = new \DateTimeImmutable($month . '-01');
        } catch (\Exception) {
            throw new InvalidArgumentException('Bitte einen gueltigen Monat im Format JJJJ-MM angeben.');
        }

        if ($date->format('Y-m') !== $month) {
            throw new InvalidArgumentException('Bitte einen gueltigen Monat im Format JJJJ-MM angeben.');
        }

        return $month;
    }

    private function normalizeHistoryEntryType(string $entryType): string
    {
        $entryType = trim($entryType) === '' ? 'all' : trim($entryType);
        $allowed = ['all', 'work', 'sick', 'vacation', 'holiday', 'absent'];

        if (!in_array($entryType, $allowed, true)) {
            throw new InvalidArgumentException('Bitte einen gueltigen Buchungstyp auswaehlen.');
        }

        return $entryType;
    }

    private function timesheetHistoryBounds(?string $month): array
    {
        if ($month === null) {
            return [null, null];
        }

        $start = new \DateTimeImmutable($month . '-01');
        $end = $start->modify('last day of this month');

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function buildTimesheetHistoryPayload(array $items): array
    {
        $summary = $this->emptyTimesheetHistorySummary();
        $days = [];
        $projectKeys = [];
        $projects = [];

        foreach ($items as $item) {
            $entryType = (string) ($item['entry_type'] ?? 'work');
            $date = (string) ($item['work_date'] ?? '');
            $breakMinutes = (int) ($item['break_minutes'] ?? 0);
            $netMinutes = (int) ($item['net_minutes'] ?? 0);
            $attachmentCount = (int) ($item['attachment_count'] ?? (is_array($item['attachments'] ?? null) ? count($item['attachments']) : 0));

            $summary['entry_count']++;
            $summary['total_net_minutes'] += $netMinutes;
            $summary['total_break_minutes'] += $breakMinutes;
            $summary['attachment_count'] += $attachmentCount;

            if ($entryType === 'work') {
                $summary['work_entry_count']++;
            } elseif (in_array($entryType, ['sick', 'vacation', 'holiday', 'absent'], true)) {
                $summary['absence_entry_count']++;
            }

            if (($item['project_id'] ?? null) === null) {
                $projectKeys['none'] = true;
            } else {
                $projectId = (int) $item['project_id'];
                $projectKeys['project:' . $projectId] = true;
                $projects[$projectId] = [
                    'id' => $projectId,
                    'project_number' => '',
                    'name' => (string) ($item['project_name'] ?? $item['project_label'] ?? ''),
                ];
            }

            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'date_label' => self::dateLabel($date),
                    'weekday' => self::weekdayLabel($date),
                    'total_net_minutes' => 0,
                    'total_break_minutes' => 0,
                    'entry_count' => 0,
                    'status_counts' => [
                        'work' => 0,
                        'sick' => 0,
                        'vacation' => 0,
                        'holiday' => 0,
                        'absent' => 0,
                    ],
                    'attachment_count' => 0,
                    'items' => [],
                ];
            }

            $days[$date]['total_net_minutes'] += $netMinutes;
            $days[$date]['total_break_minutes'] += $breakMinutes;
            $days[$date]['entry_count']++;
            $days[$date]['attachment_count'] += $attachmentCount;

            if (isset($days[$date]['status_counts'][$entryType])) {
                $days[$date]['status_counts'][$entryType]++;
            }

            $days[$date]['items'][] = $item;
        }

        $summary['project_count'] = count($projectKeys);
        krsort($days);

        return [
            'summary' => $summary,
            'days' => array_values($days),
            'projects' => array_values($projects),
        ];
    }

    private function emptyTimesheetHistorySummary(): array
    {
        return [
            'total_net_minutes' => 0,
            'total_break_minutes' => 0,
            'entry_count' => 0,
            'work_entry_count' => 0,
            'absence_entry_count' => 0,
            'attachment_count' => 0,
            'project_count' => 0,
        ];
    }

    private function findBreaksForTimesheet(int $timesheetId): array
    {
        if ($timesheetId <= 0 || !$this->connection->tableExists('timesheet_breaks')) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT id, break_started_at, break_ended_at, source, note
             FROM timesheet_breaks
             WHERE timesheet_id = :timesheet_id
             ORDER BY break_started_at ASC, id ASC',
            ['timesheet_id' => $timesheetId]
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'break_started_at' => self::dateTimeOrNull($row['break_started_at'] ?? null),
                'break_ended_at' => self::dateTimeOrNull($row['break_ended_at'] ?? null),
                'source' => (string) ($row['source'] ?? 'app'),
                'note' => self::nullableTrimmed($row['note'] ?? null),
            ],
            $rows
        );
    }

    private function findBreaksForTimesheets(array $timesheetIds): array
    {
        $timesheetIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $timesheetIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($timesheetIds === [] || !$this->connection->tableExists('timesheet_breaks')) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($timesheetIds as $index => $timesheetId) {
            $placeholder = 'id_' . $index;
            $placeholders[] = ':' . $placeholder;
            $bindings[$placeholder] = $timesheetId;
        }

        $rows = $this->connection->fetchAll(
            'SELECT timesheet_id, id, break_started_at, break_ended_at, source, note
             FROM timesheet_breaks
             WHERE timesheet_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY timesheet_id ASC, break_started_at ASC, id ASC',
            $bindings
        );

        $grouped = [];

        foreach ($rows as $row) {
            $timesheetId = (int) ($row['timesheet_id'] ?? 0);

            if ($timesheetId <= 0) {
                continue;
            }

            $grouped[$timesheetId][] = [
                'id' => (int) ($row['id'] ?? 0),
                'break_started_at' => self::dateTimeOrNull($row['break_started_at'] ?? null),
                'break_ended_at' => self::dateTimeOrNull($row['break_ended_at'] ?? null),
                'source' => (string) ($row['source'] ?? 'app'),
                'note' => self::nullableTrimmed($row['note'] ?? null),
            ];
        }

        return $grouped;
    }

    private function activeProjectsForUser(array $user): array
    {
        $permissions = $user['permissions'] ?? [];

        if (
            in_array('*', $permissions, true)
            || in_array('projects.manage', $permissions, true)
            || in_array('files.manage', $permissions, true)
            || in_array('timesheets.manage', $permissions, true)
        ) {
            return $this->projectService->list('active');
        }

        if (!$this->connection->tableExists('projects') || !$this->connection->tableExists('project_memberships')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT DISTINCT projects.id, projects.project_number, projects.name, projects.city
             FROM projects
             INNER JOIN project_memberships ON project_memberships.project_id = projects.id
             WHERE project_memberships.user_id = :user_id
               AND COALESCE(projects.is_deleted, 0) = 0
               AND projects.status <> "archived"
               AND (project_memberships.assigned_from IS NULL OR project_memberships.assigned_from <= CURDATE())
               AND (project_memberships.assigned_until IS NULL OR project_memberships.assigned_until >= CURDATE())
             ORDER BY projects.project_number ASC, projects.name ASC',
            ['user_id' => (int) ($user['id'] ?? 0)]
        );
    }

    private function findLatestEntry(int $userId, string $workDate, string $entryType): ?array
    {
        if (!$this->connection->tableExists('timesheets')) {
            return null;
        }

        $entry = $this->connection->fetchOne(
            'SELECT
                timesheets.id,
                timesheets.project_id,
                timesheets.work_date,
                timesheets.start_time,
                timesheets.end_time,
                timesheets.break_minutes,
                timesheets.net_minutes,
                timesheets.note,
                projects.name AS project_name
             FROM timesheets
             LEFT JOIN projects ON projects.id = timesheets.project_id
             WHERE timesheets.user_id = :user_id
               AND COALESCE(timesheets.is_deleted, 0) = 0
               AND timesheets.work_date = :work_date
               AND timesheets.entry_type = :entry_type
             ORDER BY
                CASE WHEN timesheets.start_time IS NOT NULL AND timesheets.end_time IS NULL THEN 0 ELSE 1 END ASC,
                COALESCE(timesheets.end_time, timesheets.start_time, "00:00:00") DESC,
                timesheets.updated_at DESC,
                timesheets.id DESC
             LIMIT 1',
            [
                'user_id' => $userId,
                'work_date' => $workDate,
                'entry_type' => $entryType,
            ]
        );

        if ($entry === null) {
            return null;
        }

        return [
            'id' => (int) ($entry['id'] ?? 0),
            'project_id' => isset($entry['project_id']) ? (int) $entry['project_id'] : null,
            'project_name' => $entry['project_name'] ?? null,
            'work_date' => (string) ($entry['work_date'] ?? ''),
            'start_time' => $entry['start_time'] ?? null,
            'end_time' => $entry['end_time'] ?? null,
            'break_minutes' => (int) ($entry['break_minutes'] ?? 0),
            'net_minutes' => (int) ($entry['net_minutes'] ?? 0),
            'note' => $entry['note'] ?? null,
        ];
    }

    private function findLatestStatusEntry(int $userId, string $workDate): ?array
    {
        if (!$this->connection->tableExists('timesheets')) {
            return null;
        }

        $entry = $this->connection->fetchOne(
            'SELECT id, entry_type, note
             FROM timesheets
             WHERE user_id = :user_id
               AND COALESCE(is_deleted, 0) = 0
               AND work_date = :work_date
               AND entry_type IN ("sick", "vacation", "holiday", "absent")
             ORDER BY updated_at DESC, id DESC
             LIMIT 1',
            [
                'user_id' => $userId,
                'work_date' => $workDate,
            ]
        );

        if ($entry === null) {
            return null;
        }

        return [
            'id' => (int) ($entry['id'] ?? 0),
            'entry_type' => (string) ($entry['entry_type'] ?? ''),
            'note' => $entry['note'] ?? null,
            'is_derived' => false,
            'status_source' => 'stored',
        ];
    }

    private function isMissingWorkday(string $workDate, ?array $workEntry, ?array $statusEntry): bool
    {
        if ($workEntry !== null || $statusEntry !== null) {
            return false;
        }

        try {
            $date = new \DateTimeImmutable($workDate);
        } catch (\Exception) {
            return false;
        }

        $today = new \DateTimeImmutable('today');

        return (int) $date->format('N') <= 5 && $date <= $today;
    }

    private function findTodayWorkEntries(int $userId, string $workDate): array
    {
        if (!$this->connection->tableExists('timesheets')) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT
                timesheets.id,
                timesheets.project_id,
                timesheets.work_date,
                timesheets.start_time,
                timesheets.end_time,
                timesheets.gross_minutes,
                timesheets.break_minutes,
                timesheets.net_minutes,
                timesheets.note,
                timesheets.updated_at,
                projects.name AS project_name
             FROM timesheets
             LEFT JOIN projects ON projects.id = timesheets.project_id
             WHERE timesheets.user_id = :user_id
               AND COALESCE(timesheets.is_deleted, 0) = 0
               AND timesheets.work_date = :work_date
               AND timesheets.entry_type = "work"
             ORDER BY
                CASE WHEN timesheets.start_time IS NOT NULL AND timesheets.end_time IS NULL THEN 0 ELSE 1 END ASC,
                COALESCE(timesheets.end_time, timesheets.start_time, "00:00:00") DESC,
                timesheets.updated_at DESC,
                timesheets.id DESC',
            [
                'user_id' => $userId,
                'work_date' => $workDate,
            ]
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
                'project_name' => trim((string) ($row['project_name'] ?? '')) !== '' ? (string) $row['project_name'] : 'Nicht zugeordnet',
                'work_date' => (string) ($row['work_date'] ?? ''),
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'gross_minutes' => (int) ($row['gross_minutes'] ?? 0),
                'break_minutes' => (int) ($row['break_minutes'] ?? 0),
                'net_minutes' => (int) ($row['net_minutes'] ?? 0),
                'note' => self::nullableTrimmed($row['note'] ?? null),
                'updated_at' => self::dateTimeOrNull($row['updated_at'] ?? null),
            ],
            $rows
        );
    }

    private function buildProjectDaySummaries(string $workDate, array $entries): array
    {
        $summaries = [];

        foreach ($entries as $entry) {
            $projectId = $entry['project_id'] ?? null;
            $key = $this->projectSummaryKey($projectId);
            $breaks = $this->findBreaksForTimesheet((int) ($entry['id'] ?? 0));
            $currentBreak = $this->workdayStateCalculator->currentBreak($breaks);
            $completedBreakMinutes = $breaks !== []
                ? $this->workdayStateCalculator->completedBreakMinutes($breaks)
                : (int) ($entry['break_minutes'] ?? 0);
            $trackedMinutesLiveBasis = $this->workdayStateCalculator->trackedMinutesLiveBasis($workDate, $entry, $breaks);
            $attachments = $this->fileAttachmentService->listForTimesheet((int) ($entry['id'] ?? 0));
            $normalizedEntry = [
                ...$entry,
                'attachments' => $attachments,
            ];

            if (!isset($summaries[$key])) {
                $summaries[$key] = [
                    'project_id' => $projectId,
                    'project_name' => $entry['project_name'] ?? 'Nicht zugeordnet',
                    'status' => $this->workdayStateCalculator->status($entry, null, $currentBreak),
                    'start_time' => $entry['start_time'] ?? null,
                    'end_time' => $entry['end_time'] ?? null,
                    'total_break_minutes' => 0,
                    'total_net_minutes' => 0,
                    'current_break' => $currentBreak,
                    'tracked_minutes_live_basis' => $trackedMinutesLiveBasis,
                    'work_entry' => $normalizedEntry,
                    'breaks_today' => $breaks,
                    'attachments' => $attachments,
                ];
            }

            $summaries[$key]['total_break_minutes'] += $completedBreakMinutes;
            $summaries[$key]['total_net_minutes'] += (int) ($entry['net_minutes'] ?? 0);
        }

        return array_values($summaries);
    }

    private function projectSummaryKey(?int $projectId): string
    {
        return $projectId === null ? 'project:none' : 'project:' . $projectId;
    }

    private static function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private static function dateLabel(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($value))->format('d.m.Y');
        } catch (\Exception) {
            return $value;
        }
    }

    private static function weekdayLabel(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '-';
        }

        try {
            $index = (int) (new \DateTimeImmutable($value))->format('N');
        } catch (\Exception) {
            return '-';
        }

        return [
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
            7 => 'Sonntag',
        ][$index] ?? '-';
    }

    private static function entryTypeLabel(string $entryType): string
    {
        return [
            'work' => 'Arbeit',
            'sick' => 'Krank',
            'vacation' => 'Urlaub',
            'holiday' => 'Feiertag',
            'absent' => 'Fehlt',
        ][$entryType] ?? 'Unbekannt';
    }

    private static function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
