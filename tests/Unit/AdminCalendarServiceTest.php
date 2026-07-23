<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarPolicyProvider;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class AdminCalendarServiceTest extends TestCase
{
    public function testMonthBuildsFullWeekGridAroundRequestedMonth(): void
    {
        $service = $this->service();
        $month = $service->month('2026-05');

        self::assertSame('2026-05', $month['month']);
        self::assertSame('Mai 2026', $month['label']);
        self::assertSame('2026-04', $month['previous_month']);
        self::assertSame('2026-06', $month['next_month']);
        self::assertCount(35, $month['days']);
        self::assertSame('2026-04-27', $month['days'][0]['date']);
        self::assertSame('2026-05-31', $month['days'][34]['date']);
    }

    public function testInvalidMonthFallsBackWithoutRollingOver(): void
    {
        $service = $this->service();
        $month = $service->month('2026-13');

        self::assertNotSame('2027-01', $month['month']);
    }

    public function testDaySummaryMarksCleanFinishedWorkAsGreen(): void
    {
        $summary = $this->service()->summarizeDay('2026-05-08', [
            $this->booking(['net_minutes' => 450]),
        ]);

        self::assertSame('ok', $summary['status']);
        self::assertSame('Sauber', $summary['status_label']);
        self::assertSame(450, $summary['net_minutes']);
        self::assertSame(1, $summary['employee_count']);
    }

    public function testDaySummaryMarksNoBookingAsGrey(): void
    {
        $summary = $this->service()->summarizeDay('2026-05-08', []);

        self::assertSame('empty', $summary['status']);
        self::assertSame('Keine Buchung', $summary['status_label']);
        self::assertSame(0, $summary['active_booking_count']);
    }

    public function testDaySummaryShowsAbsenceWithoutMarkingItAsIssue(): void
    {
        $summary = $this->service()->summarizeDay('2026-05-08', [
            $this->booking(['entry_type' => 'sick', 'start_time' => null, 'end_time' => null]),
        ]);

        self::assertSame('absence', $summary['status']);
        self::assertSame('Abwesenheit', $summary['status_label']);
        self::assertSame(1, $summary['absence_count']);
        self::assertSame(1, $summary['sick_count']);
        self::assertSame(0, $summary['issue_count']);
    }

    public function testLegacyUnpaidVacationCountsAsStoredAbsence(): void
    {
        $summary = $this->service()->summarizeDay('2026-05-08', [
            $this->booking([
                'entry_type' => 'vacation',
                'absence_reason_code' => 'unpaid_leave',
                'start_time' => null,
                'end_time' => null,
            ]),
        ]);

        self::assertSame(0, $summary['vacation_count']);
        self::assertSame(1, $summary['stored_absent_count']);
    }


    public function testDaySummaryMarksDerivedMissingForUnbookedActiveUsers(): void
    {
        $service = $this->serviceWithActiveUserRows([
            [
                'id' => 7,
                'created_at' => '2026-01-01 00:00:00',
                'first_name' => 'Anna',
                'last_name' => 'Arbeit',
                'employee_number' => 'MA-007',
                'email' => 'anna@example.test',
            ],
            [
                'id' => 8,
                'created_at' => '2026-01-01 00:00:00',
                'first_name' => 'Ben',
                'last_name' => 'Fehlt',
                'employee_number' => 'MA-008',
                'email' => 'ben@example.test',
            ],
        ]);
        $summary = $service->summarizeDay('2026-05-08', [
            $this->booking(['user_id' => 7]),
        ]);

        self::assertSame('missing', $summary['status']);
        self::assertSame('Fehlend', $summary['status_label']);
        self::assertSame(1, $summary['missing_count']);
        self::assertSame([[
            'user_id' => 8,
            'user_name' => 'Ben Fehlt',
            'employee_number' => 'MA-008',
            'email' => 'ben@example.test',
        ]], $summary['missing_users']);
        self::assertSame(1, $summary['work_booking_count']);
    }

    public function testDaySummaryDoesNotCountUsersCreatedAfterTheDayAsMissing(): void
    {
        $service = $this->serviceWithActiveUserRows([
            ['id' => 7, 'created_at' => '2026-01-01 00:00:00'],
            ['id' => 8, 'created_at' => '2026-05-09 00:00:00'],
        ]);
        $summary = $service->summarizeDay('2026-05-08', [
            $this->booking(['user_id' => 7]),
        ]);

        self::assertSame(0, $summary['missing_count']);
        self::assertSame([], $summary['missing_users']);
    }

    public function testDaySummaryDoesNotCountUsersWithoutTimeTrackingRequirementAsMissing(): void
    {
        $service = $this->serviceWithActiveUserRows([
            ['id' => 7, 'created_at' => '2026-01-01 00:00:00', 'time_tracking_required' => 1],
            ['id' => 8, 'created_at' => '2026-01-01 00:00:00', 'time_tracking_required' => 0],
        ]);
        $summary = $service->summarizeDay('2026-05-08', [
            $this->booking(['user_id' => 7]),
        ]);

        self::assertSame(0, $summary['missing_count']);
        self::assertSame([], $summary['missing_users']);
        self::assertSame('ok', $summary['status']);
    }

    public function testDaySummaryDoesNotDeriveMissingOnFreeDays(): void
    {
        $service = $this->serviceWithActiveUserRows([
            ['id' => 7, 'created_at' => '2026-01-01 00:00:00', 'time_tracking_required' => 1],
        ]);
        $summary = $service->summarizeDay('2026-05-09', []);

        self::assertSame(0, $summary['missing_count']);
        self::assertSame([], $summary['missing_users']);
        self::assertSame('empty', $summary['status']);
    }

    public function testIndividualWorkdaysSuppressAndDeriveMissingOnTheConfiguredWeekdays(): void
    {
        $service = $this->serviceWithActiveUserRows([
            $this->activeUser(['workdays_mask' => '2,3,4']),
        ]);

        self::assertSame([], $service->summarizeDay('2026-05-11', [])['missing_users']);
        self::assertSame([7], array_column(
            $service->summarizeDay('2026-05-12', [])['missing_users'],
            'user_id'
        ));
        self::assertSame([], $service->summarizeDay('2026-05-15', [])['missing_users']);
    }

    public function testConfiguredWeekendWorkdaysCanBeDerivedAsMissing(): void
    {
        $saturday = $this->serviceWithActiveUserRows([
            $this->activeUser(['id' => 7, 'workdays_mask' => '6']),
        ])->summarizeDay('2026-05-16', []);
        $sunday = $this->serviceWithActiveUserRows([
            $this->activeUser(['id' => 8, 'workdays_mask' => '7']),
        ])->summarizeDay('2026-05-17', []);

        self::assertSame([7], array_column($saturday['missing_users'], 'user_id'));
        self::assertSame('missing', $saturday['status']);
        self::assertSame([8], array_column($sunday['missing_users'], 'user_id'));
        self::assertSame('missing', $sunday['status']);
    }

    public function testExistingBookingStillSuppressesMissingOnConfiguredWorkday(): void
    {
        $service = $this->serviceWithActiveUserRows([
            $this->activeUser(['workdays_mask' => '2,3,4']),
        ]);
        $summary = $service->summarizeDay('2026-05-12', [
            $this->booking(['work_date' => '2026-05-12']),
        ]);

        self::assertSame([], $summary['missing_users']);
        self::assertSame(0, $summary['missing_count']);
    }

    public function testFutureConfiguredWorkdayDoesNotDeriveMissing(): void
    {
        $futureDate = (new \DateTimeImmutable('today'))->modify('+7 days');
        $service = $this->serviceWithActiveUserRows([
            $this->activeUser(['workdays_mask' => $futureDate->format('N')]),
        ]);

        self::assertSame([], $service->summarizeDay($futureDate->format('Y-m-d'), [])['missing_users']);
    }

    public function testDaySummaryDoesNotDeriveMissingWhenCalendarPolicyDisablesTracking(): void
    {
        $service = $this->serviceWithActiveUserRows(
            [
                ['id' => 7, 'created_at' => '2026-01-01 00:00:00', 'time_tracking_required' => 1],
            ],
            new class implements CalendarPolicyProvider {
                public function requiresTimeTracking(string $date): bool
                {
                    return false;
                }

                public function dayPolicy(string $date): array
                {
                    return [
                        'date' => $date,
                        'holiday_region' => 'NW',
                        'is_public_holiday' => true,
                        'holiday_name' => 'Test-Feiertag',
                        'is_company_closure' => false,
                        'closure_titles' => [],
                        'closures' => [],
                        'time_tracking_required' => false,
                    ];
                }
            }
        );
        $summary = $service->summarizeDay('2026-05-08', []);

        self::assertSame(0, $summary['missing_count']);
        self::assertSame([], $summary['missing_users']);
        self::assertSame('holiday', $summary['status']);
        self::assertFalse($summary['time_tracking_required']);
    }

    public function testMonthTotalsCountAbsenceAndMissingByCountsNotDominantStatus(): void
    {
        $service = $this->service();
        $monthTotals = new ReflectionMethod($service, 'monthTotals');
        $monthTotals->setAccessible(true);
        $totals = $monthTotals->invoke($service, [
            [
                'is_current_month' => true,
                'status' => 'missing',
                'absence_count' => 1,
                'missing_count' => 1,
                'net_minutes' => 0,
            ],
        ]);

        self::assertSame(1, $totals['absence_days']);
        self::assertSame(1, $totals['missing_days']);
    }

    public function testDaySummaryIgnoresArchivedBookingsForCalendarStatus(): void
    {
        $summary = $this->service()->summarizeDay('2026-05-08', [
            $this->booking(['is_deleted' => 1]),
        ]);

        self::assertSame('empty', $summary['status']);
        self::assertSame('Keine Buchung', $summary['status_label']);
        self::assertSame(1, $summary['booking_count']);
        self::assertSame(0, $summary['active_booking_count']);
        self::assertSame(0, $summary['issue_count']);
    }

    public function testActiveBookingFilterKeepsDayDerivedListsAwayFromArchivedRows(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'activeBookings');
        $method->setAccessible(true);

        $bookings = $method->invoke($service, [
            $this->booking(['id' => 1, 'is_deleted' => 0]),
            $this->booking(['id' => 2, 'is_deleted' => 1]),
        ]);

        self::assertSame([1], array_column($bookings, 'id'));
    }

    public function testDaySummaryMarksOpenWorkAsRed(): void
    {
        $service = $this->service();

        self::assertSame('issue', $service->summarizeDay('2026-05-08', [
            $this->booking(['end_time' => null]),
        ])['status']);
        self::assertSame('issue', $service->summarizeDay('2026-05-08', [
            $this->booking(['project_id' => null, 'needs_project_assignment' => true]),
        ])['status']);
    }

    private function service(?CalendarPolicyProvider $calendarPolicyService = null): AdminCalendarService
    {
        $connection = new DatabaseConnection([]);

        return new AdminCalendarService(
            $connection,
            new AdminBookingService($connection, new TimesheetCalculator()),
            $calendarPolicyService
        );
    }

    private function serviceWithActiveUsers(array $userIds): AdminCalendarService
    {
        return $this->serviceWithActiveUserRows(array_map(
            fn (int $userId): array => $this->activeUser(['id' => $userId]),
            $userIds
        ));
    }

    private function serviceWithActiveUserRows(array $users, ?CalendarPolicyProvider $calendarPolicyService = null): AdminCalendarService
    {
        $service = $this->service($calendarPolicyService);
        $property = new ReflectionProperty($service, 'activeUsers');
        $property->setAccessible(true);
        $property->setValue($service, $users);

        return $service;
    }

    private function booking(array $overrides = []): array
    {
        return [
            'id' => 1,
            'user_id' => 7,
            'project_id' => 5,
            'work_date' => '2026-05-08',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'net_minutes' => 450,
            'entry_type' => 'work',
            'is_deleted' => 0,
            'needs_project_assignment' => false,
            ...$overrides,
        ];
    }

    private function activeUser(array $overrides = []): array
    {
        return [
            'id' => 7,
            'created_at' => '2026-01-01 00:00:00',
            'first_name' => 'Test',
            'last_name' => 'Mitarbeiter',
            'employee_number' => 'MA-007',
            'email' => 'test@example.test',
            'time_tracking_required' => 1,
            'workdays_mask' => '1,2,3,4,5',
            ...$overrides,
        ];
    }
}
