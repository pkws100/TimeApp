<?php

declare(strict_types=1);

return [
    'default' => 'mysql',
    'override_file' => storage_path('config/database.override.php'),
    'connections' => [
        'mysql' => [
            'driver' => (string) env('DB_DRIVER', 'mysql'),
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PORT', 3306),
            'database' => (string) env('DB_DATABASE', 'zeiterfassung'),
            'username' => (string) env('DB_USERNAME', 'root'),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => (string) env('DB_CHARSET', 'utf8mb4'),
            'collation' => (string) env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'socket' => (string) env(
                'DB_SOCKET',
                is_file('/run/mysqld/mysqld.sock') ? '/run/mysqld/mysqld.sock' : ''
            ),
        ],
    ],
];
