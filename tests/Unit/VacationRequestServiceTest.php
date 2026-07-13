<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Domain\Vacation\VacationRequestService;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class VacationRequestServiceTest extends TestCase
{
    public function testCreateCalculatesDayCountServerSideAndRejectsOverlap(): void
    {
        $pdo = new VacationPdoDouble();
        $pdo->holidayRegion = 'NW';
        $pdo->users[1] = $pdo->user();

        $service = $this->service($pdo);
        $request = $service->createForUser(1, [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-04',
            'day_count' => '99',
            'employee_note' => 'Bitte',
        ]);

        self::assertSame(1.0, $request['day_count']);
        self::assertSame('pending', $request['status']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ueberschneidet');
        $service->createForUser(1, ['date_from' => '2026-01-02', 'date_to' => '2026-01-05']);
    }

    public function testApproveCreatesVacationTimesheetsAndIsIdempotent(): void
    {
        $pdo = new VacationPdoDouble();
        $pdo->users[1] = $pdo->user();
        $pdo->vacationRequests[] = $pdo->request(['id' => 7, 'date_from' => '2026-02-02', 'date_to' => '2026-02-03', 'day_count' => '2.00']);

        $service = $this->service($pdo);
        $approved = $service->approve(7, 99, 'OK');
        $approvedAgain = $service->approve(7, 99, 'OK');

        self::assertSame('approved', $approved['status']);
        self::assertSame('approved', $approvedAgain['status']);
        self::assertCount(2, $pdo->timesheets);
        self::assertSame([7, 7], array_column($pdo->timesheets, 'vacation_request_id'));
        self::assertSame(['2026-02-02', '2026-02-03'], array_column($pdo->timesheets, 'work_date'));
        self::assertSame(['vacation_paid', 'vacation_paid'], array_column($pdo->timesheets, 'absence_reason_code'));
        self::assertSame([480, 480], array_column($pdo->timesheets, 'credited_minutes'));
    }

    public function testRejectAndCancelOnlyAllowPendingTransitions(): void
    {
        $pdo = new VacationPdoDouble();
        $pdo->users[1] = $pdo->user();
        $pdo->vacationRequests[] = $pdo->request(['id' => 3, 'status' => 'approved']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Statusuebergang');
        $this->service($pdo)->reject(3, 99, 'Nein');
    }

    public function testEmployeeCanOnlySeeAndCancelOwnPendingRequests(): void
    {
        $pdo = new VacationPdoDouble();
        $pdo->users[1] = $pdo->user();
        $pdo->users[2] = $pdo->user(['id' => 2, 'first_name' => 'Ben']);
        $pdo->vacationRequests[] = $pdo->request(['id' => 1, 'user_id' => 1]);
        $pdo->vacationRequests[] = $pdo->request(['id' => 2, 'user_id' => 2]);

        $service = $this->service($pdo);
        self::assertSame([1], array_column($service->listForUser(1), 'id'));

        $cancelled = $service->cancelOwnPending(1, 1);
        self::assertSame('cancelled', $cancelled['status']);

        $this->expectException(InvalidArgumentException::class);
        $service->cancelOwnPending(2, 1);
    }

    public function testApproveChecksAccountingLocksBeforeWritingTimesheets(): void
    {
        $pdo = new VacationPdoDouble();
        $pdo->users[1] = $pdo->user();
        $pdo->vacationRequests[] = $pdo->request(['id' => 5, 'date_from' => '2026-04-01', 'date_to' => '2026-04-01']);
        $pdo->accountingLockedDates = ['2026-04-01'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('festgeschrieben');
        $this->service($pdo)->approve(5, 99, 'OK');

        self::assertCount(0, $pdo->timesheets);
    }

    public function testApproveRejectsExistingTimesheetConflict(): void
    {
        $pdo = new VacationPdoDouble();
        $pdo->users[1] = $pdo->user();
        $pdo->vacationRequests[] = $pdo->request(['id' => 9, 'date_from' => '2026-05-04', 'date_to' => '2026-05-04']);
        $pdo->timesheets[] = ['id' => 1, 'user_id' => 1, 'work_date' => '2026-05-04', 'entry_type' => 'work', 'is_deleted' => 0, 'vacation_request_id' => null];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bereits eine Arbeitsbuchung');
        $this->service($pdo)->approve(9, 99, 'OK');
    }

    private function service(VacationPdoDouble $pdo): VacationRequestService
    {
        $connection = new DatabaseConnection(['database' => 'test']);
        $property = new \ReflectionProperty(DatabaseConnection::class, 'pdo');
        $property->setAccessible(true);
        $property->setValue($connection, $pdo);

        return new VacationRequestService(
            $connection,
            new CalendarPolicyService($connection),
            new TimesheetWriteGuard($connection)
        );
    }
}

final class VacationPdoDouble extends PDO
{
    public array $tables = [
        'users' => true,
        'timesheets' => true,
        'vacation_requests' => true,
        'company_settings' => true,
        'company_closures' => true,
        'accounting_closures' => true,
    ];
    public array $columns = [
        'users' => ['id', 'first_name', 'last_name', 'email', 'target_hours_month', 'target_hours_mode', 'target_hours_week', 'workdays_mask', 'is_deleted'],
        'timesheets' => ['id', 'source', 'vacation_request_id', 'user_id', 'work_date', 'entry_type', 'is_deleted', 'credited_minutes', 'absence_reason_code'],
        'company_settings' => ['holiday_region'],
    ];
    public array $users = [];
    public array $vacationRequests = [];
    public array $timesheets = [];
    public array $closures = [];
    public array $accountingLockedDates = [];
    public string $holidayRegion = '';
    private int $lastInsertId = 0;
    private bool $inTransaction = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new VacationPdoStatementDouble($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->inTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->lastInsertId;
    }

    public function user(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'first_name' => 'Ada',
            'last_name' => 'Urlaub',
            'email' => 'ada@example.test',
            'target_hours_month' => '0.00',
            'target_hours_mode' => 'week',
            'target_hours_week' => '40.00',
            'workdays_mask' => '1,2,3,4,5',
            'is_deleted' => 0,
        ];
    }

    public function request(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'user_id' => 1,
            'date_from' => '2026-02-02',
            'date_to' => '2026-02-02',
            'day_count' => '1.00',
            'status' => 'pending',
            'employee_note' => null,
            'decision_note' => null,
            'requested_at' => '2026-01-01 08:00:00',
            'decided_at' => null,
            'decided_by_user_id' => null,
            'created_at' => '2026-01-01 08:00:00',
            'updated_at' => '2026-01-01 08:00:00',
            'is_deleted' => 0,
        ];
    }

    public function fetchAllFor(string $sql, array $params): array
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        if (str_contains($sql, 'FROM information_schema.tables')) {
            return [['COUNT(*)' => !empty($this->tables[(string) ($params['table'] ?? '')]) ? 1 : 0]];
        }

        if (str_contains($sql, 'FROM information_schema.columns')) {
            return [['COUNT(*)' => in_array((string) ($params['column'] ?? ''), $this->columns[(string) ($params['table'] ?? '')] ?? [], true) ? 1 : 0]];
        }

        if (str_contains($sql, 'SELECT GET_LOCK')) {
            return [['GET_LOCK' => 1]];
        }

        if (str_contains($sql, 'SELECT RELEASE_LOCK')) {
            return [['RELEASE_LOCK' => 1]];
        }

        if (str_contains($sql, 'FROM accounting_closures')) {
            return [['COUNT(*)' => in_array((string) ($params['work_date_start'] ?? ''), $this->accountingLockedDates, true) ? 1 : 0]];
        }

        if (str_contains($sql, 'SELECT holiday_region FROM company_settings')) {
            return [['holiday_region' => $this->holidayRegion]];
        }

        if (str_contains($sql, 'FROM company_closures') && str_contains($sql, 'year = :year')) {
            $year = (int) ($params['year'] ?? 0);

            return array_values(array_filter($this->closures, static fn (array $closure): bool => (int) ($closure['is_deleted'] ?? 0) === 0 && (int) ($closure['year'] ?? substr((string) $closure['date_from'], 0, 4)) === $year));
        }

        if (str_contains($sql, 'FROM company_closures') && str_contains($sql, 'date_from <= :date_from')) {
            $date = (string) ($params['date_from'] ?? '');

            return array_values(array_filter($this->closures, static fn (array $closure): bool => (int) ($closure['is_deleted'] ?? 0) === 0 && $closure['date_from'] <= $date && $closure['date_to'] >= $date));
        }

        if (str_contains($sql, 'FROM users WHERE id = :id')) {
            $user = $this->users[(int) ($params['id'] ?? 0)] ?? null;

            return $user === null ? [] : [$user];
        }

        if (str_contains($sql, 'vacation_requests.*, users.first_name')) {
            $rows = $this->vacationRequests;

            if (isset($params['id'])) {
                $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['id'] === (int) $params['id']));
            }

            if (isset($params['user_id']) && !isset($params['id'])) {
                $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['user_id'] === (int) $params['user_id']));
            }

            return array_map(function (array $row): array {
                $user = $this->users[(int) $row['user_id']] ?? [];

                return $row + [
                    'first_name' => $user['first_name'] ?? '',
                    'last_name' => $user['last_name'] ?? '',
                    'email' => $user['email'] ?? '',
                ];
            }, $rows);
        }

        if (str_contains($sql, 'FROM timesheets') && str_contains($sql, 'entry_type IN')) {
            $userId = (int) ($params['user_id'] ?? 0);
            $workDate = (string) ($params['work_date'] ?? '');

            return array_values(array_filter($this->timesheets, static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === $userId && (string) ($row['work_date'] ?? '') === $workDate && (int) ($row['is_deleted'] ?? 0) === 0));
        }

        if (str_contains($sql, 'FROM vacation_requests') && str_contains($sql, 'status IN ("pending", "approved")')) {
            $rows = array_values(array_filter($this->vacationRequests, static function (array $row) use ($params): bool {
                if ((int) ($row['user_id'] ?? 0) !== (int) ($params['user_id'] ?? 0)
                    || !in_array((string) ($row['status'] ?? ''), ['pending', 'approved'], true)
                    || (int) ($row['is_deleted'] ?? 0) !== 0
                    || $row['date_from'] > $params['date_to']
                    || $row['date_to'] < $params['date_from']) {
                    return false;
                }

                return !isset($params['exclude_request_id']) || (int) $row['id'] !== (int) $params['exclude_request_id'];
            }));

            return array_slice($rows, 0, 1);
        }

        if (str_contains($sql, 'FROM timesheets') && str_contains($sql, 'work_date IN')) {
            $dates = array_values(array_filter($params, static fn (mixed $value, string $key): bool => str_starts_with($key, 'work_date_'), ARRAY_FILTER_USE_BOTH));
            $rows = array_values(array_filter($this->timesheets, static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === (int) ($params['user_id'] ?? 0)
                && in_array((string) $row['work_date'], $dates, true)
                && (int) ($row['is_deleted'] ?? 0) === 0
                && (!isset($params['request_id']) || (int) ($row['vacation_request_id'] ?? 0) !== (int) $params['request_id'])));

            return array_slice($rows, 0, 1);
        }

        return [];
    }

    public function executeFor(string $sql, array $params): bool
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        if (str_starts_with($sql, 'INSERT INTO vacation_requests')) {
            $this->lastInsertId = max(array_column($this->vacationRequests, 'id') ?: [0]) + 1;
            $this->vacationRequests[] = $this->request([
                'id' => $this->lastInsertId,
                'user_id' => (int) $params['user_id'],
                'date_from' => $params['date_from'],
                'date_to' => $params['date_to'],
                'day_count' => (string) $params['day_count'],
                'employee_note' => $params['employee_note'],
            ]);

            return true;
        }

        if (str_starts_with($sql, 'UPDATE vacation_requests')) {
            foreach ($this->vacationRequests as &$request) {
                if ((int) $request['id'] !== (int) $params['id']) {
                    continue;
                }

                if (str_contains($sql, 'status = "approved"')) {
                    $request['status'] = 'approved';
                    $request['day_count'] = (string) $params['day_count'];
                } elseif (str_contains($sql, 'status = "rejected"')) {
                    $request['status'] = 'rejected';
                } elseif (str_contains($sql, 'status = "cancelled"')) {
                    $request['status'] = 'cancelled';
                }

                $request['decision_note'] = $params['decision_note'] ?? $request['decision_note'];
                $request['decided_by_user_id'] = $params['decided_by_user_id'] ?? $request['decided_by_user_id'];
            }
            unset($request);

            return true;
        }

        if (str_starts_with($sql, 'INSERT INTO timesheets')) {
            $this->lastInsertId = count($this->timesheets) + 1;
            $this->timesheets[] = [
                'id' => $this->lastInsertId,
                'user_id' => (int) $params['user_id'],
                'work_date' => $params['work_date'],
                'entry_type' => 'vacation',
                'source' => 'vacation_request',
                'is_deleted' => 0,
                'vacation_request_id' => (int) ($params['vacation_request_id'] ?? 0),
                'credited_minutes' => (int) ($params['credited_minutes'] ?? 0),
                'absence_reason_code' => (string) ($params['absence_reason_code'] ?? 'vacation_paid'),
            ];

            return true;
        }

        return true;
    }
}

final class VacationPdoStatementDouble extends PDOStatement
{
    private array $params = [];
    private int $rowCount = 0;

    public function __construct(private VacationPdoDouble $pdo, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];

        if (preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $this->sql) === 1) {
            $this->rowCount = $this->pdo->executeFor($this->sql, $this->params) ? 1 : 0;

            return $this->rowCount > 0;
        }

        $this->rowCount = 0;

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->pdo->fetchAllFor($this->sql, $this->params);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $rows = $this->fetchAll();
        $row = $rows[0] ?? [];

        return $row === [] ? false : (array_values($row)[$column] ?? false);
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }
}
