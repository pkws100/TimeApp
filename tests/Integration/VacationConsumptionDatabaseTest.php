<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Exports\TimeAccountExportService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetWriteGuard;
use Tests\Support\MariaDbTestCase;

final class VacationConsumptionDatabaseTest extends MariaDbTestCase
{
    public function testOnlyPaidAndLegacyVacationConsumeAnnualLeave(): void
    {
        $year = min(2099, (int) date('Y') + 1);
        $adminId = $this->createUser(['employee_number' => 'ADMIN-VC', 'email' => 'admin-vc@example.test']);
        $userId = $this->createUser(['vacation_days_year' => 30, 'vacation_carryover_days' => 0]);
        [$accounts, $cutovers] = $this->services();
        $cutovers->finalize($this->payload($userId, $year), $adminId);
        $dates = $this->fiveWeekdays($year);
        $bookingService = new AdminBookingService($this->connection(), new TimesheetCalculator());

        $paid = $bookingService->createManual([
            'user_id' => $userId,
            'work_date' => $dates[0],
            'entry_type' => 'vacation',
            'absence_reason_code' => 'vacation_paid',
            'change_reason' => 'Bezahlter Testurlaub',
        ], $adminId);
        $unpaid = $bookingService->createManual([
            'user_id' => $userId,
            'work_date' => $dates[1],
            'entry_type' => 'vacation',
            'absence_reason_code' => 'unpaid_leave',
            'change_reason' => 'Unbezahlte Testabwesenheit',
        ], $adminId);
        $this->insertAbsence($userId, $dates[2], 'vacation', null, 0);
        $legacyUnpaidId = $this->insertAbsence($userId, $dates[3], 'vacation', 'unpaid_leave', 0);
        $this->insertAbsence($userId, $dates[4], 'absent', 'unpaid_leave', 0);
        $legacyAfterNoteChange = $bookingService->update(
            $legacyUnpaidId,
            ['note' => 'Legacy-Notiz aktualisiert'],
            $adminId,
            'Nur Notiz angepasst'
        );
        $vacationBookings = $bookingService->list($bookingService->normalizeFilters([
            'date_from' => $dates[0],
            'date_to' => $dates[4],
            'entry_type' => 'vacation',
            'scope' => 'active',
        ]));
        $absenceBookings = $bookingService->list($bookingService->normalizeFilters([
            'date_from' => $dates[0],
            'date_to' => $dates[4],
            'entry_type' => 'absent',
            'scope' => 'active',
        ]));
        $bookingExport = $bookingService->exportRows($bookingService->normalizeFilters([
            'date_from' => $dates[0],
            'date_to' => $dates[4],
            'scope' => 'active',
        ]));

        $vacation = $accounts->vacationYear($userId, $year);
        $month = (int) substr($dates[0], 5, 2);
        $monthly = $accounts->monthlyAccount($userId, $year, $month, $year . '-12-31');
        $exportRows = $accounts->adminExportRows($year, $month, ['user_id' => $userId]);
        $csv = (new TimeAccountExportService($accounts))->export('csv', $year, $month, ['user_id' => $userId]);

        self::assertGreaterThan(0, (int) $paid['credited_minutes']);
        self::assertSame('absent', $unpaid['entry_type']);
        self::assertSame(0, (int) $unpaid['credited_minutes']);
        self::assertSame('vacation', $legacyAfterNoteChange['entry_type']);
        self::assertSame('unpaid_leave', $legacyAfterNoteChange['absence_reason_code']);
        self::assertCount(2, $vacationBookings);
        self::assertCount(3, $absenceBookings);
        self::assertNotContains($legacyUnpaidId, array_map('intval', array_column($vacationBookings, 'id')));
        self::assertContains($legacyUnpaidId, array_map('intval', array_column($absenceBookings, 'id')));
        $legacyExportRows = array_values(array_filter(
            $bookingExport,
            static fn (array $row): bool => (string) ($row['Notiz'] ?? '') === 'Legacy-Notiz aktualisiert'
        ));
        self::assertCount(1, $legacyExportRows);
        self::assertSame('absent', $legacyExportRows[0]['Typ']);
        self::assertSame(2.0, $vacation['approved_taken_days']);
        self::assertSame(2.0, $vacation['future_approved_days']);
        self::assertSame(28.0, $vacation['remaining_days']);
        self::assertSame(2.0, $monthly['vacation_days']);
        self::assertSame(3.0, $monthly['absent_days']);
        self::assertSame(2.0, $exportRows[0]['Urlaub genommen']);
        self::assertSame(28.0, $exportRows[0]['Resturlaub']);
        self::assertStringContainsString(';2;', (string) $csv['content']);
    }

