#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigRepository;
use App\Config\EnvironmentLoader;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../bootstrap/autoload.php';

(new EnvironmentLoader())->load(base_path('.env'));

$config = ConfigRepository::load(['database', 'permissions']);
$databaseSettings = new DatabaseSettingsManager(
    $config->get('database.connections.mysql', []),
    (string) $config->get('database.override_file')
);
$connection = new DatabaseConnection($databaseSettings->current());

$errors = [];

if (!$connection->isAvailable()) {
    fwrite(STDERR, 'Datenbankverbindung fehlgeschlagen: ' . ($connection->lastError() ?? 'unbekannter Fehler') . PHP_EOL);
    exit(1);
}

$migrationFiles = glob(base_path('migrations') . '/*.php') ?: [];
$migrationVersions = [];

foreach ($migrationFiles as $migrationFile) {
    $basename = basename($migrationFile);

    if (preg_match('/^(\d{14})_(.+)\.php$/', $basename, $matches) === 1) {
        $migrationVersions[$matches[1]] = $matches[2];
    }
}

if ($migrationVersions !== []) {
    if (!$connection->tableExists('phinxlog')) {
        $errors[] = 'Phinx-Migrationstabelle fehlt: phinxlog';
    } else {
        $rows = $connection->fetchAll('SELECT version FROM phinxlog');
        $appliedVersions = array_fill_keys(
            array_map(static fn (array $row): string => (string) ($row['version'] ?? ''), $rows),
            true
        );

        foreach ($migrationVersions as $version => $name) {
            if (!isset($appliedVersions[$version])) {
                $errors[] = sprintf('Migration ausstehend: %s_%s', $version, $name);
            }
        }
    }
}

$requiredTables = [
    'permissions',
    'roles',
    'role_permissions',
    'users',
    'timesheets',
    'vacation_requests',
    'employee_account_cutovers',
    'time_account_entries',
    'vacation_account_entries',
];

foreach ($requiredTables as $table) {
    if (!$connection->tableExists($table)) {
        $errors[] = sprintf('Tabelle fehlt: %s', $table);
    }
}

$requiredUserColumns = [
    'target_hours_mode',
    'target_hours_week',
    'workdays_mask',
    'vacation_days_year',
    'vacation_carryover_days',
];

if ($connection->tableExists('users')) {
    foreach ($requiredUserColumns as $column) {
        if (!$connection->columnExists('users', $column)) {
            $errors[] = sprintf('users.%s fehlt', $column);
        }
    }
}

if ($connection->tableExists('timesheets')) {
    foreach (['vacation_request_id', 'credited_minutes', 'absence_reason_code'] as $column) {
        if (!$connection->columnExists('timesheets', $column)) {
            $errors[] = sprintf('timesheets.%s fehlt', $column);
        }
    }
}

if ($connection->tableExists('employee_account_cutovers')) {
    foreach (['active_final_user_id', 'effective_from', 'opening_time_balance_minutes', 'opening_remaining_leave_days', 'status'] as $column) {
        if (!$connection->columnExists('employee_account_cutovers', $column)) {
            $errors[] = sprintf('employee_account_cutovers.%s fehlt', $column);
        }
    }
}

if ($connection->tableExists('time_account_entries')) {
    foreach (['user_id', 'cutover_id', 'effective_date', 'minutes', 'entry_type', 'source_type', 'source_id', 'reversal_of_id'] as $column) {
        if (!$connection->columnExists('time_account_entries', $column)) {
            $errors[] = sprintf('time_account_entries.%s fehlt', $column);
        }
    }

    if ($connection->columnExists('time_account_entries', 'cutover_id')) {
        $unassigned = (int) ($connection->fetchColumn(
            'SELECT COUNT(*) FROM time_account_entries WHERE cutover_id IS NULL'
        ) ?? 0);

        if ($unassigned > 0) {
            $errors[] = sprintf('%d Zeitkonto-Journalzeilen sind keiner Stichtagsgeneration zugeordnet und werden aus aktiven Salden ausgeschlossen.', $unassigned);
        }
    }
}

if ($connection->tableExists('vacation_account_entries')) {
    foreach (['user_id', 'cutover_id', 'leave_year', 'effective_date', 'days', 'entry_type', 'source_type', 'source_id', 'reversal_of_id'] as $column) {
        if (!$connection->columnExists('vacation_account_entries', $column)) {
            $errors[] = sprintf('vacation_account_entries.%s fehlt', $column);
        }
    }

    if ($connection->columnExists('vacation_account_entries', 'cutover_id')) {
        $unassigned = (int) ($connection->fetchColumn(
            'SELECT COUNT(*) FROM vacation_account_entries WHERE cutover_id IS NULL'
        ) ?? 0);

        if ($unassigned > 0) {
            $errors[] = sprintf('%d Urlaubskonto-Journalzeilen sind keiner Stichtagsgeneration zugeordnet und werden aus aktiven Salden ausgeschlossen.', $unassigned);
        }
    }
}

if ($connection->tableExists('accounting_closures')) {
    foreach (['source_type', 'source_id'] as $column) {
        if (!$connection->columnExists('accounting_closures', $column)) {
            $errors[] = sprintf('accounting_closures.%s fehlt', $column);
        }
    }
}

