<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class AccountJournalDatabaseTest extends MariaDbTestCase
{
    public function testDifferentVacationEntriesCanEachBeReversedExactlyOnce(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-J', 'email' => 'admin-j@example.test']);
        $userId = $this->createUser();
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $cutover = $cutovers->finalize([
            'user_id' => $userId,
            'effective_from' => '2026-01-01',
            'opening_time_balance' => '+01:00',
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => 30,
            'leave_carryover_days' => 2,
            'opening_remaining_leave_days' => 32,
        ], $adminId);

        $originals = [];
        foreach ([1.0, 2.0, 3.0] as $days) {
            $originals[] = $journal->addVacationEntry($userId, 2026, '2026-02-01', $days, 'manual_adjustment', null, null, 'Testkorrektur', $adminId, $adminId, null, (int) $cutover['id']);
        }

        foreach ($originals as $entryId) {
            $journal->reverseVacationEntry($entryId, $adminId, 'Ausgleich');
        }

        self::assertSame(3, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM vacation_account_entries WHERE entry_type = "reversal"'));
        $entries = $journal->vacationEntriesForUser($userId, 2026, (int) $cutover['id']);
        $openById = array_column($entries, 'is_open', 'id');
        foreach ($originals as $entryId) {
            self::assertSame(0, (int) ($openById[$entryId] ?? -1));
        }

        $this->expectException(InvalidArgumentException::class);
        $journal->reverseVacationEntry($originals[0], $adminId, 'Doppelter Ausgleich');
    }

    public function testReversalEntryCannotBeReversedAgain(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-R', 'email' => 'admin-r@example.test']);
        $userId = $this->createUser();
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $cutover = $cutovers->finalize([
            'user_id' => $userId,
            'effective_from' => '2026-01-01',
            'opening_time_balance' => '+01:00',
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => 30,
            'leave_carryover_days' => 0,
            'opening_remaining_leave_days' => 30,
        ], $adminId);
        $originalId = $journal->addTimeEntry($userId, '2026-02-01', 60, 'manual_adjustment', null, null, 'Testkorrektur', $adminId, $adminId, null, (int) $cutover['id']);
        $reversalId = $journal->reverseTimeEntry($originalId, $adminId, 'Ausgleich');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Gegenbuchungen koennen nicht erneut ausgeglichen werden.');
        $journal->reverseTimeEntry($reversalId, $adminId, 'Unzulaessig');
    }

    public function testZeroOpeningEntriesAreNotReportedAsOpenAndCannotBeReversed(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-Z', 'email' => 'admin-z@example.test']);
        $userId = $this->createUser();
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $cutover = $cutovers->finalize([
            'user_id' => $userId,
            'effective_from' => '2026-01-01',
            'opening_time_balance' => '0:00',
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => 0,
            'leave_carryover_days' => 0,
            'opening_remaining_leave_days' => 0,
        ], $adminId);
        $zeroId = $journal->addVacationEntry(
            $userId, 2027, '2027-01-01', 0, 'annual_entitlement',
            'vacation_year_opening_test', 2027, 'Nullmarker', $adminId, $adminId, null, (int) $cutover['id']
        );
        $entries = $journal->vacationEntriesForUser($userId, 2027, (int) $cutover['id']);
        $openById = array_column($entries, 'is_open', 'id');

        self::assertSame(0, (int) ($openById[$zeroId] ?? -1));

        $this->expectException(InvalidArgumentException::class);
        $journal->reverseVacationEntry($zeroId, $adminId, 'Manipulationsversuch');
    }
}
