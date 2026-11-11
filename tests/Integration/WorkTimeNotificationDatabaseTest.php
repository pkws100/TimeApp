<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Push\PushSender;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\MockSmsProvider;
use App\Domain\WorkTime\SmsProvider;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimeNotificationService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePushChannel;
use App\Domain\WorkTime\WorkTimeAccessService;
use App\Http\Controllers\AdminWorkTimeNotificationController;
use App\Http\Request;
use App\Presentation\Admin\AdminView;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class WorkTimeNotificationDatabaseTest extends MariaDbTestCase
{
    public function testLateStartsAtEndOneMinuteAndOneHourLaterNotifyOnlyAfterTheirOwnDelay(): void
    {
        $calls = [];
        foreach (['16:00', '16:01', '17:00'] as $start) {
            [$user, , $events, $date, $entry] = $this->fixture(['outside_window_delay_minutes' => 5], function (int $recipient, array $payload, string $key) use (&$calls): array { $calls[] = [$recipient, $payload, $key]; return ['ok' => true, 'provider_message_id' => 'accepted-test-message']; }, $start);
            $before = $this->entry((int) $entry['id']);
            $threshold = $this->at($date . ' ' . $start)->modify('+5 minutes');
            $events->process(false, $threshold->modify('-1 second'));
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_events WHERE employee_user_id = :user', ['user' => $user]));
            $events->process(false, $threshold);
            $events->process(false, $threshold->modify('+1 minute'));
            $rows = $events->notificationEventsForEmployee($user);
            self::assertCount(1, $rows);
            self::assertSame('work_time_outside_window_started', $rows[0]['event_type']);
            self::assertSame('provider_accepted', $rows[0]['status']);
            self::assertSame(1, (int) $rows[0]['attempts']);
            self::assertNull($rows[0]['proposal_id']);
            self::assertSame($before, $this->entry((int) $entry['id']));
        }
        self::assertCount(3, $calls);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_checkout_proposals'));
    }

    public function testWaitingEventsRecheckCurrentFlagsRecipientsAndWindowsDespiteWarmPolicyCache(): void
    {
        $calls = 0;
        $sender = function () use (&$calls): array { ++$calls; return ['ok' => true]; };
        foreach (['employee_push_disabled', 'employee_sms_disabled', 'outside_disabled', 'windows_changed', 'delay_increased', 'supervisor_removed', 'supervisor_channel_disabled'] as $change) {
            $supervisorCase = str_starts_with($change, 'supervisor_');
            $smsCase = $change === 'employee_sms_disabled';
            [$user, $policies, $events, $date, $entry] = $this->fixture(['notify_employee_push' => !$supervisorCase && !$smsCase, 'notify_employee_sms' => $smsCase, 'notify_supervisor_push' => $supervisorCase], $sender, '17:00', null, new MockSmsProvider(true));
            $assignment = $policies->listAssignments($user)[0];
            if ($supervisorCase) {
                $supervisor = $this->createUser();
                $this->connection()->execute('INSERT INTO employee_work_time_supervisors (assignment_id, user_id) VALUES (:assignment, :supervisor)', ['assignment' => $assignment['id'], 'supervisor' => $supervisor]);
            }
            $warmPolicy = $policies->policyFor($user, $date);
            $events->notifications()->queue('work_time_outside_window_started', $entry, $warmPolicy, $this->at($date . ' 17:00'));
            $event = $events->notifications()->list($user)[0];
            $overrides = match ($change) {
                'employee_push_disabled' => ['notify_employee_push' => false],
                'employee_sms_disabled' => ['notify_employee_sms' => false],
                'outside_disabled' => ['outside_window_notifications_enabled' => false],
                'windows_changed' => ['windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '18:00']])],
                'delay_increased' => ['outside_window_delay_minutes' => 60],
                'supervisor_channel_disabled' => ['notify_supervisor_push' => false],
                default => [],
            };
            if ($change === 'supervisor_removed') {
                $this->connection()->execute('DELETE FROM employee_work_time_supervisors WHERE assignment_id = :assignment', ['assignment' => $assignment['id']]);
            } else {
                $this->connection()->execute('UPDATE employee_work_time_assignments SET overrides_json = :overrides WHERE id = :id', ['overrides' => json_encode($overrides, JSON_THROW_ON_ERROR), 'id' => $assignment['id']]);
            }
            self::assertSame($warmPolicy, $policies->policyFor($user, $date), 'Original request cache is deliberately warm.');
            $events->notifications()->dispatch($this->at($date . ' 17:05'));
            $cancelled = $events->notifications()->find((int) $event['id']);
            self::assertSame('cancelled', $cancelled['status'], $change);
            self::assertSame(0, (int) $cancelled['attempts'], $change);
        }
        self::assertSame(0, $calls);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_attempts'));
    }

    public function testSmsUsesContactReadAtFinalClaimWhenPhoneChangesDuringBatch(): void
    {
        [$pushUser, $policies, , $date, $pushEntry] = $this->fixture();
        [$smsUser, $smsPolicies, , , $smsEntry] = $this->fixture(['notify_employee_push' => false, 'notify_employee_sms' => true]);
        $sms = new class implements SmsProvider {
            public array $phones = [];
            public function enabled(): bool { return true; }
            public function send(string $e164PhoneNumber, string $message, string $eventKey): array
            {
                $this->phones[] = $e164PhoneNumber;
                return ['ok' => true];
            }
        };
        $notifications = new WorkTimeNotificationService($this->connection(), $policies, function () use ($smsUser): array {
            $this->connection()->execute('UPDATE users SET phone = "+491709999999" WHERE id = :id', ['id' => $smsUser]);
            return ['ok' => true];
        }, $sms);
        $notifications->queue('work_time_outside_window_started', $pushEntry, $policies->policyFor($pushUser, $date), $this->at($date . ' 17:00'));
        $notifications->queue('work_time_outside_window_started', $smsEntry, $smsPolicies->policyFor($smsUser, $date), $this->at($date . ' 17:00'));
        $summary = $notifications->dispatch($this->at($date . ' 17:05'));
        self::assertSame(['+491709999999'], $sms->phones);
        self::assertSame(2, $summary['provider_accepted']);
    }

    public function testWaitingCheckoutEventsRecheckEnabledFlagAndCurrentEndBeforeClaim(): void
    {
        $calls = 0;
        foreach (['disabled', 'window_changed'] as $change) {
            [$user, $policies, $events, $date, $entry] = $this->fixture(['checkout_proposals_enabled' => true], function () use (&$calls): array { ++$calls; return ['ok' => true]; }, '08:00');
            $policy = $policies->policyFor($user, $date);
            $this->connection()->execute('INSERT INTO work_time_checkout_proposals (user_id, timesheet_id, policy_version_id, proposed_end_at, status) VALUES (:user, :timesheet, :version, :end_at, "pending")', ['user' => $user, 'timesheet' => $entry['id'], 'version' => $policy['policy_version_id'], 'end_at' => $date . ' 16:00:00']);
            $proposal = $this->connection()->lastInsertId();
            $events->notifications()->queue('work_time_checkout_proposal', $entry, $policy, $this->at($date . ' 16:00'), $proposal);
            $overrides = $change === 'disabled' ? ['checkout_proposals_enabled' => false] : ['windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '18:00']])];
            $this->connection()->execute('UPDATE employee_work_time_assignments SET overrides_json = :overrides WHERE id = :id', ['overrides' => json_encode($overrides, JSON_THROW_ON_ERROR), 'id' => $policy['assignment_id']]);
            $events->notifications()->dispatch($this->at($date . ' 16:05'));
            $event = $events->notifications()->list($user)[0];
            self::assertSame('cancelled', $event['status']);
            self::assertSame(0, (int) $event['attempts']);
        }
        self::assertSame(0, $calls);
    }

    public function testClosedBeforeThresholdAndEarlyOrInsideNightStartsDoNotSend(): void
    {
        $calls = 0;
        $sender = function () use (&$calls): array { ++$calls; return ['ok' => true]; };
        [$user, , $events, $date, $entry] = $this->fixture([], $sender, '17:00');
        $this->connection()->execute('UPDATE timesheets SET end_time = "17:03:00", net_minutes = 3 WHERE id = :id', ['id' => $entry['id']]);
        $events->process(false, $this->at($date . ' 17:05'));
        self::assertSame(0, $calls);
        [, , $early, $earlyDate] = $this->fixture([], $sender, '07:42');
        $early->process(false, $this->at($earlyDate . ' 07:50'));
        self::assertSame(0, $calls);
        foreach (['23:00', '02:00'] as $time) {
            [, , $night, $nightDate] = $this->fixture(['windows' => array_fill(1, 7, [['start' => '22:00', 'end' => '06:00']])], $sender, $time);
            $night->process(false, $this->at($nightDate . ' ' . $time)->modify('+10 minutes'));
        }
        self::assertSame(0, $calls);
    }

    public function testWeekendWithoutWindowAndExpiredCarriedNightAreIndependentEvents(): void
    {
        $calls = 0; $sender = function () use (&$calls): array { ++$calls; return ['ok' => true]; };
        [$weekendUser, , $weekend, $date] = $this->fixture(['windows' => array_fill(1, 5, [['start' => '08:00', 'end' => '16:00']])], $sender, '10:00', '2026-10-31');
        $weekend->process(false, $this->at($date . ' 10:05'));
        self::assertSame('work_time_outside_window_started', $weekend->notificationEventsForEmployee($weekendUser)[0]['event_type']);
        [$nightUser, , $night, $nightDate] = $this->fixture(['windows' => array_fill(1, 7, [['start' => '22:00', 'end' => '06:00']])], $sender, '07:00');
        $night->process(false, $this->at($nightDate . ' 07:05'));
        self::assertCount(1, $night->notificationEventsForEmployee($nightUser));
        self::assertSame(2, $calls);
    }

    public function testNormalStartContinuedWorkAndSecondSessionDoNotShareAnOutsideStartEvent(): void
    {
        $calls = 0; $sender = function () use (&$calls): array { ++$calls; return ['ok' => true]; };
        [$user, , $events, $date, $entry] = $this->fixture(['checkout_proposals_enabled' => true], $sender, '08:00');
        $events->process(false, $this->at($date . ' 16:05'));
        $proposal = $events->statusForUser($user)['checkout_proposals'][0];
        self::assertSame(1, $calls);
        $events->respond($user, (int) $proposal['id'], 'continue', null, 'continue-after-window');
        $events->process(false, $this->at($date . ' 17:05'));
        self::assertSame(1, $calls);
        $this->connection()->execute('UPDATE timesheets SET end_time = "16:10:00" WHERE id = :id', ['id' => $entry['id']]);
        $this->insertEntry($user, $date, '17:00');
        $events->process(false, $this->at($date . ' 17:05'));
        self::assertSame(2, $calls);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_events WHERE event_type = "work_time_outside_window_started"'));
    }

    public function testDefinitiveRejectionsRetryWithBackoffAndStopThenAllowAuditedManualRetry(): void
    {
        $calls = 0; $accept = false;
        $sender = function () use (&$calls, &$accept): array { ++$calls; return $accept ? ['ok' => true] : ['ok' => false, 'definitive_not_accepted' => true, 'retryable' => true]; };
        [$user, , $events, $date] = $this->fixture([], $sender);
        $first = $this->at($date . ' 17:05');
        $events->process(false, $first);
        $event = $events->notificationEventsForEmployee($user)[0];
        self::assertSame('failed', $event['status']);
        self::assertSame($first->modify('+30 seconds')->format('Y-m-d H:i:s'), $event['next_attempt_at']);
        $events->process(false, $first->modify('+29 seconds'));
        self::assertSame(1, $calls);
        $events->process(false, $first->modify('+30 seconds'));
        self::assertSame(2, $calls);
        $events->process(false, $first->modify('+150 seconds'));
        $events->process(false, $first->modify('+1 hour'));
        self::assertSame(3, $calls);
        $id = (int) $event['id'];
        $request = $events->notifications()->requestRetry($id, $user, 'Providerverbindung wurde geprueft', 'manual-retry-1');
        self::assertSame($request, $events->notifications()->requestRetry($id, $user, 'Providerverbindung wurde geprueft', 'manual-retry-1'));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_retry_requests'));
        self::assertSame('manual_retry_requested', $events->notifications()->find($id)['status']);
        $accept = true;
        $events->process(false, $first->modify('+2 hours'));
        self::assertSame(4, $calls);
        $accepted = $events->notifications()->list($user)[0];
        self::assertSame('provider_accepted', $accepted['status']);
        self::assertCount(4, $accepted['attempt_history']);
        self::assertSame('manual', $accepted['attempt_history'][3]['trigger_type']);
        self::assertSame($user, (int) $accepted['attempt_history'][3]['actor_user_id']);
        self::assertSame('Providerverbindung wurde geprueft', $accepted['attempt_history'][3]['retry_reason']);
        self::assertFalse($accepted['manual_retry_allowed']);
        $this->expectException(InvalidArgumentException::class);
        $events->notifications()->requestRetry($id, $user, 'Unzulaessiger Doppelversand', 'manual-retry-after-accepted');
    }

    public function testUncertainAcceptanceIsNeverRetriedAndProviderExceptionsAreSanitized(): void
    {
        $calls = 0;
        [$user, , $events, $date] = $this->fixture([], function () use (&$calls): array { ++$calls; throw new \RuntimeException('TOPSECRET provider API credential +491701234567'); });
        $events->process(false, $this->at($date . ' 17:05'));
        $events->process(false, $this->at($date . ' 23:05'));
        self::assertSame(1, $calls);
        $event = $events->notificationEventsForEmployee($user)[0];
        self::assertSame('uncertain', $event['status']);
        self::assertStringNotContainsString('TOPSECRET', json_encode($event, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('+491701234567', json_encode($event, JSON_THROW_ON_ERROR));
        $this->expectException(InvalidArgumentException::class);
        $events->notifications()->requestRetry((int) $event['id'], $user, 'Unklarer Empfang', 'uncertain-cannot-retry');
    }

    public function testMockSmsCannotBeMisreportedAsProviderAcceptanceAndUnconfiguredCanBeRequestedAgain(): void
    {
        [$user, , $events, $date] = $this->fixture(['notify_employee_push' => false, 'notify_employee_sms' => true], null, '17:00', null, new MockSmsProvider(true));
        $events->process(false, $this->at($date . ' 17:05'));
        $event = $events->notificationEventsForEmployee($user)[0];
        self::assertSame('test_simulated', $event['status']);
        self::assertNull($event['accepted_at']);
        self::assertNull($event['sent_at']);
        self::assertStringContainsString('Testsimulation', $events->notifications()->smsStatus());
        [$disabledUser, , $disabled, $disabledDate] = $this->fixture(['notify_employee_push' => false, 'notify_employee_sms' => true], null);
        $disabled->process(false, $this->at($disabledDate . ' 17:05'));
        $row = $disabled->notificationEventsForEmployee($disabledUser)[0];
        self::assertSame('not_configured', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        $disabled->notifications()->requestRetry((int) $row['id'], $disabledUser, 'Konfiguration wurde kontrolliert', 'retry-disabled');
        self::assertSame('manual_retry_requested', $disabled->notifications()->find((int) $row['id'])['status']);
    }

    public function testInterruptedAttemptBecomesUncertainAndNoAutomaticResendOccurs(): void
    {
        $calls = 0;
        [$user, $policies, $events, $date, $entry] = $this->fixture([], function () use (&$calls): array { ++$calls; return ['ok' => true]; });
        $events->notifications()->queue('work_time_outside_window_started', $entry, $policies->policyFor($user, $date), $this->at($date . ' 17:00'));
        $id = (int) $events->notificationEventsForEmployee($user)[0]['id'];
        $this->connection()->execute('UPDATE work_time_notification_events SET status = "sending", attempts = 1 WHERE id = :id', ['id' => $id]);
        $this->connection()->execute('INSERT INTO work_time_notification_attempts (notification_event_id, attempt_number, trigger_type, provider_request_key, status) VALUES (:id, 1, "automatic", "interrupted-request", "sending")', ['id' => $id]);
        $events->process(false, $this->at($date . ' 17:05'));
        self::assertSame(0, $calls);
        self::assertSame('uncertain', $events->notifications()->find($id)['status']);
        self::assertSame('uncertain', $this->connection()->fetchColumn('SELECT status FROM work_time_notification_attempts WHERE notification_event_id = :id', ['id' => $id]));
    }

    public function testDryRunFeatureOffAndUnassignedEmployeesHaveNoDeliverySideEffects(): void
    {
        $calls = 0;
        [$user, , $events, $date, $entry] = $this->fixture([], function () use (&$calls): array { ++$calls; return ['ok' => true]; });
        $before = $this->entry((int) $entry['id']);
        $events->process(true, $this->at($date . ' 17:05'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_events'));
        self::assertSame($before, $this->entry((int) $entry['id']));
        $unassigned = $this->createUser(); $this->insertEntry($unassigned, $date, '17:00');
        $off = new WorkTimePolicyService($this->connection(), false);
        $sync = new AppTimesheetSyncService($this->connection(), new TimesheetCalculator(), new CompanySettingsService($this->connection(), []), new WorkdayStateCalculator());
        $disabled = new WorkTimeEventService($this->connection(), $off, $sync);
        self::assertFalse($disabled->process(false, $this->at($date . ' 17:05'))['enabled']);
        self::assertSame(0, $calls);
        $events->process(false, $this->at($date . ' 17:05'));
        self::assertCount(0, $events->notificationEventsForEmployee($unassigned));
    }

    public function testPartialPushAcceptanceNeverRetriesAlreadyAcceptedDevices(): void
    {
        [$user, $policies, , $date, $entry] = $this->fixture([], null);
        $subscriptions = new PushSubscriptionService($this->connection());
        foreach (['accepted', 'rejected'] as $label) {
            $subscriptions->storeForUser(['id' => $user], ['endpoint' => 'https://push.example.invalid/' . $label, 'keys' => ['p256dh' => 'synthetic-public-key', 'auth' => 'synthetic-auth-token'], 'device_label' => $label]);
        }
        $sender = new class implements PushSender {
            public int $calls = 0;
            public function isConfigured(): bool { return true; }
            public function send(array $subscriptionRow, array $message, ?string $dedupeKey = null, ?string $workDate = null): array
            {
                ++$this->calls;
                return $subscriptionRow['device_label'] === 'accepted' ? ['ok' => true] : ['ok' => false, 'definitive_not_accepted' => true, 'retryable' => true];
            }
        };
        $notifications = new WorkTimeNotificationService($this->connection(), $policies, new WorkTimePushChannel($subscriptions, $sender));
        $notifications->queue('work_time_outside_window_started', $entry, $policies->policyFor($user, $date), $this->at($date . ' 17:00'));
        $notifications->dispatch($this->at($date . ' 17:05'));
        $notifications->dispatch($this->at($date . ' 18:05'));
        self::assertSame(2, $sender->calls);
        self::assertSame('uncertain', $notifications->list($user)[0]['status']);
        self::assertFalse($notifications->list($user)[0]['manual_retry_allowed']);
    }

    public function testAdminRetryRequiresPermissionScopeCsrfAndAuditsOnlyAuthorizedRequests(): void
    {
        [$employee, $policies, $events, $date] = $this->fixture([], static fn (): array => ['ok' => false, 'definitive_not_accepted' => true]);
        $events->process(false, $this->at($date . ' 17:05'));
        $event = $events->notificationEventsForEmployee($employee)[0];
        $id = (int) $event['id'];
        $manager = $this->createUser(); $stranger = $this->createUser();
        foreach ([$manager, $stranger] as $actor) {
            $this->connection()->execute('INSERT INTO roles (slug, name) VALUES (:slug, "Versandmanager")', ['slug' => 'notification-manager-' . $actor]);
            $role = $this->connection()->lastInsertId();
            $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', ['user' => $actor, 'role' => $role]);
            $this->connection()->execute('INSERT INTO permissions (code, label, scope) VALUES ("work_time_notifications.manage", "Versand", "backend") ON DUPLICATE KEY UPDATE code = VALUES(code)');
            $permission = $this->connection()->fetchColumn('SELECT id FROM permissions WHERE code = "work_time_notifications.manage"');
            $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)', ['role' => $role, 'permission' => $permission]);
        }
        $assignment = $policies->listAssignments($employee)[0];
        $this->connection()->execute('INSERT INTO employee_work_time_supervisors (assignment_id, user_id) VALUES (:assignment, :manager)', ['assignment' => $assignment['id'], 'manager' => $manager]);
        $auth = new AuthService($this->connection(), new PermissionMatrix([], []));
        $csrf = new CsrfService();
        $controller = new AdminWorkTimeNotificationController(new AdminView('Test', 'http://localhost'), $auth, $csrf, new UserService($this->connection()), $policies, $events->notifications(), new WorkTimeAccessService($policies));
        $request = static fn (array $body): Request => new Request('POST', '/admin/work-time/notifications/' . $id . '/retry', [], $body, [], [], []);
        $body = ['reason' => 'Providerkonfiguration geprueft', 'request_id' => 'http-manual-retry'];
        self::assertSame(403, $controller->retry($request($body), ['id' => $id])->status());
        $_SESSION['auth']['user_id'] = $employee;
        self::assertSame(403, $controller->retry($request($body + ['csrf_token' => $csrf->token()]), ['id' => $id])->status());
        $_SESSION['auth']['user_id'] = $manager;
        self::assertSame(403, $controller->retry($request($body), ['id' => $id])->status());
        self::assertSame(403, $controller->retry($request($body + ['csrf_token' => []]), ['id' => $id])->status());
        $_SESSION['auth']['user_id'] = $stranger;
        self::assertSame(403, $controller->retry($request($body + ['csrf_token' => $csrf->token()]), ['id' => $id])->status());
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_retry_requests'));
        $_SESSION['auth']['user_id'] = $manager;
        $response = $controller->retry($request($body + ['csrf_token' => $csrf->token()]), ['id' => $id]);
        self::assertSame(302, $response->status());
        self::assertStringContainsString('saved=1', $response->headers()['Location']);
        $controller->retry($request($body + ['csrf_token' => $csrf->token()]), ['id' => $id]);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_retry_requests'));
        self::assertSame($manager, (int) $this->connection()->fetchColumn('SELECT actor_user_id FROM work_time_notification_retry_requests'));
        foreach (['uncertain', 'provider_accepted'] as $status) {
            $this->connection()->execute('UPDATE work_time_notification_events SET status = :status WHERE id = :id', ['status' => $status, 'id' => $id]);
            $response = $controller->retry($request(['reason' => 'Kein doppelter Versand', 'request_id' => 'deny-' . $status, 'csrf_token' => $csrf->token()]), ['id' => $id]);
            self::assertStringContainsString('error=', $response->headers()['Location']);
        }
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_notification_retry_requests'));
        ob_start();
        $controller->index(new Request('GET', '/admin/work-time/notifications', ['user_id' => (string) $employee, 'error' => []], [], [], [], []))->send();
        $html = ob_get_clean();
        self::assertStringContainsString('Arbeitsbeginn ausserhalb des Zeitfensters', $html);
        self::assertStringContainsString('Vom Provider angenommen', $html);
        self::assertStringContainsString('Zustellung nicht nachgewiesen', $html);
        self::assertStringNotContainsString('zugestellt', $html);
    }

    private function fixture(array $policy = [], ?callable $sender = null, string $start = '17:00', ?string $date = null, ?MockSmsProvider $sms = null): array
    {
        $user = $this->createUser();
        $this->connection()->execute('UPDATE users SET phone = "+491701234567" WHERE id = :id', ['id' => $user]);
        $policies = new WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => 'Benachrichtigung', 'policy' => array_replace(['windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']]), 'outside_window_notifications_enabled' => true, 'outside_window_delay_minutes' => 5, 'notify_employee_push' => true], $policy)], $user);
        $assignment = $policies->assign(['user_id' => $user, 'policy_version_id' => $template['version']['id'], 'effective_from' => $this->at('tomorrow')->format('Y-m-d')], $user);
        $date ??= $this->at('yesterday')->format('Y-m-d');
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date_from WHERE id = :id', ['date_from' => $date, 'id' => $assignment['id']]);
        $entry = $this->insertEntry($user, $date, $start);
        $sync = new AppTimesheetSyncService($this->connection(), new TimesheetCalculator(), new CompanySettingsService($this->connection(), []), new WorkdayStateCalculator());
        $events = new WorkTimeEventService($this->connection(), $policies, $sync, $sender, $sms);
        return [$user, $policies, $events, $date, $entry];
    }
    private function insertEntry(int $user, string $date, string $start): array
    {
        $this->connection()->execute('INSERT INTO timesheets (user_id, work_date, start_time, entry_type, source, created_by_user_id) VALUES (:user, :work_date, :start, "work", "app", :actor)', ['user' => $user, 'work_date' => $date, 'start' => $start, 'actor' => $user]);
        return $this->entry($this->connection()->lastInsertId());
    }
    private function entry(int $id): array { return $this->connection()->fetchOne('SELECT * FROM timesheets WHERE id = :id', ['id' => $id]) ?? []; }
    private function at(string $date): DateTimeImmutable { return new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin')); }
}
