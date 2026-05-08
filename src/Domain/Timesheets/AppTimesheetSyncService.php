<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Domain\Settings\CompanySettingsService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class AppTimesheetSyncService
{
    public function __construct(
        private DatabaseConnection $connection,
        private TimesheetCalculator $calculator,
        private CompanySettingsService $companySettingsService,
        private WorkdayStateCalculator $workdayStateCalculator
    ) {
    }

    public function sync(int $userId, array $payload): array
    {
        $clientRequestId = trim((string) ($payload['client_request_id'] ?? ''));

        if ($clientRequestId === '') {
            throw new RuntimeException('Eine client_request_id ist erforderlich.');
        }

        $existingOperation = $this->findSyncOperation($clientRequestId);

        if ($existingOperation !== null) {
            $response = json_decode((string) ($existingOperation['response_json'] ?? ''), true);

            return is_array($response) ? $response : ['status' => 'synced'];
        }

        $action = trim((string) ($payload['action'] ?? ''));

        if (!in_array($action, ['check_in', 'check_out', 'pause', 'pause_start', 'pause_end', 'day_close', 'select_project', 'upsert'], true)) {
            throw new RuntimeException('Die Aktion ist ungueltig.');
        }

        $workDate = trim((string) ($payload['work_date'] ?? (new \DateTimeImmutable('today'))->format('Y-m-d')));
        $entry = $this->findDailyWorkEntry($userId, $workDate);
        $latestDailyEntry = $this->findLatestDailyWorkEntryAnyState($userId, $workDate);

        if ($latestDailyEntry !== null && (int) ($latestDailyEntry['is_deleted'] ?? 0) === 1) {
            throw new RuntimeException('Ihre Tagesbuchung wurde im Backend geaendert oder archiviert. Bitte App-Daten neu laden.');
        }

        $startNewEntry = $action === 'check_in' && $entry !== null && ($entry['start_time'] ?? null) !== null && ($entry['end_time'] ?? null) !== null;

        if ($startNewEntry) {
            $entry = null;
        }

        $existingBreaks = $entry !== null ? $this->findBreaksForTimesheet((int) $entry['id']) : [];
        $currentBreak = $this->workdayStateCalculator->currentBreak($existingBreaks);
        $projectId = $this->normalizeProjectId($payload['project_id'] ?? ($entry['project_id'] ?? null));
        $note = $this->nullableTrimmed($payload['note'] ?? ($entry['note'] ?? null));
        $manualBreakMinutes = isset($entry['break_minutes']) ? (int) $entry['break_minutes'] : $this->workdayStateCalculator->completedBreakMinutes($existingBreaks);

        if ($action === 'pause' && array_key_exists('manual_break_minutes', $payload)) {
            $manualBreakMinutes = $this->normalizeBreakMinutes($payload['manual_break_minutes']);
        }

        $startTime = $entry['start_time'] ?? null;
        $endTime = $entry['end_time'] ?? null;

        if ($action === 'check_in') {
            $startTime = $this->normalizeTime($payload['start_time'] ?? null) ?? $this->nowTime();
        }

        if (in_array($action, ['check_out', 'day_close'], true)) {
            if ($entry === null || $startTime === null || $startTime === '') {
                throw new RuntimeException('Bitte zuerst einen Check-in buchen, bevor Sie den Check-out erfassen.');
            }

            if ($currentBreak !== null) {
                throw new RuntimeException('Check-out ist erst moeglich, wenn die laufende Pause beendet wurde.');
            }

            $endTime = $this->normalizeTime($payload['end_time'] ?? null) ?? $this->nowTime();
        }

        if ($action === 'upsert') {
            $startTime = $this->normalizeTime($payload['start_time'] ?? null) ?? $startTime;
            $endTime = $this->normalizeTime($payload['end_time'] ?? null) ?? $endTime;
        }

        if ($action === 'pause_start') {
            if ($entry === null || $startTime === null || $startTime === '' || $endTime !== null) {
                throw new RuntimeException('Eine Pause kann nur waehrend eines laufenden Arbeitseinsatzes gestartet werden.');
            }

            if ($currentBreak !== null) {
                throw new RuntimeException('Es laeuft bereits eine Pause.');
            }
        }

        if ($action === 'pause_end') {
            if ($entry === null || $currentBreak === null) {
                throw new RuntimeException('Es gibt keine laufende Pause zum Beenden.');
            }
        }

        if ($action === 'pause' && ($entry === null || $startTime === null || $startTime === '')) {
            throw new RuntimeException('Bitte zuerst einen Check-in buchen, bevor Sie eine Pause erfassen.');
        }

        if ($action === 'select_project' && ($entry === null || $startTime === null || $startTime === '')) {
            throw new RuntimeException('Bitte zuerst einen Check-in buchen. Danach koennen Sie das Projekt zuordnen.');
        }

        if ($action === 'upsert' && $entry === null && ($startTime === null || $startTime === '') && ($endTime === null || $endTime === '')) {
            throw new RuntimeException('Bitte zuerst einen Check-in buchen oder eine Startzeit eingeben.');
        }

        $calculated = $this->calculatedValues($workDate, $startTime, $endTime, $manualBreakMinutes);
        $timesheetId = $this->persistTimesheet($entry, $userId, $projectId, $workDate, $startTime, $endTime, $note, $calculated);
        $this->handlePauseAction($action, $timesheetId, $userId, $payload, $currentBreak);

        $breaks = $this->findBreaksForTimesheet($timesheetId);
        $effectiveBreakMinutes = $action === 'pause' && array_key_exists('manual_break_minutes', $payload)
            ? $this->normalizeBreakMinutes($payload['manual_break_minutes'])
            : $this->workdayStateCalculator->completedBreakMinutes($breaks);

        $recalculated = $this->calculatedValues($workDate, $startTime, $endTime, $effectiveBreakMinutes);
        $this->refreshTimesheetDurations($timesheetId, $projectId, $startTime, $endTime, $note, $recalculated);
        $geoStored = $this->storeGeoRecord($timesheetId, $userId, $workDate, $payload);
        $timesheet = $this->findTimesheetById($timesheetId);
        $breaks = $this->findBreaksForTimesheet($timesheetId);
        $currentBreak = $this->workdayStateCalculator->currentBreak($breaks);
        $todayState = [
            'work_entry' => $timesheet,
            'current_break' => $currentBreak,
            'breaks_today' => $breaks,
            'status' => $this->workdayStateCalculator->status($timesheet, null, $currentBreak),
            'tracked_minutes_live_basis' => $this->workdayStateCalculator->trackedMinutesLiveBasis($workDate, $timesheet, $breaks),
        ];

        $response = [
            'ok' => true,
            'status' => 'synced',
            'action' => $action,
            'message' => $this->successMessage($action, $projectId),
            'geo_stored' => $geoStored,
            'timesheet' => $timesheet,
            'current_break' => $currentBreak,
            'breaks_today' => $breaks,
            'today_state' => $todayState,
            'tracked_minutes_live_basis' => $todayState['tracked_minutes_live_basis'],
            'server_time' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $this->recordSyncOperation($clientRequestId, $userId, $action, $response);

        return $response;
    }

    private function findDailyWorkEntry(int $userId, string $workDate): ?array
    {
        if (!$this->connection->tableExists('timesheets')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT *
             FROM timesheets
             WHERE user_id = :user_id
               AND COALESCE(is_deleted, 0) = 0
               AND work_date = :work_date
               AND entry_type = "work"
             ORDER BY
               CASE WHEN start_time IS NOT NULL AND end_time IS NULL THEN 0 ELSE 1 END ASC,
               COALESCE(end_time, start_time, "00:00:00") DESC,
               id DESC
             LIMIT 1',
            [
                'user_id' => $userId,
                'work_date' => $workDate,
            ]
        );
    }

    private function findLatestDailyWorkEntryAnyState(int $userId, string $workDate): ?array
    {
        if (!$this->connection->tableExists('timesheets')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT id, is_deleted, start_time, end_time
             FROM timesheets
             WHERE user_id = :user_id
               AND work_date = :work_date
               AND entry_type = "work"
             ORDER BY
               CASE WHEN start_time IS NOT NULL AND end_time IS NULL THEN 0 ELSE 1 END ASC,
               COALESCE(end_time, start_time, "00:00:00") DESC,
               updated_at DESC,
               id DESC
             LIMIT 1',
            [
                'user_id' => $userId,
                'work_date' => $workDate,
            ]
        );
    }

    private function persistTimesheet(
        ?array $entry,
        int $userId,
        ?int $projectId,
        string $workDate,
        ?string $startTime,
        ?string $endTime,
        ?string $note,
        array $calculated
    ): int {
        if (!$this->connection->tableExists('timesheets')) {
            return 0;
        }

        $bindings = [
            'user_id' => $userId,
            'project_id' => $projectId,
            'created_by_user_id' => $userId,
            'work_date' => $workDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'gross_minutes' => (int) ($calculated['gross_minutes'] ?? 0),
            'break_minutes' => (int) ($calculated['break_minutes'] ?? 0),
            'net_minutes' => (int) ($calculated['net_minutes'] ?? 0),
            'note' => $note,
        ];

        if ($entry === null) {
            $this->connection->execute(
                'INSERT INTO timesheets (
                    user_id, project_id, created_by_user_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, expenses_amount, entry_type, note, created_at, updated_at
                ) VALUES (
                    :user_id, :project_id, :created_by_user_id, :work_date, :start_time, :end_time, :gross_minutes, :break_minutes, :net_minutes, 0, "work", :note, NOW(), NOW()
                )',
                $bindings
            );

            return $this->connection->lastInsertId();
        }

        $this->connection->execute(
            'UPDATE timesheets SET
                project_id = :project_id,
                start_time = :start_time,
                end_time = :end_time,
                gross_minutes = :gross_minutes,
                break_minutes = :break_minutes,
                net_minutes = :net_minutes,
                note = :note,
                updated_at = NOW()
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0',
            [
                'project_id' => $projectId,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'gross_minutes' => (int) ($calculated['gross_minutes'] ?? 0),
                'break_minutes' => (int) ($calculated['break_minutes'] ?? 0),
                'net_minutes' => (int) ($calculated['net_minutes'] ?? 0),
                'note' => $note,
                'id' => (int) $entry['id'],
            ]
        );

        return (int) $entry['id'];
    }

    private function storeGeoRecord(int $timesheetId, int $userId, string $workDate, array $payload): bool
    {
        if ($timesheetId <= 0 || !$this->connection->tableExists('timesheet_geo_records')) {
            return false;
        }

        $settings = $this->companySettingsService->publicProfile();
        $geo = $payload['geo'] ?? null;
        $acknowledged = (bool) ($payload['geo_acknowledged'] ?? false);

        if (!is_array($geo) || !($settings['geo_capture_enabled'] ?? false)) {
            return false;
        }

        if (($settings['geo_requires_acknowledgement'] ?? false) && !$acknowledged) {
            return false;
        }

        $latitude = isset($geo['latitude']) ? (float) $geo['latitude'] : null;
        $longitude = isset($geo['longitude']) ? (float) $geo['longitude'] : null;

        if ($latitude === null || $longitude === null) {
            return false;
        }

        $this->connection->execute(
            'INSERT INTO timesheet_geo_records (
                timesheet_id, user_id, work_date, latitude, longitude, accuracy_meters, recorded_at, created_at
             ) VALUES (
                :timesheet_id, :user_id, :work_date, :latitude, :longitude, :accuracy_meters, :recorded_at, NOW()
             )',
            [
                'timesheet_id' => $timesheetId,
                'user_id' => $userId,
                'work_date' => $workDate,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_meters' => isset($geo['accuracy_meters']) ? (int) $geo['accuracy_meters'] : null,
                'recorded_at' => $this->normalizeDateTime($geo['recorded_at'] ?? null) ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]
        );

        return true;
    }

    private function recordSyncOperation(string $clientRequestId, int $userId, string $action, array $response): void
    {
        if (!$this->connection->tableExists('app_sync_operations')) {
            return;
        }

        $this->connection->execute(
            'INSERT INTO app_sync_operations (
                user_id, client_request_id, operation_type, response_json, created_at
             ) VALUES (
                :user_id, :client_request_id, :operation_type, :response_json, NOW()
             )',
            [
                'user_id' => $userId,
                'client_request_id' => $clientRequestId,
                'operation_type' => $action,
                'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
    }

    private function findSyncOperation(string $clientRequestId): ?array
    {
        if (!$this->connection->tableExists('app_sync_operations')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM app_sync_operations WHERE client_request_id = :client_request_id LIMIT 1',
            ['client_request_id' => $clientRequestId]
        );
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
            fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'break_started_at' => $this->dateTimeOrNull($row['break_started_at'] ?? null),
                'break_ended_at' => $this->dateTimeOrNull($row['break_ended_at'] ?? null),
                'source' => (string) ($row['source'] ?? 'app'),
                'note' => $this->nullableTrimmed($row['note'] ?? null),
            ],
            $rows
        );
    }

    private function handlePauseAction(string $action, int $timesheetId, int $userId, array $payload, ?array $currentBreak): void
    {
        if ($timesheetId <= 0 || !$this->connection->tableExists('timesheet_breaks')) {
            return;
        }

        if ($action === 'pause' && array_key_exists('manual_break_minutes', $payload)) {
            $this->connection->execute('DELETE FROM timesheet_breaks WHERE timesheet_id = :timesheet_id', [
                'timesheet_id' => $timesheetId,
            ]);

            return;
        }

        if ($action === 'pause_start') {
            $startedAt = $this->normalizeDateTime($payload['break_started_at'] ?? null) ?? (new DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->connection->execute(
                'INSERT INTO timesheet_breaks (
                    timesheet_id, user_id, break_started_at, break_ended_at, source, note, created_at, updated_at
                 ) VALUES (
                    :timesheet_id, :user_id, :break_started_at, NULL, :source, :note, NOW(), NOW()
                 )',
                [
                    'timesheet_id' => $timesheetId,
                    'user_id' => $userId,
                    'break_started_at' => $startedAt,
                    'source' => 'app',
                    'note' => $this->nullableTrimmed($payload['note'] ?? null),
                ]
            );

            return;
        }

        if ($action === 'pause_end' && $currentBreak !== null) {
            $endedAt = $this->normalizeDateTime($payload['break_ended_at'] ?? null) ?? (new DateTimeImmutable())->format('Y-m-d H:i:s');

            if (($currentBreak['break_started_at'] ?? null) !== null) {
                $startedAt = new DateTimeImmutable((string) $currentBreak['break_started_at']);
                $endAt = new DateTimeImmutable($endedAt);

                if ($endAt <= $startedAt) {
                    throw new RuntimeException('Das Pausenende muss nach dem Pausenstart liegen.');
                }
            }

            $this->connection->execute(
                'UPDATE timesheet_breaks SET break_ended_at = :break_ended_at, updated_at = NOW() WHERE id = :id',
                [
                    'id' => (int) ($currentBreak['id'] ?? 0),
                    'break_ended_at' => $endedAt,
                ]
            );
        }
    }

    private function findTimesheetById(int $id): ?array
    {
        if ($id <= 0 || !$this->connection->tableExists('timesheets')) {
            return null;
        }

        $row = $this->connection->fetchOne(
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
                projects.name AS project_name
             FROM timesheets
             LEFT JOIN projects ON projects.id = timesheets.project_id
             WHERE timesheets.id = :id
               AND COALESCE(timesheets.is_deleted, 0) = 0
             LIMIT 1',
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
            'project_name' => $row['project_name'] ?? null,
            'work_date' => (string) ($row['work_date'] ?? ''),
            'start_time' => $row['start_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'gross_minutes' => (int) ($row['gross_minutes'] ?? 0),
            'break_minutes' => (int) ($row['break_minutes'] ?? 0),
            'net_minutes' => (int) ($row['net_minutes'] ?? 0),
            'note' => $row['note'] ?? null,
        ];
    }

    private function refreshTimesheetDurations(
        int $timesheetId,
        ?int $projectId,
        ?string $startTime,
        ?string $endTime,
        ?string $note,
        array $calculated
    ): void {
        if ($timesheetId <= 0 || !$this->connection->tableExists('timesheets')) {
            return;
        }

        $this->connection->execute(
            'UPDATE timesheets SET
                project_id = :project_id,
                start_time = :start_time,
                end_time = :end_time,
                gross_minutes = :gross_minutes,
                break_minutes = :break_minutes,
                net_minutes = :net_minutes,
                note = :note,
                updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $timesheetId,
                'project_id' => $projectId,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'gross_minutes' => (int) ($calculated['gross_minutes'] ?? 0),
                'break_minutes' => (int) ($calculated['break_minutes'] ?? 0),
                'net_minutes' => (int) ($calculated['net_minutes'] ?? 0),
                'note' => $note,
            ]
        );
    }

    private function calculatedValues(string $workDate, ?string $startTime, ?string $endTime, int $manualBreakMinutes): array
    {
        if ($startTime === null || $startTime === '') {
            return [
                'gross_minutes' => 0,
                'break_minutes' => $manualBreakMinutes,
                'net_minutes' => 0,
            ];
        }

        if ($endTime === null || $endTime === '') {
            return [
                'gross_minutes' => 0,
                'break_minutes' => $manualBreakMinutes,
                'net_minutes' => 0,
            ];
        }

        return $this->calculator->calculate($workDate, $startTime, $endTime, $manualBreakMinutes, 'work');
    }

    private function normalizeProjectId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function normalizeBreakMinutes(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function normalizeTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat('H:i', $value)
            ?: \DateTimeImmutable::createFromFormat('H:i:s', $value);

        return $time ? $time->format('H:i') : null;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function nowTime(): string
    {
        return (new \DateTimeImmutable())->format('H:i');
    }

    private function successMessage(string $action, ?int $projectId): string
    {
        return match ($action) {
            'check_in' => $projectId !== null
                ? 'Check-in erfolgreich erfasst.'
                : 'Check-in ohne Projekt erfolgreich erfasst. Sie koennen die Baustelle spaeter zuordnen.',
            'check_out', 'day_close' => 'Check-out erfolgreich erfasst.',
            'pause' => 'Pause erfolgreich gespeichert.',
            'select_project' => 'Projekt erfolgreich zugeordnet.',
            'upsert' => 'Zeiteintrag erfolgreich aktualisiert.',
            'pause_start' => 'Pause erfolgreich gestartet.',
            'pause_end' => 'Pause erfolgreich beendet.',
            default => 'Aenderung erfolgreich gespeichert.',
        };
    }
}
