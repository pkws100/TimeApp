#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigRepository;
use App\Config\EnvironmentLoader;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../bootstrap/autoload.php';

(new EnvironmentLoader())->load(base_path('.env'));

$config = ConfigRepository::load(['database']);
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

$requiredTables = [
    'permissions',
    'roles',
    'role_permissions',
    'users',
    'timesheets',
    'vacation_requests',
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

if ($connection->tableExists('timesheets') && !$connection->columnExists('timesheets', 'vacation_request_id')) {
    $errors[] = 'timesheets.vacation_request_id fehlt';
}

$requiredPermissions = [
    'time_accounts.view',
    'time_accounts.manage',
    'vacation_requests.view',
    'vacation_requests.manage',
];

if ($connection->tableExists('permissions')) {
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

    fwrite(STDERR, 'Standardpfad: bin/update-prod.sh ohne --skip-migrations/--skip-seed erneut ausfuehren.' . PHP_EOL);
    fwrite(STDERR, 'Manuell im Container: vendor/bin/phinx migrate -c phinx.php && vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Post-Update-Pruefung erfolgreich: Zeitkonto-Schema und Rechte sind vorhanden.' . PHP_EOL);
