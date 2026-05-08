<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class InitialReferenceSeeder extends AbstractSeed
{
    public function run(): void
    {
        $permissions = [
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
            ['code' => 'timesheets.manage', 'label' => 'Zeiten verwalten', 'scope' => 'timesheets'],
            ['code' => 'timesheets.view_own', 'label' => 'Eigene Zeiten ansehen', 'scope' => 'timesheets'],
            ['code' => 'reports.export', 'label' => 'Berichte exportieren', 'scope' => 'backend'],
            ['code' => 'reports.accounting.export', 'label' => 'Buchhaltungsexport ausfuehren', 'scope' => 'backend'],
            ['code' => 'settings.manage', 'label' => 'Globale Einstellungen verwalten', 'scope' => 'backend'],
            ['code' => 'settings.database.manage', 'label' => 'Datenbank umstellen', 'scope' => 'backend'],
        ];

        $roles = [
            ['slug' => 'administrator', 'name' => 'Administrator', 'description' => 'Vollzugriff auf das System', 'is_system_role' => 1],
            ['slug' => 'geschaeftsfuehrung', 'name' => 'Geschaeftsfuehrung', 'description' => 'Steuert Unternehmen und Auswertungen', 'is_system_role' => 1],
            ['slug' => 'bauleiter', 'name' => 'Bauleiter', 'description' => 'Leitet Projekte und Teams', 'is_system_role' => 1],
            ['slug' => 'kolonnenfuehrer', 'name' => 'Kolonnenfuehrer', 'description' => 'Fuehrt Kolonnen und Tagessteuerung', 'is_system_role' => 1],
            ['slug' => 'mitarbeiter', 'name' => 'Mitarbeiter', 'description' => 'Normale Zeiterfassung', 'is_system_role' => 1],
            ['slug' => 'disposition', 'name' => 'Disposition', 'description' => 'Plant Projekte, Ressourcen und Einsaetze', 'is_system_role' => 1],
        ];

        $this->table('permissions')->insert($permissions)->saveData();
        $this->table('roles')->insert($roles)->saveData();

        $this->execute(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles
             INNER JOIN permissions ON permissions.code IN (
                'dashboard.view', 'attendance.view', 'users.manage', 'roles.manage', 'projects.view', 'projects.manage',
                'files.view', 'files.upload', 'files.manage', 'assets.manage', 'assets.assign',
                'timesheets.create', 'timesheets.manage', 'timesheets.view_own', 'reports.export',
                'reports.accounting.export', 'settings.manage', 'settings.database.manage'
             )
             WHERE roles.slug = 'administrator'"
        );

        $this->execute(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles
             INNER JOIN permissions ON permissions.code IN (
                'dashboard.view', 'attendance.view', 'users.manage', 'roles.manage', 'projects.manage',
                'files.manage', 'assets.manage', 'timesheets.manage', 'reports.export',
                'reports.accounting.export', 'settings.manage', 'settings.database.manage'
             )
             WHERE roles.slug = 'geschaeftsfuehrung'"
        );

        $this->execute(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles
             INNER JOIN permissions ON permissions.code IN (
                'dashboard.view', 'attendance.view', 'projects.view', 'projects.manage', 'files.view',
                'files.upload', 'assets.assign', 'timesheets.manage', 'reports.export'
             )
             WHERE roles.slug = 'bauleiter'"
        );

        $this->execute(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles
             INNER JOIN permissions ON permissions.code IN (
                'dashboard.view', 'attendance.view', 'projects.view', 'files.view', 'files.upload',
                'assets.assign', 'timesheets.manage'
             )
             WHERE roles.slug = 'kolonnenfuehrer'"
        );

        $this->execute(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles
             INNER JOIN permissions ON permissions.code IN (
                'projects.view', 'files.view', 'timesheets.create', 'timesheets.view_own'
             )
             WHERE roles.slug = 'mitarbeiter'"
        );

        $this->execute(
            "INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles
             INNER JOIN permissions ON permissions.code IN (
                'dashboard.view', 'attendance.view', 'projects.manage', 'assets.manage', 'reports.export'
             )
             WHERE roles.slug = 'disposition'"
        );
    }
}
