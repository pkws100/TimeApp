<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Backup\BackupService;
use App\Domain\Personnel\Compliance\InitialPersonnelComplianceCatalog;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MariaDbScratchConfig;
use ZipArchive;

final class PersonnelComplianceDatabaseTest extends TestCase
{
    private const BASELINE = '20261118190000';
    private const CATALOG_TABLES = [
        'personnel_compliance_sources', 'personnel_document_types', 'personnel_document_requirements',
    ];
    private const PRIVATE_TABLES = [
        'employee_employment_contexts', 'employee_documents', 'employee_document_versions',
        'employee_document_audit', 'employee_document_access_log',
    ];

    private PDO $server;
    private string $database;
    private string $temporaryDirectory;
    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $this->server = MariaDbScratchConfig::connectServer();
        $this->database = 'timeapp_personnel_compliance_' . bin2hex(random_bytes(5));
        $this->temporaryDirectory = sys_get_temp_dir() . '/' . $this->database;
        self::assertTrue(mkdir($this->temporaryDirectory, 0700));
        $this->server->exec('CREATE DATABASE ' . MariaDbScratchConfig::quoteIdentifier($this->database)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        $configuration = [
            'paths' => ['migrations' => base_path('migrations'), 'seeds' => base_path('seeds')],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'test',
                'test' => MariaDbScratchConfig::phinxEnvironment($this->database),
            ],
            'version_order' => 'creation',
        ];
        $this->writePrivateConfig('phinx.php', $configuration);
        $this->connection = new DatabaseConnection(MariaDbScratchConfig::connection($this->database));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->database)) {
                $this->server->exec('DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier($this->database));
            }
        } finally {
            if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
                foreach (glob($this->temporaryDirectory . '/*') ?: [] as $path) {
                    is_dir($path) ? rmdir($path) : unlink($path);
                }
                rmdir($this->temporaryDirectory);
            }
        }
    }

    public function testUpgradeAndSeedReplaysPreserveExistingUsersAndVersionedTimesheets(): void
    {
        $this->phinx('migrate', '-t', self::BASELINE);
        $user = $this->createUser('upgrade');
        $this->connection->execute(
            'INSERT INTO timesheets (user_id, created_by_user_id, work_date, start_time, end_time,
                gross_minutes, break_minutes, net_minutes, entry_type, source, note)
             VALUES (:user_id, :actor_id, "2026-11-01", "08:00:00", "12:00:00", 240, 0, 240, "work", "admin", "Synthetic first revision")',
            ['user_id' => $user, 'actor_id' => $user]
        );
        $this->connection->execute('UPDATE timesheets SET note = "Synthetic second revision"');
        $historySql = 'SELECT id, user_id, note, row_start, row_end FROM timesheets FOR SYSTEM_TIME ALL ORDER BY row_start';
        $oldHistory = $this->connection->fetchAll($historySql);
        $oldUsers = $this->connection->fetchAll('SELECT * FROM users');
        self::assertCount(2, $oldHistory);
        self::assertFalse($this->connection->tableExists('employee_documents'));

        $this->phinx('migrate');
        $catalogBeforeReplay = [];
        foreach (self::CATALOG_TABLES as $table) {
            $catalogBeforeReplay[$table] = $this->connection->fetchAll('SELECT * FROM ' . $table . ' ORDER BY id');
            self::assertNotEmpty($catalogBeforeReplay[$table]);
        }
        $migrationLog = $this->connection->fetchAll('SELECT * FROM phinxlog ORDER BY version');

        $this->phinx('migrate');
        InitialPersonnelComplianceCatalog::seed($this->requirePdo());
        InitialPersonnelComplianceCatalog::seed($this->requirePdo());
        self::assertSame($migrationLog, $this->connection->fetchAll('SELECT * FROM phinxlog ORDER BY version'));
        foreach ($catalogBeforeReplay as $table => $rows) {
            self::assertSame($rows, $this->connection->fetchAll('SELECT * FROM ' . $table . ' ORDER BY id'));
        }
        self::assertSame($oldHistory, $this->connection->fetchAll($historySql));
        self::assertSame($oldUsers, $this->connection->fetchAll('SELECT * FROM users'));
        foreach (self::PRIVATE_TABLES as $table) {
            self::assertSame(0, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM ' . $table));
        }

        $rules = $catalogBeforeReplay['personnel_document_requirements'];
        $keys = array_map(static fn (array $rule): string => $rule['rule_code'] . ':' . $rule['revision'], $rules);
        self::assertSame($keys, array_values(array_unique($keys)));
        self::assertSame(['manual_review'], array_values(array_unique(array_column($rules, 'applicability_mode'))));
        self::assertSame(0, (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM personnel_document_requirements r
             LEFT JOIN personnel_compliance_sources s ON r.source_id = s.id WHERE s.id IS NULL OR r.citation = ""'
        ));
        $this->assertSqlRejected(
            'INSERT INTO personnel_compliance_sources (code, version, title, url, checked_on)
             SELECT code, version, title, url, checked_on FROM personnel_compliance_sources ORDER BY id LIMIT 1'
        );
    }

    public function testUnknownContextAndDocumentHistorySurviveEmployeeArchive(): void
    {
        $this->phinx('migrate');
        [$user, $context, $document, $version] = $this->createDocumentFixture();
        $otherUser = $this->createUser('other');
        $contextRow = $this->connection->fetchOne('SELECT * FROM employee_employment_contexts WHERE id = :id', ['id' => $context]);
        foreach (['employment_type', 'student_status', 'pension_insurance_status', 'cross_border_status', 'work_authorization_status'] as $attribute) {
            self::assertSame('unknown', $contextRow[$attribute]);
        }
        self::assertNull($this->connection->fetchOne('SELECT document_requirement_id FROM employee_documents WHERE id = :id', ['id' => $document])['document_requirement_id']);

        $type = (int) $this->connection->fetchColumn('SELECT document_type_id FROM employee_documents WHERE id = :id', ['id' => $document]);
        $this->assertSqlRejected(
            'INSERT INTO employee_documents (user_id, employment_context_id, document_type_id, created_by_user_id)
             VALUES (:user_id, :context_id, :type_id, :actor_id)',
            ['user_id' => $otherUser, 'context_id' => $context, 'type_id' => $type, 'actor_id' => $user]
        );
        $this->assertSqlRejected(
            'INSERT INTO employee_documents (user_id, document_type_id, document_requirement_id, created_by_user_id)
             SELECT :user_id, :type_id, id, :actor_id FROM personnel_document_requirements WHERE document_type_id <> :different_type ORDER BY id LIMIT 1',
            ['user_id' => $user, 'type_id' => $type, 'actor_id' => $user, 'different_type' => $type]
        );
        $this->assertSqlRejected(
            'INSERT INTO employee_document_versions (document_id, version_number, supersedes_version_number, created_by_user_id)
             VALUES (:document_id, 3, 2, :actor_id)',
            ['document_id' => $document, 'actor_id' => $user]
        );
        $this->connection->execute(
            'INSERT INTO employee_document_versions (document_id, version_number, supersedes_version_number, created_by_user_id)
             VALUES (:document_id, 2, 1, :actor_id)',
            ['document_id' => $document, 'actor_id' => $user]
        );
        $this->assertSqlRejected(
            'INSERT INTO employee_document_versions (document_id, version_number, created_by_user_id)
             VALUES (:document_id, 1, :actor_id)',
            ['document_id' => $document, 'actor_id' => $user]
        );
        $this->assertSqlRejected(
            'UPDATE employee_document_versions SET version_number = 8 WHERE id = :id', ['id' => $version], '45000'
        );

        self::assertTrue((new UserService($this->connection))->archive($user, $otherUser));
        self::assertSame(1, (int) $this->connection->fetchColumn('SELECT is_deleted FROM users WHERE id = :id', ['id' => $user]));
        $versions = $this->connection->fetchAll(
            'SELECT version_number, supersedes_version_number FROM employee_document_versions WHERE document_id = :id ORDER BY version_number',
            ['id' => $document]
        );
        self::assertSame([1, 2], array_map('intval', array_column($versions, 'version_number')));
        self::assertNull($versions[0]['supersedes_version_number']);
        self::assertSame(1, (int) $versions[1]['supersedes_version_number']);
        self::assertSame(1, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM employee_document_audit WHERE document_id = :id', ['id' => $document]));
        self::assertSame(1, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM employee_document_access_log WHERE document_id = :id', ['id' => $document]));
        $this->assertSqlRejected('DELETE FROM users WHERE id = :id', ['id' => $user]);
    }

    public function testSystemBackupIncludesCatalogAndOnlyEmptyPrivateTables(): void
    {
        $this->phinx('migrate');
        $this->createDocumentFixture();
        $service = new BackupService(
            $this->connection,
            ['root' => $this->temporaryDirectory . '/no-uploads'],
            backupRoot: $this->temporaryDirectory . '/backup',
            runtimeOverridePath: $this->temporaryDirectory . '/no-runtime-override'
        );
        $path = $this->temporaryDirectory . '/backup.zip';
        $result = $service->export();
        file_put_contents($path, $result['content']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertEqualsCanonicalizing(self::PRIVATE_TABLES, $manifest['security']['excluded_database_table_rows']);
        foreach (self::CATALOG_TABLES as $table) {
            self::assertNotEmpty(json_decode($zip->getFromName('database/' . $table . '.json'), true, flags: JSON_THROW_ON_ERROR));
        }
        foreach (self::PRIVATE_TABLES as $table) {
            self::assertSame('[]', $zip->getFromName('database/' . $table . '.json'));
        }
        $zip->close();

        $validation = $service->validateImport(['tmp_name' => $path]);
        self::assertTrue($validation['dry_run']);
        self::assertFalse($validation['apply_supported']);
        self::assertStringNotContainsString('SYNTHETIC-AUDIT-FIXTURE', json_encode($validation, JSON_THROW_ON_ERROR));
        self::assertSame(1, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM employee_documents'));

        $alternatePath = $this->temporaryDirectory . '/backup-extra-entry.zip';
        self::assertTrue(copy($path, $alternatePath));
        self::assertTrue($zip->open($alternatePath));
        self::assertTrue($zip->addFromString('database/extra/employee_document_audit.json', '[]'));
        $zip->close();
        try {
            $service->validateImport(['tmp_name' => $alternatePath]);
            self::fail('Eine nicht deklarierte Personalakten-Datei wurde akzeptiert.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('nicht deklarierte oder doppelte Personalakten-Datei', $exception->getMessage());
        }

        self::assertTrue($zip->open($path));
        self::assertTrue($zip->addFromString('database/employee_document_audit.json', '[{"reason":"SYNTHETIC-AUDIT-FIXTURE"}]'));
        $zip->close();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Das Systembackup akzeptiert Personalakten-Tabellen nur ohne personenbezogene Zeilen.');
        $service->validateImport(['tmp_name' => $path]);
    }

    public function testUpgradePermissionsAndHttpBoundaries(): void
    {
        $this->phinx('migrate', '-t', self::BASELINE);
        // A seeded installation has its roles before the upgrade. Add only the existing grants used here.
        foreach (['administrator', 'geschaeftsfuehrung', 'bauleiter', 'kolonnenfuehrer', 'mitarbeiter', 'disposition'] as $role) {
            $this->connection->execute('INSERT INTO roles (slug, name, is_system_role) VALUES (:slug, :name, 1)', ['slug' => $role, 'name' => $role]);
        }
        self::assertSame(0, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM permissions WHERE scope = "personnel_compliance"'));
        $this->phinx('migrate');
        $grantSql = 'SELECT roles.slug, permissions.code FROM role_permissions
             INNER JOIN roles ON roles.id = role_permissions.role_id
             INNER JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE permissions.scope = "personnel_compliance" ORDER BY permissions.code';
        $grants = $this->connection->fetchAll($grantSql);
        self::assertSame(['personnel_compliance.manage', 'personnel_documents.export', 'personnel_documents.manage', 'personnel_documents.view'], array_column($grants, 'code'));
        self::assertSame(['administrator'], array_values(array_unique(array_column($grants, 'slug'))));
        $this->phinx('seed:run', '-s', 'InitialReferenceSeeder');
        $this->phinx('seed:run', '-s', 'InitialReferenceSeeder');
        self::assertSame($grants, $this->connection->fetchAll($grantSql));

        $employee = $this->createUser('worker');
        $manager = $this->createUser('manager');
        $this->grantRole($employee, 'mitarbeiter');
        $this->grantRole($employee, 'bauleiter');
        $this->grantRole($manager, 'administrator');
        $override = $this->writePrivateConfig('database.php', MariaDbScratchConfig::connection($this->database));
        $previousOverride = getenv('DB_OVERRIDE_FILE');
        $previousSession = $_SESSION ?? [];
        putenv('DB_OVERRIDE_FILE=' . $override);

        try {
            [, $router] = require base_path('bootstrap/app.php');
            $status = static fn (string $method, string $path): int => $router->dispatch(new Request($method, $path, [], [], [], [], []))->status();
            $_SESSION = [];
            self::assertSame(401, $status('GET', '/api/v1/system/backup/export'));
            self::assertSame(401, $status('POST', '/api/v1/system/backup/import/validate'));
            $_SESSION['auth']['user_id'] = $employee;
            self::assertSame(200, $status('GET', '/admin/personnel'));
            self::assertSame(403, $status('GET', '/api/v1/system/backup/export'));
            self::assertSame(403, $status('POST', '/api/v1/system/backup/import/validate'));
            $_SESSION['auth']['user_id'] = $manager;
            self::assertSame(404, $status('GET', '/admin/personnel-documents'));
            self::assertSame(404, $status('GET', '/api/v1/personnel-documents'));
            self::assertSame(404, $status('POST', '/api/v1/personnel-documents'));
        } finally {
            $_SESSION = $previousSession;
            putenv($previousOverride === false ? 'DB_OVERRIDE_FILE' : 'DB_OVERRIDE_FILE=' . $previousOverride);
        }
    }

    private function phinx(string ...$arguments): void
    {
        $command = [PHP_BINARY, base_path('vendor/bin/phinx'), $arguments[0], '-c', $this->temporaryDirectory . '/phinx.php', '-e', 'test', ...array_slice($arguments, 1)];
        $log = $this->temporaryDirectory . '/phinx.log';
        $process = proc_open($command, [1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, base_path());
        self::assertIsResource($process);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, (string) file_get_contents($log));
    }

    private function writePrivateConfig(string $name, array $configuration): string
    {
        $path = $this->temporaryDirectory . '/' . $name;
        self::assertNotFalse(file_put_contents($path, '<?php return ' . var_export($configuration, true) . ';'));
        self::assertTrue(chmod($path, 0600));

        return $path;
    }

    private function createUser(string $suffix): int
    {
        $this->connection->execute(
            'INSERT INTO users (employee_number, first_name, last_name, email, password_hash)
             VALUES (:employee_number, "Synthetic", "Compliance Fixture", :email, :password_hash)',
            ['employee_number' => 'TEST-' . $suffix, 'email' => $suffix . '@example.test', 'password_hash' => password_hash('synthetic-test', PASSWORD_DEFAULT)]
        );

        return $this->connection->lastInsertId();
    }

    private function grantRole(int $user, string $role): void
    {
        $this->connection->execute('INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE slug = :role', ['user_id' => $user, 'role' => $role]);
    }

    private function createDocumentFixture(): array
    {
        $user = $this->createUser('document');
        $this->connection->execute('INSERT INTO employee_employment_contexts (user_id, employment_key, effective_from, created_by_user_id)
             VALUES (:user_id, "synthetic-context", "2026-11-01", :actor_id)', ['user_id' => $user, 'actor_id' => $user]);
        $context = $this->connection->lastInsertId();
        $this->connection->execute('INSERT INTO employee_documents (user_id, employment_context_id, document_type_id, created_by_user_id)
             SELECT :user_id, :context_id, id, :actor_id FROM personnel_document_types ORDER BY id LIMIT 1',
            ['user_id' => $user, 'context_id' => $context, 'actor_id' => $user]);
        $document = $this->connection->lastInsertId();
        $this->connection->execute('INSERT INTO employee_document_versions (document_id, version_number, created_by_user_id)
             VALUES (:document_id, 1, :actor_id)', ['document_id' => $document, 'actor_id' => $user]);
        $version = $this->connection->lastInsertId();
        $bindings = ['document_id' => $document, 'actor_id' => $user, 'version_id' => $version];
        $this->connection->execute('INSERT INTO employee_document_audit (document_id, document_version_id, action, actor_user_id, reason)
             VALUES (:document_id, :version_id, "create", :actor_id, "SYNTHETIC-AUDIT-FIXTURE")', $bindings);
        $this->connection->execute('INSERT INTO employee_document_access_log (document_id, document_version_id, action, actor_user_id, outcome)
             VALUES (:document_id, :version_id, "view", :actor_id, "denied")', $bindings);

        return [$user, $context, $document, $version];
    }

    private function requirePdo(): PDO
    {
        $pdo = $this->connection->pdo();
        self::assertInstanceOf(PDO::class, $pdo);

        return $pdo;
    }

    private function assertSqlRejected(string $sql, array $bindings = [], string $sqlState = '23000'): void
    {
        try {
            $this->connection->execute($sql, $bindings);
            self::fail('Die Datenbank hat den ungueltigen Schreibvorgang akzeptiert.');
        } catch (PDOException $exception) {
            self::assertSame($sqlState, $exception->getCode(), $exception->getMessage());
        }
    }
}
