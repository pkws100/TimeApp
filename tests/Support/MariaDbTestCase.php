<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class MariaDbTestCase extends TestCase
{
    private static ?PDO $serverPdo = null;
    private static ?DatabaseConnection $databaseConnection = null;
    private static ?string $databaseName = null;
    private static ?string $phinxConfig = null;
    private static ?array $connectionConfig = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        try {
            self::createScratchDatabase();
            self::runMigrations();
        } catch (\Throwable $exception) {
            self::dropScratchDatabase();
            throw $exception;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::dropScratchDatabase();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        $_SESSION = [];
    }

    protected function connection(): DatabaseConnection
    {
        if (!self::$databaseConnection instanceof DatabaseConnection) {
            throw new RuntimeException('Die MariaDB-Testdatenbank wurde nicht initialisiert.');
        }

        return self::$databaseConnection;
    }

    protected function connectionConfig(): array
    {
        return self::$connectionConfig ?? [];
    }

    protected function createUser(array $overrides = []): int
    {
        $suffix = bin2hex(random_bytes(4));
        $record = $overrides + [
            'employee_number' => 'TEST-' . strtoupper($suffix),
            'first_name' => 'Test',
            'last_name' => 'Mitarbeiter',
            'email' => 'test-' . $suffix . '@example.test',
            'password_hash' => password_hash('test-password', PASSWORD_DEFAULT),
            'employment_status' => 'active',
            'target_hours_month' => 160.0,
            'target_hours_mode' => 'month',
            'target_hours_week' => null,
            'workdays_mask' => '1,2,3,4,5',
            'vacation_days_year' => 30.0,
            'vacation_carryover_days' => 0.0,
        ];

        $this->connection()->execute(
            'INSERT INTO users (
                employee_number, first_name, last_name, email, password_hash, employment_status,
                target_hours_month, target_hours_mode, target_hours_week, workdays_mask,
                vacation_days_year, vacation_carryover_days, time_tracking_required,
                created_at, updated_at, is_deleted
             ) VALUES (
                :employee_number, :first_name, :last_name, :email, :password_hash, :employment_status,
                :target_hours_month, :target_hours_mode, :target_hours_week, :workdays_mask,
                :vacation_days_year, :vacation_carryover_days, 1,
                NOW(), NOW(), 0
             )',
            $record
        );

        return $this->connection()->lastInsertId();
    }

    protected function createProject(): int
    {
        $suffix = bin2hex(random_bytes(4));
        $this->connection()->execute(
            'INSERT INTO projects (project_number, name, status, created_at, updated_at, is_deleted)
             VALUES (:project_number, :name, "active", NOW(), NOW(), 0)',
            ['project_number' => 'TEST-' . strtoupper($suffix), 'name' => 'Testprojekt ' . $suffix]
        );

        return $this->connection()->lastInsertId();
    }

    private static function createScratchDatabase(): void
    {
        try {
            self::$serverPdo = MariaDbScratchConfig::connectServer();
        } catch (\Throwable $exception) {
            throw new RuntimeException('MariaDB-Integrationstests benoetigen eine erreichbare Testdatenbank: ' . $exception->getMessage(), 0, $exception);
        }

        self::$databaseName = 'timeapp_test_' . strtolower((new \ReflectionClass(static::class))->getShortName()) . '_' . bin2hex(random_bytes(4));
        $quoted = MariaDbScratchConfig::quoteIdentifier(self::$databaseName);

        try {
            self::$serverPdo->exec('CREATE DATABASE ' . $quoted . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\Throwable $exception) {
            throw new RuntimeException('MariaDB-Integrationstests benoetigen CREATE DATABASE fuer eine isolierte Scratch-Datenbank: ' . $exception->getMessage(), 0, $exception);
        }

        $config = MariaDbScratchConfig::connection(self::$databaseName);
        self::$databaseConnection = new DatabaseConnection($config);
        self::$connectionConfig = $config;
        self::$phinxConfig = sys_get_temp_dir() . '/timeapp-phinx-' . bin2hex(random_bytes(6)) . '.php';
        $phinx = [
            'paths' => ['migrations' => base_path('migrations'), 'seeds' => base_path('seeds')],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'test',
                'test' => MariaDbScratchConfig::phinxEnvironment(self::$databaseName),
            ],
            'version_order' => 'creation',
        ];
        if (file_put_contents(self::$phinxConfig, "<?php\nreturn " . var_export($phinx, true) . ";\n") === false) {
            throw new RuntimeException('Die temporaere Phinx-Testkonfiguration konnte nicht geschrieben werden.');
        }
        chmod(self::$phinxConfig, 0600);
    }

    private static function runMigrations(): void
    {
        $command = [PHP_BINARY, base_path('vendor/bin/phinx'), 'migrate', '-c', (string) self::$phinxConfig, '-e', 'test'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());

        if (!is_resource($process)) {
            throw new RuntimeException('Phinx konnte fuer die MariaDB-Testdatenbank nicht gestartet werden.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException("Phinx-Migration der MariaDB-Testdatenbank fehlgeschlagen:\n" . $stdout . $stderr);
        }
    }

    private function resetDatabase(): void
    {
        $pdo = $this->connection()->pdo();

        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Die MariaDB-Scratch-Datenbank ist nicht erreichbar.');
        }

        $tables = $this->connection()->fetchAll(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = "BASE TABLE" AND table_name <> "phinxlog"'
        );
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $pdo->exec('DELETE FROM ' . MariaDbScratchConfig::quoteIdentifier((string) $table['table_name']));
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function dropScratchDatabase(): void
    {
        self::$databaseConnection = null;

        try {
            if (self::$serverPdo instanceof PDO && self::$databaseName !== null) {
                self::$serverPdo->exec('DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier(self::$databaseName));
            }
        } finally {
            if (self::$phinxConfig !== null && is_file(self::$phinxConfig)) {
                unlink(self::$phinxConfig);
            }

            self::$serverPdo = null;
            self::$databaseName = null;
            self::$phinxConfig = null;
            self::$connectionConfig = null;
        }
    }

}
