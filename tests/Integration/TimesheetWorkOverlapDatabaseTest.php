<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\Timesheets\WorkSessionConflict;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class TimesheetWorkOverlapDatabaseTest extends MariaDbTestCase
{
    public function testManualCreationRejectsOverlapAcrossProjectsAndAllowsAdjacentIntervals(): void
    {
        $admin = $this->createUser();
        $employee = $this->createUser();
        $projectA = $this->createProject();
        $projectB = $this->createProject();
        $service = $this->adminService();
        $this->manual($service, $admin, $employee, '2026-06-01', '07:00', '15:00', $projectA);

        $this->assertAdminOverlap(fn () => $this->manual(
            $service, $admin, $employee, '2026-06-01', '14:00', '16:00', $projectB
        ));

        $this->manual($service, $admin, $employee, '2026-06-01', '15:00', '16:00', $projectB);
        $this->manual($service, $admin, $this->createUser(), '2026-06-01', '14:00', '16:00', $projectB);
        self::assertSame(3, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets'));
    }

    public function testAdminRejectsOverlappingRestoreAndCanCorrectAnInheritedNightConflict(): void
    {
        $admin = $this->createUser();
        $employee = $this->createUser();
        $service = $this->adminService();
        $archived = $this->manual($service, $admin, $employee, '2026-06-02', '01:00', '03:00');
        $service->archive($archived, $admin, 'Archivieren');
        $this->manual($service, $admin, $employee, '2026-06-01', '22:00', '02:00');

        $this->assertAdminOverlap(fn () => $service->restore($archived, $admin, 'Wiederherstellen'));
        self::assertSame(1, $service->find($archived)['is_deleted']);

        $inherited = $this->seedWork($employee, '2026-06-02', '01:30', '05:00');
        $this->assertAdminOverlap(fn () => $service->update($inherited, ['start_time' => '01:45'], $admin, 'Termin korrigieren'));
        self::assertSame('01:30:00', $service->find($inherited)['start_time']);
        self::assertSame('Altbuchung', $service->update($inherited, ['note' => 'Altbuchung'], $admin, 'Hinweis ergänzen')['note']);

        $corrected = $service->update($inherited, ['start_time' => '02:00'], $admin, 'Überlappung korrigieren');
        self::assertSame('02:00:00', $corrected['start_time']);
        self::assertSame(180, $corrected['net_minutes']);
    }

    public function testCheckoutAndUpsertCannotOverlapAcrossMidnightAndBlockedRequestCanBeCorrected(): void
    {
        $employee = $this->createUser();
        $open = $this->seedWork($employee, '2026-06-01', '22:00', null, 'admin');
        $later = $this->seedWork($employee, '2026-06-02', '01:00', '05:00');
        $service = $this->appService();
        $checkout = [
            'client_request_id' => 'overnight-checkout',
            'action' => 'check_out',
            'work_date' => '2026-06-01',
            'expected_timesheet_id' => $open,
            'end_time' => '02:00',
            'actual_end_at' => '2026-06-02T02:00',
        ];

        $this->assertAppOverlap(fn () => $service->sync($employee, $checkout));
        self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $open]));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM app_sync_operations'));

        $checkout['end_time'] = '01:00';
        $checkout['actual_end_at'] = '2026-06-02T01:00';
        self::assertSame(180, $service->sync($employee, $checkout)['timesheet']['net_minutes']);
        self::assertSame('admin', $this->connection()->fetchColumn('SELECT source FROM timesheets WHERE id = :id', ['id' => $open]));

        $this->assertAppOverlap(fn () => $service->sync($employee, [
            'client_request_id' => 'overlapping-offline-edit',
            'action' => 'upsert',
            'expected_timesheet_id' => $open,
            'work_date' => '2026-06-01',
            'end_time' => '02:30',
        ]));
        self::assertSame('01:00:00', $this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $open]));
        self::assertSame('05:00:00', $this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $later]));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM app_sync_operations'));
    }

    public function testUpsertCannotOpenASecondBookingOnADifferentDate(): void
    {
        $employee = $this->createUser();
        $this->seedWork($employee, '2026-06-01', '22:00', null);
        $this->assertAppOverlap(fn () => $this->appService()->sync($employee, [
            'client_request_id' => 'overlapping-offline-start',
            'action' => 'upsert',
            'work_date' => '2026-06-02',
            'start_time' => '07:00',
        ]));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets'));
    }

    public function testMissingStartCannotTurnAnUpsertOrLaterCheckInIntoAClosedBooking(): void
    {
        $employee = $this->createUser();
        $service = $this->appService();
        try {
            $service->sync($employee, [
                'client_request_id' => 'missing-start',
                'action' => 'upsert',
                'work_date' => '2026-06-01',
                'end_time' => '15:00',
            ]);
            self::fail('Ein Ende ohne Arbeitsbeginn wurde als Buchung gespeichert.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Startzeit', $exception->getMessage());
        }

        $incomplete = $this->seedWork($employee, '2026-06-01', null, '15:00');
        $result = $service->sync($employee, [
            'client_request_id' => 'open-incomplete',
            'action' => 'check_in',
            'work_date' => '2026-06-01',
            'start_time' => '07:00',
        ])['timesheet'];
        self::assertSame($incomplete, $result['id']);
        self::assertSame('07:00:00', $result['start_time']);
        self::assertNull($result['end_time']);
    }

    public static function invalidStartTimes(): array
    {
        return [
            'rollover' => ['25:00', 'Uhrzeit'],
            'Berlin clock change' => ['02:30', 'Zeitumstellung'],
        ];
    }

    #[DataProvider('invalidStartTimes')]
    public function testAppCannotSilentlyNormalizeInvalidOrAmbiguousWorkTimes(string $start, string $message): void
    {
        $employee = $this->createUser();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->appService()->sync($employee, [
            'client_request_id' => 'invalid-work-time',
            'action' => 'check_in',
            'work_date' => '2026-10-25',
            'start_time' => $start,
        ]);
    }

    private function assertAdminOverlap(callable $save): void
    {
        try {
            $save();
            self::fail('Die überlappende Arbeitsbuchung wurde angenommen.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('überschneiden', $exception->getMessage());
        }
    }

    private function assertAppOverlap(callable $save): void
    {
        try {
            $save();
            self::fail('Die überlappende Arbeitsbuchung wurde angenommen.');
        } catch (WorkSessionConflict $exception) {
            self::assertSame('overlapping_work_session', $exception->reason);
        }
    }

    private function manual(
        AdminBookingService $service, int $admin, int $employee, string $date, string $start, string $end, ?int $project = null
    ): int {
        return (int) $service->createManual([
            'user_id' => $employee,
            'project_id' => $project,
            'work_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'change_reason' => 'Arbeitszeit nacherfassen',
        ], $admin)['id'];
    }

    private function seedWork(int $employee, string $date, ?string $start, ?string $end, string $source = 'app'): int
    {
        $this->connection()->execute(
            'INSERT INTO timesheets (user_id, work_date, start_time, end_time, entry_type, source)
             VALUES (:employee, :work_date, :start, :end, "work", :source)',
            ['employee' => $employee, 'work_date' => $date, 'start' => $start, 'end' => $end, 'source' => $source]
        );

        return $this->connection()->lastInsertId();
    }

    private function adminService(): AdminBookingService
    {
        return new AdminBookingService($this->connection(), new TimesheetCalculator());
    }

    private function appService(): AppTimesheetSyncService
    {
        return new AppTimesheetSyncService(
            $this->connection(),
            new TimesheetCalculator(),
            new CompanySettingsService($this->connection(), []),
            new WorkdayStateCalculator()
        );
    }
}
