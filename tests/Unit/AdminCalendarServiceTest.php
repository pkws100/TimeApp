<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

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

    public function testDaySummaryMarksAbsenceArchivedAndOpenWorkAsRed(): void
    {
        $service = $this->service();

        self::assertSame('issue', $service->summarizeDay('2026-05-08', [
            $this->booking(['entry_type' => 'sick', 'start_time' => null, 'end_time' => null]),
        ])['status']);
        self::assertSame('issue', $service->summarizeDay('2026-05-08', [
            $this->booking(['is_deleted' => 1]),
        ])['status']);
        self::assertSame('issue', $service->summarizeDay('2026-05-08', [
            $this->booking(['end_time' => null]),
        ])['status']);
        self::assertSame('issue', $service->summarizeDay('2026-05-08', [
            $this->booking(['project_id' => null, 'needs_project_assignment' => true]),
        ])['status']);
    }

    private function service(): AdminCalendarService
    {
        $connection = new DatabaseConnection([]);

        return new AdminCalendarService($connection, new AdminBookingService($connection, new TimesheetCalculator()));
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
}