    public function testMonthlyVacationDaysAreDistinctAcrossLegacyReasonVariants(): void
    {
        $year = min(2099, (int) date('Y') + 1);
        $adminId = $this->createUser(['employee_number' => 'ADMIN-VD', 'email' => 'admin-vd@example.test']);
        $userId = $this->createUser(['vacation_days_year' => 30, 'vacation_carryover_days' => 0]);
        [$accounts, $cutovers] = $this->services();
        $cutovers->finalize($this->payload($userId, $year), $adminId);
        $date = $this->fiveWeekdays($year)[0];

        $this->insertAbsence($userId, $date, 'vacation', null, 0);
        $this->insertAbsence($userId, $date, 'vacation', 'vacation_paid', 480);

        $monthly = $accounts->monthlyAccount($userId, $year, (int) substr($date, 5, 2), $year . '-12-31');
        $vacation = $accounts->vacationYear($userId, $year);

        self::assertSame(1.0, $monthly['vacation_days']);
        self::assertSame(1.0, $vacation['approved_taken_days']);
    }

    public function testFutureVacationIsLimitedToRequestedLeaveYear(): void
    {
        $currentYear = (int) date('Y');
        $nextYear = min(2099, $currentYear + 1);
        $adminId = $this->createUser(['employee_number' => 'ADMIN-VF', 'email' => 'admin-vf@example.test']);
        $userId = $this->createUser(['vacation_days_year' => 30, 'vacation_carryover_days' => 0]);
        [$accounts, $cutovers] = $this->services();
        $cutovers->finalize($this->payload($userId, $currentYear), $adminId);

        $this->insertAbsence($userId, $currentYear . '-12-15', 'vacation', 'vacation_paid', 480);
        $this->insertAbsence($userId, $nextYear . '-01-15', 'vacation', 'vacation_paid', 480);

        self::assertSame(1.0, $accounts->vacationYear($userId, $nextYear)['future_approved_days']);
    }

    private function services(): array
    {
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $calendar = new CalendarPolicyService($this->connection());
        $accounts = new TimeAccountService($this->connection(), $calendar, new DailyTargetService($calendar), $journal, $cutovers);

        return [$accounts, $cutovers];
    }

    private function payload(int $userId, int $year): array
    {
        return [
            'user_id' => $userId,
            'effective_from' => $year . '-01-01',
            'opening_time_balance' => '0:00',
            'leave_year' => $year,
            'annual_leave_entitlement_days' => 30,
            'leave_carryover_days' => 0,
            'opening_remaining_leave_days' => 30,
        ];
    }

    private function fiveWeekdays(int $year): array
    {
        $date = new \DateTimeImmutable($year . '-01-15');
        while ((int) $date->format('N') !== 1) {
            $date = $date->modify('+1 day');
        }

        return array_map(
            static fn (int $offset): string => $date->modify('+' . $offset . ' days')->format('Y-m-d'),
            range(0, 4)
        );
    }

    private function insertAbsence(int $userId, string $date, string $entryType, ?string $reason, int $creditedMinutes): int
    {
        $this->connection()->execute(
            'INSERT INTO timesheets (
                user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes,
                credited_minutes, expenses_amount, entry_type, absence_reason_code, source, note, created_at, updated_at, is_deleted
             ) VALUES (
                :user_id, NULL, :work_date, NULL, NULL, 0, 0, 0,
                :credited_minutes, 0, :entry_type, :absence_reason_code, "admin", "Legacy-Test", NOW(), NOW(), 0
             )',
            [
                'user_id' => $userId,
                'work_date' => $date,
                'credited_minutes' => $creditedMinutes,
                'entry_type' => $entryType,
                'absence_reason_code' => $reason,
            ]
        );

        return $this->connection()->lastInsertId();
    }
}
