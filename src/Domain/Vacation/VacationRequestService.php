<?php

declare(strict_types=1);

namespace App\Domain\Vacation;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\Timesheets\TimesheetDayConflictService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

final class VacationRequestService
{
    private const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public function __construct(
        private DatabaseConnection $connection,
        private CalendarPolicyService $calendarPolicyService,
        private TimesheetWriteGuard $writeGuard,
        private ?DailyTargetService $dailyTargetService = null,
        private ?TimesheetDayConflictService $dayConflictService = null
    ) {
        $this->dailyTargetService ??= new DailyTargetService($calendarPolicyService);
        $this->dayConflictService ??= new TimesheetDayConflictService($connection);
    }

    public function listForUser(int $userId): array
    {
        if ($userId <= 0 || !$this->connection->tableExists('vacation_requests')) {
            return [];
        }

        return array_map(
            fn (array $row): array => $this->normalizeRequestRow($row),
            $this->connection->fetchAll(
                'SELECT vacation_requests.*, users.first_name, users.last_name, users.email
                 FROM vacation_requests
                 INNER JOIN users ON users.id = vacation_requests.user_id
                 WHERE vacation_requests.user_id = :user_id
                   AND COALESCE(vacation_requests.is_deleted, 0) = 0
                 ORDER BY FIELD(vacation_requests.status, "pending", "approved", "rejected", "cancelled"), vacation_requests.date_from DESC, vacation_requests.id DESC',
                ['user_id' => $userId]
            )
        );
    }

    public function listForAdmin(array $filters = []): array
    {
        if (!$this->connection->tableExists('vacation_requests')) {
            return [];
        }

        $where = ['COALESCE(vacation_requests.is_deleted, 0) = 0'];
        $bindings = [];
        $status = trim((string) ($filters['status'] ?? ''));
        $userId = (int) ($filters['user_id'] ?? 0);

        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'vacation_requests.status = :status';
            $bindings['status'] = $status;
        }

        if ($userId > 0) {
            $where[] = 'vacation_requests.user_id = :user_id';
            $bindings['user_id'] = $userId;
        }

