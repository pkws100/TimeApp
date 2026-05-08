<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AdminBookingsAndTimesheetAudit extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('SET @@system_versioning_alter_history = 1;');
        $this->addTimesheetArchivalColumns();
        $this->createAuditTable();
        $this->ensurePermission('timesheets.view', 'Buchungen ansehen', 'timesheets');
        $this->ensurePermission('timesheets.archive', 'Buchungen archivieren', 'timesheets');
        $this->ensurePermission('timesheets.export', 'Buchungen exportieren', 'timesheets');

        $this->assignRolePermissions('administrator', [
            'timesheets.view',
            'timesheets.archive',
            'timesheets.export',
        ]);
        $this->assignRolePermissions('geschaeftsfuehrung', [
            'timesheets.view',
            'timesheets.manage',
            'timesheets.archive',
            'timesheets.export',
        ]);
        $this->assignRolePermissions('bauleiter', [
            'timesheets.view',
            'timesheets.manage',
            'timesheets.export',
        ]);
        $this->assignRolePermissions('kolonnenfuehrer', [
            'timesheets.view',
            'timesheets.manage',
        ]);
        $this->assignRolePermissions('disposition', [
            'timesheets.view',
            'timesheets.export',
        ]);
    }

    public function down(): void
    {
        $this->execute('SET @@system_versioning_alter_history = 1;');

        if ($this->hasTable('timesheet_change_log')) {
            $this->table('timesheet_change_log')->drop()->save();
        }

        $table = $this->table('timesheets');

        if ($table->hasForeignKey('deleted_by_user_id')) {
            $table->dropForeignKey('deleted_by_user_id')->update();
        }

        $table = $this->table('timesheets');

        foreach (['deleted_by_user_id', 'deleted_at', 'is_deleted'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }

        $table->update();
    }

    private function addTimesheetArchivalColumns(): void
    {
        $table = $this->table('timesheets');

        if (!$table->hasColumn('is_deleted')) {
            $table->addColumn('is_deleted', 'boolean', ['default' => false, 'after' => 'updated_at']);
        }

        if (!$table->hasColumn('deleted_at')) {
            $table->addColumn('deleted_at', 'datetime', ['null' => true, 'after' => 'is_deleted']);
        }

        if (!$table->hasColumn('deleted_by_user_id')) {
            $table->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'deleted_at']);
        }

        $table->update();

        $table = $this->table('timesheets');

        if (!$table->hasForeignKey('deleted_by_user_id')) {
            $table->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])->update();
        }
    }

    private function createAuditTable(): void
    {
        if ($this->hasTable('timesheet_change_log')) {
            return;
        }

        $this->table('timesheet_change_log')
            ->addColumn('timesheet_id', 'integer', ['signed' => false])
            ->addColumn('action_type', 'string', ['limit' => 60])
            ->addColumn('changed_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('change_reason', 'text')
            ->addColumn('before_snapshot', 'text', ['null' => true])
            ->addColumn('after_snapshot', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('changed_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->create();
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
