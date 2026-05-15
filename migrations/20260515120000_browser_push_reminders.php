<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BrowserPushReminders extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('push_settings')) {
            $this->table('push_settings', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'integer', ['signed' => false])
                ->addColumn('enabled', 'boolean', ['default' => false])
                ->addColumn('reminder_enabled', 'boolean', ['default' => true])
                ->addColumn('reminder_time', 'string', ['limit' => 5, 'default' => '09:00'])
                ->addColumn('reminder_weekdays', 'string', ['limit' => 30, 'default' => '1,2,3,4,5'])
                ->addColumn('notice_text', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }

        $this->execute(
            "INSERT IGNORE INTO push_settings (
                id, enabled, reminder_enabled, reminder_time, reminder_weekdays, notice_text, created_at, updated_at
            ) VALUES (
                1, 0, 1, '09:00', '1,2,3,4,5',
                'Sie haben heute noch keine Arbeitszeit gebucht. Bitte erfassen Sie Ihren Tagesstatus.',
                NOW(), NOW()
            )"
        );

        if (!$this->hasTable('push_subscriptions')) {
            $this->table('push_subscriptions')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('endpoint_hash', 'string', ['limit' => 64])
                ->addColumn('endpoint', 'text')
                ->addColumn('public_key', 'string', ['limit' => 255])
                ->addColumn('auth_token', 'string', ['limit' => 255])
                ->addColumn('content_encoding', 'string', ['limit' => 40, 'default' => 'aes128gcm'])
                ->addColumn('device_label', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('user_agent', 'text', ['null' => true])
                ->addColumn('is_enabled', 'boolean', ['default' => true])
                ->addColumn('permission_status', 'string', ['limit' => 30, 'default' => 'granted'])
                ->addColumn('last_seen_at', 'datetime', ['null' => true])
                ->addColumn('last_success_at', 'datetime', ['null' => true])
                ->addColumn('last_error_at', 'datetime', ['null' => true])
                ->addColumn('last_error_message', 'text', ['null' => true])
                ->addColumn('failed_attempts', 'integer', ['default' => 0])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addIndex(['endpoint_hash'], ['unique' => true])
                ->addIndex(['user_id', 'is_enabled'])
                ->create();
        }

        if (!$this->hasTable('push_notification_log')) {
            $this->table('push_notification_log')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('subscription_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('notification_type', 'string', ['limit' => 80])
                ->addColumn('work_date', 'date', ['null' => true])
                ->addColumn('dedupe_key', 'string', ['limit' => 160])
                ->addColumn('title', 'string', ['limit' => 180])
                ->addColumn('body', 'text')
                ->addColumn('payload_json', 'text', ['null' => true])
                ->addColumn('status', 'string', ['limit' => 30, 'default' => 'queued'])
                ->addColumn('error_message', 'text', ['null' => true])
                ->addColumn('sent_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('subscription_id', 'push_subscriptions', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['dedupe_key'], ['unique' => true])
                ->addIndex(['notification_type', 'work_date'])
                ->create();
        }

        $this->ensurePermission('push.receive', 'Push-Benachrichtigungen empfangen', 'app');
        $this->ensurePermission('push.manage', 'Push-Benachrichtigungen verwalten', 'backend');

        $this->assignRolePermissions('administrator', ['push.manage', 'push.receive']);
        $this->assignRolePermissions('geschaeftsfuehrung', ['push.manage', 'push.receive']);
        $this->assignRolePermissions('bauleiter', ['push.receive']);
        $this->assignRolePermissions('kolonnenfuehrer', ['push.receive']);
        $this->assignRolePermissions('mitarbeiter', ['push.receive']);
        $this->assignRolePermissions('disposition', ['push.receive']);
    }

    public function down(): void
    {
        if ($this->hasTable('push_notification_log')) {
            $this->table('push_notification_log')->drop()->save();
        }

        if ($this->hasTable('push_subscriptions')) {
            $this->table('push_subscriptions')->drop()->save();
        }

        if ($this->hasTable('push_settings')) {
            $this->table('push_settings')->drop()->save();
        }
    }

    private function ensurePermission(string $code, string $label, string $scope): void
    {
        $this->execute(
            sprintf(
                "INSERT IGNORE INTO permissions (code, label, scope, created_at) VALUES ('%s', '%s', '%s', NOW())",
                addslashes($code),
                addslashes($label),
                addslashes($scope)
            )
        );
    }

    private function assignRolePermissions(string $roleSlug, array $permissionCodes): void
    {
        foreach ($permissionCodes as $permissionCode) {
            $this->execute(
                sprintf(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id)
                     SELECT roles.id, permissions.id
                     FROM roles
                     INNER JOIN permissions ON permissions.code = '%s'
                     WHERE roles.slug = '%s'",
                    addslashes($permissionCode),
                    addslashes($roleSlug)
                )
            );
        }
    }
}
