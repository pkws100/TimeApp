<?php

declare(strict_types=1);

namespace App\Domain\App;

use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Infrastructure\Database\DatabaseConnection;

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
        $breaksToday = $workEntry !== null ? $this->findBreaksForTimesheet((int) $workEntry['id']) : [];
        $currentBreak = $this->workdayStateCalculator->currentBreak($breaksToday);
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
            $this->projectService->list('active')
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
                'status' => $this->workdayStateCalculator->status($workEntry, $lastStatusEntry, $currentBreak),
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

    public function timesheetList(array $user, string $scope = 'project', ?int $projectId = null): array
    {
        $scope = $scope === 'all' ? 'all' : 'project';
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
                    projects.name AS project_name
                 FROM timesheets
                 LEFT JOIN projects ON projects.id = timesheets.project_id
                 WHERE timesheets.user_id = :user_id';
            $sql .= ' AND COALESCE(timesheets.is_deleted, 0) = 0';
            $bindings = [
                'user_id' => (int) ($user['id'] ?? 0),
            ];

            if ($scope === 'project') {
                if ($projectId === null) {
                    $sql .= ' AND timesheets.project_id IS NULL';
                } else {
                    $sql .= ' AND timesheets.project_id = :project_id';
                    $bindings['project_id'] = $projectId;
                }
            }

            $sql .= ' ORDER BY timesheets.work_date DESC, timesheets.start_time DESC, timesheets.id DESC';

            $rows = $this->connection->fetchAll($sql, $bindings);
            $items = array_map(
                static fn (array $row): array => [
                    'id' => (int) ($row['id'] ?? 0),
                    'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
                    'project_name' => trim((string) ($row['project_name'] ?? '')) !== '' ? (string) $row['project_name'] : 'Nicht zugeordnet',
                    'work_date' => (string) ($row['work_date'] ?? ''),
                    'start_time' => $row['start_time'] ?? null,
                    'end_time' => $row['end_time'] ?? null,
                    'break_minutes' => (int) ($row['break_minutes'] ?? 0),
                    'net_minutes' => (int) ($row['net_minutes'] ?? 0),
                    'entry_type' => (string) ($row['entry_type'] ?? 'work'),
                    'note' => self::nullableTrimmed($row['note'] ?? null),
                ],
                $rows
            );
        }

        return [
            'items' => $items,
            'scope' => $scope,
            'project_id' => $scope === 'project' ? $projectId : null,
            'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM),
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
        ];
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

            if (($entry['start_time'] ?? null) !== null) {
                $currentStart = $summaries[$key]['start_time'] ?? null;

                if ($currentStart === null || strcmp((string) $entry['start_time'], (string) $currentStart) < 0) {
                    $summaries[$key]['start_time'] = $entry['start_time'];
                }
            }
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

    private static function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
