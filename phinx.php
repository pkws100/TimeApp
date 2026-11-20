<?php

declare(strict_types=1);

use App\Config\EnvironmentLoader;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;

require_once __DIR__ . '/bootstrap/autoload.php';

(new EnvironmentLoader())->load(__DIR__ . '/.env');

$database = require __DIR__ . '/config/database.php';
$default = $database['default'];
$connection = $database['connections'][$default];

if (is_file($database['override_file'])) {
    $override = require $database['override_file'];

    if (is_array($override)) {
        $connection = [...$connection, ...$override];
    }
}

$migrationIdentityFile = (string) env('DB_MIGRATION_IDENTITY_FILE', '');
if ($migrationIdentityFile !== '') {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Die Migrationskennung ist nur fuer die CLI verfuegbar.');
    }
    PersonnelPrivatePath::requireSeparate($migrationIdentityFile, [__DIR__, '/var/www', sys_get_temp_dir()]);
    $identityFile = PersonnelPrivatePath::openSecret($migrationIdentityFile);
    try {
        $contents = stream_get_contents($identityFile, 4097);
    } finally {
        fclose($identityFile);
    }
    if ($contents === false || strlen($contents) > 4096) {
        throw new RuntimeException('Die Migrationskennung konnte nicht gelesen werden.');
    }
    $identity = json_decode($contents, true, 3, JSON_THROW_ON_ERROR);
    if (!is_array($identity) || count($identity) !== 4 || !is_string($identity['username'] ?? null)
        || $identity['username'] === '' || !is_string($identity['password'] ?? null)
        || ($identity['database'] ?? null) !== $connection['database']
        || ($identity['socket'] ?? '') === '' || $identity['socket'] !== ($connection['socket'] ?? null)) {
        throw new RuntimeException('Die Migrationskennung passt nicht zum lokalen Datenbankziel.');
    }
    $connection = array_replace($connection, ['username' => $identity['username'], 'password' => $identity['password']]);
}

$socket = $connection['socket'] ?? '';

$environment = [
    'adapter' => 'mysql',
    'host' => $connection['host'],
    'name' => $connection['database'],
    'user' => $connection['username'],
    'pass' => $connection['password'],
    'port' => (int) $connection['port'],
    'charset' => $connection['charset'],
    'collation' => $connection['collation'],
];

if ($socket !== '') {
    $environment['unix_socket'] = $socket;
}

return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
        'seeds' => __DIR__ . '/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => $default,
        $default => $environment,
    ],
    'version_order' => 'creation',
];
