<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class TimesheetDayConflictDatabaseTest extends MariaDbTestCase
{
    public function testArchivedAbsenceCannotBeRestoredOverActiveWork(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-B', 'email' => 'admin-b@example.test']);
        $userId = $this->createUser();
        $projectId = $this->createProject();
        $service = new AdminBookingService($this->connection(), new TimesheetCalculator());
        $absence = $service->createManual([
            'user_id' => $userId,
            'work_date' => '2026-06-01',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'change_reason' => 'Krankmeldung',
        ], $adminId);
        $service->archive((int) $absence['id'], $adminId, 'Archivtest');
        $service->createManual([
            'user_id' => $userId,
            'project_id' => $projectId,
            'work_date' => '2026-06-01',
            'entry_type' => 'work',
            'start_time' => '07:00',
            'end_time' => '15:30',
            'break_minutes' => 30,
            'change_reason' => 'Arbeitsnachtrag',
        ], $adminId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Arbeitsbuchung');
        $service->restore((int) $absence['id'], $adminId, 'Wiederherstellen');
    }

    public function testConflictFreeArchivedAbsenceCanBeRestored(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-R', 'email' => 'admin-r@example.test']);
        $userId = $this->createUser();
        $service = new AdminBookingService($this->connection(), new TimesheetCalculator());
        $absence = $service->createManual([
            'user_id' => $userId,
            'work_date' => '2026-06-02',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'change_reason' => 'Krankmeldung',
        ], $adminId);
        $service->archive((int) $absence['id'], $adminId, 'Archivtest');
        $service->restore((int) $absence['id'], $adminId, 'Wiederherstellen');

        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT is_deleted FROM timesheets WHERE id = :id', ['id' => $absence['id']]));
    }

    public function testAbsenceCannotBeRestoredAfterDateBecomesHoliday(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-H', 'email' => 'admin-h@example.test']);
        $userId = $this->createUser();
        $calendar = new CalendarPolicyService($this->connection());
        $service = new AdminBookingService(
            $this->connection(),
            new TimesheetCalculator(),
            null,
            null,
            new DailyTargetService($calendar)
        );
        $absence = $service->createManual([
            'user_id' => $userId,
            'work_date' => '2027-01-01',
            'entry_type' => 'vacation',
            'absence_reason_code' => 'vacation_paid',
            'change_reason' => 'Urlaub',
        ], $adminId);
        $service->archive((int) $absence['id'], $adminId, 'Archivtest');
        $calendar->saveRegion('NW');
        $restoreService = new AdminBookingService($this->connection(), new TimesheetCalculator());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kein anrechenbarer Arbeitstag');
        $restoreService->restore((int) $absence['id'], $adminId, 'Wiederherstellen');
    }
}
