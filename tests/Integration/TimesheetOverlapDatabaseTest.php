<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\App\MobileAppService;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Exports\AccountingClosureService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\Users\StorageUsageService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class TimesheetOverlapDatabaseTest extends MariaDbTestCase
{
    public function testEightyHoursOfLegacyOverlapsAreExcludedAcrossReadSurfaces(): void
    {
        $connection = $this->connection();
        $employee = $this->createUser();
        $admin = $this->createUser();
        $localToday = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
        $date = $localToday->format('Y-m-d');
        $month = $localToday->format('Y-m');
        $cutovers = new EmployeeAccountCutoverService($connection, new AccountJournalService($connection));
        $cutovers->finalize($this->cutoverPayload($employee, $date), $admin);
        $firstProject = $this->createProject();
        $secondProject = $this->createProject();

        for ($index = 0; $index < 8; ++$index) {
            $this->insertWork($employee, $index % 2 === 0 ? $firstProject : $secondProject, $date, '07:00', '18:15', 600, 75);
        }
        $adjacentId = $this->insertWork($employee, $firstProject, $date, '18:15', '19:15', 60);
        $before = $connection->fetchAll('SELECT id, net_minutes, row_start FROM timesheets FOR SYSTEM_TIME ALL ORDER BY id, row_start');
        self::assertSame(4860, array_sum(array_column($before, 'net_minutes')));

        $calendar = new CalendarPolicyService($connection);
        $accounts = new TimeAccountService($connection, $calendar);
        $account = $accounts->monthlyAccount($employee, (int) $localToday->format('Y'), (int) $localToday->format('m'), $date);
        self::assertSame(60, $account['actual_work_minutes']);
        self::assertTrue($account['booking_overlap']);
        self::assertTrue($account['balance_is_provisional']);
        self::assertNull($account['period_delta_minutes']);
        self::assertNull($account['closing_balance_minutes']);
        $employeeAccount = $accounts->employeeMonthlyAccount($employee, (int) $localToday->format('Y'), (int) $localToday->format('m'), $localToday->modify('+1 day')->format('Y-m-d'));
        self::assertSame('needs_clarification', $employeeAccount['employee_balance']['status']);
        self::assertNull($employeeAccount['employee_balance']['minutes']);
        $followingMonth = $localToday->modify('first day of next month');
        $nextAccount = $accounts->monthlyAccount(
            $employee,
            (int) $followingMonth->format('Y'),
            (int) $followingMonth->format('m'),
            $followingMonth->format('Y-m-d')
        );
        self::assertNull($nextAccount['opening_balance_at_period_start_minutes']);
        self::assertNull($nextAccount['closing_balance_minutes']);
        self::assertTrue($nextAccount['booking_overlap']);

        $projects = new ProjectService($connection);
        $app = new MobileAppService(
            $connection,
            $projects,
            new CompanySettingsService($connection, []),
            new WorkdayStateCalculator(),
            new FileAttachmentService($connection, []),
        );
        $viewer = ['id' => $employee, 'permissions' => ['projects.manage']];
        $day = $app->dayContext($viewer);
        self::assertTrue($day['booking_overlap']);
        $projectDays = array_column($day['project_day_summaries'], null, 'project_id');
        self::assertSame(60, $projectDays[$firstProject]['total_net_minutes']);
        self::assertSame(0, $projectDays[$secondProject]['total_net_minutes']);
        $history = $app->timesheetList($viewer, 'project', $secondProject, $month);
        self::assertSame(0, $history['summary']['total_net_minutes']);
        self::assertTrue($history['summary']['booking_overlap']);
        self::assertCount(4, $history['items']);
        self::assertSame(600, $history['items'][0]['net_minutes']);
        self::assertTrue($history['items'][0]['booking_overlap']);

        $adminBookings = new AdminBookingService($connection, new TimesheetCalculator());
        $issueFilter = $adminBookings->normalizeFilters(['date_from' => $date, 'date_to' => $date, 'issue' => 'all']);
        self::assertSame(8, $adminBookings->count($issueFilter));
        $export = $adminBookings->exportRows($issueFilter);
        self::assertSame('', $export[0]['Netto (Min)']);
        $accounting = array_values(array_filter(
            $adminBookings->accountingRows('month'),
            static fn (array $row): bool => $row['booking_status'] === 'overlapping',
        ));
        self::assertCount(8, $accounting);
        self::assertNull($accounting[0]['net_minutes']);
        $reports = (new TimesheetService($connection, new TimesheetCalculator()))->reportRows('month');
        self::assertContains('', array_column($reports, 'Netto (Min)'));

        $calendarDay = (new AdminCalendarService($connection, $adminBookings))->day($date);
        self::assertSame('issue', $calendarDay['summary']['status']);
        self::assertSame(60, $calendarDay['summary']['net_minutes']);
        $projectRows = array_column($projects->list(), null, 'id');
        self::assertSame(60, (int) $projectRows[$firstProject]['tracked_net_minutes']);
        self::assertSame(0, (int) $projectRows[$secondProject]['tracked_net_minutes']);
        $attendance = new AttendanceService($connection);
        $completed = $attendance->todaySummary($date)['completed'];
        self::assertSame(60, $completed[0]['net_minutes']);
        self::assertTrue($completed[0]['booking_overlap']);
        $dashboard = new DashboardService($connection, new StorageUsageService('/nonexistent'), $attendance);
        self::assertSame(1.0, array_sum($dashboard->charts('month')['hours']['datasets'][0]['data']));

        $preview = (new WorkTimePreviewService($connection, new WorkTimePolicyService($connection, true)))->forUserPeriod($employee, $date, $date);
        self::assertTrue($preview['booking_overlap']);
        self::assertTrue($preview['unresolved']);
        self::assertSame(60, $preview['recorded_net_minutes']);
        self::assertNull($preview['rows'][0]['calculation']['actual_work_minutes']);

        $closures = new AccountingClosureService($connection, $adminBookings);
        $selection = $closures->selectionFromInput(['type' => 'project', 'project_id' => $firstProject]);
        self::assertFalse($closures->validateFinalization($selection)['ok']);
        $package = $closures->previewPackage($selection);
        self::assertTrue($package['closure']['booking_overlap']);
        self::assertSame([$adjacentId], array_column($package['items'], 'timesheet_id'));
        self::assertSame($before, $connection->fetchAll('SELECT id, net_minutes, row_start FROM timesheets FOR SYSTEM_TIME ALL ORDER BY id, row_start'));
    }

    public function testOvernightOverlapBlocksCutoverAndDoesNotDisputeAnAdjacentBooking(): void
    {
        $connection = $this->connection();
        $employee = $this->createUser();
        $admin = $this->createUser();
        $project = $this->createProject();
        $nightId = $this->insertWork($employee, $project, '2026-04-30', '22:00', '02:00', 240);
        $conflictId = $this->insertWork($employee, $project, '2026-05-01', '01:00', '03:00', 120);
        $adjacentId = $this->insertWork($employee, $project, '2026-05-01', '03:00', '04:00', 60);
        $bookingService = new AdminBookingService($connection, new TimesheetCalculator());
        $rows = array_column($bookingService->list($bookingService->normalizeFilters([])), null, 'id');
        self::assertTrue($rows[$nightId]['booking_overlap']);
        self::assertTrue($rows[$conflictId]['booking_overlap']);
        self::assertFalse($rows[$adjacentId]['booking_overlap']);

        $cutovers = new EmployeeAccountCutoverService($connection, new AccountJournalService($connection));
        try {
            $cutovers->finalize($this->cutoverPayload($employee, '2026-05-02'), $admin);
            self::fail('Ein offener Buchungskonflikt wurde festgeschrieben.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Überlappende Buchungen', $exception->getMessage());
        }
        self::assertNull($cutovers->activeCutover($employee));

        $bookingService->archive($conflictId, $admin, 'Falsch erfasster Einsatz');
        self::assertSame('final', $cutovers->finalize($this->cutoverPayload($employee, '2026-05-02'), $admin)['status']);
    }

    public function testAlreadySealedLegacyOverlapWarnsWithoutBlockingAnOpeningBalance(): void
    {
        $connection = $this->connection();
        $employee = $this->createUser();
        $admin = $this->createUser();
        $project = $this->createProject();
        $this->insertWork($employee, $project, '2026-05-01', '07:00', '09:00', 120);
        $this->insertWork($employee, $project, '2026-05-01', '08:00', '10:00', 120);
        $connection->execute(
            'INSERT INTO accounting_closures (closure_number, closure_type, status, period_start, period_end, user_id,
                snapshot_hash, item_count, total_net_minutes)
             VALUES ("LEGACY", "month", "final", "2026-05-01", "2026-05-01", :employee, :hash, 2, 240)',
            ['employee' => $employee, 'hash' => hash('sha256', 'synthetic already sealed')],
        );
        $cutovers = new EmployeeAccountCutoverService($connection, new AccountJournalService($connection));
        $input = $this->cutoverPayload($employee, '2026-05-02');
        $preview = $cutovers->preview($input);
        self::assertSame(0, $preview['editable_legacy_overlap_count']);
        self::assertStringContainsString('festgeschriebenen Altzeitraum', implode(' ', $preview['warnings']));
        self::assertSame('final', $cutovers->finalize($input, $admin)['status']);
        self::assertSame(2, (int) $connection->fetchColumn(
            'SELECT COUNT(*) FROM timesheets FOR SYSTEM_TIME ALL WHERE user_id = :employee',
            ['employee' => $employee],
        ));
    }

    public function testLiveCarryoverOverlapIsFlaggedWithoutCountingAnUnclosedDuration(): void
    {
        $connection = $this->connection();
        $employee = $this->createUser();
        $project = $this->createProject();
        $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $this->insertWork($employee, $project, $yesterday, '08:00', '10:00', 120);
        $connection->execute(
            'INSERT INTO timesheets (user_id, project_id, work_date, start_time, entry_type)
             VALUES (:employee, :project, :date, "07:00", "work")',
            ['employee' => $employee, 'project' => $project, 'date' => $yesterday],
        );
        $app = new MobileAppService(
            $connection,
            new ProjectService($connection),
            new CompanySettingsService($connection, []),
            new WorkdayStateCalculator(),
            new FileAttachmentService($connection, []),
        );
        $viewer = ['id' => $employee, 'permissions' => ['projects.manage']];
        $day = $app->dayContext($viewer);
        self::assertTrue($day['booking_overlap']);
        self::assertTrue($day['active_work_entry']['booking_overlap']);
        self::assertSame(120, $app->timesheetList($viewer, 'all')['summary']['total_net_minutes']);
    }

    private function insertWork(int $employee, int $project, string $date, string $start, string $end, int $net, int $break = 0): int
    {
        $this->connection()->execute(
            'INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type)
             VALUES (:employee, :project, :date, :start, :end, :gross, :break, :net, "work")',
            ['employee' => $employee, 'project' => $project, 'date' => $date, 'start' => $start, 'end' => $end, 'gross' => $net + $break, 'break' => $break, 'net' => $net],
        );

        return $this->connection()->lastInsertId();
    }

    private function cutoverPayload(int $user, string $date): array
    {
        return [
            'user_id' => $user,
            'effective_from' => $date,
            'leave_year' => (int) substr($date, 0, 4),
            'opening_time_balance' => '+00:00',
            'annual_leave_entitlement_days' => '0',
            'leave_carryover_days' => '0',
            'opening_remaining_leave_days' => '0',
            'note' => 'Synthetischer Stichtag',
        ];
    }
}
