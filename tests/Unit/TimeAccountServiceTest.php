<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class TimeAccountServiceTest extends TestCase
{
    public function testOldMonthlyTargetUserStillCalculatesWorkBookings(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->columns['users'] = ['id', 'first_name', 'last_name', 'email', 'target_hours_month'];
        $pdo->users[1] = $pdo->user(['target_hours_month' => '160.00']);
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-01-02', 'entry_type' => 'work', 'net_minutes' => 240, 'is_deleted' => 0];
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-01-03', 'entry_type' => 'work', 'net_minutes' => 180, 'is_deleted' => 0];

        $account = $this->service($pdo)->monthlyAccount(1, 2026, 1);

        self::assertSame(9600, $account['target_minutes']);
        self::assertSame(420, $account['actual_minutes']);
        self::assertSame(-9180, $account['saldo_minutes']);
    }

    public function testWeekModelReducesTargetOnlyForHolidayAndClosureOnWorkdays(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user([
            'target_hours_mode' => 'week',
            'target_hours_week' => '40.00',
            'workdays_mask' => '1,2,3,4,5',
        ]);
        $pdo->holidayRegion = 'NW';
        $pdo->closures[] = ['title' => 'Werkstatt zu', 'date_from' => '2026-01-05', 'date_to' => '2026-01-05', 'year' => 2026, 'is_deleted' => 0];
        $pdo->closures[] = ['title' => 'Samstag egal', 'date_from' => '2026-01-03', 'date_to' => '2026-01-03', 'year' => 2026, 'is_deleted' => 0];

        $account = $this->service($pdo)->monthlyAccount(1, 2026, 1);

        self::assertSame(22, $account['workday_count']);
        self::assertSame(1.0, $account['holiday_days']);
        self::assertSame(1.0, $account['company_closure_days']);
        self::assertSame(9600, $account['target_minutes']);
    }

    public function testVacationYearSeparatesTakenPendingRemainingAndAvailableDays(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user([
            'vacation_days_year' => '30.00',
            'vacation_carryover_days' => '2.00',
        ]);
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-02-02', 'entry_type' => 'vacation', 'net_minutes' => 0, 'is_deleted' => 0];
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-02-03', 'entry_type' => 'vacation', 'net_minutes' => 0, 'is_deleted' => 0];
        $pdo->vacationRequests[] = ['id' => 1, 'user_id' => 1, 'date_from' => '2026-03-02', 'date_to' => '2026-03-04', 'day_count' => '3.00', 'status' => 'pending', 'is_deleted' => 0];

        $vacation = $this->service($pdo)->vacationYear(1, 2026);

        self::assertSame(30.0, $vacation['entitlement_days']);
        self::assertSame(2.0, $vacation['carryover_days']);
        self::assertSame(2.0, $vacation['approved_taken_days']);
        self::assertSame(3.0, $vacation['pending_days']);
        self::assertSame(30.0, $vacation['remaining_days']);
        self::assertSame(27.0, $vacation['available_days']);
    }

    public function testPendingVacationDaysAreClampedToRequestedYear(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->holidayRegion = 'NW';
        $pdo->users[1] = $pdo->user(['vacation_days_year' => '10.00']);
        $pdo->vacationRequests[] = ['id' => 1, 'user_id' => 1, 'date_from' => '2025-12-31', 'date_to' => '2026-01-02', 'day_count' => '3.00', 'status' => 'pending', 'is_deleted' => 0];

        $vacation = $this->service($pdo)->vacationYear(1, 2026);

        self::assertSame(1.0, $vacation['pending_days']);
        self::assertSame(9.0, $vacation['available_days']);
    }

    public function testEmployeeAccountUsesOnlyCompletedCalendarDays(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user([
            'target_hours_mode' => 'week',
            'target_hours_week' => '40.00',
            'workdays_mask' => '1,2,3,4,5',
        ]);
        $pdo->cutovers[] = [
            'id' => 11,
            'user_id' => 1,
            'effective_from' => '2026-07-01',
            'opening_time_balance_minutes' => 0,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-07-08', 'entry_type' => 'work', 'net_minutes' => 480, 'is_deleted' => 0];
        $service = $this->service($pdo);

        $employee = $service->employeeMonthlyAccount(1, 2026, 7, '2026-07-08');
        $currentDay = $service->monthlyAccount(1, 2026, 7, '2026-07-08');

        self::assertSame('2026-07-07', $employee['employee_balance']['as_of_date']);
        self::assertFalse($employee['employee_balance']['current_day_included']);
        self::assertSame(0, $employee['actual_minutes']);
        self::assertSame(480, $currentDay['actual_minutes']);
        self::assertSame(2400, $employee['target_minutes']);
        self::assertSame(2880, $currentDay['target_minutes']);
        self::assertSame('negative', $employee['employee_balance']['status']);
    }

    public function testEmployeeAccountShowsOpeningBalanceOnCutoverEffectiveDate(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user();
        $pdo->cutovers[] = [
            'id' => 17,
            'user_id' => 1,
            'effective_from' => '2026-07-15',
            'opening_time_balance_minutes' => 90,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];

        $account = $this->service($pdo)->employeeMonthlyAccount(1, 2026, 7, '2026-07-15');

        self::assertSame('2026-07-14', $account['employee_balance']['as_of_date']);
        self::assertSame(90, $account['employee_balance']['minutes']);
        self::assertSame('+01:30', $account['employee_balance']['label']);
        self::assertSame('positive', $account['employee_balance']['status']);
        self::assertFalse($account['employee_balance']['current_day_included']);
    }

    public function testEmployeeAccountKeepsPreviousClosingBalanceOnFirstDayOfMonth(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user();
        $pdo->cutovers[] = [
            'id' => 12,
            'user_id' => 1,
            'effective_from' => '2026-01-01',
            'opening_time_balance_minutes' => 60,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];

        $account = $this->service($pdo)->employeeMonthlyAccount(1, 2026, 2, '2026-02-01');

        self::assertSame('2026-01-31', $account['employee_balance']['as_of_date']);
        self::assertSame(60, $account['employee_balance']['minutes']);
        self::assertSame('+01:00', $account['employee_balance']['label']);
        self::assertSame('positive', $account['employee_balance']['status']);
        self::assertSame(0, $account['period_delta_minutes']);
    }

    public function testPaidVacationCreditsTheCompletedDaysWithoutBecomingWorkTime(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user([
            'target_hours_mode' => 'week',
            'target_hours_week' => '40.00',
            'workdays_mask' => '1,2,3,4,5',
        ]);
        $pdo->cutovers[] = [
            'id' => 14,
            'user_id' => 1,
            'effective_from' => '2026-07-01',
            'opening_time_balance_minutes' => 0,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];
        foreach (['2026-07-01', '2026-07-02'] as $date) {
            $pdo->timesheets[] = [
                'user_id' => 1,
                'work_date' => $date,
                'entry_type' => 'vacation',
                'absence_reason_code' => 'vacation_paid',
                'net_minutes' => 0,
                'credited_minutes' => 480,
                'is_deleted' => 0,
            ];
        }

        $account = $this->service($pdo)->employeeMonthlyAccount(1, 2026, 7, '2026-07-03');

        self::assertSame(960, $account['target_minutes']);
        self::assertSame(960, $account['credited_absence_minutes']);
        self::assertSame(0, $account['actual_work_minutes']);
        self::assertSame(0, $account['period_delta_minutes']);
        self::assertSame('balanced', $account['employee_balance']['status']);
        self::assertSame('00:00', $account['employee_balance']['label']);
    }

    public function testEmployeeAccountDoesNotInventBalanceWithoutActiveCutover(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user();
        $service = $this->service($pdo);

        $missing = $service->employeeMonthlyAccount(1, 2026, 7, '2026-07-15');

        self::assertSame('not_configured', $missing['employee_balance']['status']);
        self::assertNull($missing['employee_balance']['minutes']);
        self::assertNull($missing['employee_balance']['label']);

        $pdo->cutovers[] = [
            'id' => 13,
            'user_id' => 1,
            'effective_from' => '2026-07-16',
            'opening_time_balance_minutes' => 0,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];
        $future = $service->employeeMonthlyAccount(1, 2026, 7, '2026-07-15');

        self::assertSame('not_active', $future['employee_balance']['status']);
        self::assertNull($future['employee_balance']['minutes']);
        self::assertNull($future['employee_balance']['label']);

        $periodBeforeCutover = $service->employeeMonthlyAccount(1, 2026, 6, '2026-08-15');

        self::assertSame('not_active_in_period', $periodBeforeCutover['cutover_status']);
        self::assertSame('not_active', $periodBeforeCutover['employee_balance']['status']);
        self::assertNull($periodBeforeCutover['employee_balance']['minutes']);
    }

    public function testEmployeeAccountClampsFutureMonthToCurrentBerlinMonth(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user();
        $pdo->cutovers[] = [
            'id' => 15,
            'user_id' => 1,
            'effective_from' => '2026-01-01',
            'opening_time_balance_minutes' => 120,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];
        $pdo->timesheets[] = [
            'user_id' => 1,
            'work_date' => '2026-07-31',
            'entry_type' => 'work',
            'net_minutes' => 600,
            'is_deleted' => 0,
        ];

        $account = $this->service($pdo)->employeeMonthlyAccount(1, 2026, 8, '2026-07-15');

        self::assertSame(2026, $account['year']);
        self::assertSame(7, $account['month']);
        self::assertSame('2026-07-14', $account['employee_balance']['as_of_date']);
        self::assertSame(120, $account['employee_balance']['minutes']);
        self::assertSame('+02:00', $account['employee_balance']['label']);
        self::assertSame(0, $account['actual_work_minutes']);
    }

    public function testFirstJanuaryCarriesNonTrivialDecemberClosingBalance(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user([
            'target_hours_mode' => 'week',
            'target_hours_week' => '40.00',
            'workdays_mask' => '1,2,3,4,5',
        ]);
        $pdo->cutovers[] = [
            'id' => 16,
            'user_id' => 1,
            'effective_from' => '2026-12-28',
            'opening_time_balance_minutes' => 120,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-12-28', 'entry_type' => 'work', 'net_minutes' => 480, 'is_deleted' => 0];
        $pdo->timesheets[] = [
            'user_id' => 1,
            'work_date' => '2026-12-29',
            'entry_type' => 'vacation',
            'absence_reason_code' => 'vacation_paid',
            'net_minutes' => 0,
            'credited_minutes' => 480,
            'is_deleted' => 0,
        ];
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-12-30', 'entry_type' => 'work', 'net_minutes' => 600, 'is_deleted' => 0];

        $account = $this->service($pdo)->employeeMonthlyAccount(1, 2027, 1, '2027-01-01');

        self::assertSame('2026-12-31', $account['employee_balance']['as_of_date']);
        self::assertSame(-240, $account['employee_balance']['minutes']);
        self::assertSame('-04:00', $account['employee_balance']['label']);
        self::assertSame('negative', $account['employee_balance']['status']);
        self::assertSame(0, $account['period_delta_minutes']);
    }

    public function testEmployeeCompletedDateStaysLocalAcrossDstAndYearBoundaries(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user();
        $service = $this->service($pdo);

        foreach ([
            ['today' => '2026-03-29', 'year' => 2026, 'month' => 3, 'expected' => '2026-03-28'],
            ['today' => '2026-10-25', 'year' => 2026, 'month' => 10, 'expected' => '2026-10-24'],
            ['today' => '2027-01-01', 'year' => 2027, 'month' => 1, 'expected' => '2026-12-31'],
        ] as $case) {
            $account = $service->employeeMonthlyAccount(1, $case['year'], $case['month'], $case['today']);

            self::assertSame($case['expected'], $account['employee_balance']['as_of_date']);
        }
    }

    public function testAdminOverviewEvaluatesMultipleActiveUsers(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'A']);
        $pdo->users[2] = $pdo->user(['id' => 2, 'first_name' => 'Ben', 'last_name' => 'B']);

        $overview = $this->service($pdo)->adminOverview(2026, 1);

        self::assertCount(2, $overview['rows']);
        self::assertSame(['Ada A', 'Ben B'], array_column($overview['rows'], 'user'));
    }

    public function testAdminOverviewReportsAndFiltersMissingRequiredAccounts(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user(['id' => 1, 'first_name' => 'Fertig', 'last_name' => 'Konto']);
        $pdo->users[2] = $pdo->user(['id' => 2, 'first_name' => 'Offen', 'last_name' => 'Konto']);
        $pdo->users[3] = $pdo->user([
            'id' => 3,
            'first_name' => 'Freiwillig',
            'last_name' => 'Ohne',
            'time_tracking_required' => 0,
        ]);
        $pdo->cutovers[] = [
            'id' => 21,
            'user_id' => 1,
            'effective_from' => '2026-01-01',
            'opening_time_balance_minutes' => 0,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];
        $service = $this->service($pdo);

        $overview = $service->adminOverview(2026, 1);
        $missing = $service->adminOverview(2026, 1, ['setup_filter' => 'missing']);

        self::assertSame([
            'required_count' => 2,
            'ready_count' => 1,
            'missing_count' => 1,
        ], $overview['readiness']);
        self::assertSame(['Offen Konto'], array_column($missing['rows'], 'user'));
        self::assertSame(1, $missing['pagination']['total']);
    }

    public function testAdminVacationOverviewDistinguishesAccountSourcesAndInactiveRows(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user([
            'id' => 1,
            'employee_number' => 'MA-001',
            'vacation_days_year' => '31.00',
            'vacation_carryover_days' => '1.00',
        ]);
        $pdo->users[2] = $pdo->user(['id' => 2, 'first_name' => 'Ohne', 'last_name' => 'Stichtag']);
        $pdo->cutovers[] = [
            'id' => 11,
            'user_id' => 1,
            'effective_from' => '2026-04-01',
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => '30.00',
            'leave_carryover_days' => '2.00',
            'opening_remaining_leave_days' => '27.00',
            'status' => 'final',
            'active_final_user_id' => 1,
        ];
        $pdo->vacationEntries[] = ['user_id' => 1, 'cutover_id' => 11, 'leave_year' => 2027, 'entry_type' => 'annual_entitlement', 'days' => '31.00'];
        $pdo->vacationEntries[] = ['user_id' => 1, 'cutover_id' => 11, 'leave_year' => 2027, 'entry_type' => 'carryover', 'days' => '1.00'];
        $service = $this->service($pdo);

        $cutoverYear = $service->adminVacationOverview(2026);
        self::assertSame('active', $cutoverYear['rows'][0]['account_status']);
        self::assertSame('cutover_snapshot', $cutoverYear['rows'][0]['source']);
        self::assertSame(27.0, $cutoverYear['rows'][0]['vacation']['total_days']);
        self::assertSame('missing', $cutoverYear['rows'][1]['account_status']);
        self::assertNull($cutoverYear['rows'][1]['vacation']);

        $beforeCutover = $service->adminVacationOverview(2025, 1);
        self::assertSame('not_active_in_year', $beforeCutover['rows'][0]['account_status']);
        self::assertNull($beforeCutover['rows'][0]['vacation']);

        $journalYear = $service->adminVacationOverview(2027, 1);
        self::assertCount(1, $journalYear['rows']);
        self::assertSame('journal', $journalYear['rows'][0]['source']);
        self::assertSame(32.0, $journalYear['rows'][0]['vacation']['total_days']);

        $proposalYear = $service->adminVacationOverview(2028, 1);
        self::assertSame('user_defaults', $proposalYear['rows'][0]['source']);
        self::assertSame(32.0, $proposalYear['rows'][0]['vacation']['total_days']);
    }

    public function testAdminOverviewSupportsSearchSortingAndPaging(): void
    {
        $pdo = new TimeAccountPdoDouble();

        for ($i = 1; $i <= 30; $i++) {
            $pdo->users[$i] = $pdo->user([
                'id' => $i,
                'first_name' => sprintf('User%02d', $i),
                'last_name' => 'Test',
            ]);
        }

        $pdo->timesheets[] = ['user_id' => 5, 'work_date' => '2026-01-02', 'entry_type' => 'work', 'net_minutes' => 60, 'is_deleted' => 0];
        $pdo->timesheets[] = ['user_id' => 15, 'work_date' => '2026-01-02', 'entry_type' => 'work', 'net_minutes' => 180, 'is_deleted' => 0];
        $service = $this->service($pdo);

        $page = $service->adminOverview(2026, 1, ['per_page' => 25, 'page' => 2]);

        self::assertSame(30, $page['pagination']['total']);
        self::assertSame(2, $page['pagination']['page']);
        self::assertCount(5, $page['rows']);
        self::assertSame('User26 Test', $page['rows'][0]['user']);

        $searched = $service->adminOverview(2026, 1, ['q' => 'user15', 'sort' => 'saldo', 'direction' => 'desc']);

        self::assertSame(1, $searched['pagination']['total']);
        self::assertSame('User15 Test', $searched['rows'][0]['user']);
        self::assertSame(180, $searched['rows'][0]['saldo_minutes']);
    }

    public function testAdminExportRowsKeepFiltersAndIgnorePaging(): void
    {
        $pdo = new TimeAccountPdoDouble();

        for ($i = 1; $i <= 30; $i++) {
            $pdo->users[$i] = $pdo->user([
                'id' => $i,
                'first_name' => sprintf('User%02d', $i),
                'last_name' => 'Export',
            ]);
        }

        $pdo->timesheets[] = ['user_id' => 5, 'work_date' => '2026-01-02', 'entry_type' => 'work', 'net_minutes' => 60, 'is_deleted' => 0];
        $pdo->timesheets[] = ['user_id' => 15, 'work_date' => '2026-01-02', 'entry_type' => 'work', 'net_minutes' => 180, 'is_deleted' => 0];

        $rows = $this->service($pdo)->adminExportRows(2026, 1, [
            'q' => 'Export',
            'sort' => 'saldo',
            'direction' => 'desc',
            'page' => 2,
            'per_page' => 25,
        ]);

        self::assertCount(30, $rows);
        self::assertSame('User15 Export', $rows[0]['Mitarbeiter']);
        self::assertSame('+03:00', $rows[0]['Saldo']);
        self::assertArrayHasKey('Resturlaub', $rows[0]);
        self::assertArrayHasKey('Betriebsurlaub', $rows[0]);
    }

    private function service(TimeAccountPdoDouble $pdo): TimeAccountService
    {
        $connection = new DatabaseConnection(['database' => 'test']);
        $property = new \ReflectionProperty(DatabaseConnection::class, 'pdo');
        $property->setAccessible(true);
        $property->setValue($connection, $pdo);

        return new TimeAccountService($connection, new CalendarPolicyService($connection));
    }
}

