<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Backup;

use App\Domain\Personnel\Compliance\PersonnelFileRuntime;
use App\Domain\Personnel\Storage\PersonnelKeyring;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Domain\Personnel\Storage\PersonnelPrivateStorage;
use App\Domain\Personnel\Storage\PersonnelStorageException;
use App\Domain\Personnel\Storage\PersonnelStreamCipher;
use App\Infrastructure\Database\DatabaseConnection;
use Generator;
use PDOException;
use Throwable;

final class PersonnelPrivateBackup
{
    private const BACKUP_FORMAT = 1;
    private const STORAGE_FORMAT = PersonnelFileRuntime::STORAGE_FORMAT;
    private const BACKUP_CONTEXT = 'personnel.backup.v1:';
    private const CHUNK_BYTES = 48000;

    private readonly PersonnelStreamCipher $archiveCipher;
    private readonly PersonnelStreamCipher $objectCipher;
    private readonly string $archiveDirectory;
    private readonly array $archiveIdentity;

    public function __construct(
        private readonly PersonnelBackupAuthority $authority,
        string $archiveDirectory,
        private readonly array $forbiddenRoots,
        PersonnelKeyring $backupKeys,
        PersonnelKeyring $storageKeys,
        private readonly int $maxArchiveBytes = 68719476736,
        private readonly int $maxObjectBytes = 16777216,
        private readonly int $maxObjects = 50000,
    ) {
        $this->authority->assertAllowed();
        $this->archiveDirectory = PersonnelPrivatePath::requireRoot($archiveDirectory, $this->forbiddenRoots);
        $this->archiveIdentity = $this->archiveDirectoryIdentity();
        $backupKeys->assertSeparateFrom($storageKeys);
        $this->archiveCipher = new PersonnelStreamCipher($backupKeys);
        $this->objectCipher = new PersonnelStreamCipher($storageKeys);
        if ($maxArchiveBytes < 1 || $maxObjectBytes < 1 || $maxObjects < 1) {
            throw new PersonnelBackupFailure('invalid_limits');
        }
    }

    public function create(DatabaseConnection $source, PersonnelPrivateStorage $sourceStorage): array
    {
        $this->authority->assertAllowed();
        if (preg_match(
            '/\Atimeapp_(?:test_[a-z0-9_]+_[a-f0-9]{8}|personnel_restore_[a-f0-9]{12})\z/D',
            (string) $source->fetchColumn('SELECT DATABASE()'),
        ) !== 1) {
            throw new PersonnelBackupFailure('synthetic_source_required');
        }

        return $this->createArchive($source, $sourceStorage);
    }

    public function createLive(PersonnelLiveBackupSource $source): array
    {
        $this->authority->assertLiveOperator();

        return $this->createArchive($source->connection(), $source);
    }

