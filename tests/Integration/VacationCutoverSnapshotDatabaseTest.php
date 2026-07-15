<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\TimeAccounts\VacationAccountYearService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use Tests\Support\MariaDbTestCase;

final class VacationCutoverSnapshotDatabaseTest extends MariaDbTestCase
{
    public function testZeroCutoverSnapshotOverridesNonZeroUserDefaults(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-S0', 'email' => 'admin-s0@example.test']);
        $userId = $this->createUser(['vacation_days_year' => 30, 'vacation_carryover_days' => 5]);
        [$cutovers, , $accounts] = $this->services();
        $cutovers->finalize($this->payload($userId, 2026, 0, 0, 0), $adminId);

        $vacation = $accounts->vacationYear($userId, 2026);

        self::assertSame('cutover_snapshot', $vacation['source']);
        self::assertSame(0.0, $vacation['entitlement_days']);
        self::assertSame(0.0, $vacation['carryover_days']);
        self::assertSame(0.0, $vacation['opening_balance_days']);
        self::assertSame(0.0, $vacation['remaining_days']);
    }

    public function testCutoverYearAdjustmentDoesNotOpenDefaultsAgain(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-S1', 'email' => 'admin-s1@example.test']);
        $userId = $this->createUser(['vacation_days_year' => 30, 'vacation_carryover_days' => 5]);
        [$cutovers, , $accounts] = $this->services();
        $cutover = $cutovers->finalize($this->payload($userId, 2026, 0, 2, 2), $adminId);
        $cutovers->addManualVacationAdjustment($userId, 2026, '2026-02-01', 1, 'Snapshotkorrektur', $adminId);

        $vacation = $accounts->vacationYear($userId, 2026);

        self::assertSame('cutover_snapshot', $vacation['source']);
        self::assertSame(0.0, $vacation['entitlement_days']);
        self::assertSame(2.0, $vacation['carryover_days']);
        self::assertSame(2.0, $vacation['opening_balance_days']);
        self::assertSame(1.0, $vacation['manual_adjustment_days']);
        self::assertSame(3.0, $vacation['total_days']);
        self::assertSame(0, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM vacation_account_entries
             WHERE cutover_id = :cutover_id AND leave_year = 2026 AND entry_type = "annual_entitlement"',
            ['cutover_id' => $cutover['id']]
        ));
    }

    public function testChangedDefaultsDoNotAlterOpenedCutoverYear(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-S2', 'email' => 'admin-s2@example.test']);
        $userId = $this->createUser(['vacation_days_year' => 30, 'vacation_carryover_days' => 0]);
        [$cutovers, , $accounts] = $this->services();
        $cutovers->finalize($this->payload($userId, 2026, 30, 2, 25), $adminId);
        $this->connection()->execute(
            'UPDATE users SET vacation_days_year = 35, vacation_carryover_days = 4 WHERE id = :id',
            ['id' => $userId]
        );

        $vacation = $accounts->vacationYear($userId, 2026);

        self::assertSame(30.0, $vacation['entitlement_days']);
        self::assertSame(2.0, $vacation['carryover_days']);
        self::assertSame(-7.0, $vacation['opening_adjustment_days']);
        self::assertSame(25.0, $vacation['opening_balance_days']);
        self::assertSame(25.0, $vacation['total_days']);
    }

    public function testCutoverOpeningBalanceMatchesTheRequestedRemainingLeave(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-S4', 'email' => 'admin-s4@example.test']);
        $userId = $this->createUser();
        [$cutovers, , $accounts] = $this->services();
        $cutovers->finalize($this->payload($userId, 2026, 27, 1, 15), $adminId);

        $vacation = $accounts->vacationYear($userId, 2026);

        self::assertSame(27.0, $vacation['entitlement_days']);
        self::assertSame(1.0, $vacation['carryover_days']);
        self::assertSame(-13.0, $vacation['opening_adjustment_days']);
        self::assertSame(15.0, $vacation['opening_balance_days']);
        self::assertSame(15.0, $vacation['remaining_days']);
    }

    public function testNewGenerationUsesOnlyItsOwnSnapshot(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-S3', 'email' => 'admin-s3@example.test']);
        $userId = $this->createUser();
        [$cutovers, , $accounts] = $this->services();
        $first = $cutovers->finalize($this->payload($userId, 2026, 30, 2, 25), $adminId);
        $cutovers->reverse((int) $first['id'], $adminId, 'Neue Snapshotgeneration');
        $second = $cutovers->finalize($this->payload($userId, 2026, 12, 1, 9), $adminId);

        $vacation = $accounts->vacationYear($userId, 2026);

        self::assertNotSame((int) $first['id'], (int) $second['id']);
        self::assertSame(12.0, $vacation['entitlement_days']);
        self::assertSame(1.0, $vacation['carryover_days']);
        self::assertSame(-4.0, $vacation['opening_adjustment_days']);
        self::assertSame(9.0, $vacation['opening_balance_days']);
    }

    private function services(): array
    {
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $years = new VacationAccountYearService($this->connection(), $journal, $cutovers);
        $cutovers->setVacationYearService($years);
        $calendar = new CalendarPolicyService($this->connection());
        $accounts = new TimeAccountService($this->connection(), $calendar, new DailyTargetService($calendar), $journal, $cutovers);

        return [$cutovers, $years, $accounts];
    }

    private function payload(int $userId, int $year, float $entitlement, float $carryover, float $remaining): array
    {
        return [
            'user_id' => $userId,
            'effective_from' => $year . '-01-01',
            'opening_time_balance' => '0:00',
            'leave_year' => $year,
            'annual_leave_entitlement_days' => $entitlement,
            'leave_carryover_days' => $carryover,
            'opening_remaining_leave_days' => $remaining,
        ];
    }
}
