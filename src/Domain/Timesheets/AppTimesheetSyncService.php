<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\WorkTime\WorkTimePresenceService;
use App\Domain\WorkTime\WorkTimeLocalTime;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class AppTimesheetSyncService
{
    private const ACCOUNTING_WRITE_LOCK = 'accounting-timesheet-write';
    private const ACTUAL_PAUSE_ACTIONS = ['pause_start', 'pause_end', 'pause_record'];
    private ?WorkTimePresenceService $workTimePresenceService = null;
    private ?TimesheetPauseService $pauses = null;
    private TimesheetPauseIntervals $pauseIntervals;

    public function __construct(
        private DatabaseConnection $connection,
        private TimesheetCalculator $calculator,
        private CompanySettingsService $companySettingsService,
        private WorkdayStateCalculator $workdayStateCalculator,
        private ?TimesheetSignatureService $signatureService = null,
        private ?TimesheetDayConflictService $dayConflictService = null
    ) {
        $this->dayConflictService ??= new TimesheetDayConflictService($connection);
        $this->pauseIntervals = new TimesheetPauseIntervals($connection);
    }

    public function setWorkTimePresenceService(WorkTimePresenceService $service): void
    {
        $this->workTimePresenceService = $service;
    }

    public function setPauseService(TimesheetPauseService $service): void
    {
        $this->pauses = $service;
    }

    public function openPresenceForUser(int $userId, ?string $referenceDate = null): ?array
    {
        return $this->workTimePresenceService?->openForUser($userId, $referenceDate);
    }

    public function workTimeWindowsEnabled(): bool
    {
        return $this->workTimePresenceService?->enabled() ?? false;
    }

    public function workTimeAppliesToUser(int $userId, string $date): bool
    {
        return $this->workTimePresenceService?->appliesToUser($userId, $date) ?? false;
    }

    public function hasOpenPauseModelBooking(int $userId): bool
    {
        return $this->pauses?->hasOpenModelBooking($userId) ?? false;
    }

    /** The optional hooks participate in the stamp transaction and run only for a new operation. */
    public function sync(int|array $user, array $payload, ?callable $afterSync = null, ?callable $beforeSync = null): array
    {
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : $user;
        $permissions = is_array($user) && is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
        $clientRequestId = trim((string) ($payload['client_request_id'] ?? ''));

        if ($clientRequestId === '') {
            throw new RuntimeException('Eine client_request_id ist erforderlich.');
        }

        $existingOperation = $this->findSyncOperation($clientRequestId, $userId);

        if ($existingOperation !== null) {
            $this->assertReplayAction($existingOperation, is_string($payload['action'] ?? null) ? trim($payload['action']) : '');
            $response = json_decode((string) ($existingOperation['response_json'] ?? ''), true);

            return is_array($response) ? $response : ['status' => 'synced'];
        }

        $action = trim((string) ($payload['action'] ?? ''));

        $actions = ['check_in', 'check_out', 'pause', 'pause_start', 'pause_end', 'pause_record', 'day_close', 'select_project', 'upsert'];
        if ($this->workTimePresenceService?->enabled()) {
            $actions = [...$actions, 'work_start', 'presence_end'];
        }
        if (!in_array($action, $actions, true)) {
            throw new RuntimeException('Die Aktion ist ungueltig.');
        }

        return $this->withAccountingWriteLock(function () use ($userId, $permissions, $clientRequestId, $payload, $action, $afterSync, $beforeSync): array {
            return $this->connection->transaction(function () use ($userId, $permissions, $clientRequestId, $payload, $action, $afterSync, $beforeSync): array {
                $existingClaim = $this->claimSyncOperation($clientRequestId, $userId, $action);

                if ($existingClaim !== null) {
                    return $existingClaim;
                }

                if ($beforeSync !== null) {
                    $beforeSync();
                }
                $result = $this->performSync($userId, $permissions, $clientRequestId, $payload, $action);
                if ($afterSync !== null) {
                    $afterSync($result);
                }
                return $result;
            });
        });
    }

    private function performSync(int $userId, array $permissions, string $clientRequestId, array $payload, string $action): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $rawWorkDate = $payload['work_date'] ?? $now->format('Y-m-d');
        $workDate = is_string($rawWorkDate) ? trim($rawWorkDate) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $workDate)) {
            throw new InvalidArgumentException('Das Arbeitsdatum ist ungültig.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, $now->getTimezone());
        if ($date === false || $date->format('Y-m-d') !== $workDate) {
            throw new InvalidArgumentException('Das Arbeitsdatum ist ungültig.');
        }
        $payload['work_date'] = $workDate;
        $originalAction = $action;
        $startingWork = in_array($action, ['check_in', 'work_start'], true);
        $endingWork = in_array($action, ['check_out', 'day_close'], true);
        $requestedStartTime = $startingWork ? $this->readStampTime($payload['start_time'] ?? null) ?? $now->format('H:i') : null;
        $requestedProjectId = $this->normalizeProjectId($payload['project_id'] ?? null);
        if ($startingWork) {
            $this->assertNoOpenWorkSession($userId, $permissions, $requestedProjectId, LiveWorkSession::referenceDate($workDate, $now));
        }
        $appliesToUser = $this->workTimePresenceService?->appliesToUser($userId, $workDate) ?? false;
        if ($appliesToUser) {
            $active = $this->connection->fetchOne('SELECT id FROM users WHERE id = :id AND employment_status = "active" AND COALESCE(is_deleted, 0) = 0', ['id' => $userId]);
            if ($active === null) {
                throw new RuntimeException('Arbeitszeit kann nur fuer aktive Mitarbeiter erfasst werden.');
            }
        }
        $entry = $this->findDailyWorkEntry($userId, $workDate);
        $explicitPauseEntry = $action === 'pause_record' && isset($payload['expected_timesheet_id']);
        if ($explicitPauseEntry) {
            $entry = $this->connection->fetchOne('SELECT * FROM timesheets WHERE id = :id AND user_id = :user_id
                AND work_date = :work_date AND entry_type = "work" AND COALESCE(is_deleted, 0) = 0', [
                'id' => (int) $payload['expected_timesheet_id'], 'user_id' => $userId, 'work_date' => $workDate,
            ]);
        }
        $pausePolicy = $entry === null ? null : $this->pauses?->snapshot((int) $entry['id']);
        $boundPauseAction = in_array($action, self::ACTUAL_PAUSE_ACTIONS, true);
        $pauseWrite = $boundPauseAction || $action === 'pause';
        if (($action === 'pause_record' || ($boundPauseAction && $pausePolicy !== null))
            && !isset($payload['expected_timesheet_id']) && !isset($payload['expected_start_time'])) {
            throw new InvalidArgumentException('Bitte den Arbeitseinsatz für die Pause eindeutig auswählen.');
        }
        if (isset($payload['expected_timesheet_id'])) {
            $expectedId = filter_var($payload['expected_timesheet_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($expectedId === false || $entry === null || (int) $entry['id'] !== $expectedId || (in_array($action, ['check_out', 'day_close', 'pause_start', 'pause_end'], true) && !empty($entry['end_time']))) {
                throw new RuntimeException('Der Arbeitseinsatz wurde inzwischen geaendert. Bitte neu laden.');
            }
        }
        $guardedProjectAssignment = $action === 'select_project'
            && (array_key_exists('expected_project_id', $payload) || array_key_exists('expected_running', $payload));
        if ($guardedProjectAssignment) {
            $this->assertExpectedProjectAssignment($entry, $payload, $userId);
            unset($payload['source']);
        }
        $checkSessionBinding = $appliesToUser || $pausePolicy !== null || $boundPauseAction || $endingWork
            || ($action === 'upsert' && isset($payload['expected_start_time']));
        if ($checkSessionBinding && !$startingWork && $action !== 'presence_end') {
            if (isset($payload['expected_start_time'])) {
                $expectedStart = is_string($payload['expected_start_time']) ? $this->normalizeTime($payload['expected_start_time']) : null;
                if ($entry === null || $expectedStart === null || substr((string) $entry['start_time'], 0, 5) !== $expectedStart) {
                    throw new RuntimeException('Die Offline-Buchung gehoert zu einem anderen oder nicht eindeutig bestimmbaren Arbeitseinsatz. Bitte neu laden und pruefen.');
                }
                if (!isset($payload['expected_timesheet_id'])) {
                    $sameStartSessions = (int) $this->connection->fetchColumn(
                        'SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id AND work_date = :work_date
                         AND entry_type = "work" AND COALESCE(is_deleted, 0) = 0 AND start_time = :start_time',
                        ['user_id' => $userId, 'work_date' => $workDate, 'start_time' => $expectedStart]
                    );
                    if ($sameStartSessions !== 1) {
                        throw new RuntimeException('Die Offline-Buchung ist keinem eindeutigen Arbeitseinsatz zugeordnet. Bitte neu laden und prüfen.');
                    }
                }
            } elseif (($appliesToUser || $pausePolicy !== null || $endingWork) && !isset($payload['expected_timesheet_id'])) {
                $dailySessionCount = (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id AND work_date = :work_date AND entry_type = "work" AND COALESCE(is_deleted, 0) = 0 AND start_time IS NOT NULL', ['user_id' => $userId, 'work_date' => $workDate]);
                if ($dailySessionCount > 1) {
                    throw new RuntimeException('Bei mehreren Arbeitseinsaetzen ist eine eindeutige Buchungszuordnung erforderlich. Bitte App-Daten neu laden.');
                }
            }
        }
        $latestDailyEntry = $this->findLatestDailyWorkEntryAnyState($userId, $workDate);

        // An explicit new check-in creates a separate session; archived predecessors
        // must only block changes to the former session, never the next check-in.
        if (!$startingWork && !$explicitPauseEntry && $latestDailyEntry !== null && (int) ($latestDailyEntry['is_deleted'] ?? 0) === 1) {
            throw new RuntimeException('Ihre Tagesbuchung wurde im Backend geaendert oder archiviert. Bitte App-Daten neu laden.');
        }

        $startNewEntry = $startingWork && $entry !== null && ($entry['start_time'] ?? null) !== null && ($entry['end_time'] ?? null) !== null;

        if ($startNewEntry) {
            $entry = null;
        }
        if ($entry === null) {
            $pausePolicy = $this->pauses?->configuredPolicy($userId, $workDate);
        }

        $existingBreaks = $entry !== null ? $this->findBreaksForTimesheet((int) $entry['id']) : [];
        $currentBreak = $this->workdayStateCalculator->currentBreak($existingBreaks);
        $entryProjectId = $this->normalizeProjectId($entry['project_id'] ?? null);
        if ($endingWork && $entry !== null && $requestedProjectId !== null && $requestedProjectId !== $entryProjectId) {
            throw new InvalidArgumentException('Die Projektzuordnung muss separat geändert werden. Laden Sie den Arbeitseinsatz vor dem Check-out neu.');
        }
        $projectId = ($pauseWrite ? null : $requestedProjectId) ?? $entryProjectId;
        if ((!$endingWork && !$pauseWrite) || $projectId !== $entryProjectId) {
            $this->assertProjectVisibleToUser($projectId, $userId, $permissions, $workDate);
        }
        $this->assertAccountingWriteAllowed($entry, $userId, $projectId, $workDate);
        $this->dayConflictService?->assertNoConflictForWork($userId, $workDate, $entry !== null ? (int) $entry['id'] : null);
        if ($startingWork) {
            $this->dayConflictService?->assertNoWorkOverlap(
                $userId, $workDate, $requestedStartTime, null, isset($entry['id']) ? (int) $entry['id'] : null, $now
            );
        }
        $presenceResponse = $this->workTimePresenceService?->handle($userId, $payload, $action, $entry);
        if ($presenceResponse !== null) {
            $this->updateSyncOperationResponse($clientRequestId, $userId, $action, $presenceResponse);
            return $presenceResponse;
        }
        if ($action === 'work_start') {
            $action = 'check_in';
        }
        $note = $guardedProjectAssignment || $pauseWrite ? ($entry['note'] ?? null) : $this->nullableTrimmed($payload['note'] ?? ($entry['note'] ?? null));
        if ($pauseWrite) {
            unset($payload['source'], $payload['geo'], $payload['geo_acknowledged']);
        }
        $source = $this->normalizeSource($payload['source'] ?? ($entry['source'] ?? 'app'));
        if (($pauseWrite || ($endingWork && !isset($payload['source']))) && isset($entry['source'])) {
            $source = (string) $entry['source'];
        }
        $manualBreakMinutes = isset($entry['break_minutes']) ? (int) $entry['break_minutes'] : $this->workdayStateCalculator->completedBreakMinutes($existingBreaks);

        if ($action === 'pause' && array_key_exists('manual_break_minutes', $payload)) {
            $manualBreakMinutes = $this->normalizeBreakMinutes($payload['manual_break_minutes']);
        }

        $startTime = $entry['start_time'] ?? null;
        $endTime = $entry['end_time'] ?? null;

        if ($action === 'check_in') {
            $startTime = $requestedStartTime;
            $endTime = null;
        }

        if (in_array($action, ['check_out', 'day_close'], true)) {
            if ($entry === null || $startTime === null || $startTime === '') {
                throw new RuntimeException('Bitte zuerst einen Check-in buchen, bevor Sie den Check-out erfassen.');
            }

            if ($currentBreak !== null) {
                throw new RuntimeException('Check-out ist erst moeglich, wenn die laufende Pause beendet wurde.');
            }

            $endTime = $this->readStampTime($payload['end_time'] ?? null) ?? $now->format('H:i');
            if (isset($payload['actual_end_at'])) {
                $actualValue = $payload['actual_end_at'];
                if (!is_string($actualValue) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ](?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/D', $actualValue)) {
                    throw new RuntimeException('Bitte Datum und Uhrzeit des tatsaechlichen Endes minutengenau eingeben.');
                }
                $actualLocal = str_replace('T', ' ', $actualValue);
                $actualLocal = strlen($actualLocal) === 16 ? $actualLocal . ':00' : $actualLocal;
                $actualEnd = WorkTimeLocalTime::parse($actualLocal);
                $actualStart = WorkTimeLocalTime::parse($workDate . ' ' . (strlen((string) $startTime) === 5 ? $startTime . ':00' : $startTime));
                $inferredEndDate = $endTime < substr((string) $startTime, 0, 5) ? $date->modify('+1 day')->format('Y-m-d') : $workDate;
                if ($actualEnd->format('Y-m-d H:i') !== $inferredEndDate . ' ' . $endTime || $actualEnd <= $actualStart || $actualEnd > $now || $actualEnd->getTimestamp() - $actualStart->getTimestamp() > 86400) {
                    throw new RuntimeException('Dieser Einsatz kann mit dem vorhandenen Tageswechselmodell nicht eindeutig abgeschlossen werden. Bitte den Arbeitszeitnachweis im Admin pruefen.');
                }
            }
        }

        if ($action === 'upsert') {
            $startTime = $this->readStampTime($payload['start_time'] ?? null) ?? $startTime;
            $endTime = $this->readStampTime($payload['end_time'] ?? null) ?? $endTime;
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

        if (in_array($action, ['pause', 'pause_record'], true) && ($entry === null || $startTime === null || $startTime === '')) {
            throw new RuntimeException('Bitte zuerst einen Check-in buchen, bevor Sie eine Pause erfassen.');
        }

        if ($action === 'select_project' && ($entry === null || $startTime === null || $startTime === '')) {
            throw new RuntimeException('Bitte zuerst einen Check-in buchen. Danach koennen Sie das Projekt zuordnen.');
        }

        if ($action === 'upsert' && ($startTime === null || $startTime === '')
            && ($entry === null || isset($payload['end_time']))) {
            throw new RuntimeException('Bitte zuerst einen Check-in buchen oder eine Startzeit eingeben.');
        }

        $strictPauseStamp = in_array($action, ['pause_start', 'pause_end'], true)
            && ($appliesToUser || $pausePolicy !== null || ($this->pauses !== null
                && (isset($payload['expected_timesheet_id']) || isset($payload['expected_start_time']))));
        if ($strictPauseStamp) {
            $pauseField = $action === 'pause_start' ? 'break_started_at' : 'break_ended_at';
            $pauseTimestamp = $payload[$pauseField] ?? (new DateTimeImmutable())->format(DATE_ATOM);
            if (!is_string($pauseTimestamp)) {
                throw new RuntimeException('Der Pausenzeitpunkt muss als vollstaendiger Zeitstempel angegeben werden.');
            }
            $payload[$pauseField] = WorkTimeLocalTime::formatForStorage(WorkTimeLocalTime::fromInput($pauseTimestamp));
        }

        $recordedPause = null;
        if ($action === 'pause_record') {
            if (!$this->pauses?->available()) {
                throw new InvalidArgumentException('Pausennachträge sind noch nicht migriert.');
            }
            if ($currentBreak !== null) {
                throw new InvalidArgumentException('Bitte zuerst die laufende Pause beenden.');
            }
            $recordedPause = $this->manualPause($payload);
            if (isset($recordedPause['duration_minutes'])) {
                $expected = filter_var($payload['expected_documented_break_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($expected === false || $expected !== $this->workdayStateCalculator->completedBreakMinutes($existingBreaks)) {
                    throw new InvalidArgumentException('Die dokumentierten Pausen wurden inzwischen geändert. Bitte prüfen Sie die vorhandenen Nachweise vor einem neuen Nachtrag.');
                }
            }
            $this->pauses->validateEvidence($entry, [...$existingBreaks, $recordedPause]);
        }
        if ($action === 'pause_end') {
            if ($pausePolicy !== null && !isset($payload['expected_break_id']) && !isset($payload['expected_break_started_at'])) {
                throw new InvalidArgumentException('Bitte die aktuell offene Pause neu laden und eindeutig bestätigen.');
            }
            $this->assertExpectedPause($payload, $currentBreak);
        }
        if ($pauseWrite && $entry !== null && $this->signatureService !== null) {
            $this->connection->fetchOne('SELECT id FROM timesheets WHERE id = :id FOR UPDATE', ['id' => $entry['id']]);
            if ($this->signatureService->findActiveForTimesheet((int) $entry['id'], true) !== null) {
                throw new InvalidArgumentException('Diese Buchung enthaelt eine Kundenbestaetigung. Bitte die Pause im Admin pruefen.');
            }
        }
        $stampChanged = $this->normalizedComparableValue($startTime) !== $this->normalizedComparableValue($entry['start_time'] ?? null)
            || $this->normalizedComparableValue($endTime) !== $this->normalizedComparableValue($entry['end_time'] ?? null);
        if ($stampChanged && $startTime !== null && $recordedPause === null && $existingBreaks !== []) {
            $this->pauses?->validateEvidence(['work_date' => $workDate, 'start_time' => $startTime, 'end_time' => $endTime], $existingBreaks);
        }

        $calculated = $pausePolicy !== null && $startTime !== null
            ? $this->calculator->calculateDocumented($workDate, $startTime, $endTime, $existingBreaks)
            : $this->calculatedValues($workDate, $startTime, $endTime, $manualBreakMinutes);
        if ($entry !== null) {
            (new TimesheetPauseEvidenceGuard($this->connection))->assertChangeAllowed((int) $entry['id'], [
                'user_id' => $userId, 'work_date' => $workDate, 'start_time' => $startTime, 'end_time' => $endTime,
                'gross_minutes' => $calculated['gross_minutes'], 'break_minutes' => $calculated['break_minutes'],
                'net_minutes' => $calculated['net_minutes'], 'entry_type' => 'work', 'source' => $source,
            ], $boundPauseAction || ($action === 'pause' && array_key_exists('manual_break_minutes', $payload)));
        }
        if ($endingWork && !empty($entry['end_time'])) {
            throw new RuntimeException('Dieser Arbeitseinsatz ist bereits beendet. Bitte App-Daten neu laden.');
        }
        if ($endingWork || ($action === 'upsert' && ($entry === null
            || $this->comparableStamp($startTime) !== $this->comparableStamp($entry['start_time'] ?? null)
            || $this->comparableStamp($endTime) !== $this->comparableStamp($entry['end_time'] ?? null)))) {
            $this->dayConflictService?->assertNoWorkOverlap(
                $userId, $workDate, $startTime, $endTime, isset($entry['id']) ? (int) $entry['id'] : null, $now
            );
        }
        if ($action === 'pause' && array_key_exists('manual_break_minutes', $payload) && ($pausePolicy !== null || $existingBreaks !== [])) {
            throw new InvalidArgumentException('Vorhandene Pausennachweise bleiben erhalten. Bitte einen einzelnen Pausennachtrag erfassen.');
        }
        $timesheetId = $this->persistTimesheet($entry, $userId, $projectId, $workDate, $startTime, $endTime, $note, $source, $calculated);
        if ($entry === null && $pausePolicy !== null) {
            $this->pauses->attachSnapshot($timesheetId, $pausePolicy);
        }
        if ($originalAction === 'work_start') {
            $this->workTimePresenceService?->markWorkStarted($userId, $timesheetId, $workDate, (string) $startTime);
        }
        $this->handlePauseAction($action, $timesheetId, $userId, $payload, $currentBreak, $strictPauseStamp);
        if ($recordedPause !== null) {
            if (isset($recordedPause['duration_minutes'])) {
                $this->pauses->addDuration($timesheetId, $userId, $recordedPause['duration_minutes'], 'app_manual', $this->nullableTrimmed($payload['note'] ?? null));
            } else {
                $this->pauses->addInterval($timesheetId, $userId, $recordedPause['break_started_at'], $recordedPause['break_ended_at'], 'app_manual', $this->nullableTrimmed($payload['note'] ?? null));
            }
        }

        $breaks = $this->findBreaksForTimesheet($timesheetId);
        if ($strictPauseStamp && $startTime !== null) {
            $this->pauses?->validateEvidence(['work_date' => $workDate, 'start_time' => $startTime, 'end_time' => $endTime], $breaks);
        }
        $effectiveBreakMinutes = $this->effectiveBreakMinutes($action, $payload, $breaks, $manualBreakMinutes);

        if ($pausePolicy === null && $action === 'pause_record') {
            $effectiveBreakMinutes = max($manualBreakMinutes, $effectiveBreakMinutes);
        }
        $recalculated = $pausePolicy !== null && $startTime !== null
            ? $this->calculator->calculateDocumented($workDate, $startTime, $endTime, $breaks)
            : $this->calculatedValues($workDate, $startTime, $endTime, $effectiveBreakMinutes);
        $this->refreshTimesheetDurations($timesheetId, $projectId, $startTime, $endTime, $note, $source, $recalculated);
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
            'action' => $originalAction,
            'message' => $this->successMessage($action, $projectId),
            'geo_stored' => $geoStored,
            'timesheet' => $timesheet,
            'current_break' => $currentBreak,
            'breaks_today' => $breaks,
            'today_state' => $todayState,
            'tracked_minutes_live_basis' => $todayState['tracked_minutes_live_basis'],
            ...(!$this->pauses?->available() ? [] : ['break_assessment' => $this->pauses->assessmentForDay($userId, $workDate)]),
            'server_time' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        $this->updateSyncOperationResponse($clientRequestId, $userId, $originalAction, $response);

        return $response;
    }

    private function assertNoOpenWorkSession(int $userId, array $permissions, ?int $requestedProjectId, string $referenceDate): void
    {
        $entry = $this->connection->fetchOne(
            'SELECT t.project_id
             FROM timesheets t
             WHERE t.user_id = :user_id AND ' . LiveWorkSession::isOpenSql('t') . '
             ORDER BY t.work_date DESC, t.start_time DESC, t.id DESC LIMIT 1',
            ['user_id' => $userId, 'live_reference_date' => $referenceDate]
        );
        if ($entry === null) {
            return;
        }

        $project = null;
        $projectId = $this->normalizeProjectId($entry['project_id']);
        if ($projectId !== null && (new ProjectAccessService($this->connection))->canAccess(['id' => $userId, 'permissions' => $permissions], $projectId)) {
            $project = $this->connection->fetchOne(
                'SELECT id AS project_id, project_number, name AS project_name FROM projects
                 WHERE id = :id AND COALESCE(is_deleted, 0) = 0 AND status <> "archived"',
                ['id' => $projectId]
            );
        }

        $message = WorkSessionConflict::ACTIVE_MESSAGE;
        $label = trim((string) ($project['project_number'] ?? '')) ?: trim((string) ($project['project_name'] ?? ''));
        if ($project !== null && $projectId === $requestedProjectId) {
            $message = 'Sie sind bereits in diesem Projekt eingecheckt.';
        } elseif ($project !== null && $label !== '') {
            $message = 'Sie sind bereits in Projekt ' . $label . ' eingecheckt. Beenden Sie dort zuerst den Einsatz, um in einem anderen Projekt zu starten.';
        }

        throw new WorkSessionConflict('active_work_session', $message, $project);
    }

    private function assertExpectedProjectAssignment(?array $entry, array $payload, int $userId): void
    {
        $expectedProjectId = $payload['expected_project_id'] ?? null;
        if ($expectedProjectId !== null) {
            $expectedProjectId = filter_var($expectedProjectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        }
        $expectedStart = is_string($payload['expected_start_time'] ?? null)
            ? $this->normalizeTime($payload['expected_start_time']) : null;
        $currentProjectId = isset($entry['project_id']) ? (int) $entry['project_id'] : null;

        if ($entry === null || !isset($payload['expected_timesheet_id'])
            || $expectedStart === null || $expectedStart !== $this->normalizeTime($entry['start_time'])
            || !array_key_exists('expected_project_id', $payload) || $expectedProjectId !== $currentProjectId
            || ($payload['expected_running'] ?? null) !== empty($entry['end_time'])
            || ($payload['expected_user_id'] ?? null) !== $userId) {
            throw new RuntimeException('Der Arbeitseinsatz wurde inzwischen geaendert. Bitte neu laden.');
        }
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
        string $source,
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
            'source' => $source,
        ];

        if ($entry === null) {
            $this->connection->execute(
                'INSERT INTO timesheets (
                    user_id, project_id, created_by_user_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, expenses_amount, entry_type, source, note, created_at, updated_at
                ) VALUES (
                    :user_id, :project_id, :created_by_user_id, :work_date, :start_time, :end_time, :gross_minutes, :break_minutes, :net_minutes, 0, "work", :source, :note, NOW(), NOW()
                )',
                $bindings
            );

            return $this->connection->lastInsertId();
        }

        $shouldArchiveSignature = $this->signatureRelevantChanges($entry, [
            'project_id' => $projectId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_minutes' => (int) ($calculated['break_minutes'] ?? 0),
            'net_minutes' => (int) ($calculated['net_minutes'] ?? 0),
        ]);

        $this->connection->execute(
            'UPDATE timesheets SET
                project_id = :project_id,
                start_time = :start_time,
                end_time = :end_time,
                gross_minutes = :gross_minutes,
                break_minutes = :break_minutes,
                net_minutes = :net_minutes,
                source = :source,
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
                'source' => $source,
                'note' => $note,
                'id' => (int) $entry['id'],
            ]
        );

        if ($shouldArchiveSignature) {
            $this->signatureService?->archiveActiveForTimesheet((int) $entry['id'], $userId);
        }

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

    private function claimSyncOperation(string $clientRequestId, int $userId, string $action): ?array
    {
        if (!$this->connection->tableExists('app_sync_operations')) {
            return null;
        }

        try {
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
                    'response_json' => json_encode(['status' => 'processing'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]
            );

            return null;
        } catch (\Throwable $throwable) {
            if (!$this->isDuplicateKeyException($throwable)) {
                throw $throwable;
            }

            $existingOperation = $this->findSyncOperation($clientRequestId, $userId);
            if ($existingOperation === null) {
                throw new InvalidArgumentException('Diese Anfrage-ID ist bereits vergeben. Bitte prüfen Sie die ausstehende Aktion.');
            }
            $this->assertReplayAction($existingOperation, $action);
            $response = json_decode((string) ($existingOperation['response_json'] ?? ''), true);

            return is_array($response) ? $response : ['status' => 'synced'];
        }
    }

    private function assertReplayAction(array $operation, string $action): void
    {
        $previous = (string) ($operation['operation_type'] ?? '');
        if ($previous === 'check_in'
            && (json_decode((string) ($operation['response_json'] ?? ''), true)['action'] ?? null) === 'work_start') {
            $previous = 'work_start';
        }
        if ($previous !== $action) {
            throw new InvalidArgumentException('Diese Anfrage-ID gehört bereits zu einer anderen Aktion.');
        }
    }

    private function updateSyncOperationResponse(string $clientRequestId, int $userId, string $action, array $response): void
    {
        if (!$this->connection->tableExists('app_sync_operations')) {
            return;
        }

        $this->connection->execute(
            'UPDATE app_sync_operations
             SET operation_type = :operation_type,
                 response_json = :response_json
             WHERE user_id = :user_id
               AND client_request_id = :client_request_id',
            [
                'user_id' => $userId,
                'client_request_id' => $clientRequestId,
                'operation_type' => $action,
                'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]
        );
    }

    private function findSyncOperation(string $clientRequestId, int $userId): ?array
    {
        if (!$this->connection->tableExists('app_sync_operations')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM app_sync_operations WHERE client_request_id = :client_request_id AND user_id = :user_id LIMIT 1',
            [
                'client_request_id' => $clientRequestId,
                'user_id' => $userId,
            ]
        );
    }

    private function findBreaksForTimesheet(int $timesheetId): array
    {
        if ($this->pauses !== null) {
            return $this->pauses->breaks($timesheetId);
        }
        if ($timesheetId <= 0) {
            return [];
        }

        return array_map(
            fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'break_started_at' => TimesheetPauseIntervals::forDisplay($row['break_started_at']),
                'break_ended_at' => TimesheetPauseIntervals::forDisplay($row['break_ended_at']),
                'source' => (string) ($row['source'] ?? 'app'),
                'note' => $this->nullableTrimmed($row['note'] ?? null),
            ],
            $this->pauseIntervals->rows([$timesheetId])
        );
    }

    private function handlePauseAction(string $action, int $timesheetId, int $userId, array $payload, ?array $currentBreak, bool $strictStamp): void
    {
        if ($timesheetId <= 0 || !$this->connection->tableExists('timesheet_breaks')) {
            return;
        }

        if ($action === 'pause_start') {
            $startedAt = $strictStamp ? $payload['break_started_at']
                : ($this->normalizeDateTime($payload['break_started_at'] ?? null) ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'));
            $this->pauseIntervals->append($timesheetId, $userId, $startedAt, null, 'app', $this->nullableTrimmed($payload['note'] ?? null));
            return;
        }

        if ($action === 'pause_end' && $currentBreak !== null) {
            $endedAt = $strictStamp ? $payload['break_ended_at']
                : ($this->normalizeDateTime($payload['break_ended_at'] ?? null) ?? (new DateTimeImmutable())->format('Y-m-d H:i:s'));

            if (($currentBreak['break_started_at'] ?? null) !== null) {
                $startedAt = $strictStamp ? WorkTimeLocalTime::fromInput($currentBreak['break_started_at']) : new DateTimeImmutable($currentBreak['break_started_at']);
                $endAt = $strictStamp ? WorkTimeLocalTime::fromInput($endedAt) : new DateTimeImmutable($endedAt);

                if ($endAt <= $startedAt) {
                    throw new RuntimeException('Das Pausenende muss nach dem Pausenstart liegen.');
                }
            }

            $this->pauseIntervals->close((int) ($currentBreak['id'] ?? 0), $endedAt);
        }
    }

    private function manualPause(array $payload): array
    {
        $start = $payload['break_started_at'] ?? null;
        $end = $payload['break_ended_at'] ?? null;
        if ($start !== null || $end !== null) {
            if (!is_string($start) || !is_string($end)) {
                throw new InvalidArgumentException('Bitte Pausenbeginn und Pausenende vollständig angeben.');
            }
            return [
                'break_started_at' => WorkTimeLocalTime::formatForStorage(WorkTimeLocalTime::fromInput($start)),
                'break_ended_at' => WorkTimeLocalTime::formatForStorage(WorkTimeLocalTime::fromInput($end)),
            ];
        }
        $duration = filter_var($payload['manual_break_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        if ($duration === false) {
            throw new InvalidArgumentException('Bitte eine Pausendauer zwischen 1 und 1.440 Minuten eingeben.');
        }
        return ['duration_minutes' => $duration];
    }

    private function assertExpectedPause(array $payload, ?array $currentBreak): void
    {
        $expectedId = $payload['expected_break_id'] ?? null;
        $expectedStart = $payload['expected_break_started_at'] ?? null;
        if (($expectedId !== null && (int) $expectedId !== (int) ($currentBreak['id'] ?? 0))
            || ($expectedStart !== null && (!is_string($expectedStart)
                || WorkTimeLocalTime::fromInput($expectedStart)->getTimestamp() !== WorkTimeLocalTime::fromInput($currentBreak['break_started_at'])->getTimestamp()))) {
            throw new InvalidArgumentException('Die offene Pause wurde inzwischen geändert. Bitte App-Daten neu laden.');
        }
    }

    private function findTimesheetById(int $id): ?array
    {
        if ($id <= 0 || !$this->connection->tableExists('timesheets')) {
            return null;
        }

        $signatureColumns = $this->projectSignatureColumns();
        $row = $this->connection->fetchOne(
            'SELECT
                timesheets.id,
                timesheets.user_id,
                timesheets.project_id,
                timesheets.work_date,
                timesheets.start_time,
                timesheets.end_time,
                timesheets.gross_minutes,
                timesheets.break_minutes,
                timesheets.net_minutes,
                timesheets.source,
                timesheets.note,
                projects.name AS project_name,
                projects.customer_name AS project_customer_name,
                ' . $signatureColumns['required'] . ' AS customer_signature_required,
                ' . $signatureColumns['name'] . ' AS customer_signature_name
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
            'user_id' => (int) ($row['user_id'] ?? 0),
            'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
            'project_name' => $row['project_name'] ?? null,
            'project_customer_name' => $row['project_customer_name'] ?? null,
            'customer_signature_required' => (int) ($row['customer_signature_required'] ?? 0) === 1,
            'customer_signature_name' => $row['customer_signature_name'] ?? null,
            'work_date' => (string) ($row['work_date'] ?? ''),
            'start_time' => $row['start_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'gross_minutes' => (int) ($row['gross_minutes'] ?? 0),
            'break_minutes' => (int) ($row['break_minutes'] ?? 0),
            'net_minutes' => (int) ($row['net_minutes'] ?? 0),
            'source' => (string) ($row['source'] ?? 'app'),
            'note' => $row['note'] ?? null,
        ];
    }

    private function refreshTimesheetDurations(
        int $timesheetId,
        ?int $projectId,
        ?string $startTime,
        ?string $endTime,
        ?string $note,
        string $source,
        array $calculated
    ): void {
        if ($timesheetId <= 0 || !$this->connection->tableExists('timesheets')) {
            return;
        }

        $this->assertTimesheetNotLockedByAccountingClosure($timesheetId);
        $before = $this->findTimesheetById($timesheetId);

        $shouldArchiveSignature = $before !== null && $this->signatureRelevantChanges($before, [
            'project_id' => $projectId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_minutes' => (int) ($calculated['break_minutes'] ?? 0),
            'net_minutes' => (int) ($calculated['net_minutes'] ?? 0),
        ]);

        $this->connection->execute(
            'UPDATE timesheets SET
                project_id = :project_id,
                start_time = :start_time,
                end_time = :end_time,
                gross_minutes = :gross_minutes,
                break_minutes = :break_minutes,
                net_minutes = :net_minutes,
                source = :source,
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
                'source' => $source,
                'note' => $note,
            ]
        );

        if ($shouldArchiveSignature) {
            $this->signatureService?->archiveActiveForTimesheet($timesheetId, (int) ($before['user_id'] ?? 0) ?: null);
        }
    }

    private function projectSignatureColumns(): array
    {
        $required = $this->connection->columnExists('projects', 'customer_signature_required')
            ? 'COALESCE(projects.customer_signature_required, 0)'
            : '0';
        $name = $this->connection->columnExists('projects', 'customer_signature_name')
            ? 'projects.customer_signature_name'
            : 'NULL';

        return [
            'required' => $required,
            'name' => $name,
        ];
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

    private function assertAccountingWriteAllowed(?array $entry, int $userId, ?int $projectId, string $workDate): void
    {
        if ($entry !== null) {
            $this->assertTimesheetNotLockedByAccountingClosure((int) ($entry['id'] ?? 0));
        }

        $this->assertAccountingPeriodOpen($userId, $projectId, $workDate);
    }

    private function assertTimesheetNotLockedByAccountingClosure(int $timesheetId): void
    {
        if ($timesheetId <= 0
            || !$this->connection->tableExists('accounting_closures')
            || !$this->connection->tableExists('accounting_closure_items')) {
            return;
        }

        $locked = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_closure_items
             INNER JOIN accounting_closures ON accounting_closures.id = accounting_closure_items.closure_id
             WHERE accounting_closure_items.timesheet_id = :timesheet_id
               AND accounting_closures.status IN ("final", "correction")',
            ['timesheet_id' => $timesheetId]
        ) ?? 0) > 0;

        if ($locked) {
            throw new RuntimeException('Diese Buchung ist bereits festgeschrieben. Bitte wenden Sie sich fuer Korrekturen an die Buchhaltung.');
        }
    }

    private function assertAccountingPeriodOpen(int $userId, ?int $projectId, string $workDate): void
    {
        if ($userId <= 0
            || $workDate === ''
            || !$this->connection->tableExists('accounting_closures')) {
            return;
        }

        $locked = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_closures
             WHERE status IN ("final", "correction")
               AND period_start <= :work_date_start
               AND period_end >= :work_date_end
               AND (user_id IS NULL OR user_id = :user_id)
               AND (project_id IS NULL OR project_id = :project_id)',
            [
                'work_date_start' => $workDate,
                'work_date_end' => $workDate,
                'user_id' => $userId,
                'project_id' => $projectId,
            ]
        ) ?? 0) > 0;

        if ($locked) {
            throw new RuntimeException('Dieser Zeitraum ist bereits festgeschrieben. Neue oder geaenderte Buchungen sind gesperrt. Bitte wenden Sie sich an die Buchhaltung.');
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withAccountingWriteLock(callable $callback): mixed
    {
        $locked = (int) ($this->connection->fetchColumn('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => self::ACCOUNTING_WRITE_LOCK]) ?? 0);

        if ($locked !== 1) {
            throw new RuntimeException('Die Abrechnung verarbeitet gerade Buchungen. Bitte erneut versuchen.');
        }

        try {
            return $callback();
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => self::ACCOUNTING_WRITE_LOCK]);
        }
    }

    private function normalizeProjectId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function normalizeSource(mixed $value): string
    {
        $source = trim((string) ($value ?? 'app'));

        return in_array($source, ['app', 'terminal'], true) ? $source : 'app';
    }

    private function assertProjectVisibleToUser(?int $projectId, int $userId, array $permissions, string $workDate): void
    {
        if ($projectId === null) {
            return;
        }

        if (!$this->connection->tableExists('projects')) {
            throw new RuntimeException('Das ausgewaehlte Projekt ist nicht verfuegbar.');
        }

        if (
            in_array('*', $permissions, true)
            || in_array('projects.manage', $permissions, true)
            || in_array('timesheets.manage', $permissions, true)
            || in_array('files.manage', $permissions, true)
        ) {
            $count = $this->connection->fetchColumn(
                'SELECT COUNT(*)
                 FROM projects
                 WHERE id = :project_id
                   AND COALESCE(is_deleted, 0) = 0
                   AND status <> "archived"
                 LIMIT 1',
                ['project_id' => $projectId]
            );

            if ((int) ($count ?? 0) > 0) {
                return;
            }
        } elseif ($this->connection->tableExists('project_memberships')) {
            $count = $this->connection->fetchColumn(
                'SELECT COUNT(*)
                 FROM project_memberships
                 INNER JOIN projects ON projects.id = project_memberships.project_id
                 WHERE project_memberships.project_id = :project_id
                   AND project_memberships.user_id = :user_id
                   AND COALESCE(projects.is_deleted, 0) = 0
                   AND projects.status <> "archived"
                   AND (project_memberships.assigned_from IS NULL OR project_memberships.assigned_from <= :work_date_from)
                   AND (project_memberships.assigned_until IS NULL OR project_memberships.assigned_until >= :work_date_until)
                 LIMIT 1',
                [
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'work_date_from' => $workDate,
                    'work_date_until' => $workDate,
                ]
            );

            if ((int) ($count ?? 0) > 0) {
                return;
            }
        }

        throw new RuntimeException('Das ausgewaehlte Projekt ist fuer Ihre App nicht freigegeben.');
    }

    private function signatureRelevantChanges(?array $before, array $after): bool
    {
        if ($before === null || !$this->signatureService instanceof TimesheetSignatureService) {
            return false;
        }

        foreach (['project_id', 'start_time', 'end_time', 'break_minutes', 'net_minutes'] as $key) {
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if (in_array($key, ['break_minutes', 'net_minutes'], true)) {
                if ((int) $beforeValue !== (int) $afterValue) {
                    return true;
                }

                continue;
            }

            if ($this->normalizedComparableValue($beforeValue) !== $this->normalizedComparableValue($afterValue)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedComparableValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function normalizeBreakMinutes(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function effectiveBreakMinutes(string $action, array $payload, array $breaks, int $storedBreakMinutes): int
    {
        if ($action === 'pause' && array_key_exists('manual_break_minutes', $payload)) {
            return $this->normalizeBreakMinutes($payload['manual_break_minutes']);
        }

        return $this->workdayStateCalculator->effectiveBreakMinutes(
            ['break_minutes' => $this->normalizeBreakMinutes($storedBreakMinutes)],
            $breaks
        );
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value) ? substr($value, 0, 5) : null;
    }

    private function readStampTime(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $time = $this->normalizeTime($value);
        if ($time === null) {
            throw new InvalidArgumentException('Die angegebene Uhrzeit ist ungültig.');
        }

        return $time;
    }

    private function comparableStamp(?string $value): ?string
    {
        return $value !== null && strlen($value) === 5 ? $value . ':00' : $value;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            $zone = new DateTimeZone('Europe/Berlin');
            return (new DateTimeImmutable($value, $zone))->setTimezone($zone)->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function successMessage(string $action, ?int $projectId): string
    {
        return match ($action) {
            'check_in' => $projectId !== null
                ? 'Check-in erfolgreich erfasst.'
                : 'Check-in ohne Projekt erfolgreich erfasst. Sie koennen die Baustelle spaeter zuordnen.',
            'check_out', 'day_close' => 'Check-out erfolgreich erfasst.',
            'pause', 'pause_record' => 'Pause erfolgreich gespeichert.',
            'select_project' => 'Projekt erfolgreich zugeordnet.',
            'upsert' => 'Zeiteintrag erfolgreich aktualisiert.',
            'pause_start' => 'Pause erfolgreich gestartet.',
            'pause_end' => 'Pause erfolgreich beendet.',
            default => 'Aenderung erfolgreich gespeichert.',
        };
    }

    private function isDuplicateKeyException(\Throwable $throwable): bool
    {
        if (!$throwable instanceof \PDOException) {
            return false;
        }

        $errorInfo = $throwable->errorInfo ?? [];

        return (string) ($errorInfo[0] ?? $throwable->getCode()) === '23000'
            || (int) ($errorInfo[1] ?? 0) === 1062;
    }
}
