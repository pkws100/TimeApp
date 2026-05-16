<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use InvalidArgumentException;

final class AdminBookingService
{
    public function __construct(
        private DatabaseConnection $connection,
        private TimesheetCalculator $calculator
    ) {
    }

    public function activeCount(): int
    {
        if (!$this->connection->tableExists('timesheets')) {
            return 0;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM timesheets WHERE ' . $this->scopeClause('active')
        ) ?? 0);
    }

    public function normalizeFilters(array $input, ?int $forcedProjectId = null): array
    {
        $scope = (string) ($input['scope'] ?? 'active');
        $scope = in_array($scope, ['active', 'archived', 'all'], true) ? $scope : 'active';

        $entryType = trim((string) ($input['entry_type'] ?? ''));
        $allowedEntryTypes = ['work', 'sick', 'vacation', 'holiday', 'absent'];
        $entryType = in_array($entryType, $allowedEntryTypes, true) ? $entryType : '';

        $projectFilter = $forcedProjectId !== null ? (string) $forcedProjectId : trim((string) ($input['project_id'] ?? ''));
        $userFilter = trim((string) ($input['user_id'] ?? ''));

        return [
            'date_from' => $this->normalizeDate($input['date_from'] ?? null),
            'date_to' => $this->normalizeDate($input['date_to'] ?? null),
            'project_id' => $this->normalizeProjectFilter($projectFilter),
            'user_id' => $this->normalizePositiveIntOrEmpty($userFilter),
            'entry_type' => $entryType,
            'scope' => $scope,
        ];
    }

    public function list(array $filters): array
    {
        if (!$this->connection->tableExists('timesheets')) {
            return [];
        }

        [$where, $bindings] = $this->buildFilterClause($filters);
        $sourceSelect = $this->hasTimesheetSourceColumn()
            ? 'COALESCE(timesheets.source, "app") AS source,'
            : '"app" AS source,';
        $auditSelect = '0 AS change_count, NULL AS last_change_at, NULL AS last_action_type, NULL AS last_change_reason';
        $auditJoin = '';

        if ($this->connection->tableExists('timesheet_change_log')) {
            $auditSelect = 'audit.change_count, audit.last_change_at, audit.last_action_type, audit.last_change_reason';
            [$auditWhere, $auditBindings] = $this->buildFilterClause($filters, 'audit_timesheets', 'audit_');
            $bindings = [...$bindings, ...$auditBindings];
            $auditJoin = 'LEFT JOIN (
                SELECT
                    change_log.timesheet_id,
                    COUNT(*) AS change_count,
                    MAX(change_log.created_at) AS last_change_at,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(change_log.action_type ORDER BY change_log.created_at DESC SEPARATOR "||"),
                        "||",
                        1
                    ) AS last_action_type,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(change_log.change_reason ORDER BY change_log.created_at DESC SEPARATOR "||"),
                        "||",
                        1
                    ) AS last_change_reason
                FROM timesheet_change_log AS change_log
                INNER JOIN timesheets AS audit_timesheets ON audit_timesheets.id = change_log.timesheet_id
                WHERE ' . $auditWhere . '
                GROUP BY change_log.timesheet_id
            ) AS audit ON audit.timesheet_id = timesheets.id';
        }

        $sql = 'SELECT
                timesheets.id,
                timesheets.user_id,
                timesheets.project_id,
                timesheets.work_date,
                timesheets.start_time,
                timesheets.end_time,
                timesheets.gross_minutes,
                timesheets.break_minutes,
                timesheets.net_minutes,
                timesheets.expenses_amount,
                timesheets.entry_type,
                ' . $sourceSelect . '
                timesheets.note,
                timesheets.updated_at,
                COALESCE(timesheets.is_deleted, 0) AS is_deleted,
                timesheets.deleted_at,
                timesheets.deleted_by_user_id,
                users.employee_number,
                users.first_name,
                users.last_name,
                COALESCE(users.is_deleted, 0) AS user_is_deleted,
                projects.project_number,
                projects.name AS project_name,
                COALESCE(projects.is_deleted, 0) AS project_is_deleted,
                ' . $auditSelect . '
            FROM timesheets
            LEFT JOIN users ON users.id = timesheets.user_id
            LEFT JOIN projects ON projects.id = timesheets.project_id
            ' . $auditJoin . '
            WHERE ' . $where . '
            ORDER BY timesheets.work_date DESC, timesheets.start_time DESC, timesheets.id DESC';

        $rows = $this->connection->fetchAll($sql, $bindings);

        return array_map(fn (array $row): array => $this->hydrateBookingRow($row), $rows);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !$this->connection->tableExists('timesheets')) {
            return null;
        }

        $auditSelect = '0 AS change_count, NULL AS last_change_at, NULL AS last_action_type, NULL AS last_change_reason';
        $auditJoin = '';
        $bindings = ['id' => $id];

        if ($this->connection->tableExists('timesheet_change_log')) {
            $auditSelect = 'audit.change_count, audit.last_change_at, audit.last_action_type, audit.last_change_reason';
            $auditJoin = 'LEFT JOIN (
                SELECT
                    change_log.timesheet_id,
                    COUNT(*) AS change_count,
                    MAX(change_log.created_at) AS last_change_at,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(change_log.action_type ORDER BY change_log.created_at DESC SEPARATOR "||"),
                        "||",
                        1
                    ) AS last_action_type,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(change_log.change_reason ORDER BY change_log.created_at DESC SEPARATOR "||"),
                        "||",
                        1
                    ) AS last_change_reason
                FROM timesheet_change_log AS change_log
                WHERE change_log.timesheet_id = :audit_id
                GROUP BY change_log.timesheet_id
             ) AS audit ON audit.timesheet_id = timesheets.id';
            $bindings['audit_id'] = $id;
        }

        $rows = $this->connection->fetchAll(
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
                timesheets.expenses_amount,
                timesheets.entry_type,
                ' . ($this->hasTimesheetSourceColumn() ? 'COALESCE(timesheets.source, "app")' : '"app"') . ' AS source,
                timesheets.note,
                timesheets.updated_at,
                COALESCE(timesheets.is_deleted, 0) AS is_deleted,
                timesheets.deleted_at,
                timesheets.deleted_by_user_id,
                users.employee_number,
                users.first_name,
                users.last_name,
                COALESCE(users.is_deleted, 0) AS user_is_deleted,
                projects.project_number,
                projects.name AS project_name,
                COALESCE(projects.is_deleted, 0) AS project_is_deleted,
                ' . $auditSelect . '
             FROM timesheets
             LEFT JOIN users ON users.id = timesheets.user_id
             LEFT JOIN projects ON projects.id = timesheets.project_id
             ' . $auditJoin . '
             WHERE timesheets.id = :id
             LIMIT 1',
            $bindings
        );

        if ($rows === []) {
            return null;
        }

        return $this->hydrateBookingRow($rows[0]);
    }

    public function createManual(array $payload, int $adminUserId, ?int $forcedProjectId = null): array
    {
        $reason = trim((string) ($payload['change_reason'] ?? ''));

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine fachliche Begruendung angeben.');
        }

        $normalized = $this->normalizeManualCreatePayload($payload, $forcedProjectId);
        $this->assertActiveUserExists((int) $normalized['user_id']);

        if (!$this->connection->tableExists('timesheets')) {
            throw new InvalidArgumentException('Die Buchungstabelle ist nicht verfuegbar.');
        }

        $bookingId = 0;

        $this->connection->transaction(function () use ($normalized, $adminUserId, $reason, &$bookingId): void {
            $this->connection->execute(
                'INSERT INTO timesheets (
                    user_id, project_id, created_by_user_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, expenses_amount, entry_type, source, note, created_at, updated_at
                ) VALUES (
                    :user_id, :project_id, :created_by_user_id, :work_date, :start_time, :end_time, :gross_minutes, :break_minutes, :net_minutes, 0, :entry_type, "admin", :note, NOW(), NOW()
                )',
                [
                    ...$normalized,
                    'created_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                ]
            );

            $bookingId = $this->connection->lastInsertId();
            $after = $this->find($bookingId);
            $this->logChange($bookingId, 'manual_created', $adminUserId, $reason, null, $after);
        });

        $booking = $this->find($bookingId);

        if ($booking === null) {
            throw new InvalidArgumentException('Die nacherfasste Buchung konnte nicht geladen werden.');
        }

        return $booking;
    }

    public function update(int $id, array $payload, int $changedByUserId, string $reason): ?array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine fachliche Begruendung angeben.');
        }

        $before = $this->find($id);

        if ($before === null) {
            throw new InvalidArgumentException('Die Buchung wurde nicht gefunden.');
        }

        $normalized = $this->normalizeBookingPayload($payload, $before);

        $this->connection->transaction(function () use ($id, $normalized, $changedByUserId, $reason, $before): void {
            $this->connection->execute(
                'UPDATE timesheets SET
                    project_id = :project_id,
                    work_date = :work_date,
                    start_time = :start_time,
                    end_time = :end_time,
                    gross_minutes = :gross_minutes,
                    break_minutes = :break_minutes,
                    net_minutes = :net_minutes,
                    entry_type = :entry_type,
                    note = :note,
                    updated_at = NOW()
                 WHERE id = :id',
                [
                    ...$normalized,
                    'id' => $id,
                ]
            );

            $after = $this->find($id);
            $this->logChange($id, 'updated', $changedByUserId, $reason, $before, $after);
        });

        return $this->find($id);
    }

    public function bulkAssign(array $bookingIds, ?int $projectId, int $changedByUserId, string $reason): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine fachliche Begruendung angeben.');
        }

        $bookingIds = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $bookingIds), static fn (int $id): bool => $id > 0)));

        if ($bookingIds === []) {
            throw new InvalidArgumentException('Bitte mindestens eine Buchung auswaehlen.');
        }

        $this->assertProjectExists($projectId);

        $updated = 0;

        $this->connection->transaction(function () use ($bookingIds, $projectId, $changedByUserId, $reason, &$updated): void {
            foreach ($bookingIds as $bookingId) {
                $before = $this->find($bookingId);

                if ($before === null) {
                    continue;
                }

                $this->connection->execute(
                    'UPDATE timesheets SET project_id = :project_id, updated_at = NOW() WHERE id = :id',
                    [
                        'id' => $bookingId,
                        'project_id' => $projectId,
                    ]
                );

                $after = $this->find($bookingId);
                $this->logChange($bookingId, 'bulk_project_reassigned', $changedByUserId, $reason, $before, $after);
                $updated++;
            }
        });

        return $updated;
    }

    public function archive(int $id, int $changedByUserId, string $reason): void
    {
        $this->setArchivedState($id, true, $changedByUserId, $reason);
    }

    public function restore(int $id, int $changedByUserId, string $reason): void
    {
        $this->setArchivedState($id, false, $changedByUserId, $reason);
    }

    public function exportRows(array $filters): array
    {
        return array_map(
            fn (array $row): array => [
                'Datum' => (string) ($row['work_date'] ?? ''),
                'Mitarbeiter' => (string) ($row['employee_name'] ?? ''),
                'Mitarbeiter-Nr' => (string) ($row['employee_number'] ?? ''),
                'Projekt' => (string) ($row['project_name_display'] ?? ''),
                'Projekt-Nr' => (string) ($row['project_number'] ?? ''),
                'Typ' => (string) ($row['entry_type'] ?? ''),
                'Herkunft' => (string) ($row['source_label'] ?? $this->sourceLabel((string) ($row['source'] ?? 'app'))),
                'Start' => (string) ($row['start_time'] ?? ''),
                'Ende' => (string) ($row['end_time'] ?? ''),
                'Pause (Min)' => (int) ($row['break_minutes'] ?? 0),
                'Netto (Min)' => (int) ($row['net_minutes'] ?? 0),
                'Status' => (int) ($row['is_deleted'] ?? 0) === 1 ? 'Archiviert' : 'Aktiv',
                'Aenderungen' => (int) ($row['change_count'] ?? 0),
                'Hinweis' => (string) ($row['version_hint'] ?? ''),
                'Notiz' => (string) ($row['note'] ?? ''),
            ],
            $this->list($filters)
        );
    }

    public function accountingRows(string $period, string $profile = 'basic', bool $includeArchived = false): array
    {
        if ($profile !== 'basic') {
            throw new InvalidArgumentException('Nur das Exportprofil "basic" wird aktuell unterstuetzt.');
        }

        $range = $this->periodRange($period);
        $rows = $this->list([
            'date_from' => $range['start']->format('Y-m-d'),
            'date_to' => $range['end']->format('Y-m-d'),
            'project_id' => '',
            'user_id' => '',
            'entry_type' => '',
            'scope' => $includeArchived ? 'all' : 'active',
        ]);

        return array_map(
            static fn (array $row): array => [
                'work_date' => (string) ($row['work_date'] ?? ''),
                'employee_number' => (string) ($row['employee_number'] ?? ''),
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'project_number' => (string) ($row['project_number'] ?? ''),
                'project_name' => (string) ($row['project_name_display'] ?? ''),
                'entry_type' => (string) ($row['entry_type'] ?? ''),
                'source' => (string) ($row['source'] ?? 'app'),
                'source_label' => (string) ($row['source_label'] ?? 'App'),
                'gross_minutes' => (int) ($row['gross_minutes'] ?? 0),
                'break_minutes' => (int) ($row['break_minutes'] ?? 0),
                'net_minutes' => (int) ($row['net_minutes'] ?? 0),
                'expenses_amount' => (string) ($row['expenses_amount'] ?? '0.00'),
                'note' => (string) ($row['note'] ?? ''),
                'booking_status' => (int) ($row['is_deleted'] ?? 0) === 1 ? 'archived' : 'active',
            ],
            $rows
        );
    }

    public function entryTypeOptions(): array
    {
        return [
            'work' => 'Arbeit',
            'sick' => 'Krank',
            'vacation' => 'Urlaub',
            'holiday' => 'Feiertag',
            'absent' => 'Fehlt',
        ];
    }

    private function setArchivedState(int $id, bool $archived, int $changedByUserId, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine fachliche Begruendung angeben.');
        }

        $before = $this->find($id);

        if ($before === null) {
            throw new InvalidArgumentException('Die Buchung wurde nicht gefunden.');
        }

        $this->connection->transaction(function () use ($id, $archived, $changedByUserId, $reason, $before): void {
            $this->connection->execute(
                'UPDATE timesheets SET
                    is_deleted = :is_deleted,
                    deleted_at = :deleted_at,
                    deleted_by_user_id = :deleted_by_user_id,
                    updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $id,
                    'is_deleted' => $archived ? 1 : 0,
                    'deleted_at' => $archived ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                    'deleted_by_user_id' => $archived ? $changedByUserId : null,
                ]
            );

            $after = $this->find($id);
            $this->logChange($id, $archived ? 'archived' : 'restored', $changedByUserId, $reason, $before, $after);
        });
    }

    private function normalizeManualCreatePayload(array $payload, ?int $forcedProjectId = null): array
    {
        $userId = (int) $this->normalizePositiveIntOrEmpty((string) ($payload['user_id'] ?? ''));

        if ($userId <= 0) {
            throw new InvalidArgumentException('Bitte einen Mitarbeiter auswaehlen.');
        }

        $entryType = trim((string) ($payload['entry_type'] ?? 'work'));
        $entryType = array_key_exists($entryType, $this->entryTypeOptions()) ? $entryType : 'work';
        $workDate = $this->normalizeDate($payload['work_date'] ?? null);

        if ($workDate === null) {
            throw new InvalidArgumentException('Bitte ein gueltiges Datum angeben.');
        }

        $startTime = $this->normalizeTime($payload['start_time'] ?? null);
        $endTime = $this->normalizeTime($payload['end_time'] ?? null);
        $manualBreakMinutes = max(0, (int) ($payload['break_minutes'] ?? 0));
        $note = self::nullableTrimmed($payload['note'] ?? null);

        if ($entryType === 'work') {
            if ($startTime === null || $endTime === null) {
                throw new InvalidArgumentException('Start- und Endzeit sind fuer Arbeitsbuchungen erforderlich.');
            }

            $calculation = $this->calculator->calculate($workDate, $startTime, $endTime, $manualBreakMinutes, $entryType);
            $grossMinutes = (int) ($calculation['gross_minutes'] ?? 0);
            $breakMinutes = (int) ($calculation['break_minutes'] ?? $manualBreakMinutes);
            $netMinutes = (int) ($calculation['net_minutes'] ?? 0);
        } else {
            $startTime = null;
            $endTime = null;
            $grossMinutes = 0;
            $breakMinutes = 0;
            $netMinutes = 0;
        }

        $projectId = $forcedProjectId ?? $this->normalizeProjectValue($payload['project_id'] ?? null);
        $this->assertProjectExists($projectId);

        return [
            'user_id' => $userId,
            'project_id' => $projectId,
            'work_date' => $workDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'gross_minutes' => $grossMinutes,
            'break_minutes' => $breakMinutes,
            'net_minutes' => $netMinutes,
            'entry_type' => $entryType,
            'note' => $note,
        ];
    }

    private function normalizeBookingPayload(array $payload, array $before): array
    {
        $entryType = trim((string) ($payload['entry_type'] ?? ($before['entry_type'] ?? 'work')));
        $entryType = array_key_exists($entryType, $this->entryTypeOptions()) ? $entryType : 'work';
        $workDate = $this->normalizeDate($payload['work_date'] ?? ($before['work_date'] ?? null));

        if ($workDate === null) {
            throw new InvalidArgumentException('Bitte ein gueltiges Datum angeben.');
        }

        $projectId = $this->normalizeProjectValue($payload['project_id'] ?? ($before['project_id'] ?? null));
        $this->assertProjectExists($projectId);
        $startInput = $payload['start_time'] ?? ($before['start_time'] ?? null);
        $endInput = $payload['end_time'] ?? ($before['end_time'] ?? null);
        $startTime = $this->normalizeTime($startInput);
        $endTime = $this->normalizeTime($endInput);

        if ($this->hasFilledTimeInput($startInput) && $startTime === null) {
            throw new InvalidArgumentException('Bitte eine gueltige Startzeit angeben.');
        }

        if ($this->hasFilledTimeInput($endInput) && $endTime === null) {
            throw new InvalidArgumentException('Bitte eine gueltige Endzeit angeben.');
        }

        $manualBreakMinutes = max(0, (int) ($payload['break_minutes'] ?? ($before['break_minutes'] ?? 0)));
        $note = self::nullableTrimmed($payload['note'] ?? ($before['note'] ?? null));

        if ($entryType === 'work' && $startTime !== null && $endTime !== null) {
            $calculation = $this->calculator->calculate($workDate, $startTime, $endTime, $manualBreakMinutes, $entryType);
            $grossMinutes = (int) ($calculation['gross_minutes'] ?? 0);
            $breakMinutes = (int) ($calculation['break_minutes'] ?? $manualBreakMinutes);
            $netMinutes = (int) ($calculation['net_minutes'] ?? 0);
        } elseif ($entryType === 'work') {
            $grossMinutes = 0;
            $breakMinutes = $manualBreakMinutes;
            $netMinutes = 0;
        } else {
            $startTime = null;
            $endTime = null;
            $grossMinutes = 0;
            $breakMinutes = 0;
            $netMinutes = 0;
        }

        return [
            'project_id' => $projectId,
            'work_date' => $workDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'gross_minutes' => $grossMinutes,
            'break_minutes' => $breakMinutes,
            'net_minutes' => $netMinutes,
            'entry_type' => $entryType,
            'note' => $note,
        ];
    }

    private function buildFilterClause(array $filters, string $alias = 'timesheets', string $bindingPrefix = ''): array
    {
        $clauses = [$this->scopeClause((string) ($filters['scope'] ?? 'active'), $alias)];
        $bindings = [];

        if (($filters['date_from'] ?? null) !== null) {
            $key = $bindingPrefix . 'date_from';
            $clauses[] = $alias . '.work_date >= :' . $key;
            $bindings[$key] = $filters['date_from'];
        }

        if (($filters['date_to'] ?? null) !== null) {
            $key = $bindingPrefix . 'date_to';
            $clauses[] = $alias . '.work_date <= :' . $key;
            $bindings[$key] = $filters['date_to'];
        }

        if (($filters['project_id'] ?? '') === '__none__') {
            $clauses[] = $alias . '.project_id IS NULL';
        } elseif (($filters['project_id'] ?? '') !== '') {
            $key = $bindingPrefix . 'project_id';
            $clauses[] = $alias . '.project_id = :' . $key;
            $bindings[$key] = (int) $filters['project_id'];
        }

        if (($filters['user_id'] ?? '') !== '') {
            $key = $bindingPrefix . 'user_id';
            $clauses[] = $alias . '.user_id = :' . $key;
            $bindings[$key] = (int) $filters['user_id'];
        }

        if (($filters['entry_type'] ?? '') !== '') {
            $key = $bindingPrefix . 'entry_type';
            $clauses[] = $alias . '.entry_type = :' . $key;
            $bindings[$key] = $filters['entry_type'];
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    private function scopeClause(string $scope, string $alias = 'timesheets'): string
    {
        return match ($scope) {
            'archived' => 'COALESCE(' . $alias . '.is_deleted, 0) = 1',
            'all' => '1 = 1',
            default => 'COALESCE(' . $alias . '.is_deleted, 0) = 0',
        };
    }

    private function hydrateBookingRow(array $row): array
    {
        $employeeName = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
        $employeeName = $employeeName !== '' ? $employeeName : 'Unbekannter Benutzer';
        $projectId = isset($row['project_id']) ? (int) $row['project_id'] : null;
        $entryType = (string) ($row['entry_type'] ?? 'work');
        $isDeleted = (int) ($row['is_deleted'] ?? 0);

        if ((int) ($row['user_is_deleted'] ?? 0) === 1) {
            $employeeName .= ' (archiviert)';
        }

        $rawProjectName = trim((string) ($row['project_name'] ?? ''));
        $projectName = $rawProjectName;

        if ($projectName === '') {
            $projectName = 'Nicht zugeordnet';
        } elseif ((int) ($row['project_is_deleted'] ?? 0) === 1) {
            $projectName .= ' (archiviert)';
        }

        $changeCount = (int) ($row['change_count'] ?? 0);
        $versionHint = $changeCount > 0
            ? $changeCount . ' Aenderung(en), zuletzt ' . (string) ($row['last_change_at'] ?? '')
            : 'Originalstand';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'project_id' => $projectId,
            'work_date' => (string) ($row['work_date'] ?? ''),
            'start_time' => $row['start_time'] ?? null,
            'end_time' => $row['end_time'] ?? null,
            'gross_minutes' => (int) ($row['gross_minutes'] ?? 0),
            'break_minutes' => (int) ($row['break_minutes'] ?? 0),
            'net_minutes' => (int) ($row['net_minutes'] ?? 0),
            'expenses_amount' => (string) ($row['expenses_amount'] ?? '0.00'),
            'entry_type' => $entryType,
            'source' => (string) ($row['source'] ?? 'app'),
            'source_label' => $this->sourceLabel((string) ($row['source'] ?? 'app')),
            'note' => self::nullableTrimmed($row['note'] ?? null),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'is_deleted' => $isDeleted,
            'deleted_at' => $row['deleted_at'] ?? null,
            'deleted_by_user_id' => isset($row['deleted_by_user_id']) ? (int) $row['deleted_by_user_id'] : null,
            'employee_number' => (string) ($row['employee_number'] ?? ''),
            'employee_name' => $employeeName,
            'project_number' => (string) ($row['project_number'] ?? ''),
            'project_name' => $rawProjectName !== '' ? $rawProjectName : 'Nicht zugeordnet',
            'project_name_display' => $projectName,
            'project_is_deleted' => (int) ($row['project_is_deleted'] ?? 0),
            'needs_project_assignment' => $projectId === null && $entryType === 'work' && $isDeleted === 0,
            'change_count' => $changeCount,
            'last_change_at' => (string) ($row['last_change_at'] ?? ''),
            'last_action_type' => (string) ($row['last_action_type'] ?? ''),
            'last_change_reason' => (string) ($row['last_change_reason'] ?? ''),
            'version_hint' => $versionHint,
        ];
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'admin' => 'Admin-Nacherfassung',
            default => 'App',
        };
    }

    private function logChange(int $timesheetId, string $actionType, int $changedByUserId, string $reason, ?array $before, ?array $after): void
    {
        if (!$this->connection->tableExists('timesheet_change_log')) {
            return;
        }

        $this->connection->execute(
            'INSERT INTO timesheet_change_log (
                timesheet_id,
                action_type,
                changed_by_user_id,
                change_reason,
                before_snapshot,
                after_snapshot,
                created_at
             ) VALUES (
                :timesheet_id,
                :action_type,
                :changed_by_user_id,
                :change_reason,
                :before_snapshot,
                :after_snapshot,
                NOW()
             )',
            [
                'timesheet_id' => $timesheetId,
                'action_type' => $actionType,
                'changed_by_user_id' => $changedByUserId > 0 ? $changedByUserId : null,
                'change_reason' => $reason,
                'before_snapshot' => $before !== null ? json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                'after_snapshot' => $after !== null ? json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }

        try {
            $fallback = new DateTimeImmutable($value);

            return $fallback->format('Y-m-d') === $value ? $fallback->format('Y-m-d') : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function normalizeTime(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        foreach (['!H:i:s', '!H:i'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            $expected = $format === '!H:i:s' ? 'H:i:s' : 'H:i';

            if ($time instanceof DateTimeImmutable && $time->format($expected) === $value && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
                return $time->format('H:i:s');
            }
        }

        return null;
    }

    private function hasFilledTimeInput(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }

    private function normalizeProjectFilter(string $value): string
    {
        if ($value === '__none__') {
            return '__none__';
        }

        $intValue = $this->normalizePositiveIntOrEmpty($value);

        return $intValue === '' ? '' : (string) $intValue;
    }

    private function normalizeProjectValue(mixed $value): ?int
    {
        if ($value === '__none__' || $value === null || $value === '') {
            return null;
        }

        $normalized = $this->normalizePositiveIntOrEmpty((string) $value);

        return $normalized === '' ? null : (int) $normalized;
    }

    private function assertProjectExists(?int $projectId): void
    {
        if ($projectId === null || !$this->connection->tableExists('projects')) {
            return;
        }

        $exists = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM projects WHERE id = :id',
            ['id' => $projectId]
        ) ?? 0);

        if ($exists === 0) {
            throw new InvalidArgumentException('Das gewaehlte Projekt ist nicht vorhanden.');
        }
    }

    private function assertActiveUserExists(int $userId): void
    {
        if ($userId <= 0 || !$this->connection->tableExists('users')) {
            throw new InvalidArgumentException('Bitte einen aktiven Mitarbeiter auswaehlen.');
        }

        $exists = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM users WHERE id = :id AND COALESCE(is_deleted, 0) = 0 AND employment_status = "active"',
            ['id' => $userId]
        ) ?? 0);

        if ($exists === 0) {
            throw new InvalidArgumentException('Bitte einen aktiven Mitarbeiter auswaehlen.');
        }
    }

    private function hasTimesheetSourceColumn(): bool
    {
        if (!$this->connection->tableExists('timesheets')) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = "timesheets"
               AND column_name = "source"'
        ) ?? 0) > 0;
    }

    private function normalizePositiveIntOrEmpty(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $intValue = (int) $value;

        return $intValue > 0 ? (string) $intValue : '';
    }

    private function periodRange(string $period): array
    {
        $today = new DateTimeImmutable('today');

        return match ($period) {
            'day' => ['start' => $today, 'end' => $today],
            'week' => ['start' => $today->modify('monday this week'), 'end' => $today->modify('sunday this week')],
            'year' => [
                'start' => new DateTimeImmutable($today->format('Y-01-01')),
                'end' => new DateTimeImmutable($today->format('Y-12-31')),
            ],
            default => [
                'start' => new DateTimeImmutable($today->format('Y-m-01')),
                'end' => new DateTimeImmutable($today->format('Y-m-t')),
            ],
        };
    }

    private static function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
