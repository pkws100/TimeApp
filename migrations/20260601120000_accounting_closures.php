<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AccountingClosures extends AbstractMigration
{
    public function up(): void
    {
        $this->createAccountingClosures();
        $this->createAccountingClosureItems();
        $this->ensurePermission('accounting.finalize', 'Abrechnung festschreiben', 'accounting');
        $this->ensurePermission('reports.accounting.export', 'Buchhaltungsexport ausfuehren', 'backend');

        $this->assignRolePermissions('administrator', ['accounting.finalize', 'reports.accounting.export']);
        $this->assignRolePermissions('geschaeftsfuehrung', ['accounting.finalize', 'reports.accounting.export']);
    }

    public function down(): void
    {
        if ($this->hasTable('accounting_closure_items')) {
            $this->table('accounting_closure_items')->drop()->save();
        }

        if ($this->hasTable('accounting_closures')) {
            $this->table('accounting_closures')->drop()->save();
        }
    }

    private function createAccountingClosures(): void
    {
        if ($this->hasTable('accounting_closures')) {
            return;
        }

        $this->table('accounting_closures')
            ->addColumn('closure_number', 'string', ['limit' => 80])
            ->addColumn('closure_type', 'enum', ['values' => ['month', 'project']])
            ->addColumn('status', 'enum', ['values' => ['draft', 'final', 'correction'], 'default' => 'final'])
            ->addColumn('period_start', 'date')
            ->addColumn('period_end', 'date')
            ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('original_closure_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('snapshot_hash', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('item_count', 'integer', ['default' => 0])
            ->addColumn('total_net_minutes', 'integer', ['default' => 0])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('finalized_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('finalized_at', 'datetime', ['null' => true])
            ->addColumn('note', 'text', ['null' => true])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('finalized_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('original_closure_id', 'accounting_closures', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['closure_number'], ['unique' => true])
            ->addIndex(['closure_type', 'period_start', 'period_end'])
            ->addIndex(['status'])
            ->create();
    }

    private function createAccountingClosureItems(): void
    {
        if ($this->hasTable('accounting_closure_items')) {
            return;
        }

        $this->table('accounting_closure_items')
            ->addColumn('closure_id', 'integer', ['signed' => false])
            ->addColumn('timesheet_id', 'integer', ['signed' => false])
            ->addColumn('work_date', 'date')
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('employee_number', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('employee_name', 'string', ['limit' => 220])
            ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('project_number', 'string', ['limit' => 80, 'null' => true])
            ->addColumn('project_name', 'string', ['limit' => 220])
            ->addColumn('entry_type', 'string', ['limit' => 40])
            ->addColumn('source', 'string', ['limit' => 40])
            ->addColumn('source_label', 'string', ['limit' => 120])
            ->addColumn('start_time', 'time', ['null' => true])
            ->addColumn('end_time', 'time', ['null' => true])
            ->addColumn('gross_minutes', 'integer', ['default' => 0])
            ->addColumn('break_minutes', 'integer', ['default' => 0])
            ->addColumn('net_minutes', 'integer', ['default' => 0])
            ->addColumn('expenses_amount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('change_count', 'integer', ['default' => 0])
            ->addColumn('version_hint', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('booking_updated_at', 'datetime', ['null' => true])
            ->addColumn('row_hash', 'string', ['limit' => 64])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('closure_id', 'accounting_closures', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['closure_id'])
            ->addIndex(['timesheet_id'])
            ->addIndex(['user_id'])
            ->addIndex(['project_id'])
            ->addIndex(['row_hash'])
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