final class TimeAccountPdoDouble extends PDO
{
    public array $tables = [
        'users' => true,
        'timesheets' => true,
        'vacation_requests' => true,
        'company_settings' => true,
        'company_closures' => true,
        'employee_account_cutovers' => true,
        'vacation_account_entries' => true,
    ];

    public array $columns = [
        'users' => ['id', 'first_name', 'last_name', 'email', 'employment_status', 'is_deleted', 'time_tracking_required', 'target_hours_month', 'target_hours_mode', 'target_hours_week', 'workdays_mask', 'vacation_days_year', 'vacation_carryover_days'],
        'timesheets' => ['id', 'user_id', 'work_date', 'entry_type', 'net_minutes', 'credited_minutes', 'absence_reason_code', 'is_deleted'],
        'company_settings' => ['holiday_region'],
        'vacation_account_entries' => ['cutover_id'],
    ];

    public array $users = [];
    public array $timesheets = [];
    public array $vacationRequests = [];
    public array $closures = [];
    public array $cutovers = [];
    public array $vacationEntries = [];
    public string $holidayRegion = '';

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TimeAccountPdoStatementDouble($this, $query);
    }

    public function user(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'employee_number' => 'MA-001',
            'first_name' => 'Mia',
            'last_name' => 'Muster',
            'email' => 'mia@example.test',
            'employment_status' => 'active',
            'is_deleted' => 0,
            'time_tracking_required' => 1,
            'target_hours_month' => '0.00',
            'target_hours_mode' => 'month',
            'target_hours_week' => null,
            'workdays_mask' => '1,2,3,4,5',
            'vacation_days_year' => '0.00',
            'vacation_carryover_days' => '0.00',
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

        if (str_contains($sql, 'SELECT holiday_region FROM company_settings')) {
            return [['holiday_region' => $this->holidayRegion]];
        }

        if (str_contains($sql, 'FROM company_closures') && str_contains($sql, 'year = :year')) {
            $year = (int) ($params['year'] ?? 0);

            return array_values(array_filter($this->closures, static fn (array $closure): bool => (int) ($closure['is_deleted'] ?? 0) === 0 && (int) ($closure['year'] ?? substr((string) $closure['date_from'], 0, 4)) === $year));
        }

        if (str_contains($sql, 'FROM company_closures') && str_contains($sql, 'date_from <= :year_end')) {
            $yearStart = (string) ($params['year_start'] ?? '');
            $yearEnd = (string) ($params['year_end'] ?? '');

            return array_values(array_filter($this->closures, static fn (array $closure): bool => (int) ($closure['is_deleted'] ?? 0) === 0
                && (string) $closure['date_from'] <= $yearEnd
                && (string) $closure['date_to'] >= $yearStart));
        }

        if (str_contains($sql, 'FROM company_closures') && str_contains($sql, 'date_from <= :date_from')) {
            $date = (string) ($params['date_from'] ?? '');

            return array_values(array_filter($this->closures, static fn (array $closure): bool => (int) ($closure['is_deleted'] ?? 0) === 0 && $closure['date_from'] <= $date && $closure['date_to'] >= $date));
        }

        if (str_contains($sql, 'FROM users WHERE id = :id')) {
            $user = $this->users[(int) ($params['id'] ?? 0)] ?? null;

            return $user === null ? [] : [$user];
        }

        if (str_contains($sql, 'FROM employee_account_cutovers AS cutovers')) {
            $userId = (int) ($params['user_id'] ?? 0);
            $rows = array_values(array_filter($this->cutovers, static fn (array $cutover): bool => (int) ($cutover['user_id'] ?? 0) === $userId
                && (string) ($cutover['status'] ?? '') === 'final'
                && (int) ($cutover['active_final_user_id'] ?? 0) === $userId));

            if ($rows === []) {
                return [];
            }

            $user = $this->users[$userId] ?? [];

            return [$rows[0] + [
                'employee_number' => $user['employee_number'] ?? '',
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'email' => $user['email'] ?? '',
            ]];
        }

        if (str_contains($sql, 'FROM employee_account_cutovers')
            && str_contains($sql, 'active_final_user_id IS NOT NULL')) {
            return array_values(array_filter(
                $this->cutovers,
                static fn (array $cutover): bool => (string) ($cutover['status'] ?? '') === 'final'
                    && (int) ($cutover['active_final_user_id'] ?? 0) > 0
            ));
        }

        if (str_contains($sql, 'FROM users') && str_contains($sql, 'employment_status = "active"')) {
            return array_values(array_filter($this->users, static fn (array $user): bool => (int) ($user['is_deleted'] ?? 0) === 0 && ($user['employment_status'] ?? 'active') === 'active'));
        }

        if (str_contains($sql, 'GROUP BY entry_type')) {
            $grouped = [];

            foreach ($this->timesheetsForRange($params) as $row) {
                $type = (string) $row['entry_type'];
                $grouped[$type]['entry_type'] = $type;
                $grouped[$type]['minutes'] = ($grouped[$type]['minutes'] ?? 0) + (int) ($row['net_minutes'] ?? 0);
                $grouped[$type]['credited_minutes'] = ($grouped[$type]['credited_minutes'] ?? 0) + (int) ($row['credited_minutes'] ?? 0);
                $grouped[$type]['dates'][(string) $row['work_date']] = true;
                if (($row['absence_reason_code'] ?? null) === 'unpaid_leave') {
                    $grouped[$type]['unpaid_dates'][(string) $row['work_date']] = true;
                } elseif ($type === 'vacation') {
                    $grouped[$type]['paid_dates'][(string) $row['work_date']] = true;
                }
            }

            return array_map(static fn (array $row): array => [
                'entry_type' => $row['entry_type'],
                'minutes' => $row['minutes'],
                'credited_minutes' => $row['credited_minutes'] ?? 0,
                'day_count' => count($row['dates']),
                'paid_vacation_days' => count($row['paid_dates'] ?? []),
                'unpaid_leave_days' => count($row['unpaid_dates'] ?? []),
            ], array_values($grouped));
        }

        if (str_contains($sql, 'entry_type = "vacation"')) {
            $dates = [];

            foreach ($this->timesheetsForRange($params) as $row) {
                if (($row['entry_type'] ?? '') === 'vacation') {
                    $dates[(string) $row['work_date']] = true;
                }
            }

            return [['COUNT(DISTINCT work_date)' => count($dates)]];
        }

        if (str_contains($sql, 'FROM vacation_requests') && str_contains($sql, 'status = "pending"')) {
            return array_values(array_filter($this->vacationRequests, static fn (array $request): bool => (int) ($request['user_id'] ?? 0) === (int) ($params['user_id'] ?? 0)
                && ($request['status'] ?? '') === 'pending'
                && (int) ($request['is_deleted'] ?? 0) === 0
                && $request['date_from'] <= $params['date_to']
                && $request['date_to'] >= $params['date_from']));
        }

        if (str_contains($sql, 'FROM vacation_account_entries')) {
            $rows = array_values(array_filter($this->vacationEntries, static function (array $entry) use ($params, $sql): bool {
                if ((int) ($entry['user_id'] ?? 0) !== (int) ($params['user_id'] ?? 0)
                    || (int) ($entry['leave_year'] ?? 0) !== (int) ($params['leave_year'] ?? 0)
                    || (isset($params['cutover_id']) && (int) ($entry['cutover_id'] ?? 0) !== (int) $params['cutover_id'])) {
                    return false;
                }

                if (isset($params['entry_type'])) {
                    return (string) ($entry['entry_type'] ?? '') === (string) $params['entry_type'];
                }

                if (str_contains($sql, 'entry_type IN')) {
                    return in_array((string) ($entry['entry_type'] ?? ''), ['manual_adjustment', 'expiry', 'reversal'], true);
                }

                return true;
            }));

            if (str_contains($sql, 'COUNT(*)')) {
                return [['COUNT(*)' => count($rows)]];
            }

            return [['SUM(days)' => array_sum(array_map(static fn (array $entry): float => (float) ($entry['days'] ?? 0), $rows))]];
        }

        return [];
    }

    private function timesheetsForRange(array $params): array
    {
        return array_values(array_filter($this->timesheets, static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === (int) ($params['user_id'] ?? 0)
            && (int) ($row['is_deleted'] ?? 0) === 0
            && $row['work_date'] >= $params['date_from']
            && $row['work_date'] <= $params['date_to']));
    }
}

final class TimeAccountPdoStatementDouble extends PDOStatement
{
    private array $params = [];

    public function __construct(private TimeAccountPdoDouble $pdo, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];

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
}
