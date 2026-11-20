<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Backup;

use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Domain\Personnel\Storage\PersonnelPrivateStorage;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;

final class PersonnelLiveBackupSource
{
    private readonly DatabaseConnection $database;
    private readonly array $directories;
    private bool $locked = false;

    public function __construct(
        private readonly PersonnelBackupAuthority $authority,
        private readonly string $root,
        private readonly int $storageUid,
        array $database,
        array $forbiddenRoots,
    ) {
        $this->authority->assertLiveOperator();
        if ($storageUid < 1 || $root === '/' || realpath($root) !== $root) {
            throw new PersonnelBackupFailure('live_storage_denied');
        }
        PersonnelPrivatePath::requireSeparate($root, $forbiddenRoots);
        $this->assertTrustedAncestors($root, 0, 'live_storage_denied');
        $this->directories = $this->directoryIdentities();

        $schema = $database['database'] ?? '';
        $account = $database['username'] ?? '';
        $socket = $database['socket'] ?? '';
        if (($database['driver'] ?? null) !== 'mysql' || ($database['host'] ?? null) !== 'localhost'
            || !is_string($schema) || !preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $schema)
            || !is_string($account) || !preg_match('/\A[a-zA-Z0-9_]{1,80}\z/', $account)
            || !is_string($socket) || !str_starts_with($socket, '/') || realpath($socket) !== $socket) {
            throw new PersonnelBackupFailure('live_source_denied');
        }
        $socketInfo = $this->info($socket, 'live_source_denied');
        if (($socketInfo['mode'] & 0170000) !== 0140000) {
            throw new PersonnelBackupFailure('live_source_denied');
        }
        $this->assertTrustedAncestors($socket, $socketInfo['uid'], 'live_source_denied');
        $this->database = new DatabaseConnection($database);
        $pdo = $this->database->pdo();
        if (!$pdo instanceof PDO || !str_contains(strtolower((string) $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)), 'unix socket')) {
            throw new PersonnelBackupFailure('live_source_denied');
        }
        $connectedSocket = $this->info($socket, 'live_source_denied');
        foreach (['dev', 'ino', 'mode', 'uid'] as $field) {
            if ($socketInfo[$field] !== $connectedSocket[$field]) {
                throw new PersonnelBackupFailure('live_source_denied');
            }
        }
        $identity = $this->database->fetchOne('SELECT DATABASE() AS schema_name, CURRENT_USER() AS principal,
            CURRENT_ROLE() AS active_role, @@socket AS socket_path');
        if (($identity['schema_name'] ?? null) !== $schema || ($identity['principal'] ?? null) !== $account . '@localhost'
            || ($identity['active_role'] ?? null) !== null || ($identity['socket_path'] ?? null) !== $socket) {
            throw new PersonnelBackupFailure('live_source_denied');
        }

        $required = [];
        foreach (PersonnelBackupTables::liveSourceTables() as $table) {
            $required['GRANT SELECT ON `' . $schema . '`.`' . $table . '`'] = true;
        }
        foreach ($this->database->fetchAll('SHOW GRANTS') as $row) {
            $grant = (string) reset($row);
            if (str_contains($grant, 'WITH GRANT OPTION')) {
                throw new PersonnelBackupFailure('live_source_denied');
            }
            if (str_starts_with($grant, 'GRANT USAGE ON *.* TO ')) {
                continue;
            }
            $scope = strstr($grant, ' TO ', true);
            if ($scope === false || !isset($required[$scope])) {
                throw new PersonnelBackupFailure('live_source_denied');
            }
            unset($required[$scope]);
        }
        if ($required !== []) {
            throw new PersonnelBackupFailure('live_source_denied');
        }
    }

    public function connection(): DatabaseConnection
    {
        return $this->database;
    }

    public function assertSeparateFrom(string $otherRoot): void
    {
        $this->assertStorage();
        PersonnelPrivatePath::requireSeparate($this->root, [$otherRoot]);
    }

    public function withLock(bool $exclusive, callable $operation): mixed
    {
        $this->authority->assertLiveOperator();
        if (!$exclusive || $this->locked) {
            throw new PersonnelBackupFailure('live_storage_denied');
        }
        $this->assertStorage();
        $lockPath = $this->root . '/locks/operation';
        $lock = $this->open($lockPath);
        try {
            $deadline = hrtime(true) + 45_000_000_000;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new PersonnelBackupFailure('live_storage_busy');
                }
                usleep(20000);
            }
            $this->assertStorage();
            $this->assertFileIdentity($lock, $this->info($lockPath));
            $this->locked = true;

            return $operation();
        } finally {
            $this->locked = false;
            fclose($lock);
        }
    }

    /** @return resource */
    public function openObject(string $key)
    {
        if (!$this->locked || !PersonnelPrivateStorage::validStorageKey($key)) {
            throw new PersonnelBackupFailure('live_storage_denied');
        }
        $this->assertStorage();

        return $this->open($this->root . '/objects/' . $key);
    }

    private function assertStorage(): void
    {
        if ($this->directoryIdentities() !== $this->directories) {
            throw new PersonnelBackupFailure('live_storage_denied');
        }
    }

    private function assertTrustedAncestors(string $path, int $ownerUid, string $error): void
    {
        for ($parent = dirname($path); ; $parent = dirname($parent)) {
            $info = $this->info($parent, $error);
            $rootSticky = $info['uid'] === 0 && ($info['mode'] & 01000) !== 0;
            if (($info['mode'] & 0170000) !== 0040000 || !in_array($info['uid'], [0, $ownerUid], true)
                || (($info['mode'] & 0022) !== 0 && !$rootSticky)) {
                throw new PersonnelBackupFailure($error);
            }
            if ($parent === '/') {
                return;
            }
        }
    }

    private function directoryIdentities(): array
    {
        $identities = [];
        foreach ([$this->root, $this->root . '/objects', $this->root . '/locks'] as $path) {
            $info = $this->info($path);
            if (($info['mode'] & 0177777) !== 0040700 || $info['uid'] !== $this->storageUid) {
                throw new PersonnelBackupFailure('live_storage_denied');
            }
            $identities[] = [$info['dev'], $info['ino']];
        }

        return $identities;
    }

    /** @return resource */
    private function open(string $path)
    {
        $expected = $this->info($path);
        if (($expected['mode'] & 0177777) !== 0100600 || $expected['uid'] !== $this->storageUid) {
            throw new PersonnelBackupFailure('live_storage_denied');
        }
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new PersonnelBackupFailure('live_storage_denied');
        }
        try {
            $this->assertFileIdentity($stream, $expected);
        } catch (PersonnelBackupFailure $failure) {
            fclose($stream);
            throw $failure;
        }

        return $stream;
    }

    /** @param resource $stream */
    private function assertFileIdentity($stream, array $expected): void
    {
        $opened = fstat($stream);
        if ($opened === false || $opened['dev'] !== $expected['dev'] || $opened['ino'] !== $expected['ino']
            || ($opened['mode'] & 0177777) !== 0100600 || $opened['uid'] !== $this->storageUid
            || $opened['mode'] !== $expected['mode'] || $opened['uid'] !== $expected['uid']) {
            throw new PersonnelBackupFailure('live_storage_denied');
        }
    }

    private function info(string $path, string $error = 'live_storage_denied'): array
    {
        clearstatcache(true, $path);
        $info = @lstat($path);
        if ($info === false) {
            throw new PersonnelBackupFailure($error);
        }

        return $info;
    }
}
