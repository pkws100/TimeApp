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
    private const PHASE_ONE_BASELINE = '20261118202000';
    private const HARDENING = '20261118203000';
    private const PHASE_TWO_PRIVATE_TABLES = ['employee_personnel_scope_events', 'personnel_command_receipts'];
    private const PERSONNEL_EVIDENCE_TABLE = 'employee_document_evidence_reviews';
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
    private ?string $backupPrincipal = null;

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
            if ($this->backupPrincipal !== null) {
                $this->server->exec('DROP USER IF EXISTS ' . $this->backupPrincipal);
            }
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

        $this->connection->execute(
            'UPDATE employee_documents SET archived_at = NOW(), archived_by_user_id = :actor_id WHERE id = :document_id',
            ['actor_id' => $otherUser, 'document_id' => $document]
        );
        self::assertSame($otherUser, (int) $this->connection->fetchColumn(
            'SELECT archived_by_user_id FROM employee_documents WHERE id = :id AND archived_at IS NOT NULL',
            ['id' => $document]
        ));
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

    public function testUnreferencedHistoryRejectsDeleteAndUpdateWhileAllowingNewRevisions(): void
    {
        $this->phinx('migrate');
        [$user, , $document] = $this->createDocumentFixture();
        $this->connection->execute(
            'INSERT INTO employee_employment_contexts (user_id, employment_key, revision, effective_from, revision_reason, created_by_user_id)
             VALUES (:user_id, "synthetic-context", 2, "2026-11-14", "Synthetic amendment", :actor_id)',
            ['user_id' => $user, 'actor_id' => $user]
        );
        $unreferencedContext = $this->connection->lastInsertId();
        $unreferencedRule = $this->addPublishedRuleRevision();
        $this->connection->execute(
            'INSERT INTO personnel_compliance_sources (code, version, title, url, checked_on)
             VALUES ("SYNTHETIC-SOURCE", "1", "Synthetic source", "https://example.test/source", "2026-11-14")'
        );
        $unreferencedSource = $this->connection->lastInsertId();
        $this->connection->execute(
            'INSERT INTO personnel_document_types (code, label, category) VALUES ("synthetic-type", "Synthetic type", "custom")'
        );
        $unreferencedType = $this->connection->lastInsertId();
        $this->connection->execute(
            'INSERT INTO employee_documents (user_id, document_type_id, created_by_user_id)
             SELECT user_id, document_type_id, created_by_user_id FROM employee_documents WHERE id = :id',
            ['id' => $document]
        );
        $unreferencedDocument = $this->connection->lastInsertId();
        $this->connection->execute(
            'INSERT INTO employee_document_versions (document_id, version_number, supersedes_version_number, created_by_user_id)
             VALUES (:document_id, 2, 1, :actor_id)',
            ['document_id' => $document, 'actor_id' => $user]
        );
        $unreferencedVersion = $this->connection->lastInsertId();

        foreach ([
            'personnel_compliance_sources' => $unreferencedSource,
            'personnel_document_types' => $unreferencedType,
            'personnel_document_requirements' => $unreferencedRule,
            'employee_employment_contexts' => $unreferencedContext,
            'employee_documents' => $unreferencedDocument,
            'employee_document_versions' => $unreferencedVersion,
            'employee_document_audit' => (int) $this->connection->fetchColumn('SELECT id FROM employee_document_audit WHERE document_id = :id', ['id' => $document]),
            'employee_document_access_log' => (int) $this->connection->fetchColumn('SELECT id FROM employee_document_access_log WHERE document_id = :id', ['id' => $document]),
        ] as $table => $id) {
            $this->assertSqlRejected('DELETE FROM ' . $table . ' WHERE id = :id', ['id' => $id], '45000');
        }
        $this->assertSqlRejected(
            'UPDATE employee_employment_contexts SET employment_type = "regular" WHERE id = :id', ['id' => $unreferencedContext], '45000'
        );
        $this->assertSqlRejected(
            'UPDATE personnel_document_requirements SET citation = "Synthetic rewrite" WHERE id = :id', ['id' => $unreferencedRule], '45000'
        );
        $this->assertSqlRejected('UPDATE employee_document_audit SET reason = "Synthetic rewrite"', [], '45000');
        $this->assertSqlRejected('UPDATE employee_document_access_log SET outcome = "allowed"', [], '45000');
        $this->assertSqlRejected(
            'REPLACE INTO personnel_compliance_sources (code, version, title, url, checked_on)
             VALUES ("SYNTHETIC-SOURCE", "1", "Synthetic replacement", "https://example.test/source", "2026-11-14")',
            [],
            '45000'
        );
        self::assertSame('Synthetic source', $this->connection->fetchColumn(
            'SELECT title FROM personnel_compliance_sources WHERE id = :id', ['id' => $unreferencedSource]
        ));
    }

    public function testDocumentIdentityCannotBeReclassifiedEvenWhenNewReferencesAreValid(): void
    {
        $this->phinx('migrate');
        [$user, , $document] = $this->createDocumentFixture();
        $otherUser = $this->createUser('change-owner');
        $rules = $this->connection->fetchOne(
            'SELECT r.id AS rule_id, other.id AS type_id FROM employee_documents d
             INNER JOIN personnel_document_requirements r ON r.document_type_id = d.document_type_id
             INNER JOIN personnel_document_types other ON other.id <> d.document_type_id
             WHERE d.id = :id ORDER BY r.id, other.id LIMIT 1',
            ['id' => $document]
        );
        self::assertNotNull($rules);
        $this->connection->execute(
            'INSERT INTO employee_documents (user_id, document_type_id, created_by_user_id)
             VALUES (:user_id, :type_id, :actor_id)',
            ['user_id' => $user, 'type_id' => $rules['type_id'], 'actor_id' => $user]
        );
        $documentWithoutContext = $this->connection->lastInsertId();

        foreach ([
            ['id', $document + 1000, $document],
            ['user_id', $otherUser, $documentWithoutContext],
            ['document_type_id', $rules['type_id'], $document],
            ['employment_context_id', null, $document],
            ['document_requirement_id', $rules['rule_id'], $document],
            ['created_by_user_id', $otherUser, $document],
            ['created_at', '2026-01-01 00:00:00', $document],
        ] as [$column, $value, $documentId]) {
            $this->assertSqlRejected(
                'UPDATE employee_documents SET ' . $column . ' = :value WHERE id = :id',
                ['value' => $value, 'id' => $documentId],
                '45000'
            );
        }

        self::assertTrue($this->connection->execute('UPDATE employee_documents SET review_status = "reviewed" WHERE id = :id', ['id' => $document]));
        self::assertSame('reviewed', $this->connection->fetchColumn('SELECT review_status FROM employee_documents WHERE id = :id', ['id' => $document]));
    }

    public function testHardeningUpgradeRetainsExistingRowsAndCanResumeMissingTriggers(): void
    {
        $this->phinx('migrate', '-t', self::PHASE_ONE_BASELINE);
        $this->createDocumentFixture();
        $this->addPublishedRuleRevision();
        $original = $this->personnelSnapshot();
        self::assertSame([], $this->hardeningTriggers());

        $this->phinx('migrate', '-t', self::HARDENING);
        $triggers = $this->hardeningTriggers();
        self::assertCount(9, $triggers);
        self::assertSame($original, $this->personnelSnapshot());

        // Simulate interrupted autocommit DDL: existing triggers remain without a successful Phinx entry.
        $this->connection->execute('DROP TRIGGER deny_delete_employee_document_access_log');
        $this->connection->execute('DROP TRIGGER protect_employee_document_identity');
        $this->connection->execute('DELETE FROM phinxlog WHERE version = :version', ['version' => self::HARDENING]);
        self::assertCount(7, $this->hardeningTriggers());
        $this->assertRollbackBlocked('Unterbrochene Trigger-DDL', self::PHASE_ONE_BASELINE);
        $this->phinx('migrate', '-t', self::HARDENING);
        $this->phinx('migrate', '-t', self::HARDENING);
        InitialPersonnelComplianceCatalog::seed($this->requirePdo());
        self::assertSame($triggers, $this->hardeningTriggers());
        self::assertSame($original, $this->personnelSnapshot());
        self::assertSame(1, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM phinxlog WHERE version = :version', ['version' => self::HARDENING]));
    }

    public function testRollbackStopsBeforeChangesForInitialCatalogPublishedRevisionsAndPrivateRows(): void
    {
        $this->phinx('migrate', '-t', self::HARDENING);
        $this->assertRollbackBlocked('Initialkatalog', self::PHASE_ONE_BASELINE);
        $this->addPublishedRuleRevision();
        $this->assertRollbackBlocked('Ergaenzte Regelrevision', self::BASELINE);
        $this->createDocumentFixture();
        $this->assertRollbackBlocked('Personenbezogene Daten', self::BASELINE, '--force');
        $this->assertRollbackBlocked('Phinx-Status', self::BASELINE, '--fake');
    }

    public function testPhaseTwoMigrationRetainsPublishedDataAndProtectsNewDocumentProvenance(): void
    {
        $this->phinx('migrate', '-t', self::HARDENING);
        [$employee, $context, $document] = $this->createDocumentFixture();
        $catalog = $this->connection->fetchAll('SELECT * FROM personnel_document_requirements ORDER BY id');
        $head = $this->connection->fetchOne('SELECT * FROM employee_documents WHERE id = :id', ['id' => $document]);
        $contextBefore = $this->connection->fetchOne('SELECT * FROM employee_employment_contexts WHERE id = :id', ['id' => $context]);
        $accessBefore = $this->connection->fetchOne('SELECT * FROM employee_document_access_log WHERE document_id = :id', ['id' => $document]);
        $this->connection->execute(
            'INSERT INTO time_account_entries (user_id, effective_date, minutes, entry_type, description)
             VALUES (:employee, "2026-08-10", 30, "adjustment", "Synthetic prior time account entry")',
            ['employee' => $employee]
        );
        $this->connection->execute(
            'INSERT INTO vacation_account_entries (user_id, leave_year, effective_date, days, entry_type, description)
             VALUES (:employee, 2026, "2026-08-10", 1.5, "adjustment", "Synthetic prior vacation account entry")',
            ['employee' => $employee]
        );
        $journals = [];
        foreach (['time_account_entries', 'vacation_account_entries'] as $table) {
            $journals[$table] = $this->connection->fetchAll('SELECT * FROM ' . $table . ' ORDER BY id');
            self::assertCount(1, $journals[$table]);
        }

        $this->phinx('migrate');
        $this->phinx('migrate');
        $newHead = $this->connection->fetchOne('SELECT * FROM employee_documents WHERE id = :id', ['id' => $document]);
        self::assertSame(1, (int) $newHead['lock_version']);
        unset($newHead['lock_version']);
        self::assertSame($head, $newHead);
        $newContext = $this->connection->fetchOne('SELECT * FROM employee_employment_contexts WHERE id = :id', ['id' => $context]);
        self::assertNull($newContext['supersedes_context_id']);
        self::assertNull($newContext['continues_context_id']);
        unset($newContext['supersedes_context_id'], $newContext['continues_context_id']);
        self::assertSame($contextBefore, $newContext);
        $newAccess = $this->connection->fetchOne('SELECT * FROM employee_document_access_log WHERE document_id = :id', ['id' => $document]);
        self::assertNull($newAccess['employee_user_id']);
        unset($newAccess['employee_user_id']);
        self::assertSame($accessBefore, $newAccess);
        $foreign = $this->createUser('foreign-history');
        $bindings = ['document' => $document, 'foreign' => $foreign, 'actor' => $employee];
        $this->assertSqlRejected('INSERT INTO employee_document_access_log (document_id, employee_user_id, action, actor_user_id, outcome)
            VALUES (:document, :foreign, "document.read", :actor, "allowed")', $bindings);
        $this->assertSqlRejected('INSERT INTO employee_document_access_log (document_id, employee_user_id, action, actor_user_id, outcome)
            VALUES (:document, :foreign, "document.read", :actor, "denied")', $bindings);
        $this->assertSqlRejected('UPDATE employee_document_access_log SET employee_user_id = :employee WHERE document_id = :id',
            ['employee' => $employee, 'id' => $document], '45000');
        $this->assertSqlRejected('INSERT INTO employee_employment_contexts (user_id, employment_key, revision, continues_context_id, effective_from, revision_reason, created_by_user_id)
            VALUES (:foreign, "synthetic-context", 2, :context, "2027-01-01", "change_of_circumstances", :actor)',
            ['foreign' => $foreign, 'context' => $context, 'actor' => $employee]);
        self::assertSame($catalog, $this->connection->fetchAll('SELECT * FROM personnel_document_requirements ORDER BY id'));
        foreach ($journals as $table => $rows) {
            self::assertSame($rows, $this->connection->fetchAll('SELECT * FROM ' . $table . ' ORDER BY id'));
        }
        $this->assertSqlRejected('UPDATE employee_documents SET source = "import" WHERE id = :id', ['id' => $document], '45000');
        $migrationLog = $this->connection->fetchAll('SELECT * FROM phinxlog ORDER BY version');
        [$status] = $this->runPhinx('rollback', '-t', self::HARDENING);
        self::assertNotSame(0, $status);
        self::assertSame($migrationLog, $this->connection->fetchAll('SELECT * FROM phinxlog ORDER BY version'));
    }

    public function testScratchDatabaseCanBeDiscardedWithAppendOnlyData(): void
    {
        $this->phinx('migrate');
        $this->createDocumentFixture();
        $this->assertSqlRejected('DELETE FROM employee_document_audit', [], '45000');

        $this->server->exec('DROP DATABASE ' . MariaDbScratchConfig::quoteIdentifier($this->database));
        $statement = $this->server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name');
        $statement->execute(['name' => $this->database]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testSystemBackupIncludesCatalogAndOnlyEmptyPrivateTables(): void
    {
        $this->phinx('migrate');
        $privateTables = [...self::PRIVATE_TABLES, ...self::PHASE_TWO_PRIVATE_TABLES, self::PERSONNEL_EVIDENCE_TABLE];
        $username = 'timeapp_pb_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(24));
        $this->backupPrincipal = "'{$username}'@'%'";
        $this->server->exec('CREATE USER ' . $this->backupPrincipal . ' IDENTIFIED BY ' . $this->server->quote($password));
        $schema = MariaDbScratchConfig::quoteIdentifier($this->database);
        $tables = $this->connection->fetchAll('SELECT table_name FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_type IN ("BASE TABLE", "SYSTEM VERSIONED")');
        foreach (array_diff(array_column($tables, 'table_name'), $privateTables) as $table) {
            $this->server->exec('GRANT SELECT ON ' . $schema . '.' . MariaDbScratchConfig::quoteIdentifier($table)
                . ' TO ' . $this->backupPrincipal);
        }
        $backupConnection = new DatabaseConnection(array_replace(MariaDbScratchConfig::connection($this->database), [
            'username' => $username, 'password' => $password,
        ]));
        $service = new BackupService(
            $backupConnection,
            ['root' => $this->temporaryDirectory . '/no-uploads'],
            backupRoot: $this->temporaryDirectory . '/backup',
            runtimeOverridePath: $this->temporaryDirectory . '/no-runtime-override'
        );
        try {
            $service->export();
            self::fail('The restricted principal must read the coverage view to export.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Das installierte Datenbankschema ist fuer das Systembackup nicht vollstaendig sichtbar.', $exception->getMessage());
        }
        $this->server->exec('GRANT SELECT ON ' . $schema . '.personnel_backup_presence TO ' . $this->backupPrincipal);
        $presence = $backupConnection->fetchAll('SELECT * FROM personnel_backup_presence');
        self::assertCount(1, $presence);
        self::assertEqualsCanonicalizing($privateTables, array_keys($presence[0]));
        self::assertSame([0], array_unique(array_map('intval', array_values($presence[0]))));
        foreach ($privateTables as $table) {
            try {
                $backupConnection->fetchAll('SELECT * FROM ' . MariaDbScratchConfig::quoteIdentifier($table));
                self::fail('The general backup principal could read a private table.');
            } catch (PDOException $exception) {
                self::assertSame(1142, $exception->errorInfo[1]);
            }
        }
        $path = $this->temporaryDirectory . '/backup.zip';
        $this->connection->execute('RENAME TABLE employee_document_access_log TO hidden_backup_access_log');
        try {
            $service->export();
            self::fail('A missing private table was reported as an empty excluded scope.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Das installierte Datenbankschema ist fuer das Systembackup nicht vollstaendig sichtbar.', $exception->getMessage());
        } finally {
            $this->connection->execute('RENAME TABLE hidden_backup_access_log TO employee_document_access_log');
        }

        $empty = $service->export(requireComplete: true);
        file_put_contents($path, $empty['content']);
        $emptyValidation = $service->validateImport(['tmp_name' => $path]);
        self::assertSame('complete_for_scope', $emptyValidation['coverage']['status']);
        self::assertSame([
            'data_present' => false,
            'separate_backup_required' => false,
        ], $emptyValidation['coverage']['excluded_areas']['private_personnel_records']);
        self::assertFalse($emptyValidation['coverage']['excluded_areas']['system_versioned_tables']['data_present']);

        $scopeActor = $this->createUser('scope');
        $this->connection->execute(
            'INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code)
             VALUES (:employee, :grantee, :actor, 1, "SYNTHETIC-SCOPE-FIXTURE")',
            ['employee' => $scopeActor, 'grantee' => $scopeActor, 'actor' => $scopeActor]
        );
        $this->connection->execute(
            'INSERT INTO personnel_command_receipts (actor_user_id, request_hash, payload_hash, operation, result_json)
             VALUES (:actor, :request, :payload, "scope.change", :result)',
            ['actor' => $scopeActor, 'request' => str_repeat('b', 64), 'payload' => str_repeat('c', 64), 'result' => '{"synthetic":"RECEIPT-FIXTURE"}']
        );
        self::assertSame('partial', $service->export()['headers']['X-TimeApp-Backup-Coverage']);
        $this->assertSqlRejected('DELETE FROM employee_personnel_scope_events', [], '45000');
        $this->assertSqlRejected('UPDATE personnel_command_receipts SET operation = "other"', [], '45000');

        [$user, , $document] = $this->createDocumentFixture();
        $this->connection->execute(
            'INSERT INTO employee_document_versions (document_id, version_number, supersedes_version_number, storage_key, sha256,
                mime_type, size_bytes, storage_key_id, storage_format, format_verified_at, malware_scanned_at, created_by_user_id)
             VALUES (:document, 2, 1, :storage_key, :hash, "image/png", 8, "fixture", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :actor)',
            ['document' => $document, 'storage_key' => str_repeat('a', 64), 'hash' => str_repeat('b', 64), 'actor' => $user]
        );
        $fileVersion = $this->connection->lastInsertId();
        $this->connection->execute(
            'INSERT INTO employee_document_evidence_reviews (document_id, document_version_id, actor_user_id, `procedure`,
                procedure_version, outcome, original_kind, paper_status, signature_status, reason, legible, matches_record, created_at)
             VALUES (:document, :version, :actor, "manual_visual", 1, "confirmed_internal", "paper", "retained", "unresolved",
                "visual_checks_confirmed", 1, 1, UTC_TIMESTAMP())',
            ['document' => $document, 'version' => $fileVersion, 'actor' => $user]
        );
        $this->connection->execute(
            'INSERT INTO timesheets (user_id, created_by_user_id, work_date, start_time, end_time,
                gross_minutes, break_minutes, net_minutes, entry_type, source, note)
             VALUES (:user_id, :actor_id, "2026-11-01", "08:00:00", "12:00:00", 240, 0, 240, "work", "admin", "synthetic history")',
            ['user_id' => $user, 'actor_id' => $user]
        );
        $this->connection->execute('DELETE FROM timesheets WHERE id = :id', ['id' => $this->connection->lastInsertId()]);
        self::assertSame(0, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM timesheets'));
        self::assertSame(1, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM timesheets FOR SYSTEM_TIME ALL'));

        $result = $service->export();
        self::assertSame('partial', $result['headers']['X-TimeApp-Backup-Coverage']);
        file_put_contents($path, $result['content']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $manifest['backup_version']);
        self::assertSame('20261118209000_personnel_backup_presence', $manifest['schema_version']);
        self::assertFalse($zip->locateName('database/timesheets.json'));
        self::assertFalse($zip->locateName('database/personnel_backup_presence.json'));
        self::assertEqualsCanonicalizing($privateTables, $manifest['security']['excluded_database_table_rows']);
        foreach (self::CATALOG_TABLES as $table) {
            self::assertNotEmpty(json_decode($zip->getFromName('database/' . $table . '.json'), true, flags: JSON_THROW_ON_ERROR));
        }
        foreach ($privateTables as $table) {
            self::assertSame('[]', $zip->getFromName('database/' . $table . '.json'));
        }
        $zip->close();

        $validation = $service->validateImport(['tmp_name' => $path]);
        self::assertTrue($validation['dry_run']);
        self::assertFalse($validation['apply_supported']);
        self::assertSame('partial', $validation['coverage']['status']);
        self::assertSame('archive_manifest_only', $validation['coverage_evidence']);
        foreach (['private_personnel_records', 'system_versioned_tables'] as $scope) {
            self::assertSame([
                'data_present' => true,
                'separate_backup_required' => true,
            ], $validation['coverage']['excluded_areas'][$scope]);
        }
        self::assertStringNotContainsString('SYNTHETIC-AUDIT-FIXTURE', json_encode($validation, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('visual_checks_confirmed', json_encode($validation, JSON_THROW_ON_ERROR));
        self::assertSame(1, (int) $this->connection->fetchColumn('SELECT COUNT(*) FROM employee_documents'));

        $legacyPath = $this->temporaryDirectory . '/legacy-backup.zip';
        self::assertTrue(copy($path, $legacyPath));
        self::assertTrue($zip->open($legacyPath));
        $legacyManifest = $manifest;
        $legacyManifest['schema_version'] = '20261118208000_personnel_evidence_reviews';
        self::assertTrue($zip->addFromString('manifest.json', json_encode($legacyManifest, JSON_THROW_ON_ERROR)));
        self::assertTrue($zip->close());
        self::assertFalse($service->validateImport(['tmp_name' => $legacyPath])['apply_supported']);

        self::assertTrue($zip->open($legacyPath));
        $legacyManifest['schema_version'] = '20261118207000_personnel_private_storage';
        $legacyManifest['database']['tables'] = array_values(array_filter($legacyManifest['database']['tables'], static fn (array $entry): bool => $entry['table'] !== self::PERSONNEL_EVIDENCE_TABLE));
        $legacyManifest['security']['excluded_database_table_rows'] = array_values(array_diff($legacyManifest['security']['excluded_database_table_rows'], [self::PERSONNEL_EVIDENCE_TABLE]));
        self::assertTrue($zip->deleteName('database/' . self::PERSONNEL_EVIDENCE_TABLE . '.json'));
        self::assertTrue($zip->addFromString('manifest.json', json_encode($legacyManifest, JSON_THROW_ON_ERROR)));
        self::assertTrue($zip->close());
        self::assertFalse($service->validateImport(['tmp_name' => $legacyPath])['apply_supported']);

        self::assertTrue($zip->open($legacyPath));
        $legacyManifest['schema_version'] = '20261118203000_harden_personnel_compliance_history';
        $legacyManifest['database']['tables'] = array_values(array_filter($legacyManifest['database']['tables'], static fn (array $entry): bool => !in_array($entry['table'], self::PHASE_TWO_PRIVATE_TABLES, true)));
        $legacyManifest['security']['excluded_database_table_rows'] = array_values(array_diff($legacyManifest['security']['excluded_database_table_rows'], self::PHASE_TWO_PRIVATE_TABLES));
        foreach (self::PHASE_TWO_PRIVATE_TABLES as $table) {
            self::assertTrue($zip->deleteName('database/' . $table . '.json'));
        }
        self::assertTrue($zip->addFromString('manifest.json', json_encode($legacyManifest, JSON_THROW_ON_ERROR)));
        self::assertTrue($zip->close());
        self::assertFalse($service->validateImport(['tmp_name' => $legacyPath])['apply_supported']);

        self::assertTrue($zip->open($legacyPath));
        $legacyManifest['backup_version'] = 1;
        $legacyManifest['schema_version'] = '20261118202000_personnel_compliance_permissions';
        unset($legacyManifest['coverage'], $legacyManifest['uploads']['entries'], $legacyManifest['security']['status']);
        foreach ($legacyManifest['database']['tables'] as &$table) {
            unset($table['sha256']);
        }
        unset($table);
        self::assertTrue($zip->addFromString('manifest.json', json_encode($legacyManifest, JSON_THROW_ON_ERROR)));
        self::assertTrue($zip->close());
        $legacyValidation = $service->validateImport(['tmp_name' => $legacyPath]);
        self::assertSame('unknown', $legacyValidation['coverage']['status']);
        self::assertNull($legacyValidation['coverage']['excluded_areas']['private_personnel_records']['data_present']);
        self::assertTrue($legacyValidation['coverage']['excluded_areas']['private_personnel_records']['separate_backup_required']);
        self::assertFalse($legacyValidation['apply_supported']);

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
        self::assertTrue($zip->addFromString('database/personnel_command_receipts.json', '[{"result":"RECEIPT-FIXTURE"}]'));
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
        self::assertSame(['personnel_compliance.manage', 'personnel_documents.delegate', 'personnel_documents.export', 'personnel_documents.manage', 'personnel_documents.view'], array_column($grants, 'code'));
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
        $backupWorkDirectory = $this->temporaryDirectory . '/http-backups';
        $previousOverride = $_ENV['DB_OVERRIDE_FILE'] ?? null;
        $previousBackupWorkDirectory = $_ENV['BACKUP_TEMP_PATH'] ?? null;
        $previousSession = $_SESSION ?? [];
        $_ENV['DB_OVERRIDE_FILE'] = $override;
        $_ENV['BACKUP_TEMP_PATH'] = $backupWorkDirectory;

        try {
            [, $router] = require base_path('bootstrap/app.php');
            $status = static fn (string $method, string $path, array $query = []): int => $router->dispatch(new Request($method, $path, $query, [], [], [], []))->status();
            $_SESSION = [];
            self::assertSame(401, $status('GET', '/api/v1/system/backup/export'));
            self::assertSame(401, $status('POST', '/api/v1/system/backup/import/validate'));
            $_SESSION['auth']['user_id'] = $employee;
            self::assertSame(200, $status('GET', '/admin/personnel'));
            self::assertSame(403, $status('GET', '/api/v1/system/backup/export'));
            self::assertSame(403, $status('POST', '/api/v1/system/backup/import/validate'));
            $_SESSION['auth']['user_id'] = $manager;
            self::assertSame(503, $status('GET', '/api/v1/system/backup/export', ['require_complete' => '1']));
            self::assertSame(['.', '..'], scandir($backupWorkDirectory));
            self::assertSame(404, $status('GET', '/admin/personnel-documents'));
            self::assertSame(404, $status('GET', '/api/v1/personnel-documents'));
            self::assertSame(404, $status('POST', '/api/v1/personnel-documents'));
        } finally {
            $_SESSION = $previousSession;
            if ($previousOverride === null) {
                unset($_ENV['DB_OVERRIDE_FILE']);
            } else {
                $_ENV['DB_OVERRIDE_FILE'] = $previousOverride;
            }
            if ($previousBackupWorkDirectory === null) {
                unset($_ENV['BACKUP_TEMP_PATH']);
            } else {
                $_ENV['BACKUP_TEMP_PATH'] = $previousBackupWorkDirectory;
            }
        }
    }

    private function phinx(string ...$arguments): void
    {
        [$exitCode, $output] = $this->runPhinx(...$arguments);
        self::assertSame(0, $exitCode, $output);
    }

    private function runPhinx(string ...$arguments): array
    {
        $command = [PHP_BINARY, base_path('vendor/bin/phinx'), $arguments[0], '-c', $this->temporaryDirectory . '/phinx.php', '-e', 'test', ...array_slice($arguments, 1)];
        $log = $this->temporaryDirectory . '/phinx.log';
        $process = proc_open($command, [1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, base_path());
        self::assertIsResource($process);
        $exitCode = proc_close($process);

        return [$exitCode, (string) file_get_contents($log)];
    }

    private function assertRollbackBlocked(string $case, string $target, string ...$options): void
    {
        $log = $this->connection->fetchAll('SELECT * FROM phinxlog ORDER BY version');
        $triggers = $this->hardeningTriggers();
        $rows = $this->personnelSnapshot();

        [$exitCode, $output] = $this->runPhinx('rollback', '-t', $target, ...$options);
        self::assertNotSame(0, $exitCode, $case . "\n" . $output);
        self::assertStringContainsString('Der Compliance-Katalog ist vor Phinx-Rollbacks geschützt.', $output, $case);
        self::assertSame($log, $this->connection->fetchAll('SELECT * FROM phinxlog ORDER BY version'), $case);
        self::assertSame($triggers, $this->hardeningTriggers(), $case);
        self::assertSame($rows, $this->personnelSnapshot(), $case);
    }

    private function personnelSnapshot(): array
    {
        $rows = [];
        foreach ([...self::CATALOG_TABLES, ...self::PRIVATE_TABLES] as $table) {
            $rows[$table] = $this->connection->fetchAll('SELECT * FROM ' . $table . ' ORDER BY id');
        }

        return $rows;
    }

    private function hardeningTriggers(): array
    {
        return array_column($this->connection->fetchAll(
            'SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE()
             AND (trigger_name LIKE "deny\\_delete\\_%" OR trigger_name = "protect_employee_document_identity")
             ORDER BY trigger_name'
        ), 'trigger_name');
    }

    private function addPublishedRuleRevision(): int
    {
        $this->connection->execute(
            'INSERT INTO personnel_document_requirements (
                rule_code, revision, document_type_id, source_id, citation, valid_from, legal_force, record_kind,
                record_frequency, applicability_mode, employment_scope, evidence_rule, signature_rule,
                retention_trigger, retention_years, retention_rule
             ) SELECT rule_code, revision + 1, document_type_id, source_id, "Synthetic published revision", "2027-01-01",
                legal_force, record_kind, record_frequency, applicability_mode, employment_scope, evidence_rule,
                signature_rule, retention_trigger, retention_years, retention_rule
             FROM personnel_document_requirements ORDER BY id LIMIT 1'
        );

        return $this->connection->lastInsertId();
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
