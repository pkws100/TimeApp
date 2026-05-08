<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AdminNavigationBrandingAndExports extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('company_settings');

        if (!$table->hasColumn('app_display_name')) {
            $table->addColumn('app_display_name', 'string', ['limit' => 150, 'null' => true, 'after' => 'company_name'])->update();
        }

        $this->ensurePermission('attendance.view', 'Anwesenheit ansehen', 'backend');
        $this->ensurePermission('reports.accounting.export', 'Buchhaltungsexport ausfuehren', 'backend');

        $this->assignRolePermissions('administrator', ['attendance.view', 'reports.accounting.export']);
        $this->assignRolePermissions('geschaeftsfuehrung', ['attendance.view', 'reports.accounting.export']);
        $this->assignRolePermissions('bauleiter', ['attendance.view']);
        $this->assignRolePermissions('kolonnenfuehrer', ['attendance.view']);
        $this->assignRolePermissions('disposition', ['attendance.view']);
    }

    public function down(): void
    {
        $table = $this->table('company_settings');

        if ($table->hasColumn('app_display_name')) {
            $table->removeColumn('app_display_name')->update();
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
