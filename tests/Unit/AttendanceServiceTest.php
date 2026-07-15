<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Attendance\AttendanceService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class AttendanceServiceTest extends TestCase
{
    public function testLegacyUnpaidVacationIsReportedAsAbsence(): void
    {
        $service = new AttendanceService(new DatabaseConnection([]));
        $summary = $service->summarizeRows([[
            'id' => 1,
            'user_id' => 10,
            'entry_type' => 'vacation',
            'absence_reason_code' => 'unpaid_leave',
            'user_is_deleted' => 0,
            'first_name' => 'Anna',
            'last_name' => 'Berg',
        ]], '2026-05-15');

        self::assertSame('absent', $summary['statuses'][0]['entry_type']);
        self::assertSame(0, $summary['status_counts']['vacation']);
        self::assertSame(1, $summary['status_counts']['absent']);
    }

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
        self::assertSame(0, $summary['currently_present_count']);
        self::assertCount(0, $summary['currently_present']);
        self::assertSame(1, $summary['completed_count']);
        self::assertCount(1, $summary['completed']);
        self::assertSame('Anna Berg', $summary['completed'][0]['user_name']);
        self::assertSame('Projekt Alpha', $summary['completed'][0]['location']);
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
        self::assertSame(1, $summary['present_count']);
        self::assertSame(0, $summary['currently_present_count']);
        self::assertSame(1, $summary['completed_count']);
        self::assertSame(1, $summary['status_counts']['absent']);
        self::assertSame('absent', $summary['statuses'][0]['entry_type']);
        self::assertTrue($summary['statuses'][0]['is_derived']);
        self::assertSame('derived_missing', $summary['statuses'][0]['status_source']);
        self::assertSame('Keine Tagesbuchung', $summary['statuses'][0]['note']);
        self::assertSame(2, $summary['chart']['workforce_count']);
        self::assertSame(0, $summary['chart']['currently_present_count']);
        self::assertSame(1, $summary['chart']['prevented_count']);
        self::assertSame(50.0, $summary['chart']['prevented_percent']);
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

    public function testSummarizeRowsDoesNotDeriveMissingForUsersWithoutTimeTrackingRequirement(): void
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
                'time_tracking_required' => 1,
            ],
            [
                'id' => 11,
                'employee_number' => 'MA-0011',
                'first_name' => 'Ben',
                'last_name' => 'Kurz',
                'email' => 'ben@example.test',
                'time_tracking_required' => 0,
            ],
        ]);

        self::assertSame(0, $summary['derived_missing_count']);
        self::assertSame(0, $summary['status_counts']['absent']);
    }

    public function testSummarizeRowsSeparatesOpenWorkFromCompletedWorkAndAggregatesDailyDuration(): void
    {
        $service = new AttendanceService(new DatabaseConnection([]));

        $summary = $service->summarizeRows([
            [
                'id' => 1, 'user_id' => 10, 'entry_type' => 'work', 'user_is_deleted' => 0,
                'employee_number' => 'MA-0010', 'first_name' => 'Anna', 'last_name' => 'Berg',
                'project_name' => 'Projekt Alpha', 'project_is_deleted' => 0, 'start_time' => '07:00', 'end_time' => '11:00', 'net_minutes' => 225, 'updated_at' => '2026-05-15 18:00:00', 'note' => '',
            ],
            [
                'id' => 2, 'user_id' => 10, 'entry_type' => 'work', 'user_is_deleted' => 0,
                'employee_number' => 'MA-0010', 'first_name' => 'Anna', 'last_name' => 'Berg',
                'project_name' => 'Projekt Beta', 'project_is_deleted' => 0, 'start_time' => '12:00', 'end_time' => '16:00', 'net_minutes' => 225, 'updated_at' => '2026-05-15 16:00:00', 'note' => 'Feierabend',
            ],
            [
                'id' => 3, 'user_id' => 11, 'entry_type' => 'work', 'user_is_deleted' => 0,
                'employee_number' => 'MA-0011', 'first_name' => 'Ben', 'last_name' => 'Kurz',
                'project_name' => 'Projekt Beta', 'project_is_deleted' => 0, 'start_time' => '08:00', 'end_time' => null, 'net_minutes' => 0, 'updated_at' => '2026-05-15 08:00:00', 'note' => '',
            ],
        ], '2026-05-15');

        self::assertSame(1, $summary['currently_present_count']);
        self::assertSame('Ben Kurz', $summary['currently_present'][0]['user_name']);
        self::assertSame(1, $summary['completed_count']);
        self::assertSame('Anna Berg', $summary['completed'][0]['user_name']);
        self::assertSame('Projekt Beta', $summary['completed'][0]['location']);
        self::assertSame('07:00', $summary['completed'][0]['start_time']);
        self::assertSame('16:00', $summary['completed'][0]['end_time']);
        self::assertSame(450, $summary['completed'][0]['net_minutes']);
        self::assertSame(2, $summary['completed'][0]['work_entry_count']);
        self::assertSame(2, $summary['chart']['workforce_count']);
        self::assertSame(1, $summary['chart']['currently_present_count']);
        self::assertSame(50.0, $summary['chart']['readiness_percent']);
    }

    public function testSummarizeRowsDoesNotClassifyIncompleteWorkEntriesAsPresentOrCompleted(): void
    {
        $service = new AttendanceService(new DatabaseConnection([]));

        $summary = $service->summarizeRows([
            [
                'id' => 1, 'user_id' => 10, 'entry_type' => 'work', 'user_is_deleted' => 0,
                'first_name' => 'Anna', 'last_name' => 'Berg', 'start_time' => null, 'end_time' => null, 'net_minutes' => 0,
            ],
            [
                'id' => 2, 'user_id' => 11, 'entry_type' => 'work', 'user_is_deleted' => 0,
                'first_name' => 'Ben', 'last_name' => 'Kurz', 'start_time' => null, 'end_time' => '15:00', 'net_minutes' => 0,
            ],
            [
                'id' => 3, 'user_id' => 12, 'entry_type' => 'work', 'user_is_deleted' => 0,
                'first_name' => 'Cara', 'last_name' => 'Neu', 'start_time' => '08:00', 'end_time' => '16:00', 'net_minutes' => 450,
            ],
            [
                'id' => 4, 'user_id' => 12, 'entry_type' => 'work', 'user_is_deleted' => 0,
                'first_name' => 'Cara', 'last_name' => 'Neu', 'start_time' => null, 'end_time' => null, 'net_minutes' => 0,
            ],
        ], '2026-05-15');

        self::assertSame(0, $summary['currently_present_count']);
        self::assertSame(1, $summary['completed_count']);
        self::assertSame('Cara Neu', $summary['completed'][0]['user_name']);
        self::assertSame(450, $summary['completed'][0]['net_minutes']);
    }

    public function testSummarizeRowsExcludesInactiveUsersFromTheCurrentWorkforceChart(): void
    {
        $service = new AttendanceService(new DatabaseConnection([]));

        $summary = $service->summarizeRows([
            [
                'id' => 1, 'user_id' => 10, 'entry_type' => 'work', 'user_is_deleted' => 0,
                'first_name' => 'Ehemalig', 'last_name' => 'Mitarbeiter', 'start_time' => '08:00', 'end_time' => null, 'net_minutes' => 0,
            ],
        ], '2026-05-15', [
            [
                'id' => 11, 'employee_number' => 'MA-0011', 'first_name' => 'Aktiv', 'last_name' => 'Mitarbeiter', 'email' => 'active@example.test', 'time_tracking_required' => 1,
            ],
        ]);

        self::assertSame(0, $summary['currently_present_count']);
        self::assertSame(0, $summary['completed_count']);
        self::assertSame(1, $summary['present_count']);
        self::assertSame(1, $summary['chart']['workforce_count']);
        self::assertSame(1, $summary['chart']['absent_count']);
        self::assertSame(100.0, $summary['chart']['prevented_percent']);
    }
}
