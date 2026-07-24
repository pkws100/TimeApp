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
        $absence = $this->legacyAbsence($service, $userId, '2026-06-01', 'sick', 'sick_paid');
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
        $absence = $this->legacyAbsence($service, $userId, '2026-06-02', 'sick', 'sick_paid');
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
        $absence = $this->legacyAbsence($service, $userId, '2027-01-01', 'vacation', 'vacation_paid');
        $service->archive((int) $absence['id'], $adminId, 'Archivtest');
        $calendar->saveRegion('NW');
        $restoreService = new AdminBookingService($this->connection(), new TimesheetCalculator());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('kein anrechenbarer Arbeitstag');
        $restoreService->restore((int) $absence['id'], $adminId, 'Wiederherstellen');
    }

    private function legacyAbsence(
        AdminBookingService $service,
        int $userId,
        string $date,
        string $entryType,
        string $reason
    ): array {
        $this->connection()->execute(
            'INSERT INTO timesheets (
                user_id, work_date, gross_minutes, break_minutes, net_minutes,
                credited_minutes, entry_type, absence_reason_code, source, created_at, updated_at, is_deleted
             ) VALUES (
                :user_id, :work_date, 0, 0, 0,
                480, :entry_type, :absence_reason_code, "admin", NOW(), NOW(), 0
             )',
            [
                'user_id' => $userId,
                'work_date' => $date,
                'entry_type' => $entryType,
                'absence_reason_code' => $reason,
            ]
        );

        return $service->find($this->connection()->lastInsertId()) ?? [];
    }
}
