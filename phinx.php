<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/autoload.php';

$database = require __DIR__ . '/config/database.php';
$default = $database['default'];
$connection = $database['connections'][$default];

if (is_file($database['override_file'])) {
    $override = require $database['override_file'];

    if (is_array($override)) {
        $connection = [...$connection, ...$override];
    }
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
