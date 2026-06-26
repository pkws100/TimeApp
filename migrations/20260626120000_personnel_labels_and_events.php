<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PersonnelLabelsAndEvents extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('personnel_labels')) {
            $this->table('personnel_labels')
                ->addColumn('slug', 'string', ['limit' => 80])
                ->addColumn('name', 'string', ['limit' => 120])
                ->addColumn('color', 'string', ['limit' => 20, 'default' => '#2563eb'])
                ->addColumn('icon', 'string', ['limit' => 60, 'default' => 'award'])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['slug'], ['unique' => true])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->create();
        }

        if (!$this->hasTable('user_labels')) {
            $this->table('user_labels')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('label_id', 'integer', ['signed' => false])
                ->addColumn('assigned_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('label_id', 'personnel_labels', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('assigned_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['user_id', 'label_id'], ['unique' => true])
                ->addIndex(['label_id'])
                ->create();
        }

        if (!$this->hasTable('personnel_event_types')) {
            $this->table('personnel_event_types')
                ->addColumn('slug', 'string', ['limit' => 100])
                ->addColumn('name', 'string', ['limit' => 150])
                ->addColumn('color', 'string', ['limit' => 20, 'default' => '#7c3aed'])
                ->addColumn('icon', 'string', ['limit' => 60, 'default' => 'calendar-check'])
                ->addColumn('default_reminder_days', 'integer', ['null' => true])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['slug'], ['unique' => true])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->create();
        }

        if (!$this->hasTable('user_events')) {
            $this->table('user_events')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('event_type_id', 'integer', ['signed' => false])
                ->addColumn('title', 'string', ['limit' => 180, 'null' => true])
                ->addColumn('due_on', 'date')
                ->addColumn('valid_from', 'date', ['null' => true])
                ->addColumn('valid_until', 'date', ['null' => true])
                ->addColumn('note', 'text', ['null' => true])
                ->addColumn('completed_at', 'datetime', ['null' => true])
                ->addColumn('completed_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('event_type_id', 'personnel_event_types', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('completed_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['user_id', 'due_on'])
                ->addIndex(['event_type_id', 'due_on'])
                ->addIndex(['due_on', 'is_deleted'])
                ->create();
        }

        if (!$this->hasTable('user_event_reminders')) {
            $this->table('user_event_reminders')
                ->addColumn('user_event_id', 'integer', ['signed' => false])
                ->addColumn('channel', 'enum', ['values' => ['admin', 'push', 'email'], 'default' => 'admin'])
                ->addColumn('recipient_type', 'enum', ['values' => ['user', 'admin_role'], 'default' => 'user'])
                ->addColumn('role_slug', 'string', ['limit' => 80, 'null' => true])
                ->addColumn('days_before', 'integer', ['default' => 0])
                ->addColumn('is_enabled', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_event_id', 'user_events', 'id', ['delete' => 'CASCADE'])
                ->addIndex(['user_event_id', 'channel', 'recipient_type', 'role_slug'], ['unique' => true, 'name' => 'uniq_user_event_reminder_target'])
                ->addIndex(['channel', 'is_enabled'])
                ->create();
        }

        if (!$this->hasTable('personnel_event_notification_log')) {
            $this->table('personnel_event_notification_log')
                ->addColumn('user_event_id', 'integer', ['signed' => false])
                ->addColumn('reminder_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('recipient_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('recipient_role_slug', 'string', ['limit' => 80, 'null' => true])
                ->addColumn('channel', 'string', ['limit' => 30])
                ->addColumn('reminder_date', 'date')
                ->addColumn('dedupe_key', 'string', ['limit' => 180])
                ->addColumn('title', 'string', ['limit' => 180])
                ->addColumn('body', 'text')
                ->addColumn('status', 'string', ['limit' => 30, 'default' => 'queued'])
                ->addColumn('error_message', 'text', ['null' => true])
                ->addColumn('sent_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_event_id', 'user_events', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('reminder_id', 'user_event_reminders', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('recipient_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['dedupe_key'], ['unique' => true])
                ->addIndex(['channel', 'reminder_date'])
                ->create();
        }

        $this->ensurePermission('personnel.view', 'Personal-Qualifikationen ansehen', 'backend');
        $this->ensurePermission('personnel.manage', 'Personal-Qualifikationen verwalten', 'backend');

        $this->assignRolePermissions('administrator', ['personnel.view', 'personnel.manage']);
        $this->assignRolePermissions('geschaeftsfuehrung', ['personnel.view', 'personnel.manage']);
        $this->assignRolePermissions('bauleiter', ['personnel.view']);
        $this->assignRolePermissions('disposition', ['personnel.view']);
    }

    public function down(): void
    {
        foreach ([
            'personnel_event_notification_log',
            'user_event_reminders',
            'user_events',
            'personnel_event_types',
            'user_labels',
            'personnel_labels',
        ] as $tableName) {
            if ($this->hasTable($tableName)) {
                $this->table($tableName)->drop()->save();
            }
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