        return array_map(
            fn (array $row): array => $this->normalizeRequestRow($row),
            $this->connection->fetchAll(
                'SELECT vacation_requests.*, users.first_name, users.last_name, users.email
                 FROM vacation_requests
                 INNER JOIN users ON users.id = vacation_requests.user_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY FIELD(vacation_requests.status, "pending", "approved", "rejected", "cancelled"), vacation_requests.date_from ASC, vacation_requests.id ASC',
                $bindings
            )
        );
    }

    public function preview(int $userId, array $payload): array
    {
        $dates = $this->normalizedDateRange($payload);
        $user = $this->user($userId);

        if ($user === null) {
            throw new InvalidArgumentException('Der Mitarbeiter wurde nicht gefunden.');
        }

        $workDates = $this->vacationWorkDates($user, $dates['date_from'], $dates['date_to']);

        return [
            'date_from' => $dates['date_from'],
            'date_to' => $dates['date_to'],
            'day_count' => (float) count($workDates),
            'work_dates' => $workDates,
        ];
    }

    public function createForUser(int $userId, array $payload): array
    {
        if (!$this->connection->tableExists('vacation_requests')) {
            throw new InvalidArgumentException('Urlaubsantraege sind noch nicht migriert.');
        }

        $preview = $this->preview($userId, $payload);

        if ((float) $preview['day_count'] <= 0) {
            throw new InvalidArgumentException('Im gewaehlten Zeitraum liegt kein anrechenbarer Arbeitstag.');
        }

        $employeeNote = $this->nullableTrimmed($payload['employee_note'] ?? ($payload['note'] ?? null));

        return $this->withUserVacationLock($userId, function () use ($userId, $preview, $employeeNote): array {
            $this->assertNoOverlappingRequest($userId, $preview['date_from'], $preview['date_to']);

            $this->connection->execute(
                'INSERT INTO vacation_requests (
                    user_id, date_from, date_to, day_count, status, employee_note, decision_note, requested_at, decided_at, decided_by_user_id, created_at, updated_at, is_deleted, deleted_at, deleted_by_user_id
                 ) VALUES (
                    :user_id, :date_from, :date_to, :day_count, "pending", :employee_note, NULL, NOW(), NULL, NULL, NOW(), NOW(), 0, NULL, NULL
                 )',
                [
                    'user_id' => $userId,
                    'date_from' => $preview['date_from'],
                    'date_to' => $preview['date_to'],
                    'day_count' => $preview['day_count'],
                    'employee_note' => $employeeNote,
                ]
            );

            return $this->find((int) $this->connection->lastInsertId()) ?? [];
        });
    }

    public function approve(int $requestId, int $adminUserId, ?string $decisionNote = null): array
    {
        $request = $this->find($requestId);

        if ($request === null) {
            throw new InvalidArgumentException('Der Urlaubsantrag wurde nicht gefunden.');
        }

        if ($request['status'] === 'approved') {
            return $request;
        }

        $this->assertPendingTransition($request, 'approved');
        $userId = (int) $request['user_id'];
        $user = $this->user($userId);

        if ($user === null) {
            throw new InvalidArgumentException('Der Mitarbeiter wurde nicht gefunden.');
        }

        $workDates = $this->vacationWorkDates($user, (string) $request['date_from'], (string) $request['date_to']);

        if ($workDates === []) {
            throw new InvalidArgumentException('Im gewaehlten Zeitraum liegt kein anrechenbarer Arbeitstag.');
        }

        return $this->withUserVacationLock($userId, function () use ($requestId, $userId, $adminUserId, $decisionNote): array {
            return $this->writeGuard->withAccountingWriteLock(function () use ($requestId, $userId, $adminUserId, $decisionNote): array {
                $fresh = $this->find($requestId);

                if ($fresh === null) {
                    throw new InvalidArgumentException('Der Urlaubsantrag wurde nicht gefunden.');
                }

                if ($fresh['status'] === 'approved') {
                    return $fresh;
                }

                $user = $this->user($userId);

                if ($user === null) {
                    throw new InvalidArgumentException('Der Mitarbeiter wurde nicht gefunden.');
                }

                $workDates = $this->vacationWorkDates($user, (string) $fresh['date_from'], (string) $fresh['date_to']);

                if ($workDates === []) {
                    throw new InvalidArgumentException('Im gewaehlten Zeitraum liegt kein anrechenbarer Arbeitstag.');
                }

                $this->assertPendingTransition($fresh, 'approved');
                $this->assertNoOverlappingRequest($userId, (string) $fresh['date_from'], (string) $fresh['date_to'], $requestId);
                $this->writeGuard->assertAccountingPeriodsOpen($userId, $workDates);
                $this->dayConflictService?->assertNoConflictsForVacationRequest($userId, $workDates, $requestId);

                $this->connection->transaction(function ($pdo) use ($requestId, $adminUserId, $decisionNote, $workDates, $userId): void {
                    $updated = $this->executeAffected(
                        $pdo,
                        'UPDATE vacation_requests
                         SET status = "approved",
                             day_count = :day_count,
                             decision_note = :decision_note,
                             decided_at = NOW(),
                             decided_by_user_id = :decided_by_user_id,
                             updated_at = NOW()
                         WHERE id = :id AND status = "pending" AND COALESCE(is_deleted, 0) = 0',
                        [
                            'id' => $requestId,
                            'day_count' => count($workDates),
                            'decision_note' => $this->nullableTrimmed($decisionNote),
                            'decided_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                        ]
                    );

                    if ($updated !== 1) {
                        throw new InvalidArgumentException('Dieser Statusuebergang ist fuer Urlaubsantraege in V1 nicht erlaubt.');
                    }

                    foreach ($workDates as $workDate) {
                        $this->insertVacationTimesheet($requestId, $userId, $adminUserId, $workDate);
                    }
                });

                return $this->find($requestId) ?? [];
            });
        });
    }

    public function reject(int $requestId, int $adminUserId, ?string $decisionNote = null): array
    {
        $request = $this->find($requestId);

        if ($request === null) {
            throw new InvalidArgumentException('Der Urlaubsantrag wurde nicht gefunden.');
        }

        $this->assertPendingTransition($request, 'rejected');

        $this->withUserVacationLock((int) $request['user_id'], function () use ($requestId, $adminUserId, $decisionNote): void {
            $fresh = $this->find($requestId);

            if ($fresh === null) {
                throw new InvalidArgumentException('Der Urlaubsantrag wurde nicht gefunden.');
            }

            $this->assertPendingTransition($fresh, 'rejected');

            $this->connection->transaction(function ($pdo) use ($requestId, $adminUserId, $decisionNote): void {
                $updated = $this->executeAffected(
                    $pdo,
                    'UPDATE vacation_requests
                     SET status = "rejected",
                         decision_note = :decision_note,
                         decided_at = NOW(),
                         decided_by_user_id = :decided_by_user_id,
                         updated_at = NOW()
                     WHERE id = :id AND status = "pending" AND COALESCE(is_deleted, 0) = 0',
                    [
                        'id' => $requestId,
                        'decision_note' => $this->nullableTrimmed($decisionNote),
                        'decided_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                    ]
                );

                if ($updated !== 1) {
                    throw new InvalidArgumentException('Dieser Statusuebergang ist fuer Urlaubsantraege in V1 nicht erlaubt.');
                }
            });
        });

        return $this->find($requestId) ?? [];
    }

    public function cancelOwnPending(int $requestId, int $userId): array
    {
        $request = $this->find($requestId);

        if ($request === null || (int) $request['user_id'] !== $userId) {
            throw new InvalidArgumentException('Der Urlaubsantrag wurde nicht gefunden.');
        }

        $this->assertPendingTransition($request, 'cancelled');

        $this->withUserVacationLock($userId, function () use ($requestId, $userId): void {
            $fresh = $this->find($requestId);

            if ($fresh === null || (int) $fresh['user_id'] !== $userId) {
                throw new InvalidArgumentException('Der Urlaubsantrag wurde nicht gefunden.');
            }

            $this->assertPendingTransition($fresh, 'cancelled');

            $this->connection->transaction(function ($pdo) use ($requestId, $userId): void {
                $updated = $this->executeAffected(
                    $pdo,
                    'UPDATE vacation_requests
                     SET status = "cancelled", updated_at = NOW()
                     WHERE id = :id AND user_id = :user_id AND status = "pending" AND COALESCE(is_deleted, 0) = 0',
                    [
                        'id' => $requestId,
                        'user_id' => $userId,
                    ]
                );

                if ($updated !== 1) {
                    throw new InvalidArgumentException('Dieser Statusuebergang ist fuer Urlaubsantraege in V1 nicht erlaubt.');
                }
            });
        });

        return $this->find($requestId) ?? [];
    }

    public function find(int $requestId): ?array
    {
        if ($requestId <= 0 || !$this->connection->tableExists('vacation_requests')) {
            return null;
        }

        $row = $this->connection->fetchOne(
            'SELECT vacation_requests.*, users.first_name, users.last_name, users.email
             FROM vacation_requests
             INNER JOIN users ON users.id = vacation_requests.user_id
             WHERE vacation_requests.id = :id
               AND COALESCE(vacation_requests.is_deleted, 0) = 0
             LIMIT 1',
            ['id' => $requestId]
        );

        return $row === null ? null : $this->normalizeRequestRow($row);
    }

    private function insertVacationTimesheet(int $requestId, int $userId, int $adminUserId, string $workDate): void
    {
        $user = $this->user($userId) ?? [];
        $creditedMinutes = $this->dailyTargetService?->effectiveTargetForDate($user, $workDate) ?? 0;
        $columns = [
            'user_id',
            'project_id',
            'created_by_user_id',
            'work_date',
            'start_time',
            'end_time',
            'gross_minutes',
            'break_minutes',
            'net_minutes',
            'expenses_amount',
            'entry_type',
        ];
        $values = [
            ':user_id',
            'NULL',
            ':created_by_user_id',
            ':work_date',
            'NULL',
            'NULL',
            '0',
            '0',
            '0',
            '0',
            '"vacation"',
        ];
        $bindings = [
            'user_id' => $userId,
            'created_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
            'work_date' => $workDate,
            'note' => 'Urlaub aus Antrag #' . $requestId,
        ];

        if ($this->connection->columnExists('timesheets', 'source')) {
            $columns[] = 'source';
            $values[] = '"vacation_request"';
        }

        if ($this->connection->columnExists('timesheets', 'credited_minutes')) {
            $columns[] = 'credited_minutes';
            $values[] = ':credited_minutes';
            $bindings['credited_minutes'] = $creditedMinutes;
        }

        if ($this->connection->columnExists('timesheets', 'absence_reason_code')) {
            $columns[] = 'absence_reason_code';
            $values[] = '"vacation_paid"';
        }

        if ($this->connection->columnExists('timesheets', 'vacation_request_id')) {
            $columns[] = 'vacation_request_id';
            $values[] = ':vacation_request_id';
            $bindings['vacation_request_id'] = $requestId;
        }

        $columns[] = 'note';
        $values[] = ':note';
        $columns[] = 'created_at';
        $values[] = 'NOW()';
        $columns[] = 'updated_at';
        $values[] = 'NOW()';

        $this->connection->execute(
            'INSERT INTO timesheets (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')',
            $bindings
        );
    }

    private function assertNoTimesheetConflicts(int $userId, array $workDates, int $requestId): void
    {
        if ($workDates === [] || !$this->connection->tableExists('timesheets')) {
            return;
        }

        $placeholders = [];
        $bindings = ['user_id' => $userId];

        foreach ($workDates as $index => $workDate) {
            $key = 'work_date_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $workDate;
        }

        $vacationRequestClause = '';

        if ($this->connection->columnExists('timesheets', 'vacation_request_id')) {
            $vacationRequestClause = ' AND (vacation_request_id IS NULL OR vacation_request_id <> :request_id)';
            $bindings['request_id'] = $requestId;
        }

        $conflict = $this->connection->fetchOne(
            'SELECT work_date, entry_type
             FROM timesheets
             WHERE user_id = :user_id
               AND work_date IN (' . implode(', ', $placeholders) . ')
               AND COALESCE(is_deleted, 0) = 0' . $vacationRequestClause . '
             ORDER BY work_date ASC
             LIMIT 1',
            $bindings
        );

        if ($conflict !== null) {
            throw new InvalidArgumentException('Am ' . (string) $conflict['work_date'] . ' existiert bereits eine aktive Buchung.');
        }
    }

    private function assertNoOverlappingRequest(int $userId, string $dateFrom, string $dateTo, ?int $excludeRequestId = null): void
    {
        if (!$this->connection->tableExists('vacation_requests')) {
            return;
        }

        $sql = 'SELECT id, date_from, date_to, status
                FROM vacation_requests
                WHERE user_id = :user_id
                  AND status IN ("pending", "approved")
                  AND date_from <= :date_to
                  AND date_to >= :date_from
                  AND COALESCE(is_deleted, 0) = 0';
        $bindings = [
            'user_id' => $userId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        if ($excludeRequestId !== null) {
            $sql .= ' AND id <> :exclude_request_id';
            $bindings['exclude_request_id'] = $excludeRequestId;
        }

        $sql .= ' ORDER BY date_from ASC LIMIT 1';
        $overlap = $this->connection->fetchOne($sql, $bindings);

        if ($overlap !== null) {
            throw new InvalidArgumentException('Der Zeitraum ueberschneidet sich mit Urlaubsantrag #' . (int) $overlap['id'] . ' (' . (string) $overlap['status'] . ').');
        }
    }

    private function assertPendingTransition(array $request, string $targetStatus): void
    {
        if (!in_array($targetStatus, ['approved', 'rejected', 'cancelled'], true) || (string) ($request['status'] ?? '') !== 'pending') {
            throw new InvalidArgumentException('Dieser Statusuebergang ist fuer Urlaubsantraege in V1 nicht erlaubt.');
        }
    }

    private function withUserVacationLock(int $userId, callable $callback): mixed
    {
        $lockName = 'vacation-request-user-' . max(0, $userId);
        $locked = (int) ($this->connection->fetchColumn('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $lockName]) ?? 0);

        if ($locked !== 1) {
            throw new InvalidArgumentException('Urlaubsantraege fuer diesen Mitarbeiter werden gerade verarbeitet. Bitte erneut versuchen.');
        }

        try {
            return $callback();
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName]);
        }
    }

    private function executeAffected(\PDO $pdo, string $sql, array $params): int
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    private function vacationWorkDates(array $user, string $dateFrom, string $dateTo): array
    {
        $start = new DateTimeImmutable($dateFrom);
        $end = new DateTimeImmutable($dateTo);
        $workdays = $this->workdays($user);
        $dates = [];

        foreach (new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day')) as $date) {
            if (!in_array((int) $date->format('N'), $workdays, true)) {
                continue;
            }

            $dateString = $date->format('Y-m-d');
            $policy = $this->calendarPolicyService->dayPolicy($dateString);

            if ((bool) ($policy['is_public_holiday'] ?? false) || (bool) ($policy['is_company_closure'] ?? false)) {
                continue;
            }

            if ((int) ($this->dailyTargetService?->effectiveTargetForDate($user, $dateString) ?? 0) <= 0) {
                continue;
            }

            $dates[] = $dateString;
        }

        return $dates;
    }

    private function normalizedDateRange(array $payload): array
    {
        $dateFrom = $this->normalizeDate($payload['date_from'] ?? null);
        $dateTo = $this->normalizeDate($payload['date_to'] ?? null);

        if ($dateFrom === null || $dateTo === null) {
            throw new InvalidArgumentException('Bitte einen gueltigen Zeitraum angeben.');
        }

        if ($dateTo < $dateFrom) {
            throw new InvalidArgumentException('Das Enddatum darf nicht vor dem Startdatum liegen.');
        }

        return ['date_from' => $dateFrom, 'date_to' => $dateTo];
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

        return null;
    }

    private function user(int $userId): ?array
    {
        if ($userId <= 0 || !$this->connection->tableExists('users')) {
            return null;
        }

        $columns = [
            'id',
            'first_name',
            'last_name',
            'email',
            $this->columnOrLiteral('target_hours_month', '0', 'target_hours_month'),
            $this->columnOrLiteral('target_hours_mode', '"month"', 'target_hours_mode'),
            $this->columnOrLiteral('target_hours_week', 'NULL', 'target_hours_week'),
            $this->columnOrLiteral('workdays_mask', '"1,2,3,4,5"', 'workdays_mask'),
        ];

        return $this->connection->fetchOne(
            'SELECT ' . implode(', ', $columns) . ' FROM users WHERE id = :id AND COALESCE(is_deleted, 0) = 0 LIMIT 1',
            ['id' => $userId]
        );
    }

    private function columnOrLiteral(string $column, string $literal, string $alias): string
    {
        return $this->connection->columnExists('users', $column)
            ? $column . ' AS ' . $alias
            : $literal . ' AS ' . $alias;
    }

    private function workdays(array $user): array
    {
        $values = preg_split('/[,\s;|]+/', trim((string) ($user['workdays_mask'] ?? '1,2,3,4,5'))) ?: [];
        $days = [];

        foreach ($values as $value) {
            $day = (int) $value;

            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    private function normalizeRequestRow(array $row): array
    {
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'employee_name' => $name !== '' ? $name : (string) ($row['email'] ?? ''),
            'date_from' => (string) ($row['date_from'] ?? ''),
            'date_to' => (string) ($row['date_to'] ?? ''),
            'day_count' => (float) ($row['day_count'] ?? 0),
            'status' => in_array((string) ($row['status'] ?? ''), self::STATUSES, true) ? (string) $row['status'] : 'pending',
            'employee_note' => $this->nullableTrimmed($row['employee_note'] ?? null),
            'decision_note' => $this->nullableTrimmed($row['decision_note'] ?? null),
            'requested_at' => (string) ($row['requested_at'] ?? ''),
            'decided_at' => $row['decided_at'] ?? null,
            'decided_by_user_id' => isset($row['decided_by_user_id']) ? (int) $row['decided_by_user_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'is_deleted' => (int) ($row['is_deleted'] ?? 0),
        ];
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
