<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Domain\Personnel\Backup\PersonnelBackupTables;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use Throwable;

final class PersonnelProductionHttpBoundary
{
    private const ROOT = '/admin/personnel-documents';
    private const GRANTS = [
        'users' => ['SELECT'],
        'roles' => ['SELECT'],
        'user_roles' => ['SELECT'],
        'permissions' => ['SELECT'],
        'role_permissions' => ['SELECT'],
        'phinxlog' => ['SELECT'],
        'personnel_compliance_sources' => ['SELECT'],
        'personnel_document_types' => ['SELECT'],
        'personnel_document_requirements' => ['SELECT'],
        'employee_employment_contexts' => ['SELECT'],
        'employee_personnel_scope_events' => ['SELECT'],
        'employee_documents' => ['SELECT', 'INSERT', 'UPDATE'],
        'employee_document_versions' => ['SELECT', 'INSERT'],
        'employee_document_audit' => ['SELECT', 'INSERT'],
        'employee_document_access_log' => ['SELECT', 'INSERT'],
        'employee_document_evidence_reviews' => ['SELECT', 'INSERT'],
        'personnel_command_receipts' => ['SELECT', 'INSERT'],
    ];

    private function __construct(
        private readonly DatabaseConnection $connection,
        private readonly string $origin,
        private readonly string $incoming,
        private readonly array $fileConfig,
    ) {
    }

    public static function discover(Request $request, DatabaseConnection $application, array $applicationDatabase): ?self
    {
        $path = $request->path();
        if (PHP_SAPI !== 'apache2handler' || env('APP_ENV') !== 'production'
            || env('PERSONNEL_HTTP_MODE') !== 'production_beta'
            || ($path !== '/admin' && !str_starts_with($path, '/admin/'))
            || session_status() !== PHP_SESSION_ACTIVE || session_get_cookie_params()['secure'] !== true) {
            return null;
        }

        $origin = (string) env('PERSONNEL_BETA_ORIGIN', '');
        if (!self::matchesOrigin($request, $origin)) {
            return null;
        }

        try {
            $fileConfig = require config_path('personnel_storage.php');
            $backupRoot = (string) env('PERSONNEL_BACKUP_ROOT', '');
            if ($backupRoot === '' || realpath($backupRoot) !== $backupRoot || !is_dir($backupRoot)
                || $fileConfig['root'] === '' || $fileConfig['keyring'] === '' || $fileConfig['scanner_socket'] === '') {
                return null;
            }
            $forbidden = [base_path(), '/var/www', sys_get_temp_dir(), $backupRoot, $fileConfig['root'], $fileConfig['keyring']];
            $incoming = PersonnelPrivatePath::requireRoot((string) env('PERSONNEL_BETA_INCOMING_ROOT', ''), $forbidden);
            $quarantine = PersonnelPrivatePath::requireRoot((string) env('PERSONNEL_BETA_QUARANTINE_ROOT', ''), [...$forbidden, $incoming]);
            PersonnelPrivatePath::requireTmpfs($incoming);
            PersonnelPrivatePath::requireTmpfs($quarantine);
            if (($path === self::ROOT || str_starts_with($path, self::ROOT . '/')) && ini_get('upload_tmp_dir') !== $incoming) {
                return null;
            }

            $schema = (string) ($applicationDatabase['database'] ?? '');
            if (!$application->isAvailable() || !self::hasSeparatedApplicationGrants($schema, $application->fetchAll('SHOW GRANTS'))) {
                return null;
            }
            $connection = self::connect($applicationDatabase);
            if ($connection === null) {
                return null;
            }
            $fileConfig['quarantine_root'] = $quarantine;
            $fileConfig['forbidden_roots'] = [...$fileConfig['forbidden_roots'], '/var/www', $incoming];

            return new self($connection, $origin, $incoming, $fileConfig);
        } catch (Throwable) {
            return null;
        }
    }

    public static function matchesOrigin(Request $request, string $origin): bool
    {
        // HTTPS is set by Apache only after authenticating the TLS ingress. HTTP headers cannot set it.
        return preg_match('~\Ahttps://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[1-9][0-9]{0,4})?)\z~', $origin, $matches) === 1
            && $request->server('HTTPS') === 'on'
            && $request->server('HTTP_HOST') === $matches[1];
    }

