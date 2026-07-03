<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TerminalNfcTimeTracking extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('company_settings')) {
            $settings = $this->table('company_settings');

            if (!$settings->hasColumn('terminal_enabled')) {
                $settings->addColumn('terminal_enabled', 'boolean', ['default' => false, 'after' => 'geo_requires_acknowledgement'])->update();
            }
        }

        if (!$this->hasTable('terminals')) {
            $this->table('terminals')
                ->addColumn('terminal_identifier', 'string', ['limit' => 80])
                ->addColumn('name', 'string', ['limit' => 150])
                ->addColumn('ip_allowlist', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('token_hash', 'string', ['limit' => 255])
                ->addColumn('welcome_text', 'string', ['limit' => 80, 'default' => 'Willkommen'])
                ->addColumn('default_project_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('settings_json', 'text', ['null' => true])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('last_seen_at', 'datetime', ['null' => true])
                ->addColumn('last_seen_ip', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('default_project_id', 'projects', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['terminal_identifier'], ['unique' => true])
                ->addIndex(['is_active', 'is_deleted'])
                ->create();
        }

        if (!$this->hasTable('nfc_tags')) {
            $this->table('nfc_tags')
                ->addColumn('uid_hash', 'string', ['limit' => 128])
                ->addColumn('uid_masked', 'string', ['limit' => 80])
                ->addColumn('label', 'string', ['limit' => 150, 'null' => true])
                ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending'])
                ->addColumn('learned_terminal_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('learned_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('learned_at', 'datetime', ['null' => true])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('learned_terminal_id', 'terminals', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('learned_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['uid_hash'], ['unique' => true])
                ->addIndex(['status', 'is_deleted'])
                ->create();
        }

        if (!$this->hasTable('terminal_learn_sessions')) {
            $this->table('terminal_learn_sessions')
                ->addColumn('terminal_id', 'integer', ['signed' => false])
                ->addColumn('admin_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('nfc_tag_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending'])
                ->addColumn('expires_at', 'datetime')
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('completed_at', 'datetime', ['null' => true])
                ->addForeignKey('terminal_id', 'terminals', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('admin_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('nfc_tag_id', 'nfc_tags', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['terminal_id', 'status', 'expires_at'])
                ->create();
        }

        if (!$this->hasTable('terminal_events')) {
            $this->table('terminal_events')
                ->addColumn('terminal_id', 'integer', ['signed' => false])
                ->addColumn('nfc_tag_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('request_id', 'string', ['limit' => 120])
                ->addColumn('action', 'string', ['limit' => 40, 'null' => true])
                ->addColumn('result', 'string', ['limit' => 40])
                ->addColumn('message', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('firmware_version', 'string', ['limit' => 120, 'null' => true])
                ->addColumn('device_time', 'datetime', ['null' => true])
                ->addColumn('response_json', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('terminal_id', 'terminals', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('nfc_tag_id', 'nfc_tags', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['terminal_id', 'request_id'], ['unique' => true])
                ->addIndex(['created_at'])
                ->create();
        }

        $this->ensurePermission('terminals.manage', 'Terminals und NFC-Tags verwalten', 'backend');
        $this->assignRolePermissions('administrator', ['terminals.manage']);
        $this->assignRolePermissions('geschaeftsfuehrung', ['terminals.manage']);
    }

    public function down(): void
    {
        foreach (['terminal_events', 'terminal_learn_sessions', 'nfc_tags', 'terminals'] as $tableName) {
            if ($this->hasTable($tableName)) {
                $this->table($tableName)->drop()->save();
            }
        }

        if ($this->hasTable('company_settings')) {
            $settings = $this->table('company_settings');

            if ($settings->hasColumn('terminal_enabled')) {
                $settings->removeColumn('terminal_enabled')->update();
            }
        }
    }

    private function ensurePermission(string $code, string $label, string $scope): void
    {
        $this->execute(sprintf(
            "INSERT INTO permissions (code, label, scope, created_at)
             VALUES ('%s', '%s', '%s', NOW())
             ON DUPLICATE KEY UPDATE label = VALUES(label), scope = VALUES(scope)",
            addslashes($code),
            addslashes($label),
            addslashes($scope)
        ));
    }

    private function assignRolePermissions(string $roleSlug, array $permissionCodes): void
    {
        foreach ($permissionCodes as $permissionCode) {
            $this->execute(sprintf(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id)
                 SELECT roles.id, permissions.id
                 FROM roles
                 INNER JOIN permissions ON permissions.code = '%s'
                 WHERE roles.slug = '%s'",
                addslashes($permissionCode),
                addslashes($roleSlug)
            ));
        }
    }
}