    private function createArchive(DatabaseConnection $source, PersonnelPrivateStorage|PersonnelLiveBackupSource $sourceStorage): array
    {
        $this->assertSeparateStorage($sourceStorage);
        $tables = new PersonnelBackupTables($source);
        $id = bin2hex(random_bytes(16));
        $final = $this->archivePath($id);
        $staging = $final . '.part';
        $result = [];
        try {
            $sourceStorage->withLock(true, function () use ($tables, $sourceStorage, $id, $staging, &$result): void {
                $pdo = $tables->pdo();
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $pdo->beginTransaction();
                $output = null;
                try {
                    $output = PersonnelPrivatePath::createExclusive($staging);
                    if (!flock($output, LOCK_EX)) {
                        throw new PersonnelBackupFailure('archive_write_failed');
                    }
                    $schema = $tables->schema();
                    $backupKeyId = $this->archiveCipher->encryptChunks(
                        $this->boundedFrames($this->backupRecords($tables, $sourceStorage, $id, $schema, $result)),
                        $output,
                        self::BACKUP_CONTEXT . $id,
                    );
                    PersonnelPrivatePath::sync($output);
                    $result['backup_key_id'] = $backupKeyId;
                    $pdo->commit();
                } finally {
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
            });
            if (!@link($staging, $final)) {
                throw new PersonnelBackupFailure('archive_publish_failed');
            }
            try {
                PersonnelPrivatePath::syncDirectory($this->archiveDirectory);
            } catch (Throwable) {
                @unlink($final);
                throw new PersonnelBackupFailure('archive_publish_failed');
            }
        } catch (PersonnelBackupFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new PersonnelBackupFailure('backup_failed');
        } finally {
            if (is_file($staging)) {
                unlink($staging);
            }
        }

        return [...$result, 'verified' => true];
    }

    public function validate(string $backupId, DatabaseConnection $scratch, PersonnelPrivateStorage $scratchStorage): array
    {
        return $this->readArchive($backupId, $scratch, $scratchStorage, false);
    }

    public function restoreToScratch(string $backupId, DatabaseConnection $scratch, PersonnelPrivateStorage $scratchStorage): array
    {
        return $this->readArchive($backupId, $scratch, $scratchStorage, true);
    }

    private function backupRecords(
        PersonnelBackupTables $tables,
        PersonnelPrivateStorage|PersonnelLiveBackupSource $storage,
        string $id,
        array $schema,
        array &$result,
    ): Generator {
        $header = $this->header($id, $schema, $tables->catalogHash(), $tables->usersBasis());
        yield ['header', $header];
        $tableManifest = [];
        foreach (array_keys(PersonnelBackupTables::TABLES) as $table) {
            yield ['table', $table, $schema['columns'][$table]];
            $count = 0;
            $hash = hash_init('sha256');
            foreach ($tables->rows($table) as $data) {
                $row = ['row', array_values($data)];
                hash_update($hash, PersonnelBackupFrames::encode($row));
                ++$count;
                yield $row;
            }
            $tableManifest[$table] = [$count, hash_final($hash)];
            yield ['table_end', ...$tableManifest[$table]];
        }
        yield ['objects'];
        $objectCount = 0;
        $plainBytes = 0;
        foreach ($tables->objectRows() as $row) {
            $metadata = $this->objectMetadata($row);
            $plainBytes += $metadata['size_bytes'];
            if (++$objectCount > $this->maxObjects) {
                throw new PersonnelBackupFailure('object_limit');
            }
            yield from $this->objectRecords($storage, $metadata);
        }
        $result = [...$header, 'tables' => $tableManifest, 'objects' => $objectCount, 'original_bytes' => $plainBytes];
        yield ['manifest', $result];
    }

    private function objectRecords(PersonnelPrivateStorage|PersonnelLiveBackupSource $storage, array $metadata): Generator
    {
        $source = null;
        $copy = null;
        $path = null;
        try {
            try {
                $source = $storage->openObject($metadata['storage_key']);
            } catch (PersonnelStorageException) {
                throw new PersonnelBackupFailure('object_unavailable');
            }
            // Check the exact protected byte copy written to the archive, even if the source file changes.
            $path = $this->archiveDirectory . '/.' . bin2hex(random_bytes(16)) . '.object';
            $copy = PersonnelPrivatePath::createExclusive($path);
            if (!unlink($path)) {
                throw new PersonnelBackupFailure('archive_stage_unavailable');
            }
            $path = null;
            $limit = $this->maxCipherBytes($metadata['size_bytes']);
            $bytes = stream_copy_to_stream($source, $copy, $limit + 1);
            if ($bytes === false || $bytes > $limit || !rewind($copy)) {
                throw new PersonnelBackupFailure('object_size_invalid');
            }
            $this->verifyObject($copy, $metadata);
            if (!rewind($copy)) {
                throw new PersonnelBackupFailure('object_unavailable');
            }
            yield ['object', $metadata];
            $hash = hash_init('sha256');
            while (!feof($copy)) {
                $chunk = fread($copy, self::CHUNK_BYTES);
                if ($chunk === false || ($chunk === '' && !feof($copy))) {
                    throw new PersonnelBackupFailure('object_unavailable');
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                    yield ['bytes', base64_encode($chunk)];
                }
            }
            yield ['object_end', $bytes, hash_final($hash)];
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($copy)) {
                fclose($copy);
            }
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function readArchive(
        string $id,
        DatabaseConnection $scratch,
        PersonnelPrivateStorage $storage,
        bool $apply,
    ): array {
        $this->authority->assertAllowed();
        $this->assertSeparateStorage($storage);
        $path = $this->archivePath($id);
        $tables = new PersonnelBackupTables($scratch);
        try {
            return $storage->withLock(true, function () use ($path, $id, $scratch, $tables, $storage, $apply): array {
                $tables->assertScratchTarget();
                $lock = 'personnel-restore-' . substr(hash('sha256', (string) $scratch->fetchColumn('SELECT DATABASE()')), 0, 32);
                if ((int) $scratch->fetchColumn('SELECT GET_LOCK(:lock_name, 0)', ['lock_name' => $lock]) !== 1) {
                    throw new PersonnelBackupFailure('restore_in_progress');
                }
                try {
                    return $this->readIntoScratch($path, $id, $tables, $storage, $apply);
                } finally {
                    $scratch->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lock]);
                }
            });
        } catch (PersonnelBackupFailure $failure) {
            throw $failure;
        } catch (PDOException) {
            throw new PersonnelBackupFailure('scratch_reference_or_database_failed');
        } catch (Throwable) {
            throw new PersonnelBackupFailure('archive_authentication_or_restore_failed');
        }
    }