    /** @return array<string, list<string>> */
    public static function databaseGrants(): array
    {
        return self::GRANTS;
    }

    private static function connect(array $applicationDatabase): ?DatabaseConnection
    {
        $schema = (string) env('PERSONNEL_BETA_DB_SCHEMA', '');
        $socket = (string) env('PERSONNEL_BETA_DB_SOCKET', '');
        $account = (string) env('PERSONNEL_BETA_DB_USERNAME', '');
        $password = (string) env('PERSONNEL_BETA_DB_PASSWORD', '');
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $schema) !== 1 || preg_match('/\A[a-zA-Z0-9_]+\z/', $account) !== 1
            || $schema !== ($applicationDatabase['database'] ?? null) || $socket === '' || $socket !== ($applicationDatabase['socket'] ?? null)
            || $account === ($applicationDatabase['username'] ?? null) || $password === '') {
            return null;
        }

        // The connection realm must retain the same schema and endpoint as the admin session.
        $connection = new DatabaseConnection(array_replace($applicationDatabase, ['username' => $account, 'password' => $password]));
        $pdo = $connection->pdo();
        if ($pdo === null || !str_contains(strtolower((string) $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)), 'unix socket')) {
            return null;
        }
        $identity = $connection->fetchOne('SELECT DATABASE() AS schema_name, CURRENT_USER() AS principal, CURRENT_ROLE() AS active_role, @@socket AS socket_path');
        if (($identity['schema_name'] ?? null) !== $schema || ($identity['principal'] ?? null) !== $account . '@localhost'
            || ($identity['active_role'] ?? null) !== null || realpath($socket) === false
            || realpath((string) ($identity['socket_path'] ?? '')) !== realpath($socket)
            || !self::hasExpectedGrants($schema, $connection->fetchAll('SHOW GRANTS'))
            || !$connection->fetchColumn('SELECT version FROM phinxlog WHERE version = 20261118209000')) {
            return null;
        }

        return $connection;
    }

    private static function hasExpectedGrants(string $schema, array $grants): bool
    {
        $remaining = self::GRANTS;
        foreach ($grants as $grant) {
            $statement = (string) reset($grant);
            if (str_contains($statement, 'WITH GRANT OPTION')) {
                return false;
            }
            if (str_starts_with($statement, 'GRANT USAGE ON *.* TO ')) {
                continue;
            }
            $grant = self::tableGrant($schema, $statement);
            if ($grant === null || !isset($remaining[$grant['table']]) || $grant['privileges'] !== $remaining[$grant['table']]) {
                return false;
            }
            unset($remaining[$grant['table']]);
        }

        return $remaining === [];
    }

    private static function hasSeparatedApplicationGrants(string $schema, array $grants): bool
    {
        $tables = [];
        foreach ($grants as $grant) {
            $statement = (string) reset($grant);
            if (str_contains($statement, 'WITH GRANT OPTION')) {
                return false;
            }
            if (str_starts_with($statement, 'GRANT USAGE ON *.* TO ')) {
                continue;
            }
            $grant = self::tableGrant($schema, $statement);
            if ($grant === null || isset(PersonnelBackupTables::TABLES[$grant['table']])
                || array_diff($grant['privileges'], ['SELECT', 'INSERT', 'UPDATE', 'DELETE']) !== []) {
                return false;
            }
            $tables[] = $grant['table'];
        }

        return in_array('users', $tables, true) && in_array('personnel_backup_presence', $tables, true);
    }

    /** @return array{table: string, privileges: list<string>}|null */
    private static function tableGrant(string $schema, string $statement): ?array
    {
        if (preg_match('/\AGRANT ([A-Z, ]+) ON `([a-zA-Z0-9_]+)`\.`([a-zA-Z0-9_]+)` TO /', $statement, $matches) !== 1
            || $matches[2] !== $schema) {
            return null;
        }

        return ['table' => $matches[3], 'privileges' => explode(', ', $matches[1])];
    }

    public function connection(): DatabaseConnection
    {
        return $this->connection;
    }

    public function assertBound(DatabaseConnection $connection): void
    {
        if ($connection !== $this->connection) {
            throw new PersonnelFileUnavailable();
        }
    }

    public function fileConfiguration(DatabaseConnection $connection): array
    {
        $this->assertBound($connection);

        return $this->fileConfig;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function incomingDirectory(): string
    {
        return $this->incoming;
    }
}
