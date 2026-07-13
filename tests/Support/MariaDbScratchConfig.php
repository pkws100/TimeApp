<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

final class MariaDbScratchConfig
{
    public static function server(): array
    {
        $socketEnvironment = getenv('TIMEAPP_TEST_DB_SOCKET');
        $hostEnvironment = getenv('TIMEAPP_TEST_DB_HOST');
        $configuredSocket = $socketEnvironment !== false
            ? (string) $socketEnvironment
            : ($hostEnvironment !== false ? '' : '/run/mysqld/mysqld.sock');
        $socket = $configuredSocket !== '' && file_exists($configuredSocket) ? $configuredSocket : '';
        $host = (string) ($hostEnvironment !== false ? $hostEnvironment : '127.0.0.1');
        $port = (int) (getenv('TIMEAPP_TEST_DB_PORT') ?: 3306);
        $username = (string) (getenv('TIMEAPP_TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('TIMEAPP_TEST_DB_PASSWORD') ?: '');

        return [
            'dsn' => $socket !== ''
                ? 'mysql:unix_socket=' . $socket . ';charset=utf8mb4'
                : sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'socket' => $socket,
        ];
    }

    public static function connectServer(): PDO
    {
        $server = self::server();

        return new PDO(
            (string) $server['dsn'],
            (string) $server['username'],
            (string) $server['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public static function connection(string $database): array
    {
        $server = self::server();

        return [
            'driver' => 'mysql',
            'host' => $server['host'],
            'port' => $server['port'],
            'database' => $database,
            'username' => $server['username'],
            'password' => $server['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'socket' => $server['socket'],
        ];
    }

    public static function phinxEnvironment(string $database): array
    {
        $config = self::connection($database);
        $environment = [
            'adapter' => 'mysql',
            'host' => $config['host'],
            'name' => $database,
            'user' => $config['username'],
            'pass' => $config['password'],
            'port' => $config['port'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        if ($config['socket'] !== '') {
            $environment['unix_socket'] = $config['socket'];
        }

        return $environment;
    }

    public static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
