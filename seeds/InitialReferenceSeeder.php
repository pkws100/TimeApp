<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class InitialReferenceSeeder extends AbstractSeed
{
    /**
     * @return list<array{code: string, label: string, scope: string}>
     */
    public static function permissions(): array
    {
        return [
            ['code' => 'dashboard.view', 'label' => 'Dashboard ansehen', 'scope' => 'backend'],
            ['code' => 'attendance.view', 'label' => 'Anwesenheit ansehen', 'scope' => 'backend'],
            ['code' => 'users.manage', 'label' => 'Benutzer verwalten', 'scope' => 'backend'],
            ['code' => 'roles.manage', 'label' => 'Rollen verwalten', 'scope' => 'backend'],
            ['code' => 'projects.view', 'label' => 'Projekte ansehen', 'scope' => 'project'],
            ['code' => 'projects.manage', 'label' => 'Projekte verwalten', 'scope' => 'backend'],
            ['code' => 'files.view', 'label' => 'Dateien ansehen', 'scope' => 'project'],
            ['code' => 'files.upload', 'label' => 'Dateien hochladen', 'scope' => 'project'],
            ['code' => 'files.manage', 'label' => 'Dateien verwalten', 'scope' => 'project'],
            ['code' => 'assets.manage', 'label' => 'Fahrzeuge und Geraete verwalten', 'scope' => 'backend'],
            ['code' => 'assets.assign', 'label' => 'Fahrzeuge und Geraete zuweisen', 'scope' => 'backend'],
            ['code' => 'timesheets.create', 'label' => 'Zeiten erfassen', 'scope' => 'timesheets'],
            ['code' => 'timesheets.view', 'label' => 'Buchungen ansehen', 'scope' => 'timesheets'],
            ['code' => 'timesheets.manage', 'label' => 'Zeiten verwalten', 'scope' => 'timesheets'],
            ['code' => 'timesheets.archive', 'label' => 'Buchungen archivieren', 'scope' => 'timesheets'],
            ['code' => 'timesheets.export', 'label' => 'Buchungen exportieren', 'scope' => 'timesheets'],
            ['code' => 'timesheets.view_own', 'label' => 'Eigene Zeiten ansehen', 'scope' => 'timesheets'],
            ['code' => 'reports.export', 'label' => 'Berichte exportieren', 'scope' => 'backend'],
            ['code' => 'reports.accounting.export', 'label' => 'Buchhaltungsexport ausfuehren', 'scope' => 'backend'],
            ['code' => 'settings.manage', 'label' => 'Globale Einstellungen verwalten', 'scope' => 'backend'],
            ['code' => 'settings.database.manage', 'label' => 'Datenbank umstellen', 'scope' => 'backend'],
            ['code' => 'push.receive', 'label' => 'Push-Benachrichtigungen empfangen', 'scope' => 'app'],
            ['code' => 'push.manage', 'label' => 'Push-Benachrichtigungen verwalten', 'scope' => 'backend'],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, description: string, is_system_role: int}>
     */
    public static function roles(): array
    {
        return [
            ['slug' => 'administrator', 'name' => 'Administrator', 'description' => 'Vollzugriff auf das System', 'is_system_role' => 1],
            ['slug' => 'geschaeftsfuehrung', 'name' => 'Geschaeftsfuehrung', 'description' => 'Steuert Unternehmen und Auswertungen', 'is_system_role' => 1],
            ['slug' => 'bauleiter', 'name' => 'Bauleiter', 'description' => 'Leitet Projekte und Teams', 'is_system_role' => 1],
            ['slug' => 'kolonnenfuehrer', 'name' => 'Kolonnenfuehrer', 'description' => 'Fuehrt Kolonnen und Tagessteuerung', 'is_system_role' => 1],
            ['slug' => 'mitarbeiter', 'name' => 'Mitarbeiter', 'description' => 'Normale Zeiterfassung', 'is_system_role' => 1],
            ['slug' => 'disposition', 'name' => 'Disposition', 'description' => 'Plant Projekte, Ressourcen und Einsaetze', 'is_system_role' => 1],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        $allPermissions = array_map(static fn (array $permission): string => $permission['code'], self::permissions());

        return [
            'administrator' => $allPermissions,
            'geschaeftsfuehrung' => [
                'dashboard.view',
                'attendance.view',
                'users.manage',
                'roles.manage',
                'projects.manage',
                'files.manage',
                'assets.manage',
                'timesheets.view',
                'timesheets.manage',
                'timesheets.archive',
                'timesheets.export',
                'reports.export',
                'reports.accounting.export',
                'settings.manage',
                'settings.database.manage',
                'push.manage',
                'push.receive',
            ],
            'bauleiter' => [
                'dashboard.view',
                'attendance.view',
                'projects.manage',
                'files.upload',
                'files.view',
                'assets.assign',
                'timesheets.view',
                'timesheets.manage',
                'timesheets.export',
                'reports.export',
                'push.receive',
            ],
            'kolonnenfuehrer' => [
                'dashboard.view',
                'attendance.view',
                'projects.view',
                'files.upload',
                'files.view',
                'assets.assign',
                'timesheets.view',
                'timesheets.manage',
                'push.receive',
            ],
            'mitarbeiter' => [
                'projects.view',
                'files.view',
                'timesheets.create',
                'timesheets.view_own',
                'push.receive',
            ],
            'disposition' => [
                'dashboard.view',
                'attendance.view',
                'projects.manage',
                'assets.manage',
                'timesheets.view',
                'timesheets.export',
                'reports.export',
                'push.receive',
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::permissions() as $permission) {
            $this->execute(sprintf(
                "INSERT INTO permissions (code, label, scope, created_at)
                 VALUES ('%s', '%s', '%s', NOW())
                 ON DUPLICATE KEY UPDATE label = VALUES(label), scope = VALUES(scope)",
                addslashes($permission['code']),
                addslashes($permission['label']),
                addslashes($permission['scope'])
            ));
        }

        foreach (self::roles() as $role) {
            $this->execute(sprintf(
                "INSERT INTO roles (slug, name, description, is_system_role, created_at, updated_at)
                 VALUES ('%s', '%s', '%s', %d, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    is_system_role = VALUES(is_system_role),
                    updated_at = NOW()",
                addslashes($role['slug']),
                addslashes($role['name']),
                addslashes($role['description']),
                $role['is_system_role']
            ));
        }

        foreach (self::rolePermissions() as $roleSlug => $permissionCodes) {
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
}
