<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Attendance\AttendanceService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class AttendanceServiceTest extends TestCase
{
    public function testSummarizeRowsUsesLatestEntryPerUserAndSkipsArchivedRecords(): void
    {
        $service = new AttendanceService(new DatabaseConnection([]));

        $summary = $service->summarizeRows([
            [
                'id' => 2,
                'user_id' => 10,
                'entry_type' => 'work',
                'employee_number' => 'MA-0010',
                'first_name' => 'Anna',
                'last_name' => 'Berg',
                'project_name' => 'Projekt Alpha',
                'project_is_deleted' => 0,
                'note' => 'Spaetere Buchung',
                'updated_at' => '2026-04-23 08:00:00',
                'user_is_deleted' => 0,
                'start_time' => '07:00',
                'end_time' => '15:30',
                'net_minutes' => 480,
            ],
            [
                'id' => 1,
                'user_id' => 10,
                'entry_type' => 'vacation',
                'employee_number' => 'MA-0010',
                'first_name' => 'Anna',
                'last_name' => 'Berg',
                'project_name' => 'Projekt Alt',
                'project_is_deleted' => 0,
                'note' => 'Aeltere Buchung',
                'updated_at' => '2026-04-23 07:00:00',
                'user_is_deleted' => 0,
                'start_time' => null,
                'end_time' => null,
                'net_minutes' => 0,
            ],
            [
                'id' => 3,
                'user_id' => 11,
                'entry_type' => 'sick',
                'employee_number' => 'MA-0011',
                'first_name' => 'Ben',
                'last_name' => 'Kurz',
                'project_name' => '',
                'project_is_deleted' => 0,
                'note' => 'Attest folgt',
                'updated_at' => '2026-04-23 06:30:00',
                'user_is_deleted' => 0,
                'start_time' => null,
                'end_time' => null,
                'net_minutes' => 0,
            ],
            [
                'id' => 4,
                'user_id' => 12,
                'entry_type' => 'work',
                'employee_number' => 'MA-0012',
                'first_name' => 'Cara',
                'last_name' => 'Neu',
                'project_name' => 'Archiv Projekt',
                'project_is_deleted' => 1,
                'note' => '',
                'updated_at' => '2026-04-23 09:15:00',
                'user_is_deleted' => 1,
                'start_time' => '08:00',
                'end_time' => '16:00',
                'net_minutes' => 450,
            ],
        ], '2026-04-23');

        self::assertSame(1, $summary['present_count']);
        self::assertCount(1, $summary['present']);
        self::assertSame('Anna Berg', $summary['present'][0]['user_name']);
        self::assertSame('Projekt Alpha', $summary['present'][0]['location']);
        self::assertCount(1, $summary['statuses']);
        self::assertSame('sick', $summary['statuses'][0]['entry_type']);
        self::assertSame(1, $summary['status_counts']['sick']);
        self::assertSame(0, $summary['derived_missing_count']);
    }

    public function testSummarizeRowsAddsDerivedMissingActiveUsersOnWeekdays(): void
    {
        $service = new AttendanceService(new DatabaseConnection([]));

        $summary = $service->summarizeRows([
            [
                'id' => 2,
                'user_id' => 10,
                'entry_type' => 'work',
                'employee_number' => 'MA-0010',
                'first_name' => 'Anna',
                'last_name' => 'Berg',
                'project_name' => 'Projekt Alpha',
                'project_is_deleted' => 0,
                'note' => '',
                'updated_at' => '2026-05-15 08:00:00',
                'user_is_deleted' => 0,
                'start_time' => '07:00',
                'end_time' => '15:30',
                'net_minutes' => 480,
            ],
        ], '2026-05-15', [
            [
                'id' => 10,
                'employee_number' => 'MA-0010',
                'first_name' => 'Anna',
                'last_name' => 'Berg',
                'email' => 'anna@example.test',
            ],
            [
                'id' => 11,
                'employee_number' => 'MA-0011',
                'first_name' => 'Ben',
                'last_name' => 'Kurz',
                'email' => 'ben@example.test',
            ],
        ]);

        self::assertSame(1, $summary['derived_missing_count']);
        self::assertSame(1, $summary['status_counts']['absent']);
        self::assertSame('absent', $summary['statuses'][0]['entry_type']);
        self::assertTrue($summary['statuses'][0]['is_derived']);
        self::assertSame('derived_missing', $summary['statuses'][0]['status_source']);
        self::assertSame('Keine Tagesbuchung', $summary['statuses'][0]['note']);
    }

    public function testSummarizeRowsDoesNotDeriveMissingOnWeekends(): void
    {
        $service = new AttendanceService(new DatabaseConnection([]));

        $summary = $service->summarizeRows([], '2026-05-16', [
            [
                'id' => 11,
                'employee_number' => 'MA-0011',
                'first_name' => 'Ben',
                'last_name' => 'Kurz',
                'email' => 'ben@example.test',
            ],
        ]);

        self::assertSame(0, $summary['derived_missing_count']);
        self::assertSame(0, $summary['status_counts']['absent']);
    }
}