    private function readIntoScratch(
        string $path,
        string $id,
        PersonnelBackupTables $tables,
        PersonnelPrivateStorage $storage,
        bool $apply,
    ): array {
        $pdo = $tables->pdo();
        $archive = PersonnelPrivatePath::openPrivateFile($path);
        $staged = [];
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $alreadyRestored = !$tables->isEmpty();
            if (!$apply && $alreadyRestored) {
                throw new PersonnelBackupFailure('scratch_rows_present');
            }
            $schema = $tables->schema();
            $published = $storage->listObjectKeys();
            $priorStage = $storage->listStagingKeys();
            if ($storage->listQuarantineKeys() !== [] || (!$apply && ($published !== [] || $priorStage !== []))
                || ($alreadyRestored && $priorStage !== [])) {
                throw new PersonnelBackupFailure('scratch_storage_not_empty');
            }

            $frames = new PersonnelBackupFrames(
                $this->archiveCipher->decryptedChunks($archive, self::BACKUP_CONTEXT . $id, null, $this->maxArchiveBytes)
            );
            $headerFrame = $frames->next();
            if (count($headerFrame) !== 2) {
                throw new PersonnelBackupFailure('invalid_record');
            }
            $header = $headerFrame[1] ?? [];
            $this->assertHeader($headerFrame[0], $header, $id, $schema, $tables->catalogHash());
            $tableManifest = $this->readTables($frames, $tables, $schema, !$alreadyRestored);
            $this->verifyRestoredRows($tables, $tableManifest);
            [$usersCount, $usersHash] = $tables->usersBasis();
            if ($header['basis_user_count'] !== $usersCount || !hash_equals($header['basis_users_sha256'], $usersHash)) {
                throw new PersonnelBackupFailure('baseline_users_mismatch');
            }

            if ($frames->next() !== ['objects']) {
                throw new PersonnelBackupFailure('invalid_object_section');
            }
            $objectCount = 0;
            $plainBytes = 0;
            foreach ($tables->objectRows() as $row) {
                $metadata = $this->objectMetadata($row);
                $key = $metadata['storage_key'];
                if (++$objectCount > $this->maxObjects || $frames->next() !== ['object', $metadata]) {
                    throw new PersonnelBackupFailure('object_manifest_mismatch');
                }
                if ($alreadyRestored && !isset($published[$key])) {
                    throw new PersonnelBackupFailure('scratch_rows_present');
                }
                $plainBytes += $metadata['size_bytes'];
                $this->readObject($frames, $storage, $metadata, $published, $priorStage, $staged);
            }
            if ($published !== [] || $priorStage !== []) {
                throw new PersonnelBackupFailure('scratch_storage_unrelated');
            }
            $expected = [...$header, 'tables' => $tableManifest, 'objects' => $objectCount, 'original_bytes' => $plainBytes];
            if ($frames->next() !== ['manifest', $expected]) {
                throw new PersonnelBackupFailure('archive_manifest_mismatch');
            }
            $keyId = $frames->requireEnd();

            if ($apply && !$alreadyRestored) {
                foreach (array_keys($staged) as $key) {
                    $storage->publishObject($key);
                    unset($staged[$key]);
                }
                foreach ($tables->objectRows() as $row) {
                    $metadata = $this->objectMetadata($row);
                    $restored = $storage->openObject($metadata['storage_key']);
                    try {
                        $this->verifyObject($restored, $metadata);
                    } finally {
                        fclose($restored);
                    }
                }
                $pdo->commit();
            }

            return [
                ...$expected, 'backup_key_id' => $keyId, 'restored' => $apply,
                'verified' => true, 'replayed' => $alreadyRestored,
            ];
        } finally {
            fclose($archive);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach (array_keys($staged) as $key) {
                $storage->abortObject($key);
            }
        }
    }

    private function readTables(PersonnelBackupFrames $frames, PersonnelBackupTables $tables, array $schema, bool $insertRows): array
    {
        $manifest = [];
        foreach (array_keys(PersonnelBackupTables::TABLES) as $table) {
            $columns = $schema['columns'][$table];
            if ($frames->next() !== ['table', $table, $columns]) {
                throw new PersonnelBackupFailure('table_schema_mismatch');
            }
            $statement = $insertRows ? $tables->restoreInsert($table, $columns) : null;
            $count = 0;
            $hash = hash_init('sha256');
            while (($row = $frames->next())[0] === 'row') {
                $values = $row[1] ?? null;
                if (count($row) !== 2 || !is_array($values) || !array_is_list($values) || count($values) !== count($columns)) {
                    throw new PersonnelBackupFailure('invalid_database_row');
                }
                foreach ($values as $value) {
                    if ($value !== null && !is_int($value) && !is_string($value)) {
                        throw new PersonnelBackupFailure('invalid_database_value');
                    }
                }
                $statement?->execute($values);
                hash_update($hash, PersonnelBackupFrames::encode($row));
                ++$count;
            }
            $manifest[$table] = [$count, hash_final($hash)];
            if ($row !== ['table_end', ...$manifest[$table]]) {
                throw new PersonnelBackupFailure('table_manifest_mismatch');
            }
        }

        return $manifest;
    }

    private function verifyRestoredRows(PersonnelBackupTables $tables, array $manifest): void
    {
        foreach ($manifest as $table => $expected) {
            $count = 0;
            $hash = hash_init('sha256');
            foreach ($tables->rows($table) as $row) {
                hash_update($hash, PersonnelBackupFrames::encode(['row', array_values($row)]));
                ++$count;
            }
            if ($expected !== [$count, hash_final($hash)]) {
                throw new PersonnelBackupFailure('restored_database_values_mismatch');
            }
        }
    }

    private function readObject(
        PersonnelBackupFrames $frames,
        PersonnelPrivateStorage $storage,
        array $metadata,
        array &$published,
        array &$priorStage,
        array &$staged,
    ): void {
        $key = $metadata['storage_key'];
        if (isset($priorStage[$key])) {
            $storage->abortObject($key);
            unset($priorStage[$key]);
        }
        $resuming = isset($published[$key]);
        if ($resuming) {
            $stream = $storage->openObject($key);
            unset($published[$key]);
        } else {
            $stream = $storage->beginObject($key);
            $staged[$key] = true;
        }
        try {
            $hash = hash_init('sha256');
            $size = 0;
            while (($record = $frames->next())[0] === 'bytes') {
                $encoded = $record[1] ?? null;
                $data = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (count($record) !== 2 || $data === false || $data === ''
                    || base64_encode($data) !== $encoded || strlen($data) > self::CHUNK_BYTES) {
                    throw new PersonnelBackupFailure('invalid_object_chunk');
                }
                $size += strlen($data);
                if ($size > $this->maxCipherBytes($metadata['size_bytes'])) {
                    throw new PersonnelBackupFailure('object_size_invalid');
                }
                if (!$resuming) {
                    PersonnelStreamCipher::writeAll($stream, $data);
                }
                hash_update($hash, $data);
            }
            $expectedHash = hash_final($hash);
            if ($record !== ['object_end', $size, $expectedHash]) {
                throw new PersonnelBackupFailure('object_hash_mismatch');
            }
            if ($resuming) {
                $existingHash = hash_init('sha256');
                if (hash_update_stream($existingHash, $stream, $size + 1) !== $size
                    || !hash_equals($expectedHash, hash_final($existingHash))) {
                    throw new PersonnelBackupFailure('scratch_storage_unrelated');
                }
            } else {
                PersonnelPrivatePath::sync($stream);
            }
            if (!rewind($stream)) {
                throw new PersonnelBackupFailure('object_unavailable');
            }
            $this->verifyObject($stream, $metadata);
        } finally {
            fclose($stream);
        }
    }

    private function verifyObject($stream, array $metadata): void
    {
        $hash = hash_init('sha256');
        $length = 0;
        try {
            foreach ($this->objectCipher->decryptedChunks(
                $stream,
                PersonnelFileRuntime::objectContext($metadata['storage_key']),
                $metadata['storage_key_id'],
                $metadata['size_bytes'],
            ) as $plain) {
                hash_update($hash, $plain);
                $length += strlen($plain);
            }
        } catch (PersonnelStorageException) {
            throw new PersonnelBackupFailure('object_integrity_failed');
        }
        if ($length !== $metadata['size_bytes'] || !hash_equals($metadata['sha256'], hash_final($hash))) {
            throw new PersonnelBackupFailure('object_integrity_failed');
        }
    }

    private function objectMetadata(array $row): array
    {
        $size = (int) $row['size_bytes'];
        if (preg_match('/\A[a-f0-9]{64}\z/D', (string) $row['storage_key']) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $row['sha256']) !== 1
            || !PersonnelKeyring::validIdentifier((string) $row['storage_key_id'])
            || (int) $row['storage_format'] !== self::STORAGE_FORMAT
            || !in_array($row['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true)
            || $row['format_verified_at'] === null || $row['malware_scanned_at'] === null
            || $size < 1 || $size > $this->maxObjectBytes) {
            throw new PersonnelBackupFailure('file_evidence_invalid');
        }

        return [
            'version_id' => (int) $row['id'],
            'document_id' => (int) $row['document_id'],
            'storage_key' => $row['storage_key'],
            'storage_key_id' => $row['storage_key_id'],
            'sha256' => $row['sha256'],
            'size_bytes' => $size,
            'mime_type' => $row['mime_type'],
        ];
    }

    private function maxCipherBytes(int $plain): int
    {
        return $plain + intdiv($plain, 4) + 65536;
    }

    private function header(string $id, array $schema, string $catalogHash, array $users): array
    {
        return [
            'backup_id' => $id,
            'backup_format' => self::BACKUP_FORMAT,
            'storage_format' => self::STORAGE_FORMAT,
            'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'schema_version' => $schema['version'],
            'schema_sha256' => $schema['hash'],
            'basis_catalog_sha256' => $catalogHash,
            'basis_user_count' => $users[0],
            'basis_users_sha256' => $users[1],
        ];
    }

    private function assertHeader(string $type, mixed $header, string $id, array $schema, string $catalogHash): void
    {
        if ($type !== 'header' || !is_array($header) || count($header) !== 9
            || ($header['backup_id'] ?? null) !== $id || ($header['backup_format'] ?? null) !== self::BACKUP_FORMAT
            || ($header['storage_format'] ?? null) !== self::STORAGE_FORMAT
            || ($header['schema_version'] ?? null) !== $schema['version'] || ($header['schema_sha256'] ?? null) !== $schema['hash']
            || ($header['basis_catalog_sha256'] ?? null) !== $catalogHash
            || !is_int($header['basis_user_count'] ?? null) || $header['basis_user_count'] < 0
            || !is_string($header['basis_users_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $header['basis_users_sha256']) !== 1
            || !is_string($header['created_at_utc'] ?? null) || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D', $header['created_at_utc']) !== 1) {
            throw new PersonnelBackupFailure('incompatible_archive_or_baseline');
        }
    }

    private function boundedFrames(iterable $records): Generator
    {
        $size = 0;
        foreach ($records as $record) {
            $frame = PersonnelBackupFrames::encode($record);
            $size += strlen($frame);
            if ($size > $this->maxArchiveBytes) {
                throw new PersonnelBackupFailure('archive_size_limit');
            }
            yield $frame;
        }
    }

    private function archivePath(string $id): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new PersonnelBackupFailure('invalid_backup_id');
        }

        return $this->archiveDirectory . '/' . $id . '.tpb';
    }

    private function assertSeparateStorage(PersonnelPrivateStorage|PersonnelLiveBackupSource $storage): void
    {
        try {
            PersonnelPrivatePath::requireRoot($this->archiveDirectory, $this->forbiddenRoots);
            if ($this->archiveIdentity !== $this->archiveDirectoryIdentity()) {
                throw new PersonnelBackupFailure('archive_location_invalid');
            }
            $storage->assertSeparateFrom($this->archiveDirectory);
        } catch (Throwable) {
            throw new PersonnelBackupFailure('archive_location_invalid');
        }
    }

    private function archiveDirectoryIdentity(): array
    {
        clearstatcache(true, $this->archiveDirectory);
        $info = @lstat($this->archiveDirectory);
        if (!is_array($info)) {
            throw new PersonnelBackupFailure('archive_location_invalid');
        }

        return [$info['dev'], $info['ino']];
    }
}
