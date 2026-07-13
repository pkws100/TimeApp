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

final class VacationAccountYearDatabaseTest extends MariaDbTestCase
{
    public function testYearsAndCutoverGenerationsAreOpenedIndependently(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-V', 'email' => 'admin-v@example.test']);
        $userId = $this->createUser(['vacation_days_year' => 30, 'vacation_carryover_days' => 2]);
        [$cutovers, $years, $accounts] = $this->services();
        $first = $cutovers->finalize($this->payload($userId, 2026, 2, 32), $adminId);

        $this->connection()->execute(
            'UPDATE users SET vacation_carryover_days = 0 WHERE id = :id',
            ['id' => $userId]
        );
        $years->ensureYearOpened($userId, 2027, $adminId);
        $years->ensureYearOpened($userId, 2027, $adminId);
        $cutovers->addManualVacationAdjustment($userId, 2027, '2027-02-01', 1.0, 'Korrektur 2027', $adminId);

        $vacation2027 = $accounts->vacationYear($userId, 2027);
        self::assertSame(31.0, $vacation2027['opening_balance_days'] + $vacation2027['manual_adjustment_days']);
        self::assertSame(1, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM vacation_account_entries WHERE cutover_id = :cutover_id AND leave_year = 2027 AND entry_type = "annual_entitlement"',
            ['cutover_id' => $first['id']]
        ));
        self::assertSame(30.0, $accounts->vacationYear($userId, 2026)['entitlement_days']);

        $cutovers->reverse((int) $first['id'], $adminId, 'Neue Generation');
        $second = $cutovers->finalize($this->payload($userId, 2027, 0, 30), $adminId);
        self::assertNotSame((int) $first['id'], (int) $second['id']);
        self::assertSame(30.0, $accounts->vacationYear($userId, 2027)['available_days']);
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

    private function payload(int $userId, int $year, float $carryover, float $remaining): array
    {
        return [
            'user_id' => $userId,
            'effective_from' => $year . '-01-01',
            'opening_time_balance' => '0:00',
            'leave_year' => $year,
            'annual_leave_entitlement_days' => 30,
            'leave_carryover_days' => $carryover,
            'opening_remaining_leave_days' => $remaining,
        ];
    }
}
