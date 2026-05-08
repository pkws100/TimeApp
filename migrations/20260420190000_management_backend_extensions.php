<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ManagementBackendExtensions extends AbstractMigration
{
    public function up(): void
    {
        $this->addArchivalColumns('roles');
        $this->addArchivalColumns('users', true);
        $this->addArchivalColumns('projects');
        $this->addArchivalColumns('assets');
        $this->addArchivalColumns('project_files');

        if (!$this->hasTable('asset_files')) {
            $this->table('asset_files')
                ->addColumn('asset_id', 'integer', ['signed' => false])
                ->addColumn('original_name', 'string', ['limit' => 255])
                ->addColumn('stored_name', 'string', ['limit' => 255])
                ->addColumn('mime_type', 'string', ['limit' => 150])
                ->addColumn('size_bytes', 'biginteger')
                ->addColumn('storage_path', 'string', ['limit' => 255])
                ->addColumn('uploaded_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('uploaded_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('uploaded_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->create();
        }

        $this->ensurePermission('dashboard.view', 'Dashboard ansehen', 'backend');
        $this->ensurePermission('users.manage', 'Benutzer verwalten', 'backend');
        $this->ensurePermission('roles.manage', 'Rollen verwalten', 'backend');
        $this->ensurePermission('projects.view', 'Projekte ansehen', 'project');
        $this->ensurePermission('projects.manage', 'Projekte verwalten', 'project');
        $this->ensurePermission('files.view', 'Dateien ansehen', 'project');
        $this->ensurePermission('files.upload', 'Dateien hochladen', 'project');
        $this->ensurePermission('files.manage', 'Dateien verwalten', 'project');
        $this->ensurePermission('assets.manage', 'Fahrzeuge und Geraete verwalten', 'backend');
        $this->ensurePermission('assets.assign', 'Fahrzeuge und Geraete zuweisen', 'backend');
        $this->ensurePermission('timesheets.create', 'Zeiten erfassen', 'timesheets');
        $this->ensurePermission('timesheets.manage', 'Zeiten verwalten', 'timesheets');
        $this->ensurePermission('timesheets.view_own', 'Eigene Zeiten ansehen', 'timesheets');
        $this->ensurePermission('reports.export', 'Berichte exportieren', 'backend');
        $this->ensurePermission('settings.database.manage', 'Datenbank umstellen', 'backend');

        $this->assignRolePermissions('administrator', [
            'dashboard.view',
            'users.manage',
            'roles.manage',
            'projects.view',
            'projects.manage',
            'files.view',
            'files.upload',
            'files.manage',
            'assets.manage',
            'assets.assign',
            'timesheets.create',
            'timesheets.manage',
            'timesheets.view_own',
            'reports.export',
            'settings.database.manage',
        ]);
        $this->assignRolePermissions('bauleiter', [
            'dashboard.view',
            'projects.view',
            'projects.manage',
            'files.view',
            'files.upload',
            'files.manage',
            'assets.manage',
            'timesheets.manage',
            'reports.export',
        ]);
        $this->assignRolePermissions('kolonnenfuehrer', [
            'dashboard.view',
            'projects.view',
            'files.view',
            'files.upload',
            'assets.assign',
            'timesheets.manage',
        ]);
        $this->assignRolePermissions('mitarbeiter', [
            'projects.view',
            'files.view',
            'timesheets.create',
            'timesheets.view_own',
        ]);

        $this->execute(
            "INSERT IGNORE INTO user_roles (user_id, role_id)
             SELECT users.id, roles.id
             FROM users
             INNER JOIN roles ON roles.slug = 'administrator'
             WHERE users.email = 'admin@example.invalid'"
        );
    }

    public function down(): void
    {
        if ($this->hasTable('asset_files')) {
            $this->table('asset_files')->drop()->save();
        }

        $this->dropArchivalColumns('project_files');
        $this->dropArchivalColumns('assets');
        $this->dropArchivalColumns('projects');
        $this->dropArchivalColumns('users');
        $this->dropArchivalColumns('roles');
    }

    private function addArchivalColumns(string $tableName, bool $userSelfReference = false): void
    {
        $table = $this->table($tableName);

        if (!$table->hasColumn('is_deleted')) {
            $table->addColumn('is_deleted', 'boolean', ['default' => false]);
        }

        if (!$table->hasColumn('deleted_at')) {
            $table->addColumn('deleted_at', 'datetime', ['null' => true]);
        }

        if (!$table->hasColumn('deleted_by_user_id')) {
            $table->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true]);
        }

        $table->update();

        $table = $this->table($tableName);

        if (!$table->hasForeignKey('deleted_by_user_id')) {
            $table->addForeignKey(
                'deleted_by_user_id',
                $userSelfReference ? 'users' : 'users',
                'id',
                ['delete' => 'SET_NULL']
            )->update();
        }
    }

    private function dropArchivalColumns(string $tableName): void
    {
        $table = $this->table($tableName);

        if ($table->hasForeignKey('deleted_by_user_id')) {
            $table->dropForeignKey('deleted_by_user_id')->update();
        }

        $table = $this->table($tableName);

        foreach (['deleted_by_user_id', 'deleted_at', 'is_deleted'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }

        $table->update();
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

