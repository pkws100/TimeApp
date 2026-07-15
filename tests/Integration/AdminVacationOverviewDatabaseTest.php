<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Domain\Vacation\VacationRequestService;
use Tests\Support\MariaDbTestCase;

final class AdminVacationOverviewDatabaseTest extends MariaDbTestCase
{
    public function testOverviewSeparatesTakenFutureApprovedPendingAndMissingAccounts(): void
    {
        $currentYear = (int) date('Y');
        $cutoverYear = max(2001, $currentYear - 1);
        $futureYear = min(2099, $currentYear + 1);
        $adminId = $this->createUser(['employee_number' => 'ADMIN-VO', 'email' => 'admin-vo@example.test']);
        $userId = $this->createUser([
            'employee_number' => 'MA-VO-1',
            'email' => 'urlaub-vo@example.test',
            'vacation_days_year' => 30,
            'vacation_carryover_days' => 0,
        ]);
        $missingUserId = $this->createUser(['employee_number' => 'MA-VO-2', 'email' => 'ohne-stichtag-vo@example.test']);
        [$accounts, $cutovers, $requests] = $this->services();
        $cutovers->finalize($this->cutoverPayload($userId, $cutoverYear), $adminId);

        $pastDates = $this->weekdays($cutoverYear, 2, 2);
        $this->insertAbsence($userId, $pastDates[0], 'vacation', 'vacation_paid');
        $this->insertAbsence($userId, $pastDates[1], 'absent', 'unpaid_leave');

        $futureDates = $this->weekdays($futureYear, 3, 4);
        $approved = $requests->createForUser($userId, ['date_from' => $futureDates[0], 'date_to' => $futureDates[0]]);
        $requests->approve((int) $approved['id'], $adminId, 'Genehmigt fuer Uebersicht');
        $requests->createForUser($userId, ['date_from' => $futureDates[1], 'date_to' => $futureDates[1]]);
        $this->insertVacationRequest($userId, $futureDates[2], $futureDates[2], 'rejected');
        $this->insertVacationRequest($userId, $futureDates[3], $futureDates[3], 'cancelled');
        $this->insertAbsence($userId, $futureDates[3], 'absent', 'unpaid_leave');

        $past = $accounts->adminVacationOverview($cutoverYear, $userId)['rows'][0];
        self::assertSame('cutover_snapshot', $past['source']);
        self::assertSame(1.0, $past['vacation']['approved_taken_past_days']);
        self::assertSame(29.0, $past['vacation']['remaining_days']);

        $future = $accounts->adminVacationOverview($futureYear, $userId)['rows'][0];
        self::assertSame('user_defaults', $future['source']);
        self::assertSame(0.0, $future['vacation']['approved_taken_past_days']);
        self::assertSame(1.0, $future['vacation']['future_approved_days']);
        self::assertSame(1.0, $future['vacation']['pending_days']);
        self::assertSame(29.0, $future['vacation']['remaining_days']);
        self::assertSame(28.0, $future['vacation']['available_days']);

        $missing = $accounts->adminVacationOverview($futureYear, $missingUserId)['rows'][0];
        self::assertSame('missing', $missing['account_status']);
        self::assertNull($missing['vacation']);

        $beforeCutover = $accounts->adminVacationOverview($cutoverYear - 1, $userId)['rows'][0];
        self::assertSame('not_active_in_year', $beforeCutover['account_status']);
        self::assertNull($beforeCutover['vacation']);
    }

    public function testAdminRequestYearFilterIncludesCrossYearRequests(): void
    {
        $year = min(2098, (int) date('Y') + 1);
        $userId = $this->createUser(['employee_number' => 'MA-VY', 'email' => 'urlaub-jahr@example.test']);
        [, , $requests] = $this->services();
        $outsideBefore = $this->insertVacationRequest($userId, ($year - 1) . '-11-03', ($year - 1) . '-11-04', 'pending');
        $crossYear = $this->insertVacationRequest($userId, ($year - 1) . '-12-31', $year . '-01-02', 'pending');
        $inside = $this->insertVacationRequest($userId, $year . '-08-03', $year . '-08-04', 'approved');
        $outsideAfter = $this->insertVacationRequest($userId, ($year + 1) . '-02-03', ($year + 1) . '-02-04', 'pending');

        $ids = array_map('intval', array_column($requests->listForAdmin(['year' => $year, 'user_id' => $userId]), 'id'));

        self::assertContains($crossYear, $ids);
        self::assertContains($inside, $ids);
        self::assertNotContains($outsideBefore, $ids);
        self::assertNotContains($outsideAfter, $ids);
    }

    private function services(): array
    {
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $calendar = new CalendarPolicyService($this->connection());
        $dailyTarget = new DailyTargetService($calendar);

        return [
            new TimeAccountService($this->connection(), $calendar, $dailyTarget, $journal, $cutovers),
            $cutovers,
            new VacationRequestService($this->connection(), $calendar, new TimesheetWriteGuard($this->connection()), $dailyTarget),
        ];
    }

    private function cutoverPayload(int $userId, int $year): array
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

    private function weekdays(int $year, int $month, int $count): array
    {
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-02', $year, $month));
        $dates = [];

        while (count($dates) < $count) {
            if ((int) $date->format('N') <= 5) {
                $dates[] = $date->format('Y-m-d');
            }

            $date = $date->modify('+1 day');
        }

        return $dates;
    }

    private function insertAbsence(int $userId, string $date, string $entryType, ?string $reason): void
    {
        $this->connection()->execute(
            'INSERT INTO timesheets (
                user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes,
                credited_minutes, expenses_amount, entry_type, absence_reason_code, source, note, created_at, updated_at, is_deleted
             ) VALUES (
                :user_id, NULL, :work_date, NULL, NULL, 0, 0, 0,
                0, 0, :entry_type, :absence_reason_code, "admin", "Urlaubsuebersicht-Test", NOW(), NOW(), 0
             )',
            ['user_id' => $userId, 'work_date' => $date, 'entry_type' => $entryType, 'absence_reason_code' => $reason]
        );
    }

    private function insertVacationRequest(int $userId, string $dateFrom, string $dateTo, string $status): int
    {
        $this->connection()->execute(
            'INSERT INTO vacation_requests (
                user_id, date_from, date_to, day_count, status, employee_note, requested_at, created_at, updated_at, is_deleted
             ) VALUES (
                :user_id, :date_from, :date_to, 1, :status, "Urlaubsuebersicht-Test", NOW(), NOW(), NOW(), 0
             )',
            ['user_id' => $userId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'status' => $status]
        );

        return $this->connection()->lastInsertId();
    }
}
