<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\App\MobileAppService;
use App\Domain\Backup\PdoBackupDatabaseSource;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectDispatchService;
use App\Domain\Projects\ProjectMaterialService;
use App\Domain\Projects\ProjectService;
use App\Domain\Push\PushSender;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class ProjectWorkOrdersDatabaseTest extends MariaDbTestCase
{
    public function testMigrationCreatesProjectFieldsTablesForeignKeysAndIndexes(): void
    {
        foreach (['work_instructions', 'work_instructions_updated_at', 'work_instructions_updated_by_user_id'] as $column) {
            self::assertTrue($this->connection()->columnExists('projects', $column));
        }

        foreach (['project_material_entries', 'project_dispatches', 'project_dispatch_recipients'] as $table) {
            self::assertTrue($this->connection()->tableExists($table));
        }

        self::assertSame('decimal', strtolower((string) $this->connection()->fetchColumn(
            'SELECT data_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = "project_material_entries" AND column_name = "quantity"'
        )));
        self::assertSame('12,3', $this->connection()->fetchColumn(
            'SELECT CONCAT(numeric_precision, ",", numeric_scale) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = "project_material_entries" AND column_name = "quantity"'
        ));
        self::assertSame(1, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = "project_dispatch_recipients"
               AND index_name = "uniq_project_dispatch_recipient"
               AND non_unique = 0'
        ));
        self::assertSame('SET NULL', $this->connection()->fetchColumn(
            'SELECT delete_rule FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name = "project_dispatch_recipients"
               AND referenced_table_name = "users"
             LIMIT 1'
        ));
        self::assertSame(2, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name IN ("project_material_entries", "project_dispatches")
               AND referenced_table_name = "projects"
               AND delete_rule = "RESTRICT"'
        ));
    }

    public function testWorkInstructionsAreNormalizedAndMetadataOnlyChangesWithContent(): void
    {
        $actorId = $this->createUser();
        $service = new ProjectService($this->connection());
        $project = $service->create([
            'project_number' => 'ORDER-1',
            'name' => 'Arbeitsauftrag',
            'status' => 'active',
            'work_instructions' => "  Erste Zeile\r\nZweite Zeile\r  ",
        ], $actorId);
        $projectId = (int) $project['id'];
        $stored = $service->find($projectId);

        self::assertSame("Erste Zeile\nZweite Zeile", $stored['work_instructions']);
        self::assertSame($actorId, (int) $stored['work_instructions_updated_by_user_id']);
        self::assertNotNull($stored['work_instructions_updated_at']);

        $this->connection()->execute(
            'UPDATE projects SET work_instructions_updated_at = "2026-01-02 03:04:05" WHERE id = :id',
            ['id' => $projectId]
        );
        $service->update($projectId, [
            ...$stored,
            'name' => 'Nur Stammdaten geaendert',
            'work_instructions' => "Erste Zeile\r\nZweite Zeile",
        ], $actorId);
        $unchanged = $service->find($projectId);

        self::assertSame('2026-01-02 03:04:05', $unchanged['work_instructions_updated_at']);

        $service->update($projectId, [
            ...$unchanged,
            'work_instructions' => "Neue\nAnweisung",
        ], $actorId);
        $changed = $service->find($projectId);

        self::assertSame("Neue\nAnweisung", $changed['work_instructions']);
        self::assertNotSame('2026-01-02 03:04:05', $changed['work_instructions_updated_at']);

        $legacyPayload = $changed;
        unset($legacyPayload['work_instructions']);
        $legacyPayload['name'] = 'Aelterer API-Client';
        $legacyUpdated = $service->update($projectId, $legacyPayload, $actorId);
        self::assertSame("Neue\nAnweisung", $legacyUpdated['work_instructions']);

        $service->update($projectId, [
            ...$changed,
            'work_instructions' => " \r\n ",
        ], $actorId);
        self::assertNull($service->find($projectId)['work_instructions']);
    }

    public function testProjectAccessAllowsCurrentMembershipAndGlobalRightsButRejectsIdor(): void
    {
        $memberId = $this->createUser();
        $strangerId = $this->createUser();
        $inactiveId = $this->createUser(['employment_status' => 'inactive']);
        $projectId = $this->createProject();
        $otherProjectId = $this->createProject();
        $this->assign($projectId, $memberId);
        $this->assign($projectId, $inactiveId);
        $access = new ProjectAccessService($this->connection());

        self::assertTrue($access->canAccess($this->actor($memberId), $projectId));
        self::assertFalse($access->canAccess($this->actor($memberId), $otherProjectId));
        self::assertFalse($access->canAccess($this->actor($strangerId), $projectId));
        self::assertFalse($access->canAccess($this->actor($inactiveId, [], 'inactive'), $projectId));
        self::assertTrue($access->canAccess($this->actor($strangerId, ['files.manage']), $otherProjectId));

        $this->connection()->execute(
            'UPDATE project_memberships SET assigned_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
             WHERE project_id = :project_id AND user_id = :user_id',
            ['project_id' => $projectId, 'user_id' => $memberId]
        );
        self::assertFalse($access->canAccess($this->actor($memberId), $projectId));

        $this->connection()->execute(
            'UPDATE project_memberships
             SET assigned_from = DATE_ADD(CURDATE(), INTERVAL 1 DAY), assigned_until = NULL
             WHERE project_id = :project_id AND user_id = :user_id',
            ['project_id' => $projectId, 'user_id' => $memberId]
        );
        self::assertFalse($access->canAccess($this->actor($memberId), $projectId));

        $this->connection()->execute(
            'UPDATE projects SET status = "archived" WHERE id = :project_id',
            ['project_id' => $projectId]
        );
        self::assertFalse($access->canAccess($this->actor($memberId), $projectId));
        self::assertTrue($access->canAccess($this->actor($strangerId, ['projects.manage']), $projectId));
    }

    public function testMobileDayContextIncludesOnlyAuthorizedProjectOrderDetails(): void
    {
        $userId = $this->createUser();
        $authorizedProjectId = $this->createProject();
        $unauthorizedProjectId = $this->createProject();
        $this->assign($authorizedProjectId, $userId);
        $this->connection()->execute(
            'UPDATE projects
             SET customer_name = "Kunde Test",
                 address_line_1 = "Musterweg 1",
                 postal_code = "12345",
                 city = "Musterstadt",
                 work_instructions = "Erste Zeile\nZweite Zeile",
                 work_instructions_updated_at = "2026-07-22 12:00:00"
             WHERE id = :id',
            ['id' => $authorizedProjectId]
        );
        $connection = $this->connection();
        $service = new MobileAppService(
            $connection,
            new ProjectService($connection, new ProjectAccessService($connection)),
            new CompanySettingsService($connection, []),
            new WorkdayStateCalculator(),
            new FileAttachmentService($connection, [])
        );
        $context = $service->dayContext([
            ...$this->actor($userId),
            'first_name' => 'Mobile',
            'last_name' => 'Mitarbeiter',
            'email' => 'mobile@example.test',
            'time_tracking_required' => 1,
            'roles' => [],
        ]);

        self::assertCount(1, $context['projects']);
        self::assertSame($authorizedProjectId, $context['projects'][0]['id']);
        self::assertSame('Kunde Test', $context['projects'][0]['customer_name']);
        self::assertSame('Musterweg 1', $context['projects'][0]['address_line_1']);
        self::assertSame('12345', $context['projects'][0]['postal_code']);
        self::assertSame('Musterstadt', $context['projects'][0]['city']);
        self::assertSame("Erste Zeile\nZweite Zeile", $context['projects'][0]['work_instructions']);
        self::assertSame('2026-07-22 12:00:00', $context['projects'][0]['work_instructions_updated_at']);
        self::assertNotSame($unauthorizedProjectId, $context['projects'][0]['id']);
    }

    public function testMaterialEntriesValidateCreateListAndArchiveRights(): void
    {
        $creatorId = $this->createUser();
        $otherId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $creatorId);
        $this->assign($projectId, $otherId);
        $service = new ProjectMaterialService($this->connection(), new ProjectAccessService($this->connection()));
        $entry = $service->create($this->actor($creatorId), $projectId, [
            'description' => 'Kupferrohr',
            'quantity' => '12,500',
            'unit' => 'm',
            'note' => 'Fuer Heizkreis',
            'work_date' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d'),
        ]);

        self::assertSame('12.500', $entry['quantity']);
        self::assertSame('Kupferrohr', $entry['description']);
        self::assertCount(1, $service->list($this->actor($creatorId), $projectId));

        try {
            $service->archive($this->actor($otherId), (int) $entry['id']);
            self::fail('Fremde Materialeintraege muessen fuer Mitarbeiter gesperrt sein.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Fremde Materialeintraege', $exception->getMessage());
        }

        $service->archive($this->actor($creatorId), (int) $entry['id']);
        self::assertSame([], $service->list($this->actor($creatorId), $projectId));
        $adminArchived = $service->create($this->actor($creatorId), $projectId, [
            'description' => 'Admin-Archiv',
            'quantity' => '1',
            'work_date' => date('Y-m-d'),
        ]);
        $service->archive(
            $this->actor($otherId, ['projects.manage']),
            (int) $adminArchived['id']
        );
        self::assertSame([], $service->list($this->actor($creatorId), $projectId));
        self::assertCount(2, $service->list($this->actor($creatorId, ['projects.manage']), $projectId, 'all'));
    }

    public function testMaterialValidationRejectsInvalidQuantityTextAndFutureDate(): void
    {
        $userId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $userId);
        $service = new ProjectMaterialService($this->connection(), new ProjectAccessService($this->connection()));
        $normalized = $service->create($this->actor($userId), $projectId, [
            'description' => 'Mit fuehrenden Nullen',
            'quantity' => '0002,50',
            'work_date' => date('Y-m-d'),
        ]);
        $invalidInputs = [
            ['description' => 'A', 'quantity' => '0', 'work_date' => date('Y-m-d')],
            ['description' => 'A', 'quantity' => '1.2345', 'work_date' => date('Y-m-d')],
            ['description' => 'A', 'quantity' => 'Text', 'work_date' => date('Y-m-d')],
            ['description' => 'A', 'quantity' => '1', 'work_date' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')],
            ['description' => '', 'quantity' => '1', 'work_date' => date('Y-m-d')],
            ['description' => [], 'quantity' => '1', 'work_date' => date('Y-m-d')],
            ['description' => 'A', 'quantity' => [], 'work_date' => date('Y-m-d')],
            ['description' => 'A', 'quantity' => '1', 'unit' => [], 'work_date' => date('Y-m-d')],
            ['description' => 'A', 'quantity' => '1', 'note' => [], 'work_date' => date('Y-m-d')],
            ['description' => 'A', 'quantity' => '1', 'work_date' => []],
        ];

        self::assertSame('2.500', $normalized['quantity']);

        foreach ($invalidInputs as $input) {
            try {
                $service->create($this->actor($userId), $projectId, $input);
                self::fail('Ungueltiger Materialeintrag wurde akzeptiert: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDispatchWithoutActiveRecipientsIsRejectedWithoutHistoryEntry(): void
    {
        $actorId = $this->createUser();
        $projectId = $this->createProject();
        $service = $this->dispatchService(new RecordingProjectPushSender());

        try {
            $service->dispatch($this->actor($actorId, ['projects.manage']), $projectId);
            self::fail('Ein Versand ohne aktive Empfaenger muss abgelehnt werden.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('keine aktiven Empfaenger', $exception->getMessage());
        }

        self::assertSame(0, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM project_dispatches WHERE project_id = :project_id',
            ['project_id' => $projectId]
        ));
    }

    public function testDispatchPersistsKindsCountersDeviceResultsAndRepeatSend(): void
    {
        $actorId = $this->createUser();
        $sentUserId = $this->createUser(['first_name' => 'Erika', 'last_name' => 'Erreichbar']);
        $noDeviceUserId = $this->createUser(['first_name' => 'Nora', 'last_name' => 'Ohnegeraet']);
        $noPermissionUserId = $this->createUser(['first_name' => 'Paul', 'last_name' => 'OhneRecht']);
        $projectId = $this->createProject();

        foreach ([$sentUserId, $noDeviceUserId, $noPermissionUserId] as $userId) {
            $this->assign($projectId, $userId);
        }

        $this->grantPushPermission($sentUserId);
        $this->grantPushPermission($noDeviceUserId);
        $firstSubscription = $this->createSubscription($sentUserId, 'first');
        $secondSubscription = $this->createSubscription($sentUserId, 'second');
        $this->setPushEnabled(true);
        $sender = new RecordingProjectPushSender([$firstSubscription => true, $secondSubscription => false]);
        $service = $this->dispatchService($sender);
        $actor = $this->actor($actorId, ['projects.manage']);

        $preview = $service->recipientPreview($actor, $projectId);
        $previewWithSkipReason = array_values(array_filter(
            $preview,
            static fn (array $row): bool => $row['skip_reason'] !== null
        ));

        self::assertCount(3, $preview);
        self::assertCount(2, $previewWithSkipReason);

        $first = $service->dispatch($actor, $projectId);
        $second = $service->dispatch($actor, $projectId);

        self::assertSame('new', $first['dispatch_kind']);
        self::assertSame('update', $second['dispatch_kind']);
        self::assertSame(3, $first['recipient_count']);
        self::assertSame(1, $first['delivered_count']);
        self::assertSame(2, $first['skipped_count']);
        self::assertSame(0, $first['error_count']);
        self::assertSame(1, $first['successful_device_count']);
        self::assertSame(1, $first['failed_device_count']);
        self::assertCount(4, $sender->calls);
        self::assertContains(
            'project-dispatch:' . $first['id'] . ':user:' . $sentUserId . ':subscription:' . $firstSubscription,
            array_column($sender->calls, 'dedupe_key')
        );
        $sentRecipient = $this->connection()->fetchOne(
            'SELECT status, active_subscription_count, successful_subscription_count, failed_subscription_count
             FROM project_dispatch_recipients
             WHERE dispatch_id = :dispatch_id AND user_id = :user_id',
            ['dispatch_id' => $first['id'], 'user_id' => $sentUserId]
        );
        self::assertSame('sent', $sentRecipient['status']);
        self::assertSame(2, (int) $sentRecipient['active_subscription_count']);
        self::assertSame(1, (int) $sentRecipient['successful_subscription_count']);
        self::assertSame(1, (int) $sentRecipient['failed_subscription_count']);
        $history = $service->history($actor, $projectId);
        self::assertSame(1, (int) $history[0]['successful_device_count']);
        self::assertSame(1, (int) $history[0]['failed_device_count']);
        self::assertSame(6, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM project_dispatch_recipients WHERE dispatch_id IN (:first_id, :second_id)',
            ['first_id' => $first['id'], 'second_id' => $second['id']]
        ));
    }

    public function testArchivedRoleDoesNotGrantPushPermission(): void
    {
        $actorId = $this->createUser();
        $recipientId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $recipientId);
        $this->grantPushPermission($recipientId);
        $this->createSubscription($recipientId, 'archived-role');
        $this->connection()->execute(
            'UPDATE roles
             INNER JOIN user_roles ON user_roles.role_id = roles.id
             SET roles.is_deleted = 1
             WHERE user_roles.user_id = :user_id',
            ['user_id' => $recipientId]
        );

        $preview = $this->dispatchService(new RecordingProjectPushSender())->recipientPreview(
            $this->actor($actorId, ['projects.manage']),
            $projectId
        );

        self::assertCount(1, $preview);
        self::assertFalse($preview[0]['has_push_permission']);
        self::assertSame('Push-Recht fehlt', $preview[0]['skip_reason']);
    }

    public function testDisabledPushAuditsEligibleRecipientAsErrorWithoutSending(): void
    {
        $actorId = $this->createUser();
        $recipientId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $recipientId);
        $this->grantPushPermission($recipientId);
        $this->createSubscription($recipientId, 'disabled');
        $this->setPushEnabled(false);
        $sender = new RecordingProjectPushSender();

        $result = $this->dispatchService($sender)->dispatch(
            $this->actor($actorId, ['projects.manage']),
            $projectId
        );

        self::assertFalse($result['push_available']);
        self::assertSame(1, $result['error_count']);
        self::assertSame([], $sender->calls);
        self::assertSame('error', $this->connection()->fetchColumn(
            'SELECT status FROM project_dispatch_recipients WHERE dispatch_id = :dispatch_id',
            ['dispatch_id' => $result['id']]
        ));
        self::assertStringContainsString('global deaktiviert', $result['message']);
    }

    public function testMissingVapidConfigurationIsAuditedWithoutSending(): void
    {
        $actorId = $this->createUser();
        $recipientId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $recipientId);
        $this->grantPushPermission($recipientId);
        $this->createSubscription($recipientId, 'missing-vapid');
        $this->setPushEnabled(true);
        $sender = new RecordingProjectPushSender([], false);

        $result = $this->dispatchService($sender)->dispatch(
            $this->actor($actorId, ['projects.manage']),
            $projectId
        );

        self::assertFalse($result['push_available']);
        self::assertSame(1, $result['error_count']);
        self::assertSame([], $sender->calls);
        self::assertStringContainsString('VAPID', $result['message']);
    }

    public function testBackupSourceDiscoversNewTablesAutomatically(): void
    {
        $tables = (new PdoBackupDatabaseSource($this->connection()))->applicationTables();

        self::assertContains('project_material_entries', $tables);
        self::assertContains('project_dispatches', $tables);
        self::assertContains('project_dispatch_recipients', $tables);
    }

    private function actor(int $userId, array $permissions = [], string $status = 'active'): array
    {
        return [
            'id' => $userId,
            'employment_status' => $status,
            'is_deleted' => 0,
            'permissions' => $permissions,
        ];
    }

    private function assign(int $projectId, int $userId): void
    {
        $this->connection()->execute(
            'INSERT INTO project_memberships (project_id, user_id, assigned_from, assigned_until)
             VALUES (:project_id, :user_id, CURDATE(), NULL)',
            ['project_id' => $projectId, 'user_id' => $userId]
        );
    }

    private function grantPushPermission(int $userId): void
    {
        $roleSlug = 'push-' . $userId;
        $this->connection()->execute(
            'INSERT INTO permissions (code, label, scope, created_at)
             VALUES ("push.receive", "Push empfangen", "app", NOW())
             ON DUPLICATE KEY UPDATE label = VALUES(label)'
        );
        $this->connection()->execute(
            'INSERT INTO roles (slug, name, is_system_role, created_at, updated_at, is_deleted)
             VALUES (:slug, :name, 0, NOW(), NOW(), 0)',
            ['slug' => $roleSlug, 'name' => 'Push ' . $userId]
        );
        $roleId = $this->connection()->lastInsertId();
        $permissionId = (int) $this->connection()->fetchColumn(
            'SELECT id FROM permissions WHERE code = "push.receive" LIMIT 1'
        );
        $this->connection()->execute(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
            ['role_id' => $roleId, 'permission_id' => $permissionId]
        );
        $this->connection()->execute(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $userId, 'role_id' => $roleId]
        );
    }

    private function createSubscription(int $userId, string $suffix): int
    {
        $endpoint = 'https://push.example.test/' . $userId . '/' . $suffix;
        $this->connection()->execute(
            'INSERT INTO push_subscriptions (
                user_id, endpoint_hash, endpoint, public_key, auth_token, content_encoding,
                is_enabled, permission_status, last_seen_at, created_at, updated_at
             ) VALUES (
                :user_id, :endpoint_hash, :endpoint, "public", "auth", "aes128gcm",
                1, "granted", NOW(), NOW(), NOW()
             )',
            [
                'user_id' => $userId,
                'endpoint_hash' => hash('sha256', $endpoint),
                'endpoint' => $endpoint,
            ]
        );

        return $this->connection()->lastInsertId();
    }

    private function setPushEnabled(bool $enabled): void
    {
        $this->connection()->execute(
            'INSERT INTO push_settings (
                id, enabled, reminder_enabled, reminder_time, reminder_weekdays, notice_text, created_at, updated_at
             ) VALUES (
                1, :enabled, 1, "09:00", "1,2,3,4,5", "Test", NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()',
            ['enabled' => $enabled ? 1 : 0]
        );
    }

    private function dispatchService(RecordingProjectPushSender $sender): ProjectDispatchService
    {
        $settings = new PushSettingsService($this->connection(), [
            'vapid' => [
                'public_key' => 'public',
                'private_key' => 'private',
                'subject' => 'mailto:test@example.test',
            ],
        ]);

        return new ProjectDispatchService(
            $this->connection(),
            new ProjectAccessService($this->connection()),
            $settings,
            new PushSubscriptionService($this->connection()),
            $sender
        );
    }
}

final class RecordingProjectPushSender implements PushSender
{
    public array $calls = [];

    public function __construct(
        private array $outcomes = [],
        private bool $configured = true
    )
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(
        array $subscriptionRow,
        array $message,
        ?string $dedupeKey = null,
        ?string $workDate = null
    ): array {
        $this->calls[] = [
            'subscription' => $subscriptionRow,
            'message' => $message,
            'dedupe_key' => $dedupeKey,
            'work_date' => $workDate,
        ];
        $ok = $this->outcomes[(int) ($subscriptionRow['id'] ?? 0)] ?? true;

        return ['ok' => $ok, 'message' => $ok ? 'ok' : 'failed'];
    }
}
