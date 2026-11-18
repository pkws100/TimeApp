<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class WorkTimeHistoricalAssignmentDatabaseTest extends MariaDbTestCase
{
    private const SECRET = 'synthetic historical confirmation test';

    public function testHistoricalImpactShowsLockedDaysAndLeavesTimesheetsCutoverAndJournalsUntouched(): void
    {
        [$actor, $employee, $service, $template] = $this->setupPolicies();
        $today = $this->today();
        $date = $today->modify('-4 days')->format('Y-m-d');
        $id = $this->work($employee, $date);
        $cutover = new EmployeeAccountCutoverService($this->connection(), new AccountJournalService($this->connection()));
        $cutover->finalize([
            'user_id' => $employee, 'effective_from' => $today->format('Y-m-d'), 'opening_time_balance' => '+01:30',
            'leave_year' => (int) $today->format('Y'), 'annual_leave_entitlement_days' => 30, 'opening_remaining_leave_days' => 20,
        ], $actor);
        $before = $this->accountingRows();
        $payload = $this->input($employee, $template['version']['id'], $date, $date);
        $this->reject(fn () => $service->assign($payload, $actor), 'ausdrücklich bestätigen');
        $impact = $service->assignmentPreview($payload, $actor, self::SECRET);
        self::assertTrue($impact['historical']);
        self::assertTrue($impact['preview_only']);
        self::assertSame(1, $impact['workday_count']);
        self::assertSame(1, $impact['closed_workday_count']);
        self::assertSame($id, $impact['rows'][0]['id']);
        self::assertSame(503, $impact['rows'][0]['after']['actual_work_minutes']);
        self::assertSame(53, $impact['rows'][0]['after']['outside_pending_minutes']);
        self::assertSame(450, $impact['rows'][0]['after']['inside_window_minutes']);
        self::assertSame(30, $impact['rows'][0]['after']['documented_break_minutes']);
        self::assertSame(-53, $impact['accountable_delta_minutes']);

        $approved = $payload + ['preview_token' => $impact['preview_token'], 'confirm_impact' => '1'];
        $this->reject(fn () => $service->assign($approved, $employee, self::SECRET), 'erneut prüfen');
        $result = $service->assign($approved, $actor, self::SECRET);
        self::assertSame($result['id'], $service->policyFor($employee, $date)['assignment_id']);
        $readModel = new WorkTimePreviewService($this->connection(), $service);
        self::assertSame(450, $readModel->forUserPeriod($employee, $date, $date)['inside_window_minutes']);
        self::assertSame(['created' => 0, 'superseded' => 0], $readModel->reconcileUser($employee, $date, $date));
        self::assertSame($before, $this->accountingRows());
        self::assertSame('Historischer Nachweis', $service->assignmentHistory($employee)[0]['after']['reason']);
    }

    public function testAChangeToAProposedBookingInvalidatesThePreviewBeforeAnyWrites(): void
    {
        [$actor, $employee, $service, $template] = $this->setupPolicies();
        $date = $this->today()->modify('-2 days')->format('Y-m-d');
        $entry = $this->work($employee, $date);
        $payload = $this->input($employee, $template['version']['id'], $date, $date);
        $first = $service->assignmentPreview($payload, $actor, self::SECRET);
        $this->connection()->execute('UPDATE timesheets SET end_time = "16:45:00", gross_minutes = 543, net_minutes = 513 WHERE id = :id', ['id' => $entry]);
        $this->reject(fn () => $service->assign($payload + ['preview_token' => $first['preview_token'], 'confirm_impact' => '1'], $actor, self::SECRET), 'erneut prüfen');
        self::assertSame([], $service->listAssignments($employee));
        $second = $service->assignmentPreview($payload, $actor, self::SECRET);
        self::assertNotSame($first['preview_token'], $second['preview_token']);
        self::assertSame(513, $second['rows'][0]['after']['actual_work_minutes']);
    }

    public function testExplicitReplacementPreservesThePriorAndResumedRulesAndAllowsASameDayReplacement(): void
    {
        [$actor, $employee, $service, $template] = $this->setupPolicies();
        $base = $this->today()->modify('first day of last month');
        $day = static fn (int $index): string => $base->modify('+' . $index . ' days')->format('Y-m-d');
        $old = $this->save($service, $this->input($employee, $template['version']['id'], $day(0), $day(12)), $actor);
        $preview = new WorkTimePreviewService($this->connection(), $service);
        $this->work($employee, $day(2));
        $this->work($employee, $day(6));
        $restored = $this->work($employee, $day(10));
        $preview->reconcileUser($employee, $day(10), $day(10));
        $this->connection()->execute('UPDATE work_time_deviations SET status = "approved" WHERE timesheet_id = :id', ['id' => $restored]);
        $before = $preview->forUserPeriod($employee, $day(10), $day(10));

        $new = $service->addVersion($template['id'], ['windows' => $this->windows('09:00', '17:00')], $actor);
        $payload = $this->input($employee, $new['id'], $day(4), $day(8));
        $impact = $service->assignmentPreview($payload, $actor, self::SECRET);
        self::assertSame([$old['id']], array_column($impact['overlaps'], 'id'));
        $this->reject(fn () => $service->assign($payload + ['preview_token' => $impact['preview_token'], 'confirm_impact' => '1'], $actor, self::SECRET), 'Ersetzung');
        $changed = $service->assign($payload + ['preview_token' => $impact['preview_token'], 'confirm_impact' => '1', 'replace_overlaps' => '1'], $actor, self::SECRET);
        self::assertSame($new['id'], $service->policyFor($employee, $day(6))['policy_version_id']);
        self::assertSame($old['id'], $service->policyFor($employee, $day(2))['assignment_id']);
        self::assertSame($old['id'], $service->policyFor($employee, $day(10))['carried_from_assignment_id']);
        $after = $preview->forUserPeriod($employee, $day(10), $day(10));
        self::assertSame($before['outside_approved_minutes'], $after['outside_approved_minutes']);
        self::assertSame([$template['version']['id'], $new['id'], $template['version']['id']], array_map(static fn (array $row): int => $row['policy']['policy_version_id'], $preview->forUserPeriod($employee, $day(0), $day(12))['rows']));

        $sameDay = $this->save($service, $this->input($employee, $template['version']['id'], $day(4), $day(8)), $actor);
        self::assertSame($sameDay['id'], $service->policyFor($employee, $day(6))['assignment_id']);
        self::assertNotSame($changed['id'], $sameDay['id']);
        $history = array_column($service->listAssignments($employee, true), null, 'id');
        self::assertSame($changed['id'], (int) $history[$changed['id']]['interval_revision']);
        self::assertSame($day(4), $history[$changed['id']]['effective_from']);
        self::assertCount(3, $service->listAssignments($employee));
    }

    public function testHistoricallyEndingAnAssignmentRequiresItsOwnFreshConfirmation(): void
    {
        [$actor, $employee, $service, $template] = $this->setupPolicies();
        $from = $this->today()->modify('-7 days')->format('Y-m-d');
        $until = $this->today()->modify('-4 days')->format('Y-m-d');
        $removed = $this->today()->modify('-2 days')->format('Y-m-d');
        $assignment = $this->save($service, $this->input($employee, $template['version']['id'], $from), $actor);
        $this->work($employee, $removed);
        $impact = $service->endAssignmentPreview($assignment['id'], $until, $actor, 'Neue belegte Gültigkeit', self::SECRET);
        self::assertTrue($impact['historical']);
        self::assertSame($removed, $impact['rows'][0]['work_date']);
        self::assertNull($impact['rows'][0]['after_version']);
        $this->reject(fn () => $service->endAssignment($assignment['id'], $until, $actor, 'Neue belegte Gültigkeit'), 'ausdrücklich bestätigen');
        $service->endAssignment($assignment['id'], $until, $actor, 'Neue belegte Gültigkeit', self::SECRET, ['preview_token' => $impact['preview_token'], 'confirm_impact' => '1']);
        self::assertNotNull($service->policyFor($employee, $until));
        self::assertNull($service->policyFor($employee, $removed));
        self::assertSame('Neue belegte Gültigkeit', $service->assignmentHistory($employee)[0]['after']['reason']);
    }

    public function testRetroactiveRulesReconcileClosedWorkWithoutGeneratingRealtimeEventsForOldOpenWork(): void
    {
        [$actor, $employee, $service, $template] = $this->setupPolicies();
        $date = $this->today()->modify('-2 days')->format('Y-m-d');
        $closed = $this->work($employee, $date);
        $this->connection()->execute('INSERT INTO timesheets (user_id, work_date, start_time, entry_type, source, gross_minutes, break_minutes, net_minutes) VALUES (:user, :date, "08:00:00", "work", "app", 0, 0, 0)', ['user' => $employee, 'date' => $date]);
        $this->connection()->execute('INSERT INTO timesheets (user_id, work_date, start_time, entry_type, source, gross_minutes, break_minutes, net_minutes) VALUES (:user, :date, "19:00:00", "work", "app", 0, 0, 0)', ['user' => $employee, 'date' => $date]);
        $payload = $this->input($employee, $template['version']['id'], $date, $date);
        $payload['overrides'] = ['checkout_proposals_enabled' => true, 'outside_window_notifications_enabled' => true, 'notify_employee_push' => true, 'notify_employee_sms' => true];
        $this->save($service, $payload, $actor);
        $connection = $this->connection();
        $preview = new WorkTimePreviewService($connection, $service);
        $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
        $events = new WorkTimeEventService($connection, $service, $sync, null, null, $preview);
        $result = $events->process(false, $this->today()->modify('+20 hours'));
        self::assertSame(0, $result['errors']);
        self::assertSame(0, $result['proposals']);
        self::assertSame(0, $result['outside_notifications_queued']);
        self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM work_time_notification_events'));
        self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM work_time_checkout_proposals'));
        self::assertSame(2, (int) $connection->fetchColumn('SELECT COUNT(*) FROM work_time_deviations WHERE timesheet_id = :id', ['id' => $closed]));
    }

    private function setupPolicies(): array
    {
        $actor = $this->createUser();
        $employee = $this->createUser();
        $service = new WorkTimePolicyService($this->connection(), true);
        $template = $service->createTemplate(['name' => 'Bestandsregel', 'windows' => $this->windows('08:00', '16:00')], $actor);
        return [$actor, $employee, $service, $template];
    }

    private function windows(string $start, string $end): array
    {
        return array_fill(1, 7, [['start' => $start, 'end' => $end]]);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
    }

    private function input(int $employee, int $version, string $from, ?string $until = null): array
    {
        return ['user_id' => $employee, 'policy_version_id' => $version, 'effective_from' => $from, 'effective_to' => $until, 'reason' => 'Historischer Nachweis'];
    }

    private function save(WorkTimePolicyService $service, array $input, int $actor): array
    {
        $impact = $service->assignmentPreview($input, $actor, self::SECRET);
        return $service->assign($input + ['preview_token' => $impact['preview_token'], 'confirm_impact' => '1', 'replace_overlaps' => '1'], $actor, self::SECRET);
    }

    private function work(int $employee, string $date): int
    {
        $connection = $this->connection();
        $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source) VALUES (:user, :date, "07:42:00", "16:35:00", 533, 30, 503, "work", "app")', ['user' => $employee, 'date' => $date]);
        $id = $connection->lastInsertId();
        $connection->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source) VALUES (:id, :user, :started, :ended, "app")', ['id' => $id, 'user' => $employee, 'started' => $date . ' 12:00:00', 'ended' => $date . ' 12:30:00']);
        return $id;
    }

    private function accountingRows(): array
    {
        $snapshot = [];
        foreach (['timesheets', 'employee_account_cutovers', 'time_account_entries', 'vacation_account_entries', 'accounting_closures'] as $table) {
            $history = $table === 'timesheets' ? ', ROW_START, ROW_END' : '';
            $systemTime = $table === 'timesheets' ? ' FOR SYSTEM_TIME ALL' : '';
            $snapshot[$table] = $this->connection()->fetchAll('SELECT *' . $history . ' FROM ' . $table . $systemTime . ' ORDER BY id' . ($history !== '' ? ', ROW_START' : ''));
        }
        return $snapshot;
    }

    private function reject(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('The unsafe historic write was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }
}
