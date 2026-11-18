<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PausePolicyModels extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pause_policy_templates')) {
            $this->table('pause_policy_templates')
                ->addColumn('name', 'string', ['limit' => 150])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_by_user_id', 'integer', ['signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('archived_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('archived_at', 'datetime', ['null' => true])
                ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('archived_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->create();
        }
        if (!$this->hasTable('pause_policy_versions')) {
            $this->table('pause_policy_versions')
                ->addColumn('template_id', 'integer', ['signed' => false])
                ->addColumn('revision', 'integer', ['signed' => false])
                ->addColumn('policy_json', 'text')
                ->addColumn('created_by_user_id', 'integer', ['signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('template_id', 'pause_policy_templates', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['template_id', 'revision'], ['unique' => true])
                ->create();
        }
        $this->createSchedule('pause_policy_defaults', false);
        $this->createSchedule('employee_pause_policy_assignments', true);
        if (!$this->hasTable('pause_policy_audit')) {
            $this->table('pause_policy_audit')
                ->addColumn('actor_user_id', 'integer', ['signed' => false])
                ->addColumn('affected_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('action', 'string', ['limit' => 40])
                ->addColumn('entity_id', 'integer', ['signed' => false])
                ->addColumn('reason', 'text')
                ->addColumn('before_json', 'text', ['null' => true])
                ->addColumn('after_json', 'text')
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('affected_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->addIndex(['affected_user_id', 'created_at'])
                ->create();
        }

        $this->execute(<<<'SQL'
INSERT IGNORE INTO permissions (code, label, scope, created_at) VALUES
    ('pause_policies.view', 'Pausenmodelle ansehen', 'timesheets', NOW()),
    ('pause_policies.manage', 'Pausenmodelle verwalten', 'timesheets', NOW())
SQL);
        $this->execute(<<<'SQL'
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id FROM roles CROSS JOIN permissions
WHERE roles.slug = 'administrator' AND permissions.code IN ('pause_policies.view', 'pause_policies.manage')
SQL);
    }

    public function down(): void
    {
        $tables = ['pause_policy_audit', 'employee_pause_policy_assignments', 'pause_policy_defaults', 'pause_policy_versions', 'pause_policy_templates'];
        foreach ($tables as $name) {
            if ($this->hasTable($name) && (int) ($this->fetchRow('SELECT COUNT(*) AS records FROM ' . $name)['records'] ?? 0) > 0) {
                throw new RuntimeException('Pausenregeln oder ihre Historie sind vorhanden. Rueckkehr nur ueber eine gesicherte Datenbankwiederherstellung.');
            }
        }
        foreach ($tables as $name) {
            if ($this->hasTable($name)) {
                $this->table($name)->drop()->save();
            }
        }
    }

    private function createSchedule(string $name, bool $perEmployee): void
    {
        if ($this->hasTable($name)) {
            return;
        }
        $table = $this->table($name);
        if ($perEmployee) {
            $table->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('overrides_json', 'text')
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT']);
        }
        $table->addColumn('policy_version_id', 'integer', ['signed' => false])
            ->addColumn('effective_from', 'date')
            ->addColumn('effective_to', 'date', ['null' => true])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('superseded_at', 'datetime', ['null' => true])
            ->addForeignKey('policy_version_id', 'pause_policy_versions', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
            ->addIndex($perEmployee ? ['user_id', 'superseded_at', 'effective_from'] : ['superseded_at', 'effective_from'], [
                'name' => $perEmployee ? 'idx_employee_pause_schedule' : 'idx_default_pause_schedule',
            ])
            ->create();
    }
}
