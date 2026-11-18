<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Personnel\Backup\PersonnelBackupAuthority;
use App\Domain\Personnel\Backup\PersonnelBackupFailure;
use App\Domain\Personnel\Backup\PersonnelBackupFrames;
use App\Domain\Personnel\Backup\PersonnelBackupTables;
use App\Domain\Personnel\Backup\PersonnelPrivateBackup;
use App\Domain\Personnel\Storage\PersonnelKeyring;
use App\Domain\Personnel\Storage\PersonnelPrivateStorage;
use App\Domain\Personnel\Storage\PersonnelStreamCipher;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use RuntimeException;
use Tests\Support\MariaDbScratchConfig;
use Tests\Support\MariaDbTestCase;

final class PersonnelPrivateBackupDatabaseTest extends MariaDbTestCase
{
    private string $directory;
    private PersonnelPrivateStorage $source;
    private PersonnelPrivateStorage $scratchStorage;
    private PersonnelKeyring $sourceKeys;
    private PersonnelKeyring $backupKeys;
    private string $storageSecret;
    private string $backupSecret;
    private DatabaseConnection $scratch;
    private DatabaseConnection $scratchAdmin;
    private array $scratchConfig;
    private PDO $databaseServer;
    private string $databaseName;
    private string $restoreUser;
    private array $originals;
    private int $actorId;
    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = '/dev/shm/timeapp-personnel-backup-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        foreach (['source', 'scratch', 'archive'] as $name) {
            mkdir($this->directory . '/' . $name, 0700);
        }
        $this->source = new PersonnelPrivateStorage($this->directory . '/source', [base_path(), sys_get_temp_dir()], minFreeBytes: 0, timeoutSeconds: 1);
        $this->scratchStorage = new PersonnelPrivateStorage($this->directory . '/scratch', [base_path(), sys_get_temp_dir()], minFreeBytes: 0);
        $this->storageSecret = random_bytes(32);
        $this->backupSecret = random_bytes(32);
        $this->sourceKeys = new PersonnelKeyring('storage-v1', ['storage-v1' => $this->storageSecret]);
        $this->backupKeys = new PersonnelKeyring('archive-v1', ['archive-v1' => $this->backupSecret]);
        $this->originals = $this->seedPersonnel();
        $this->prepareScratch();
    }

    protected function tearDown(): void
    {
        if (isset($this->databaseServer, $this->databaseName)) {
            $this->databaseServer->exec('DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier($this->databaseName));
            $this->databaseServer->exec("DROP USER IF EXISTS '{$this->restoreUser}'@'%'");
        }
        if (isset($this->directory) && is_dir($this->directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testPrivateArchiveRestoresVersionsAuditsAndExactOriginalsAfterInterruptedPublication(): void
    {
        $backup = $this->backup();
        $archive = $backup->create($this->connection(), $this->source);
        $id = $archive['backup_id'];
        self::assertSame(2, $archive['objects']);
        self::assertSame(3, $archive['tables']['employee_document_versions'][0]);
        self::assertSame(2, $archive['tables']['employee_employment_contexts'][0]);
        self::assertSame(2, $archive['basis_user_count']);
        self::assertSame('archive-v1', $archive['backup_key_id']);
        $artifact = $this->archivePath($id);
        self::assertSame(0600, fileperms($artifact) & 0777);
        self::assertStringNotContainsString('synthetic-private-identity', file_get_contents($artifact));
        foreach ($this->originals as $key => $original) {
            self::assertStringNotContainsString($original, file_get_contents($artifact));
            self::assertStringNotContainsString($key, file_get_contents($artifact));
        }

        $validation = $backup->validate($id, $this->scratch, $this->scratchStorage);
        self::assertTrue($validation['verified']);
        self::assertFalse($validation['restored']);
        $this->assertScratchEmpty();

        // An interruption can leave a mixture of published ciphertext and partial encrypted staging.
        [$published, $staged] = array_keys($this->originals);
        $this->scratchStorage->withLock(true, function () use ($published, $staged): void {
            $original = $this->source->openObject($published);
            $destination = $this->scratchStorage->beginObject($published);
            stream_copy_to_stream($original, $destination);
            fclose($original);
            fclose($destination);
            $this->scratchStorage->publishObject($published);
            $partial = $this->scratchStorage->beginObject($staged);
            fwrite($partial, 'incomplete synthetic ciphertext');
            fclose($partial);
        });
        $restore = $backup->restoreToScratch($id, $this->scratch, $this->scratchStorage);
        self::assertTrue($restore['restored']);
        self::assertSame($archive['tables'], $restore['tables']);
        foreach (PersonnelBackupTables::TABLES as $table => $order) {
            $orderSql = ' ORDER BY ' . implode(', ', $order);
            self::assertSame(
                $this->connection()->fetchAll('SELECT * FROM ' . $table . $orderSql),
                $this->scratch->fetchAll('SELECT * FROM ' . $table . $orderSql),
                $table,
            );
        }
        foreach ($this->originals as $key => $original) {
            $storageCiphertext = $this->scratchStorage->openObject($key);
            $restored = fopen('php://memory', 'w+b');
            (new PersonnelStreamCipher($this->sourceKeys))->decrypt($storageCiphertext, $restored, 'personnel.object.v1:' . $key);
            rewind($restored);
            self::assertSame($original, stream_get_contents($restored));
            fclose($storageCiphertext);
            fclose($restored);
        }
        self::assertSame([], $this->scratchStorage->withLock(true, fn () => $this->scratchStorage->listStagingKeys()));
        self::assertTrue($backup->restoreToScratch($id, $this->scratch, $this->scratchStorage)['replayed']);
        $this->assertFailure('scratch_rows_present', fn () => $backup->validate($id, $this->scratch, $this->scratchStorage));
    }

    public function testBackupAndRestoreFailClosedOnMissingFilesWrongKeyAndTampering(): void
    {
        $backup = $this->backup();
        $id = $backup->create($this->connection(), $this->source)['backup_id'];
        $wrongKey = $this->backup(new PersonnelKeyring('archive-v1', ['archive-v1' => random_bytes(32)]));
        $this->assertFailure('archive_authentication_or_restore_failed', fn () => $wrongKey->validate($id, $this->scratch, $this->scratchStorage));
        $missingKey = $this->backup(new PersonnelKeyring('new-archive', ['new-archive' => random_bytes(32)]));
        $this->assertFailure('archive_authentication_or_restore_failed', fn () => $missingKey->validate($id, $this->scratch, $this->scratchStorage));
        $this->assertScratchEmpty();

        $path = $this->archivePath($id);
        $authentic = file_get_contents($path);
        $mutated = $authentic;
        $mutated[strlen($mutated) - 1] = chr(ord($mutated[strlen($mutated) - 1]) ^ 16);
        file_put_contents($path, $mutated);
        $this->assertFailure('archive_authentication_or_restore_failed', fn () => $backup->restoreToScratch($id, $this->scratch, $this->scratchStorage));
        $this->assertScratchEmpty();

        file_put_contents($path, $authentic);
        $this->rewriteArchive($id, static function (array $record): array {
            if ($record[0] === 'manifest') {
                $record[1]['objects'] += 1;
            }

            return $record;
        });
        $this->assertFailure('archive_manifest_mismatch', fn () => $backup->validate($id, $this->scratch, $this->scratchStorage));
        $this->assertScratchEmpty();

        file_put_contents($path, $authentic);
        $this->rewriteArchive($id, static function (array $record): array {
            if ($record[0] === 'object') {
                $record[1]['version_id'] = 99999999;
            }

            return $record;
        });
        $this->assertFailure('object_manifest_mismatch', fn () => $backup->validate($id, $this->scratch, $this->scratchStorage));
        $this->assertScratchEmpty();

        file_put_contents($path, $authentic);
        $index = null;
        $first = null;
        $this->rewriteArchive($id, static function (array $record) use (&$index, &$first): array {
            if ($record[0] === 'table' && $record[1] === 'employee_document_versions') {
                $index = array_search('storage_key', $record[2], true);
            } elseif ($record[0] === 'table_end') {
                $index = null;
            } elseif ($record[0] === 'row' && $index !== null && $record[1][$index] !== null) {
                $first ??= $record[1][$index];
                $record[1][$index] = $first;
            }

            return $record;
        });
        $this->assertFailure('scratch_reference_or_database_failed', fn () => $backup->validate($id, $this->scratch, $this->scratchStorage));
        $this->assertScratchEmpty();

        $key = array_key_first($this->originals);
        $this->source->withLock(true, function () use ($key): void {
            $this->source->removeOrphanObject($key);
            $manipulated = $this->source->beginObject($key);
            fwrite($manipulated, 'invalid encrypted object in a synthetic store');
            fclose($manipulated);
            $this->source->publishObject($key);
        });
        $this->assertFailure('object_integrity_failed', fn () => $backup->create($this->connection(), $this->source));
        $this->source->withLock(true, fn () => $this->source->removeOrphanObject($key));
        $this->assertFailure('object_unavailable', fn () => $backup->create($this->connection(), $this->source));
        self::assertSame([$path], glob($this->directory . '/archive/*.tpb'));
        self::assertSame([], glob($this->directory . '/archive/*.part'));
    }

    public function testScratchNeedsDedicatedPrivilegesExactSchemaAndCompatibleEmployeeReferences(): void
    {
        $backup = $this->backup();
        $id = $backup->create($this->connection(), $this->source)['backup_id'];
        $this->assertFailure('operator_denied', fn () => PersonnelBackupAuthority::forLocalOperator(posix_geteuid() + 1));
        $this->assertFailure('scratch_target_denied', fn () => $backup->restoreToScratch($id, $this->scratchAdmin, $this->scratchStorage));
        $this->assertFailure('scratch_target_denied', fn () => $backup->restoreToScratch($id, $this->connection(), $this->scratchStorage));
        $nonScratch = new DatabaseConnection(array_replace($this->connectionConfig(), ['database' => 'mysql']));
        $this->assertFailure('synthetic_source_required', fn () => $backup->create($nonScratch, $this->source));

        $this->scratchAdmin->execute('UPDATE users SET email = "incompatible@example.test" WHERE id = ?', [$this->employeeId]);
        $this->assertFailure('baseline_users_mismatch', fn () => $backup->restoreToScratch($id, $this->scratch, $this->scratchStorage));
        $this->assertScratchEmpty();

        $this->scratchAdmin->execute('DELETE FROM users WHERE id = ?', [$this->employeeId]);
        $this->assertFailure('scratch_reference_or_database_failed', fn () => $backup->restoreToScratch($id, $this->scratch, $this->scratchStorage));
        $this->assertScratchEmpty();

        $this->scratchAdmin->execute('ALTER TABLE employee_documents ADD COLUMN unrelated_fixture VARCHAR(12) NULL');
        $this->assertFailure('incompatible_archive_or_baseline', fn () => $backup->validate($id, $this->scratch, $this->scratchStorage));
        $this->assertScratchEmpty();
    }

    public function testMissingOrChangedHistoryTriggerPreventsScratchRestore(): void
    {
        $backup = $this->backup();
        $id = $backup->create($this->connection(), $this->source)['backup_id'];
        $name = 'immutable_employee_document_access_log';
        $definition = $this->scratchAdmin->fetchOne('SHOW CREATE TRIGGER ' . $name)['SQL Original Statement'];
        $this->scratchAdmin->execute('DROP TRIGGER ' . $name);
        try {
            $this->assertFailure('protective_triggers_missing_or_invalid', fn () => $backup->restoreToScratch($id, $this->scratch, $this->scratchStorage));
            $this->assertScratchEmpty();

            $this->scratchAdmin->execute('CREATE TRIGGER ' . $name
                . ' BEFORE UPDATE ON employee_document_access_log FOR EACH ROW SET @timeapp_synthetic_no_guard = 1');
            $this->assertFailure('incompatible_archive_or_baseline', fn () => $backup->restoreToScratch($id, $this->scratch, $this->scratchStorage));
            $this->assertScratchEmpty();
        } finally {
            $this->scratchAdmin->execute('DROP TRIGGER IF EXISTS ' . $name);
            $this->scratchAdmin->execute($definition);
        }

        self::assertTrue($backup->validate($id, $this->scratch, $this->scratchStorage)['verified']);
    }

    public function testBackupSnapshotCannotOverlapAnActiveUploadLease(): void
    {
        $backup = $this->backup();
        $heldRoot = new PersonnelPrivateStorage($this->directory . '/source', [base_path(), sys_get_temp_dir()]);
        $heldRoot->withLock(false, function () use ($backup): void {
            $this->assertFailure('backup_failed', fn () => $backup->create($this->connection(), $this->source));
            self::assertSame([], glob($this->directory . '/archive/*'));
        });
        self::assertSame(2, $backup->create($this->connection(), $this->source)['objects']);
    }

    public function testCliRequiresProtectedConfigurationAndCanCreateValidateAndRestore(): void
    {
        $configuration = $this->directory . '/operator.json';
        $file = [
            'mode' => 'synthetic',
            'operator_uid' => posix_geteuid(),
            'archive_root' => $this->directory . '/archive',
            'storage_keyring' => $this->directory . '/storage-keyring.json',
            'backup_keyring' => $this->directory . '/backup-keyring.json',
            'source_storage' => $this->directory . '/source',
            'source_database' => $this->connectionConfig(),
            'scratch_storage' => $this->directory . '/scratch',
            'scratch_database' => $this->scratchConfig,
        ];
        $this->writeProtectedJson($configuration, $file);
        $this->writeProtectedJson($file['storage_keyring'], [
            'active' => 'storage-v1', 'keys' => ['storage-v1' => base64_encode($this->storageSecret)],
        ]);
        $this->writeProtectedJson($file['backup_keyring'], [
            'active' => 'archive-v1', 'keys' => ['archive-v1' => base64_encode($this->backupSecret)],
        ]);
        chmod($configuration, 0644);
        [$exit, $stdout] = $this->executeCli('create', $configuration);
        self::assertSame(1, $exit);
        self::assertSame('', $stdout);
        chmod($configuration, 0600);

        [$exit, $stdout, $stderr] = $this->executeCli('create', $configuration);
        self::assertSame(0, $exit, $stderr);
        $id = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR)['backup_id'];
        [$exit, $stdout, $stderr] = $this->executeCli('validate', $configuration, $id);
        self::assertSame(0, $exit, $stderr);
        self::assertFalse(json_decode($stdout, true, 8, JSON_THROW_ON_ERROR)['restored']);
        [$exit, $stdout, $stderr] = $this->executeCli('restore-scratch', $configuration, $id);
        self::assertSame(0, $exit, $stderr);
        self::assertTrue(json_decode($stdout, true, 8, JSON_THROW_ON_ERROR)['restored']);
    }

    private function backup(?PersonnelKeyring $keyring = null): PersonnelPrivateBackup
    {
        return new PersonnelPrivateBackup(
            PersonnelBackupAuthority::forLocalOperator(posix_geteuid()),
            $this->directory . '/archive',
            [base_path(), sys_get_temp_dir(), $this->directory . '/source', $this->directory . '/scratch'],
            $keyring ?? $this->backupKeys,
            $this->sourceKeys,
        );
    }

    private function prepareScratch(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->databaseName = 'timeapp_personnel_restore_' . $suffix;
        $this->restoreUser = 'timeapp_pr_' . $suffix;
        $password = bin2hex(random_bytes(16));
        $this->databaseServer = MariaDbScratchConfig::connectServer();
        $database = MariaDbScratchConfig::quoteIdentifier($this->databaseName);
        $this->databaseServer->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->databaseServer->exec("CREATE USER '{$this->restoreUser}'@'%' IDENTIFIED BY " . $this->databaseServer->quote($password));
        $grantSchema = MariaDbScratchConfig::quoteIdentifier(str_replace('_', '\\_', $this->databaseName));
        $this->databaseServer->exec('GRANT SELECT, INSERT ON ' . $grantSchema . ".* TO '{$this->restoreUser}'@'%'");
        $configuration = tempnam(sys_get_temp_dir(), 'timeapp-personnel-restore-');
        try {
            $phinx = [
                'paths' => ['migrations' => base_path('migrations'), 'seeds' => base_path('seeds')],
                'environments' => [
                    'default_migration_table' => 'phinxlog',
                    'default_environment' => 'test',
                    'test' => MariaDbScratchConfig::phinxEnvironment($this->databaseName),
                ],
                'version_order' => 'creation',
            ];
            file_put_contents($configuration, '<?php return ' . var_export($phinx, true) . ';');
            $process = proc_open([PHP_BINARY, base_path('vendor/bin/phinx'), 'migrate', '-c', $configuration, '-e', 'test'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (!is_resource($process)) {
                throw new RuntimeException('Could not prepare restore migration fixture.');
            }
            foreach ($pipes as $pipe) {
                stream_get_contents($pipe);
                fclose($pipe);
            }
            self::assertSame(0, proc_close($process));
        } finally {
            unlink($configuration);
        }

        $config = MariaDbScratchConfig::connection($this->databaseName);
        $this->scratchAdmin = new DatabaseConnection($config);
        $this->scratchConfig = array_replace($config, ['username' => $this->restoreUser, 'password' => $password]);
        $this->scratch = new DatabaseConnection($this->scratchConfig);
        $sourceSchema = MariaDbScratchConfig::quoteIdentifier($this->connectionConfig()['database']);
        $catalog = ['personnel_compliance_sources', 'personnel_document_types', 'personnel_document_requirements'];
        $admin = $this->scratchAdmin->pdo();
        $admin->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_reverse($catalog) as $table) {
                $admin->exec('TRUNCATE TABLE ' . $table);
            }
        } finally {
            $admin->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach (['users', ...$catalog] as $table) {
            $admin->exec('INSERT INTO ' . $table . ' SELECT * FROM ' . $sourceSchema . '.' . $table);
        }
    }

    private function seedPersonnel(): array
    {
        $db = $this->connection();
        $this->actorId = $this->createUser();
        $this->employeeId = $this->createUser(['employee_number' => 'synthetic-private-identity']);
        $db->execute("INSERT INTO personnel_document_types (code, label, category) VALUES ('synthetic', 'Synthetic', 'fixture')");
        $type = $db->lastInsertId();
        $db->execute("INSERT INTO employee_employment_contexts
            (user_id, employment_key, employment_type, effective_from, effective_until, created_by_user_id)
            VALUES (?, 'fixture-job', 'marginal', '2026-11-01', '2026-12-31', ?)", [$this->employeeId, $this->actorId]);
        $context = $db->lastInsertId();
        $db->execute("INSERT INTO employee_employment_contexts
            (user_id, employment_key, employment_type, effective_from, revision, revision_reason, continues_context_id, created_by_user_id)
            VALUES (?, 'fixture-job', 'regular', '2027-01-01', 2, 'change_of_circumstances', ?, ?)", [$this->employeeId, $context, $this->actorId]);
        $db->execute('INSERT INTO employee_documents (user_id, document_type_id, employment_context_id, created_by_user_id) VALUES (?, ?, ?, ?)',
            [$this->employeeId, $type, $context, $this->actorId]);
        $document = $db->lastInsertId();
        $db->execute('INSERT INTO employee_document_versions (document_id, version_number, created_by_user_id) VALUES (?, 1, ?)',
            [$document, $this->actorId]);
        $originals = [];
        $cipher = new PersonnelStreamCipher($this->sourceKeys);
        for ($version = 2; $version <= 3; ++$version) {
            $original = "Synthetic private bytes of version {$version} " . bin2hex(random_bytes(32));
            $key = $this->source->newStorageKey();
            $this->source->withLock(false, function () use ($cipher, $key, $original): void {
                $output = $this->source->beginObject($key);
                $cipher->encryptChunks([$original], $output, 'personnel.object.v1:' . $key);
                fclose($output);
                $this->source->publishObject($key);
            });
            $db->execute('INSERT INTO employee_document_versions
                (document_id, version_number, supersedes_version_number, storage_key, sha256, mime_type, size_bytes, storage_key_id,
                 storage_format, format_verified_at, malware_scanned_at, created_by_user_id)
                VALUES (?, ?, ?, ?, ?, "image/png", ?, "storage-v1", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)',
                [$document, $version, $version - 1, $key, hash('sha256', $original), strlen($original), $this->actorId]);
            $originals[$key] = $original;
        }
        $lastVersion = $db->lastInsertId();
        $db->execute("INSERT INTO employee_document_audit (document_id, document_version_id, action, actor_user_id, reason)
            VALUES (?, ?, 'version.upload', ?, 'technical_fixture')", [$document, $lastVersion, $this->actorId]);
        $db->execute("INSERT INTO employee_document_access_log (document_id, document_version_id, employee_user_id, action, actor_user_id, outcome)
            VALUES (?, ?, ?, 'document.file.download', ?, 'allowed')", [$document, $lastVersion, $this->employeeId, $this->actorId]);
        $db->execute("INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code)
            VALUES (?, ?, ?, 1, 'assignment')", [$this->employeeId, $this->actorId, $this->actorId]);
        $db->execute('INSERT INTO personnel_command_receipts (actor_user_id, request_hash, payload_hash, operation, result_json) VALUES (?, ?, ?, ?, ?)',
            [$this->actorId, hash('sha256', 'synthetic-request'), hash('sha256', 'synthetic-payload'), 'document.upload', '{"version_id":' . $lastVersion . '}']);

        return $originals;
    }

    private function rewriteArchive(string $id, callable $change): void
    {
        $archive = fopen($this->archivePath($id), 'rb');
        $new = fopen('php://memory', 'w+b');
        $cipher = new PersonnelStreamCipher($this->backupKeys);
        $context = 'personnel.backup.v1:' . $id;
        $frames = new PersonnelBackupFrames($cipher->decryptedChunks($archive, $context));
        $records = static function () use ($frames, $change): \Generator {
            do {
                $record = $frames->next();
                yield PersonnelBackupFrames::encode($change($record));
            } while ($record[0] !== 'manifest');
            $frames->requireEnd();
        };
        $cipher->encryptChunks($records(), $new, $context);
        fclose($archive);
        rewind($new);
        file_put_contents($this->archivePath($id), stream_get_contents($new));
        fclose($new);
    }

    private function archivePath(string $id): string
    {
        return $this->directory . '/archive/' . $id . '.tpb';
    }

    private function writeProtectedJson(string $path, array $value): void
    {
        $previous = umask(0077);
        try {
            file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));
        } finally {
            umask($previous);
        }
    }

    private function executeCli(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, base_path('bin/personnel-private-backup.php'), ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the private backup CLI test.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function assertScratchEmpty(): void
    {
        self::assertSame(0, (int) $this->scratch->fetchColumn('SELECT COUNT(*) FROM employee_documents'));
        $this->scratchStorage->withLock(true, fn () => $this->scratchStorage->assertEmpty());
    }

    private function assertFailure(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('A failing private backup operation must abort.');
        } catch (PersonnelBackupFailure $failure) {
            self::assertSame($reason, $failure->reason);
        }
    }
}
