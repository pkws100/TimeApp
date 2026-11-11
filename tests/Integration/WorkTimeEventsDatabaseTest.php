<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Terminals\NfcTagService;
use App\Domain\Terminals\TerminalPunchService;
use App\Domain\Terminals\TerminalService;
use App\Domain\Terminals\TerminalTrustBundleService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\MockSmsProvider;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimeDeviationService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePresenceService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class WorkTimeEventsDatabaseTest extends MariaDbTestCase
{
    private function services(bool $enabled = true, array $policy = [], ?callable $push = null, ?MockSmsProvider $sms = null): array
    {
        $user = $this->createUser();
        $policies = new WorkTimePolicyService($this->connection(), $enabled);
        $windows = array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']]);
        $template = $policies->createTemplate(['name' => 'Testfenster', 'policy' => array_replace(['windows' => $windows, 'checkout_proposals_enabled' => true, 'checkout_delay_minutes' => 0, 'early_check_in_mode' => 'work_started', 'notify_employee_push' => false], $policy)], $user);
        $assignment = $policies->assign(['user_id' => $user, 'policy_version_id' => $template['version']['id'], 'effective_from' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d')], $user);
        // Only synthetic fixtures are backdated; production API forbids past activation.
        $date = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date_from WHERE id = :id', ['date_from' => $date, 'id' => $assignment['id']]);
        $sync = new AppTimesheetSyncService($this->connection(), new TimesheetCalculator(), new CompanySettingsService($this->connection(), []), new WorkdayStateCalculator());
        $sync->setWorkTimePresenceService(new WorkTimePresenceService($this->connection(), $policies));
        $events = new WorkTimeEventService($this->connection(), $policies, $sync, $push, $sms);
        return [$user, $policies, $sync, $events, $date];
    }

    private function checkIn(AppTimesheetSyncService $sync, int $user, string $date, string $start = '08:00', string $request = 'in'): array
    {
        return $sync->sync($user, ['client_request_id' => $request, 'action' => 'check_in', 'work_date' => $date, 'start_time' => $start]);
    }

    private function createProposal(WorkTimeEventService $events, int $user, string $date): array
    {
        $events->process(false, new DateTimeImmutable($date . ' 16:30', new DateTimeZone('Europe/Berlin')));
        return $events->statusForUser($user)['checkout_proposals'][0];
    }

    public function testDisabledFeaturePreservesEarlyCheckInAndCreatesNoEvents(): void
    {
        [$user, , $sync, $events, $date] = $this->services(false, ['early_check_in_mode' => 'presence_only']);
        $response = $this->checkIn($sync, $user, $date, '07:42');
        self::assertSame('synced', $response['status']);
        self::assertSame('07:42:00', $response['timesheet']['start_time']);
        self::assertFalse($events->process()['enabled']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_presence_sessions'));
    }

    public function testNoAssignmentLeavesNormalCheckInUntouched(): void
    {
        [, , $sync, , $date] = $this->services(true, ['early_check_in_mode' => 'presence_only']);
        $other = $this->createUser();
        $response = $this->checkIn($sync, $other, $date, '07:42');
        self::assertSame('synced', $response['status']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_presence_sessions'));
    }

    public function testPresenceNeverInventsWorkStartAndCanBeExplicitlyConfirmed(): void
    {
        [$user, , $sync, $events, $date] = $this->services(true, ['early_check_in_mode' => 'presence_only']);
        $presence = $this->checkIn($sync, $user, $date, '07:42');
        self::assertSame('presence_only', $presence['status']);
        self::assertNull($presence['timesheet']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id', ['user_id' => $user]));
        $events->process(false, new DateTimeImmutable($date . ' 16:30'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id', ['user_id' => $user]));
        $work = $sync->sync($user, ['client_request_id' => 'start', 'action' => 'work_start', 'work_date' => $date, 'start_time' => '07:45']);
        self::assertSame('07:45:00', $work['timesheet']['start_time']);
        self::assertNull($sync->openPresenceForUser($user));
        self::assertSame('07:45:00', substr((string) $this->connection()->fetchColumn('SELECT work_started_at FROM work_time_presence_sessions'), 11));
    }

    public function testPresenceCanEndWithoutAnyWorkEntry(): void
    {
        [$user, , $sync, , $date] = $this->services(true, ['early_check_in_mode' => 'presence_only']);
        $this->checkIn($sync, $user, $date, '07:42');
        $result = $sync->sync($user, ['client_request_id' => 'presence-end', 'action' => 'presence_end', 'work_date' => $date]);
        self::assertSame('presence_ended', $result['status']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id', ['user_id' => $user]));
    }

    public function testPresenceCanTransitionToActualWorkAfterMidnight(): void
    {
        [$user, , $sync, , $date] = $this->services(true, ['early_check_in_mode' => 'presence_only', 'windows' => array_fill(1, 7, [['start' => '22:00', 'end' => '06:00']])]);
        $presenceDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date_from WHERE user_id = :user_id', ['date_from' => $presenceDate, 'user_id' => $user]);
        self::assertSame('presence_only', $this->checkIn($sync, $user, $presenceDate, '21:00')['status']);
        $work = $sync->sync($user, ['client_request_id' => 'night-work-start', 'action' => 'work_start', 'work_date' => $date, 'start_time' => '00:10']);
        self::assertSame($date, $work['timesheet']['work_date']);
        self::assertSame('00:10:00', $work['timesheet']['start_time']);
        self::assertSame($presenceDate, $this->connection()->fetchColumn('SELECT work_date FROM work_time_presence_sessions WHERE user_id = :id', ['id' => $user]));
    }

    public function testCheckInAfterMidnightUsesPreviousNightsWindowAndCheckoutProposal(): void
    {
        [$user, , $sync, $events, $date] = $this->services(true, ['early_check_in_mode' => 'presence_only', 'windows' => array_fill(1, 7, [['start' => '22:00', 'end' => '06:00']])]);
        $in = $this->checkIn($sync, $user, $date, '00:10');
        self::assertSame('synced', $in['status']);
        self::assertSame('00:10:00', $in['timesheet']['start_time']);
        $events->process(false, new DateTimeImmutable($date . ' 06:30'));
        $proposal = $events->statusForUser($user)['checkout_proposals'][0];
        self::assertSame($date . ' 06:00:00', $proposal['proposed_end_at']);
        self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $in['timesheet']['id']]));
    }

    public function testDryRunAndRepeatedWorkerDoNotCloseActualWork(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $now = new DateTimeImmutable($date . ' 16:30');
        self::assertSame(1, $events->process(true, $now)['proposals']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_checkout_proposals'));
        self::assertSame(1, $events->process(false, $now)['proposals']);
        self::assertSame(0, $events->process(false, $now)['proposals']);
        self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE user_id = :user_id', ['user_id' => $user]));
    }

    public function testContinueKeepsSessionAndConfirmationAllowsAnotherSession(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        self::assertSame('continued', $events->respond($user, (int) $proposal['id'], 'continue', null, 'continue')['status']);
        self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE user_id = :user_id', ['user_id' => $user]));
        $sync->sync($user, ['client_request_id' => 'actual-out', 'action' => 'check_out', 'work_date' => $date, 'end_time' => '16:35']);
        $this->checkIn($sync, $user, $date, '17:00', 'second-in');
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id', ['user_id' => $user]));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id AND end_time IS NULL', ['user_id' => $user]));
    }

    public function testConfirmationIsAtomicAndIdempotentAndAllowsRecheckIn(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $result = $events->respond($user, (int) $proposal['id'], 'confirm', null, 'confirm');
        self::assertSame('confirmed', $result['status']);
        self::assertSame('16:00:00', $result['timesheet']['end_time']);
        self::assertSame('already_resolved', $events->respond($user, (int) $proposal['id'], 'confirm', null, 'confirm')['status']);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_checkout_proposal_actions'));
        $this->checkIn($sync, $user, $date, '17:00', 'second-in');
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id', ['user_id' => $user]));
    }

    public function testActualEndCanBeCorrectedAndProposalCannotCloseNewSession(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $result = $events->respond($user, (int) $proposal['id'], 'correct', '16:35', 'correct');
        self::assertSame('16:35:00', $result['timesheet']['end_time']);
        $next = $this->checkIn($sync, $user, $date, '17:00', 'second-in');
        $events->respond($user, (int) $proposal['id'], 'confirm', null, 'late-confirm');
        self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $next['timesheet']['id']]));
    }

    #[DataProvider('sameClockNextDayEndCases')]
    public function testProposalCannotConfirmAnUnrepresentableNextDayEnd(string $action, bool $springForward): void
    {
        [$user, , $sync, $events] = $this->services(true, ['windows' => array_fill(1, 7, [['start' => '22:00', 'end' => '08:00']])]);
        $zone = new DateTimeZone('Europe/Berlin');
        $year = (int) (new DateTimeImmutable('today', $zone))->format('Y') - 1;
        $start = $springForward
            ? (new DateTimeImmutable('last sunday of March ' . $year, $zone))->modify('-1 day')->setTime(8, 0)
            : new DateTimeImmutable($year . '-01-15 08:00:00', $zone);
        $end = $start->modify('+1 day');
        $date = $start->format('Y-m-d');
        self::assertSame($springForward ? 23 * 3600 : 24 * 3600, $end->getTimestamp() - $start->getTimestamp());
        // These synthetic historical assignments exercise a valid <=24h endpoint
        // that cannot be represented by the existing date + start/end TIME model.
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date_from WHERE user_id = :user_id', ['date_from' => $date, 'user_id' => $user]);
        $in = $this->checkIn($sync, $user, $date);
        self::assertSame(1, $events->process(false, $end->modify('+30 minutes'))['proposals']);
        $proposal = $events->statusForUser($user)['checkout_proposals'][0];
        self::assertSame($end->format('Y-m-d H:i:s'), $proposal['proposed_end_at']);
        $beforeEntry = $this->connection()->fetchOne('SELECT *, row_start AS work_time_source_revision FROM timesheets WHERE id = :id', ['id' => $in['timesheet']['id']]);
        $beforeProposal = $this->connection()->fetchOne('SELECT * FROM work_time_checkout_proposals WHERE id = :id', ['id' => $proposal['id']]);
        $beforeVersions = (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id', ['id' => $in['timesheet']['id']]);
        $beforeOperations = (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM app_sync_operations');

        try {
            $events->respond($user, (int) $proposal['id'], $action, $action === 'correct' ? $end->format('Y-m-d\TH:i') : null, 'same-clock-next-day');
            self::fail('A next-day end at the same clock time must not become a zero-minute actual timesheet.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Tageswechselmodell', $exception->getMessage());
        }

        self::assertSame($beforeEntry, $this->connection()->fetchOne('SELECT *, row_start AS work_time_source_revision FROM timesheets WHERE id = :id', ['id' => $in['timesheet']['id']]));
        self::assertSame($beforeProposal, $this->connection()->fetchOne('SELECT * FROM work_time_checkout_proposals WHERE id = :id', ['id' => $proposal['id']]));
        self::assertSame($beforeVersions, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id', ['id' => $in['timesheet']['id']]));
        self::assertSame($beforeOperations, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM app_sync_operations'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_checkout_proposal_actions'));
    }

    public static function sameClockNextDayEndCases(): iterable
    {
        yield 'confirm exactly 24 hours' => ['confirm', false];
        yield 'correct exactly 24 hours' => ['correct', false];
        yield 'confirm spring transition in 23 hours' => ['confirm', true];
        yield 'correct spring transition in 23 hours' => ['correct', true];
    }

    public function testOfflineCheckoutMakesProposalStaleWithoutChangingNewSession(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $sync->sync($user, ['client_request_id' => 'offline-out', 'action' => 'check_out', 'work_date' => $date, 'end_time' => '16:20']);
        $new = $this->checkIn($sync, $user, $date, '17:00', 'second-in');
        $result = $events->respond($user, (int) $proposal['id'], 'confirm', null, 'stale-confirm');
        self::assertSame('stale', $result['status']);
        self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $new['timesheet']['id']]));
    }

    public function testOtherEmployeesCannotSeeOrResolveProposal(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $other = $this->createUser();
        self::assertSame([], $events->statusForUser($other)['checkout_proposals']);
        $this->expectException(InvalidArgumentException::class);
        $events->respond($other, (int) $proposal['id'], 'confirm', null, 'wrong-owner');
    }

    public function testReusingCorrectionRequestWithDifferentEndTimeIsRejected(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $events->respond($user, (int) $proposal['id'], 'correct', '16:35', 'correction');
        $this->expectException(InvalidArgumentException::class);
        $events->respond($user, (int) $proposal['id'], 'correct', '16:40', 'correction');
    }

    public function testAccountingClosureBlocksConfirmationAndLeavesProposalPending(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $this->connection()->execute('INSERT INTO accounting_closures (closure_number, closure_type, status, period_start, period_end, user_id) VALUES ("TEST-LOCK", "month", "final", :date_from, :date_to, :user_id)', ['date_from' => $date, 'date_to' => $date, 'user_id' => $user]);
        try {
            $events->respond($user, (int) $proposal['id'], 'confirm', null, 'closed-confirm');
            self::fail('Closed work must not be changed.');
        } catch (RuntimeException) {
            self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE user_id = :id', ['id' => $user]));
            self::assertSame('pending', $this->connection()->fetchColumn('SELECT status FROM work_time_checkout_proposals WHERE id = :id', ['id' => $proposal['id']]));
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_checkout_proposal_actions'));
        }
    }

    public function testConfirmationAndActualWorkRollbackTogether(): void
    {
        [$user, , $sync, , $date] = $this->services();
        $in = $this->checkIn($sync, $user, $date);
        try {
            $sync->sync($user, ['client_request_id' => 'atomic-out', 'action' => 'check_out', 'work_date' => $date, 'end_time' => '16:00', 'expected_timesheet_id' => $in['timesheet']['id']], static function (): void { throw new RuntimeException('Simulated audit failure.'); });
            self::fail('Audit failure must abort actual work mutation.');
        } catch (RuntimeException) {
            self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $in['timesheet']['id']]));
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM app_sync_operations WHERE user_id = :id AND client_request_id = "atomic-out"', ['id' => $user]));
        }
    }

    public function testIndependentWorkerLockPreventsParallelProcessing(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $otherConnection = new DatabaseConnection($this->connectionConfig());
        $otherConnection->fetchColumn('SELECT GET_LOCK(:name, 0)', ['name' => $events->workerLockName()]);
        try {
            self::assertTrue($events->process(false, new DateTimeImmutable($date . ' 16:30'))['locked']);
        } finally {
            $otherConnection->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $events->workerLockName()]);
        }
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_checkout_proposals'));
    }

    public function testParallelTerminalCheckoutAndProposalConfirmationCannotOverwriteEachOther(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $in = $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $configFile = tempnam(sys_get_temp_dir(), 'timeapp-worktime-config-');
        $barrier = sys_get_temp_dir() . '/timeapp-worktime-barrier-' . bin2hex(random_bytes(6));
        file_put_contents($configFile, json_encode($this->connectionConfig(), JSON_THROW_ON_ERROR));
        chmod($configFile, 0600);
        $workers = [];
        $results = [];
        try {
            foreach (['confirm', 'terminal'] as $mode) {
                $payload = base64_encode(json_encode(['mode' => $mode, 'user_id' => $user, 'proposal_id' => $proposal['id'], 'timesheet_id' => $in['timesheet']['id'], 'work_date' => $date], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/work-time-checkout-worker.php'), $configFile, $payload, $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            touch($barrier);
            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $stderr);
                $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            }
        } finally {
            @touch($barrier);
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
            @unlink($configFile); @unlink($barrier);
        }
        self::assertCount(2, $results);
        $row = $this->connection()->fetchOne('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $in['timesheet']['id']]);
        $state = $this->connection()->fetchColumn('SELECT status FROM work_time_checkout_proposals WHERE id = :id', ['id' => $proposal['id']]);
        self::assertContains($row['end_time'], ['16:00:00', '16:35:00']);
        self::assertSame($row['end_time'] === '16:00:00' ? 'confirmed' : 'stale', $state);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :id', ['id' => $user]));
    }

    public function testPushAndSmsAreDeduplicatedPerEventAndFailuresAreRecorded(): void
    {
        $calls = 0;
        $sms = new MockSmsProvider(true);
        [$user, , $sync, $events, $date] = $this->services(true, ['notify_employee_push' => true, 'notify_employee_sms' => true], function () use (&$calls): array { $calls++; return ['ok' => false]; }, $sms);
        $this->connection()->execute('UPDATE users SET phone = :phone WHERE id = :id', ['phone' => '0049 (170) 1234567', 'id' => $user]);
        $this->checkIn($sync, $user, $date);
        $now = new DateTimeImmutable($date . ' 16:30');
        $result = $events->process(false, $now);
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['failed']);
        $events->process(false, $now);
        self::assertSame(1, $calls);
        self::assertCount(1, $sms->messages());
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_events'));
        $this->connection()->execute('UPDATE work_time_notification_events SET status = "sending" WHERE channel = "push"');
        self::assertSame(1, $events->process(false, $now)['uncertain']);
        self::assertSame(1, $calls);
        self::assertSame('uncertain', $events->notificationEventsForEmployee($user)[1]['status']);
    }

    public function testInactiveEmployeesProduceNoProposals(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $this->connection()->execute('UPDATE users SET employment_status = "inactive" WHERE id = :id', ['id' => $user]);
        self::assertSame(0, $events->process(false, new DateTimeImmutable($date . ' 16:30'))['proposals']);
    }

    public function testDecisionRejectsStaleTimesheetBeforeWorkerReconciliation(): void
    {
        [$user, $policies, $sync, , $date] = $this->services();
        $this->checkIn($sync, $user, $date, '07:42');
        $sync->sync($user, ['client_request_id' => 'out', 'action' => 'check_out', 'work_date' => $date, 'end_time' => '08:35']);
        (new WorkTimePreviewService($this->connection(), $policies))->reconcileUser($user, $date, $date);
        $id = (int) $this->connection()->fetchColumn('SELECT id FROM work_time_deviations WHERE user_id = :id', ['id' => $user]);
        $decisions = new WorkTimeDeviationService($this->connection(), $policies);
        self::assertSame('approved', $decisions->decide($id, 'approved', $user, 'Gepruefte Arbeitszeit', 'approve')['new_status']);
        $sync->sync($user, ['client_request_id' => 'edit', 'action' => 'upsert', 'work_date' => $date, 'start_time' => '07:40', 'end_time' => '08:35']);
        $this->expectException(InvalidArgumentException::class);
        $decisions->decide($id, 'rejected', $user, 'Veralteter Nachweis', 'reject-stale');
    }

    public function testDelayedOfflineMutationsCannotCloseANewerWorkSession(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $in = $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $events->respond($user, (int) $proposal['id'], 'confirm', null, 'confirmed-old');
        $next = $this->checkIn($sync, $user, $date, '17:00', 'next-in');
        foreach ([['expected_timesheet_id' => $in['timesheet']['id']], ['expected_start_time' => '08:00'], []] as $index => $identity) {
            try {
                $sync->sync($user, ['client_request_id' => 'delayed-out-' . $index, 'action' => 'check_out', 'work_date' => $date, 'end_time' => '16:35'] + $identity);
                self::fail('An old offline operation must not close the new session.');
            } catch (RuntimeException) {
                self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $next['timesheet']['id']]));
            }
        }
        try {
            $this->checkIn($sync, $user, $date, '17:15', 'delayed-other-in');
            self::fail('A delayed check-in must not overwrite an open start.');
        } catch (RuntimeException) {
            self::assertSame('17:00:00', $this->connection()->fetchColumn('SELECT start_time FROM timesheets WHERE id = :id', ['id' => $next['timesheet']['id']]));
        }
    }

    public function testPreclaimedInternalRequestCannotPretendToConfirmAProposal(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $this->checkIn($sync, $user, $date);
        $proposal = $this->createProposal($events, $user, $date);
        $sync->sync($user, ['client_request_id' => 'wt:' . hash('sha256', $user . ':claimed-confirm'), 'action' => 'upsert', 'work_date' => $date, 'note' => 'Already claimed ordinary operation']);
        try {
            $events->respond($user, (int) $proposal['id'], 'confirm', null, 'claimed-confirm');
            self::fail('A reused sync operation must not pretend to confirm the proposal.');
        } catch (InvalidArgumentException) {
            self::assertSame('pending', $this->connection()->fetchColumn('SELECT status FROM work_time_checkout_proposals WHERE id = :id', ['id' => $proposal['id']]));
            self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE user_id = :id', ['id' => $user]));
        }
    }

    public function testPreviousDaySessionIsExposedOnlyToItsOwnerAndCanBeExplicitlyClosed(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $in = $this->checkIn($sync, $user, $date, '23:00');
        $status = $events->statusForUser($user);
        self::assertSame($in['timesheet']['id'], $status['active_work_entry']['id']);
        self::assertSame($date, $status['active_work_entry']['work_date']);
        self::assertFalse($status['active_work_entry_conflict']);
        self::assertSame([], $status['checkout_proposals']);
        self::assertNull($events->statusForUser($this->createUser())['active_work_entry']);
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $out = $sync->sync($user, ['client_request_id' => 'night-explicit-end', 'action' => 'check_out', 'work_date' => $date, 'end_time' => '00:00', 'actual_end_at' => $today . 'T00:00', 'expected_timesheet_id' => $in['timesheet']['id']]);
        self::assertSame('00:00:00', $out['timesheet']['end_time']);
        self::assertSame(60, $out['timesheet']['net_minutes']);
        self::assertNull($events->statusForUser($user)['active_work_entry']);
    }

    public function testOverlongOrWrongDateNightCheckoutNeverCreatesAnInventedEndTime(): void
    {
        [$user, , $sync, $events, $date] = $this->services();
        $in = $this->checkIn($sync, $user, $date, '08:00');
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        try {
            $sync->sync($user, ['client_request_id' => 'unrepresentable-night-end', 'action' => 'check_out', 'work_date' => $date, 'end_time' => '09:00', 'actual_end_at' => $today . 'T09:00', 'expected_timesheet_id' => $in['timesheet']['id']]);
            self::fail('A next-day end cannot be stored as a same-day end.');
        } catch (RuntimeException) {
            self::assertNull($this->connection()->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $in['timesheet']['id']]));
        }
        $this->connection()->execute('INSERT INTO timesheets (user_id, work_date, start_time, entry_type, source) VALUES (:user_id, :work_date, "01:00:00", "work", "admin")', ['user_id' => $user, 'work_date' => $today]);
        $status = $events->statusForUser($user);
        self::assertNull($status['active_work_entry']);
        self::assertTrue($status['active_work_entry_conflict']);
    }

    public function testAssignedPauseTimestampsRejectDstAndInvalidDatesWithoutChangingExistingRows(): void
    {
        [$user, , $sync, , $date] = $this->services();
        $in = $this->checkIn($sync, $user, $date);
        $before = $this->connection()->fetchOne('SELECT *, row_start AS source_revision FROM timesheets WHERE id = :id', ['id' => $in['timesheet']['id']]);
        foreach (['2026-03-29 02:30:00', '2026-10-25 02:30', '2026-10-25T00:30:00.000Z', '2026-02-30T07:30:00Z', 'yesterday'] as $index => $timestamp) {
            try {
                $sync->sync($user, ['client_request_id' => 'invalid-pause-start-' . $index, 'action' => 'pause_start', 'work_date' => $date, 'expected_timesheet_id' => $in['timesheet']['id'], 'break_started_at' => $timestamp]);
                self::fail('An invalid or unrepresentable pause start must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame($before, $this->connection()->fetchOne('SELECT *, row_start AS source_revision FROM timesheets WHERE id = :id', ['id' => $in['timesheet']['id']]));
                self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $in['timesheet']['id']]));
            }
        }
        $startedAt = new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('Europe/Berlin'));
        $utcStart = $startedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s') . '.125Z';
        $sync->sync($user, ['client_request_id' => 'valid-offset-pause-start', 'action' => 'pause_start', 'work_date' => $date, 'expected_timesheet_id' => $in['timesheet']['id'], 'break_started_at' => $utcStart]);
        $breakBefore = $this->connection()->fetchOne('SELECT * FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $in['timesheet']['id']]);
        self::assertSame($date . ' 12:00:00', $breakBefore['break_started_at']);
        foreach (['2026-03-29 02:30:00', '2026-10-25T00:30:00Z', '2026-02-30 12:30'] as $index => $timestamp) {
            try {
                $sync->sync($user, ['client_request_id' => 'invalid-pause-end-' . $index, 'action' => 'pause_end', 'work_date' => $date, 'expected_timesheet_id' => $in['timesheet']['id'], 'break_ended_at' => $timestamp]);
                self::fail('An invalid or unrepresentable pause end must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame($breakBefore, $this->connection()->fetchOne('SELECT * FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $in['timesheet']['id']]));
            }
        }
        $utcEnd = $startedAt->modify('+30 minutes')->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        $out = $sync->sync($user, ['client_request_id' => 'valid-offset-pause-end', 'action' => 'pause_end', 'work_date' => $date, 'expected_timesheet_id' => $in['timesheet']['id'], 'break_ended_at' => $utcEnd]);
        self::assertNull($out['current_break']);
        self::assertSame($date . ' 12:30:00', $this->connection()->fetchColumn('SELECT break_ended_at FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $in['timesheet']['id']]));
        self::assertSame(30, $out['timesheet']['break_minutes']);
    }

    public function testUnassignedPauseTimestampRetainsLegacyNormalization(): void
    {
        [, , $sync, , $date] = $this->services();
        $user = $this->createUser();
        $in = $this->checkIn($sync, $user, $date);
        $legacyExpected = (new DateTimeImmutable('2026-03-29 02:30:00'))->format('Y-m-d H:i:s');
        $sync->sync($user, ['client_request_id' => 'legacy-gap-pause', 'action' => 'pause_start', 'work_date' => $date, 'break_started_at' => '2026-03-29 02:30:00']);
        self::assertSame($legacyExpected, $this->connection()->fetchColumn('SELECT break_started_at FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $in['timesheet']['id']]));
    }

    public function testTerminalCheckoutAfterMidnightClosesOriginalSessionBeforeNextCheckIn(): void
    {
        [$user, , $sync, , $date] = $this->services(true, ['windows' => array_fill(1, 7, [['start' => '22:00', 'end' => '06:00']])]);
        $in = $this->checkIn($sync, $user, $date, '23:59');
        $connection = $this->connection();
        $connection->execute('INSERT INTO company_settings (id, company_name, terminal_enabled) VALUES (1, "Test", 1) ON DUPLICATE KEY UPDATE terminal_enabled = 1');
        $connection->execute('INSERT INTO terminals (terminal_identifier, name, token_hash, is_active) VALUES ("test-night", "Nachtterminal", "test-hash", 1)');
        $terminalId = $connection->lastInsertId();
        $terminal = $connection->fetchOne('SELECT * FROM terminals WHERE id = :id', ['id' => $terminalId]);
        $tags = new NfcTagService($connection, 'test-only-hash-key');
        $uid = '04AABBCCDDEE';
        $connection->execute('INSERT INTO nfc_tags (uid_hash, uid_masked, user_id, status) VALUES (:hash, "test", :user_id, "active")', ['hash' => $tags->hashUid($uid), 'user_id' => $user]);
        $punch = new TerminalPunchService($connection, new TerminalService($connection, new CompanySettingsService($connection, [])), $tags, $sync, new TerminalTrustBundleService('/nonexistent/test-bundle', '/nonexistent/test-key'));
        $out = $punch->scan($terminal, ['request_id' => 'night-out', 'nfc_uid' => $uid]);
        self::assertTrue($out['ok'], $out['message']);
        self::assertSame('check_out', $out['action']);
        self::assertSame($in['timesheet']['id'], $out['data']['timesheet']['id']);
        self::assertSame($date, $out['data']['timesheet']['work_date']);
        $next = $punch->scan($terminal, ['request_id' => 'night-next-in', 'nfc_uid' => $uid]);
        self::assertTrue($next['ok'], $next['message']);
        self::assertSame('check_in', $next['action']);
        self::assertSame((new DateTimeImmutable('today'))->format('Y-m-d'), $next['data']['timesheet']['work_date']);
        self::assertNotSame($in['timesheet']['id'], $next['data']['timesheet']['id']);
    }
}
