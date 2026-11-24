<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Appointments\AppointmentAccessDeniedException;
use App\Domain\Appointments\AppointmentConflictException;
use App\Domain\Appointments\AppointmentDispatchService;
use App\Domain\Appointments\AppointmentNotFoundException;
use App\Domain\Appointments\AppointmentService;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Push\PushSender;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class AppointmentDispatchServiceDatabaseTest extends MariaDbTestCase
{
    private AppointmentService $appointments;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appointments = new AppointmentService($this->connection(), new ProjectAccessService($this->connection()));
        $this->admin = ['id' => $this->createUser(), 'permissions' => ['*']];
    }

    public function testOnlyAssignedEmployeesArePreviewedAndEachDeviceOutcomeIsRecorded(): void
    {
        $this->enablePush();
        $project = $this->createProject();
        $assigned = $this->createUser(['first_name' => 'Ada', 'last_name' => 'Arbeiter']);
        $empty = $this->createUser();
        $denied = $this->createUser();
        $inactive = $this->createUser();
        $projectMember = $this->createUser();
        $scopedDispatcher = ['id' => $this->createUser(), 'permissions' => ['appointments.view', 'appointments.dispatch']];
        foreach ([$projectMember, $scopedDispatcher['id']] as $member) {
            $this->connection()->execute(
                'INSERT INTO project_memberships (project_id, user_id) VALUES (:project, :user)',
                ['project' => $project, 'user' => $member]
            );
        }
        foreach ([$assigned, $empty, $inactive, $projectMember] as $user) {
            $this->grantPush($user);
        }
        $accepted = $this->device($assigned, 'accepted');
        $rejected = $this->device($assigned, 'rejected');
        $this->device($assigned, 'disabled', false);
        $this->device($empty, 'permission-denied', true, 'denied');
        $this->device($denied, 'not-authorized');
        $this->device($inactive, 'deactivated-employee');
        $this->device($projectMember, 'not-a-participant');
        $id = $this->createAppointment([$assigned, $empty, $denied, $inactive], ['project_id' => $project]);
        $this->connection()->execute('UPDATE users SET employment_status = "inactive" WHERE id = :id', ['id' => $inactive]);

        $sent = [];
        $sender = $this->createMock(PushSender::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects(self::exactly(2))->method('send')->willReturnCallback(
            static function (array $device, array $payload, string $key) use (&$sent, $accepted): array {
                $sent[(int) $device['id']] = [$payload, $key, (int) $device['user_id']];

                return (int) $device['id'] === $accepted
                    ? ['ok' => true] : ['ok' => false, 'definitive_not_accepted' => true, 'message' => 'PRIVATE-ENDPOINT-FAILURE'];
            }
        );
        $dispatches = $this->service($sender);
        $preview = $dispatches->recipientPreview($scopedDispatcher, $id);
        self::assertTrue($preview['push_available']);
        $projected = array_column($preview['recipients'], null, 'user_id');
        self::assertCount(4, $projected);
        self::assertSame('Ada Arbeiter', $projected[$assigned]['display_name']);
        self::assertSame(2, $projected[$assigned]['active_subscription_count']);
        self::assertSame('ready', $projected[$assigned]['status']);
        self::assertSame('no_device', $projected[$empty]['status']);
        self::assertSame('no_permission', $projected[$denied]['status']);
        self::assertSame('inactive', $projected[$inactive]['status']);

        $key = str_repeat('a', 32);
        $result = $dispatches->dispatch($scopedDispatcher, $id, $preview['appointment_version'], $key);
        self::assertSame([$rejected, $accepted], array_keys($sent));
        self::assertSame($key, $result['request_key']);
        self::assertSame($scopedDispatcher['id'], $result['sent_by_user_id']);
        self::assertSame(1, $result['appointment_version']);
        self::assertSame('push', $result['channel']);
        self::assertNotNull($result['created_at_utc']);
        self::assertNotNull($result['completed_at_utc']);
        self::assertSame(4, $result['recipient_count']);
        self::assertSame(1, $result['accepted_subscription_count']);
        self::assertSame(1, $result['failed_subscription_count']);
        self::assertSame(1, $result['accepted_count']);
        self::assertSame(3, $result['skipped_count']);
        $recipients = array_column($result['recipients'], null, 'user_id');
        self::assertSame('partially_accepted', $recipients[$assigned]['status']);
        self::assertSame('no_device', $recipients[$empty]['status']);
        self::assertSame('no_permission', $recipients[$denied]['status']);
        self::assertSame('inactive', $recipients[$inactive]['status']);
        foreach ($sent as $subscriptionId => [$payload, $dedupeKey, $userId]) {
            self::assertSame($assigned, $userId);
            self::assertSame('appointment_dispatch', $payload['type']);
            self::assertSame('/app/termine?id=' . $id, $payload['url']);
            self::assertSame('appointment-dispatch:' . $result['id'] . ':subscription:' . $subscriptionId, $dedupeKey);
            self::assertSame('Termin – Heizungswartung', $payload['title']);
            self::assertStringContainsString('09:15 Uhr', $payload['body']);
            self::assertStringContainsString('Treffpunkt: Werkstatt', $payload['body']);
            self::assertStringNotContainsString('Vertrauliche Detailbeschreibung', $payload['body']);
        }
        self::assertStringNotContainsString('PRIVATE-ENDPOINT-FAILURE', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame([$result], $dispatches->history($scopedDispatcher, $id));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM appointment_participants WHERE acknowledged_at_utc IS NOT NULL'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets'));
    }

    public function testDisabledAndUnconfiguredPushAreSavedWithoutContactingDevices(): void
    {
        $recipient = $this->createUser();
        $this->grantPush($recipient);
        $this->device($recipient, 'available');
        $id = $this->createAppointment([$recipient]);

        $sender = $this->createMock(PushSender::class);
        $sender->method('isConfigured')->willReturn(false);
        $sender->expects(self::never())->method('send');
        $dispatches = $this->service($sender);
        $preview = $dispatches->recipientPreview($this->admin, $id);
        self::assertFalse($preview['push_available']);
        self::assertSame('push_disabled', $preview['recipients'][0]['status']);
        $disabled = $dispatches->dispatch($this->admin, $id, 1, str_repeat('b', 32));
        self::assertSame('push_disabled', $disabled['recipients'][0]['status']);
        self::assertSame(1, $disabled['skipped_count']);
        self::assertNotNull($disabled['completed_at_utc']);

        $this->enablePush();
        $unconfigured = $dispatches->dispatch($this->admin, $id, 1, str_repeat('c', 32));
        self::assertSame('push_unconfigured', $unconfigured['recipients'][0]['status']);
        self::assertSame([$unconfigured, $disabled], $dispatches->history($this->admin, $id));
        self::assertSame([$disabled], $dispatches->history($this->admin, $id, 10, $unconfigured['id']));
    }

    public function testViewPermissionAndProjectMembershipAloneDoNotAllowPreviewSendOrHistory(): void
    {
        $project = $this->createProject();
        $id = $this->createAppointment([$this->createUser()], ['project_id' => $project]);
        $sender = $this->createMock(PushSender::class);
        $sender->expects(self::never())->method('send');
        $service = $this->service($sender);
        $member = $this->createUser();
        $this->connection()->execute(
            'INSERT INTO project_memberships (project_id, user_id) VALUES (:project, :user)',
            ['project' => $project, 'user' => $member]
        );
        $viewer = ['id' => $member, 'permissions' => ['appointments.view', 'appointments.manage']];
        $outsider = ['id' => $this->createUser(), 'permissions' => ['appointments.view', 'appointments.dispatch']];

        foreach ([[$viewer, AppointmentAccessDeniedException::class], [$outsider, AppointmentNotFoundException::class]] as [$actor, $exception]) {
            foreach (['recipientPreview', 'dispatch', 'history'] as $method) {
                try {
                    $method === 'dispatch' ? $service->dispatch($actor, $id, 1, str_repeat('d', 32)) : $service->$method($actor, $id);
                    self::fail('The actor should not be able to access the appointment dispatch.');
                } catch (AppointmentAccessDeniedException|AppointmentNotFoundException $actual) {
                    self::assertInstanceOf($exception, $actual);
                }
            }
        }
        self::assertSame([], $service->history($this->admin, $id));
    }

    public function testConcurrentReplayUsesPersistedSnapshotAndDeliberateResendGetsNewId(): void
    {
        $this->enablePush();
        $recipient = $this->createUser();
        $this->grantPush($recipient);
        $this->device($recipient, 'retry');
        $id = $this->createAppointment([$recipient]);
        $key = str_repeat('e', 32);
        $otherSender = $this->createMock(PushSender::class);
        $otherSender->expects(self::never())->method('send');
        $observer = $this->service($otherSender, new DatabaseConnection($this->connectionConfig()));
        $midSend = null;
        $calls = 0;
        $sender = $this->createMock(PushSender::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects(self::exactly(2))->method('send')->willReturnCallback(function () use ($observer, $id, $key, &$midSend, &$calls): array {
            if (++$calls === 1) {
                $midSend = $observer->dispatch($this->admin, $id, 1, $key);
            }

            return ['ok' => true];
        });
        $dispatches = $this->service($sender);

        $first = $dispatches->dispatch($this->admin, $id, 1, $key);
        self::assertIsArray($midSend);
        self::assertSame($first['id'], $midSend['id']);
        self::assertNull($midSend['completed_at_utc']);
        self::assertSame('sending', $midSend['recipients'][0]['status']);
        self::assertSame(1, $midSend['recipients'][0]['uncertain_subscription_count']);
        self::assertSame($first, $dispatches->dispatch($this->admin, $id, 1, $key));

        $second = $dispatches->dispatch($this->admin, $id, 1, str_repeat('f', 32));
        self::assertGreaterThan($first['id'], $second['id']);
        self::assertSame([$second, $first], $dispatches->history($this->admin, $id));
        $this->appointments->archive($this->admin, $id, 1);
        self::assertSame($first, $dispatches->dispatch($this->admin, $id, 1, $key));
        $this->expectException(AppointmentConflictException::class);
        $dispatches->dispatch($this->admin, $id, 2, str_repeat('1', 32));
    }

    public function testDispatchHistoryAndReplayRequireAccessToOriginalProject(): void
    {
        $oldProject = $this->createProject();
        $currentProject = $this->createProject();
        $operator = ['id' => $this->createUser(), 'permissions' => ['appointments.view', 'appointments.dispatch']];
        foreach ([$oldProject, $currentProject] as $project) {
            $this->connection()->execute(
                'INSERT INTO project_memberships (project_id, user_id) VALUES (:project, :user)',
                ['project' => $project, 'user' => $operator['id']]
            );
        }
        $id = $this->createAppointment([$this->createUser()], ['project_id' => $oldProject]);
        $sender = $this->createMock(PushSender::class);
        $sender->expects(self::never())->method('send');
        $service = $this->service($sender);
        $key = str_repeat('5', 32);
        $original = $service->dispatch($operator, $id, 1, $key);
        self::assertSame($oldProject, (int) $this->connection()->fetchColumn(
            'SELECT project_id FROM appointment_dispatches WHERE id = :id', ['id' => $original['id']]
        ));

        $this->appointments->update($this->admin, $id, ['version' => 1, 'project_id' => $currentProject]);
        self::assertSame([$original], $service->history($operator, $id));
        $this->connection()->execute(
            'DELETE FROM project_memberships WHERE project_id = :project AND user_id = :user',
            ['project' => $oldProject, 'user' => $operator['id']]
        );
        self::assertSame([], $service->history($operator, $id));
        try {
            $service->dispatch($operator, $id, 1, $key);
            self::fail('A replay must not expose a dispatch from an inaccessible project.');
        } catch (AppointmentNotFoundException) {
            self::assertSame([$original], $service->history($this->admin, $id));
        }

        $moved = $service->dispatch($operator, $id, 2, str_repeat('6', 32));
        self::assertSame([$moved], $service->history($operator, $id));
        $this->connection()->execute(
            'UPDATE appointment_dispatches SET project_scope_known = 0, project_id = NULL WHERE id = :id',
            ['id' => $moved['id']]
        );
        self::assertSame([], $service->history($operator, $id));
        self::assertSame([$moved, $original], $service->history($this->admin, $id));
    }

    public function testEditedVersionStopsRemainingDeliveryAndKeepsPriorAcceptance(): void
    {
        $this->enablePush();
        $first = $this->createUser(['last_name' => 'A']);
        $removed = $this->createUser(['last_name' => 'B']);
        $added = $this->createUser(['last_name' => 'C']);
        foreach ([$first, $removed, $added] as $user) {
            $this->grantPush($user);
            $this->device($user, 'first-' . $user);
        }
        $this->device($first, 'second');
        $id = $this->createAppointment([$first, $removed]);
        $written = [];
        $sender = $this->createMock(PushSender::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects(self::once())->method('send')->willReturnCallback(function (array $device) use ($id, $first, $added, &$written): array {
            $this->appointments->update($this->admin, $id, ['version' => 1, 'participant_ids' => [$first, $added]]);
            $written[] = (int) $device['user_id'];

            return ['ok' => true];
        });
        $dispatches = $this->service($sender);

        $attempt = $dispatches->dispatch($this->admin, $id, 1, str_repeat('2', 32));
        self::assertSame([$first], $written);
        $snapshot = array_column($attempt['recipients'], null, 'user_id');
        self::assertSame([$first, $removed], array_keys($snapshot));
        self::assertSame('partially_accepted', $snapshot[$first]['status']);
        self::assertSame(1, $snapshot[$first]['accepted_subscription_count']);
        self::assertSame(0, $snapshot[$first]['failed_subscription_count']);
        self::assertSame('appointment_changed', $snapshot[$removed]['status']);
        self::assertSame(2, $dispatches->recipientPreview($this->admin, $id)['appointment_version']);
        $this->expectException(AppointmentConflictException::class);
        $dispatches->dispatch($this->admin, $id, 1, str_repeat('3', 32));
    }

    public function testUncertainEndpointIsNotRetriedAndReassignedDeviceIsNotContacted(): void
    {
        $this->enablePush();
        $recipient = $this->createUser();
        $newOwner = $this->createUser();
        $this->grantPush($recipient);
        $reassigned = $this->device($recipient, 'reassigned');
        $accepted = $this->device($recipient, 'second');
        $uncertain = $this->device($recipient, 'first');
        $id = $this->createAppointment([$recipient], ['status' => 'cancelled']);
        $sent = [];
        $sender = $this->createMock(PushSender::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects(self::exactly(2))->method('send')->willReturnCallback(function (array $device, array $message) use ($reassigned, $newOwner, $uncertain, &$sent): array {
            $sent[(int) $device['id']] = $message['title'];
            if ((int) $device['id'] === $uncertain) {
                $this->connection()->execute('UPDATE push_subscriptions SET user_id = :user WHERE id = :id', ['user' => $newOwner, 'id' => $reassigned]);
                throw new RuntimeException('SECRET-PROVIDER-DETAIL');
            }

            return ['ok' => true];
        });
        $dispatches = $this->service($sender);
        $key = str_repeat('4', 32);
        $result = $dispatches->dispatch($this->admin, $id, 1, $key);
        self::assertSame([$uncertain, $accepted], array_keys($sent));
        self::assertSame('Termin abgesagt – Heizungswartung', $sent[$uncertain]);
        self::assertSame('partially_accepted', $result['recipients'][0]['status']);
        self::assertSame(1, $result['accepted_subscription_count']);
        self::assertSame(1, $result['failed_subscription_count']);
        self::assertSame(1, $result['uncertain_subscription_count']);
        self::assertStringNotContainsString('SECRET-PROVIDER-DETAIL', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame($result, $dispatches->dispatch($this->admin, $id, 1, $key));
    }

    private function service(PushSender $sender, ?DatabaseConnection $connection = null): AppointmentDispatchService
    {
        $connection ??= $this->connection();

        return new AppointmentDispatchService($connection, new ProjectAccessService($connection), new PushSettingsService($connection), new PushSubscriptionService($connection), $sender);
    }

    private function createAppointment(array $participants, array $values = []): int
    {
        return $this->appointments->create($this->admin, $values + [
            'title' => 'Heizungswartung', 'starts_at' => '2099-07-16T09:15', 'ends_at' => '2099-07-16T10:15',
            'description' => 'Vertrauliche Detailbeschreibung', 'location' => 'Werkstatt', 'participant_ids' => $participants,
        ]);
    }

    private function enablePush(): void
    {
        (new PushSettingsService($this->connection()))->save(['enabled' => '1']);
    }

    private function grantPush(int $userId): void
    {
        $connection = $this->connection();
        $connection->execute(
            'INSERT IGNORE INTO roles (slug, name, is_system_role, created_at, updated_at)
             VALUES ("fixture-push", "Push-Freigabe", 0, NOW(), NOW())'
        );
        $connection->execute(
            'INSERT IGNORE INTO permissions (code, label, scope, created_at) VALUES ("push.receive", "Push empfangen", "app", NOW())'
        );
        $connection->execute(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = "push.receive" WHERE r.slug = "fixture-push"'
        );
        $connection->execute(
            'INSERT INTO user_roles (user_id, role_id) SELECT :user, id FROM roles WHERE slug = "fixture-push"',
            ['user' => $userId]
        );
    }

    private function device(int $userId, string $label, bool $active = true, string $permission = 'granted'): int
    {
        $endpoint = 'https://push.example.invalid/' . rawurlencode($label);
        $this->connection()->execute(
            'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, public_key, auth_token, permission_status,
                                             is_enabled, last_seen_at, created_at, updated_at)
             VALUES (:user, :hash, :endpoint, "fixture-key", "fixture-token", :permission, :enabled, "2099-01-01", NOW(), NOW())',
            ['user' => $userId, 'hash' => hash('sha256', $endpoint), 'endpoint' => $endpoint, 'permission' => $permission, 'enabled' => (int) $active]
        );

        return $this->connection()->lastInsertId();
    }
}
