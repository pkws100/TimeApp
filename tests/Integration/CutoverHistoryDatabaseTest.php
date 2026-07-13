<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class CutoverHistoryDatabaseTest extends MariaDbTestCase
{
    public function testReversedAndFinalGenerationsRemainDiscoverableAndSeparated(): void
    {
        $adminId = $this->createUser([
            'employee_number' => 'ADMIN-H',
            'first_name' => 'History',
            'last_name' => 'Admin',
            'email' => 'admin-history@example.test',
        ]);
        $userId = $this->createUser(['email' => 'history-employee@example.test']);
        [$accounts, $journal, $cutovers] = $this->services();
        $first = $cutovers->finalize($this->payload($userId, '2026-01-01', '+01:00'), $adminId);
        $journal->addTimeEntry($userId, '2026-02-01', 60, 'manual_adjustment', null, null, 'Alte Generation', $adminId, $adminId, null, (int) $first['id']);
        $cutovers->reverse((int) $first['id'], $adminId, 'Historie pruefen');
        $second = $cutovers->finalize($this->payload($userId, '2026-03-01', '+02:00'), $adminId);
        $journal->addTimeEntry($userId, '2026-03-02', 120, 'manual_adjustment', null, null, 'Aktive Generation', $adminId, $adminId, null, (int) $second['id']);

        $history = $cutovers->cutoversForUser($userId);
        $historicEntries = $accounts->adminJournalEntries($userId, 2026, 100, 1, (int) $first['id']);
        $activeEntries = $accounts->adminJournalEntries($userId, 2026, 100, 1, (int) $second['id']);
        $protocol = $cutovers->protocolData((int) $first['id']);
        $activeProtocol = $cutovers->protocolData((int) $second['id']);
        $pdf = $cutovers->protocolPdf((int) $first['id']);

        self::assertSame([(int) $second['id'], (int) $first['id']], array_map('intval', array_column($history, 'id')));
        self::assertSame(['final', 'reversed'], array_column($history, 'status'));
        self::assertSame('History Admin', $history[0]['finalized_by']);
        self::assertSame('History Admin', $history[1]['reversed_by']);
        self::assertSame('Historie pruefen', $history[1]['reversal_note']);
        self::assertTrue($historicEntries['read_only']);
        self::assertFalse($activeEntries['read_only']);
        self::assertContains('Alte Generation', array_column($historicEntries['time_entries'], 'description'));
        self::assertNotContains('Aktive Generation', array_column($historicEntries['time_entries'], 'description'));
        self::assertSame('Revidiert', $protocol['status_label']);
        self::assertSame('Final', $activeProtocol['status_label']);
        self::assertSame('Historie pruefen', $protocol['reversal_note']);
        self::assertNotSame('', (string) $protocol['reversed_at']);
        self::assertSame('application/pdf', $pdf['headers']['Content-Type']);
        self::assertStringContainsString('<strong>REVIDIERT</strong>', $pdf['source_html']);
        self::assertStringContainsString('Historie pruefen', $pdf['source_html']);
    }

    public function testHistoricGenerationMustBelongToRequestedUser(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-H2', 'email' => 'admin-history-2@example.test']);
        $userId = $this->createUser(['email' => 'history-owner@example.test']);
        $otherUserId = $this->createUser(['email' => 'history-other@example.test']);
        [$accounts, , $cutovers] = $this->services();
        $cutover = $cutovers->finalize($this->payload($userId, '2026-01-01', '0:00'), $adminId);

        $this->expectException(InvalidArgumentException::class);
        $accounts->adminJournalEntries($otherUserId, 2026, 50, 1, (int) $cutover['id']);
    }

    private function services(): array
    {
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $calendar = new CalendarPolicyService($this->connection());
        $accounts = new TimeAccountService($this->connection(), $calendar, new DailyTargetService($calendar), $journal, $cutovers);

        return [$accounts, $journal, $cutovers];
    }

    private function payload(int $userId, string $effectiveFrom, string $opening): array
    {
        return [
            'user_id' => $userId,
            'effective_from' => $effectiveFrom,
            'opening_time_balance' => $opening,
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => 30,
            'leave_carryover_days' => 0,
            'opening_remaining_leave_days' => 30,
        ];
    }
}