$requiredPermissions = [
    'time_accounts.view',
    'time_accounts.manage',
    'vacation_requests.view',
    'vacation_requests.manage',
];

if ($connection->tableExists('permissions')) {
    $configuredPermissions = $config->get('permissions.available', []);

    if (is_array($configuredPermissions) && $configuredPermissions !== []) {
        $rows = $connection->fetchAll('SELECT code FROM permissions');
        $existingPermissionLookup = array_fill_keys(
            array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $rows),
            true
        );

        foreach ($configuredPermissions as $permission) {
            $permission = (string) $permission;

            if ($permission !== '' && !isset($existingPermissionLookup[$permission])) {
                $errors[] = sprintf('Permission aus config/permissions.php fehlt: %s', $permission);
            }
        }
    }

    $rows = $connection->fetchAll(
        'SELECT code FROM permissions WHERE code IN ("time_accounts.view", "time_accounts.manage", "vacation_requests.view", "vacation_requests.manage")'
    );
    $existingPermissions = array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $rows);

    foreach ($requiredPermissions as $permission) {
        if (!in_array($permission, $existingPermissions, true)) {
            $errors[] = sprintf('Permission fehlt: %s', $permission);
        }
    }
}

if ($connection->tableExists('roles') && $connection->tableExists('role_permissions') && $connection->tableExists('permissions')) {
    $activeRoleCondition = $connection->columnExists('roles', 'is_deleted')
        ? 'AND COALESCE(roles.is_deleted, 0) = 0'
        : '';
    $configuredRoles = $config->get('permissions.roles', []);
    $configuredPermissions = $config->get('permissions.available', []);

    if (is_array($configuredRoles) && is_array($configuredPermissions) && $configuredRoles !== []) {
        $roleRows = $connection->fetchAll('SELECT slug FROM roles WHERE 1 = 1 ' . $activeRoleCondition);
        $existingRoles = array_fill_keys(
            array_map(static fn (array $row): string => (string) ($row['slug'] ?? ''), $roleRows),
            true
        );

        $permissionRows = $connection->fetchAll(
            'SELECT roles.slug, permissions.code
             FROM roles
             INNER JOIN role_permissions ON role_permissions.role_id = roles.id
             INNER JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE 1 = 1 ' . $activeRoleCondition
        );

        $configuredAssignments = [];
        foreach ($permissionRows as $row) {
            $slug = (string) ($row['slug'] ?? '');
            $code = (string) ($row['code'] ?? '');

            if ($slug !== '' && $code !== '') {
                $configuredAssignments[$slug][$code] = true;
            }
        }

        foreach ($configuredRoles as $roleSlug => $roleConfig) {
            $roleSlug = (string) $roleSlug;

            if ($roleSlug === '') {
                continue;
            }

            if (!isset($existingRoles[$roleSlug])) {
                $errors[] = sprintf('Rolle aus config/permissions.php fehlt: %s', $roleSlug);
                continue;
            }

            $rolePermissions = is_array($roleConfig) ? ($roleConfig['permissions'] ?? []) : [];
            $expectedPermissions = $rolePermissions === ['*'] ? $configuredPermissions : $rolePermissions;

            if (!is_array($expectedPermissions)) {
                continue;
            }

            foreach ($expectedPermissions as $permission) {
                $permission = (string) $permission;

                if ($permission !== '' && !isset($configuredAssignments[$roleSlug][$permission])) {
                    $errors[] = sprintf('Permission-Zuordnung aus config/permissions.php fehlt: %s -> %s', $roleSlug, $permission);
                }
            }
        }
    }

    $rows = $connection->fetchAll(
        'SELECT roles.slug, permissions.code
         FROM roles
         INNER JOIN role_permissions ON role_permissions.role_id = roles.id
         INNER JOIN permissions ON permissions.id = role_permissions.permission_id
         WHERE roles.slug IN ("administrator", "geschaeftsfuehrung")
           AND permissions.code IN ("time_accounts.view", "time_accounts.manage", "vacation_requests.view", "vacation_requests.manage")
           ' . $activeRoleCondition
    );

    $assigned = [];
    foreach ($rows as $row) {
        $slug = (string) ($row['slug'] ?? '');
        $code = (string) ($row['code'] ?? '');

        if ($slug !== '' && $code !== '') {
            $assigned[$slug][$code] = true;
        }
    }

    foreach (['administrator', 'geschaeftsfuehrung'] as $roleSlug) {
        foreach ($requiredPermissions as $permission) {
            if (!isset($assigned[$roleSlug][$permission])) {
                $errors[] = sprintf('Permission-Zuordnung fehlt: %s -> %s', $roleSlug, $permission);
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, 'Post-Update-Pruefung fehlgeschlagen:' . PHP_EOL);

    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . PHP_EOL);
    }

    fwrite(STDERR, 'Docker-Standardpfad: bin/update-prod.sh ohne --skip-migrations/--skip-seed erneut ausfuehren.' . PHP_EOL);
    fwrite(STDERR, 'Nativer Standardpfad: bin/update-native.sh ohne --skip-migrations/--skip-seed erneut ausfuehren.' . PHP_EOL);
    fwrite(STDERR, 'Manuell: vendor/bin/phinx migrate -c phinx.php && vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Post-Update-Pruefung erfolgreich: Zeitkonto-Schema und Rechte sind vorhanden.' . PHP_EOL);
