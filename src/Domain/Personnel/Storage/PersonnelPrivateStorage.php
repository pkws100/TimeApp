<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Storage;

final class PersonnelPrivateStorage
{
    private const OBJECTS = 'objects';
    private const STAGING = 'staging';
    private const QUARANTINE = 'quarantine';
    private const LOCKS = 'locks';

    private readonly string $root;
    private readonly string $identity;
    private readonly ?string $quarantineRoot;
    private readonly ?string $quarantineIdentity;
    private readonly ?int $quarantineDevice;
    private ?bool $exclusive = null;

    /** @param list<string> $forbiddenRoots */
    public function __construct(
        string $root,
        private readonly array $forbiddenRoots,
        private readonly int $maxBytes = 16777216,
        private readonly int $maxConcurrent = 4,
        private readonly int $minFreeBytes = 67108864,
        private readonly int $timeoutSeconds = 45,
        ?string $quarantineRoot = null,
    ) {
        if ($maxBytes < 1 || $maxBytes > 33554432 || $maxConcurrent < 1 || $maxConcurrent > 32
            || $minFreeBytes < 0 || $timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new PersonnelStorageException('Ungültige Grenzwerte für den privaten Speicher.');
        }
        $this->root = PersonnelPrivatePath::requireRoot($root, $forbiddenRoots);
        $this->identity = $this->readRootIdentity($this->root);
        $this->quarantineRoot = $quarantineRoot === null
            ? null
            : PersonnelPrivatePath::requireRoot($quarantineRoot, [...$forbiddenRoots, $this->root]);
        $this->quarantineIdentity = $this->quarantineRoot === null ? null : $this->readRootIdentity($this->quarantineRoot);
        $this->quarantineDevice = $this->quarantineRoot === null ? null : PersonnelPrivatePath::requireTmpfs($this->quarantineRoot);

        $directories = [self::OBJECTS, self::STAGING, self::LOCKS];
        if ($this->quarantineRoot === null) {
            $directories[] = self::QUARANTINE;
        }
        foreach ($directories as $directory) {
            $path = $this->root . '/' . $directory;
            if (@lstat($path) === false && @mkdir($path, 0700)) {
                PersonnelPrivatePath::syncDirectory($this->root);
            }
            PersonnelPrivatePath::requireDirectory($path);
        }
    }

    public function rootIdentity(): string
    {
        $this->assertCurrentRoot();

        return $this->identity;
    }

    public function assertSeparateFrom(string $otherRoot): void
    {
        $this->assertCurrentRoot();
        PersonnelPrivatePath::requireSeparate($this->root, [$otherRoot]);
        if ($this->quarantineRoot !== null) {
            PersonnelPrivatePath::requireSeparate($this->quarantineRoot, [$otherRoot]);
        }
    }

    public function maxUploadBytes(): int
    {
        return $this->maxBytes;
    }

    public static function validStorageKey(string $key): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $key) === 1;
    }

    public function newStorageKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function withLock(bool $exclusive, callable $operation): mixed
    {
        if ($this->exclusive !== null) {
            throw new PersonnelStorageException('Die private Speichersperre ist bereits aktiv.');
        }
        $this->assertCurrentRoot();
        $handle = $this->openLock('operation');
        $deadline = hrtime(true) + ($this->timeoutSeconds * 1_000_000_000);
        try {
            while (!flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new PersonnelStorageException('Der private Speicher ist vorübergehend belegt.');
                }
                usleep(20000);
            }
            $this->assertCurrentRoot();
            $this->exclusive = $exclusive;

            return $operation();
        } finally {
            $this->exclusive = null;
            fclose($handle);
        }
    }

    /** @param resource $source */
    public function stageUpload($source, int $expectedBytes): PersonnelStagedFile
    {
        $this->requireLock();
        if (!is_resource($source) || get_resource_type($source) !== 'stream' || $expectedBytes < 1 || $expectedBytes > $this->maxBytes) {
            throw new PersonnelStorageException('Ungültige Größe für die private Datei.');
        }
        $this->requireCompleteInput($source, $expectedBytes);
        $path = $this->entry(self::QUARANTINE, $this->newStorageKey());
        $lease = $this->reserveUploadSlot();
        $output = null;
        try {
            $separateDevice = $this->quarantineDevice !== null && !str_starts_with($this->identity, $this->quarantineDevice . ':');
            $reservedBytes = $expectedBytes * ($separateDevice ? 1 : 2);
            $this->requireCapacity($this->root, $reservedBytes);
            if ($this->quarantineRoot !== null) {
                $this->requireCapacity($this->quarantineRoot, $reservedBytes);
            }
            $output = PersonnelPrivatePath::createExclusive($path);
            if ($this->quarantineDevice !== null && (fstat($output)['dev'] ?? null) !== $this->quarantineDevice) {
                throw new PersonnelStorageException('Der private Quarantänepfad wurde geändert.');
            }
            $hash = hash_init('sha256');
            $total = 0;
            $deadline = hrtime(true) + ($this->timeoutSeconds * 1_000_000_000);
            while (!feof($source)) {
                if (hrtime(true) >= $deadline) {
                    throw new PersonnelStorageException('Die private Übertragung hat das Zeitlimit überschritten.');
                }
                $chunk = @fread($source, min(65536, $expectedBytes + 1 - $total));
                if ($chunk === false || ($chunk === '' && !feof($source))) {
                    throw new PersonnelStorageException('Die private Übertragung ist unvollständig.');
                }
                $total += strlen($chunk);
                if ($total > $expectedBytes) {
                    throw new PersonnelStorageException('Die private Datei überschreitet die erwartete Größe.');
                }
                hash_update($hash, $chunk);
                PersonnelStreamCipher::writeAll($output, $chunk);
            }
            if ($total !== $expectedBytes || hrtime(true) >= $deadline) {
                throw new PersonnelStorageException('Die private Übertragung ist unvollständig.');
            }
            PersonnelPrivatePath::sync($output);
            fclose($output);

            return new PersonnelStagedFile($path, $total, hash_final($hash), $lease);
        } catch (\Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
                @unlink($path);
            }
            fclose($lease);
            throw $exception;
        }
    }

    /** @return resource */
    public function beginObject(string $key)
    {
        $this->requireLock();

        return PersonnelPrivatePath::createExclusive($this->entry(self::STAGING, $key));
    }

    public function publishObject(string $key): void
    {
        $this->requireLock();
        $source = $this->entry(self::STAGING, $key);
        $destination = $this->entry(self::OBJECTS, $key);
        $handle = PersonnelPrivatePath::openPrivateFile($source);
        try {
            if ((fstat($handle)['size'] ?? 0) < 1 || !@fsync($handle) || !@link($source, $destination)) {
                throw new PersonnelStorageException('Das private Speicherobjekt konnte nicht dauerhaft abgelegt werden.');
            }
            // A hard link publishes atomically without ever overwriting an existing version.
            PersonnelPrivatePath::syncDirectory($this->root . '/' . self::OBJECTS);
        } finally {
            fclose($handle);
        }
        $this->removeEntry(self::STAGING, $key);
    }

    /** @return resource */
    public function openObject(string $key)
    {
        if ($this->exclusive === null) {
            $this->assertCurrentRoot();
        }

        return PersonnelPrivatePath::openPrivateFile($this->entry(self::OBJECTS, $key));
    }

    public function abortObject(string $key): void
    {
        $this->requireLock();
        $this->removeEntry(self::STAGING, $key);
    }

    public function removeQuarantine(string $key): void
    {
        $this->requireExclusive();
        $this->removeEntry(self::QUARANTINE, $key);
    }

    public function removeOrphanObject(string $key): void
    {
        $this->requireExclusive();
        $this->removeEntry(self::OBJECTS, $key);
    }

    /** @return array<string,int> Storage key to Unix mtime. */
    public function listObjectKeys(): array
    {
        return $this->listEntries(self::OBJECTS);
    }

    /** @return array<string,int> */
    public function listStagingKeys(): array
    {
        return $this->listEntries(self::STAGING);
    }

    /** @return array<string,int> */
    public function listQuarantineKeys(): array
    {
        return $this->listEntries(self::QUARANTINE);
    }

    public function assertEmpty(): void
    {
        $this->requireExclusive();
        if ($this->listObjectKeys() !== [] || $this->listStagingKeys() !== [] || $this->listQuarantineKeys() !== []) {
            throw new PersonnelStorageException('Der private Zielspeicher ist belegt.');
        }
    }

    private function requireLock(): void
    {
        if ($this->exclusive === null) {
            throw new PersonnelStorageException('Die private Speichersperre fehlt.');
        }
    }

    private function requireExclusive(): void
    {
        if ($this->exclusive !== true) {
            throw new PersonnelStorageException('Die exklusive private Speichersperre fehlt.');
        }
    }

    /** @param resource $source */
    private function requireCompleteInput($source, int $expectedBytes): void
    {
        $meta = stream_get_meta_data($source);
        $wrapper = $meta['wrapper_type'] ?? '';
        $builtInFile = $wrapper === 'plainfile' && ($meta['stream_type'] ?? null) === 'STDIO';
        $builtInMemory = $wrapper === 'PHP' && in_array($meta['uri'] ?? '', ['php://memory', 'php://temp'], true);
        if ((!$builtInFile && !$builtInMemory) || !($meta['seekable'] ?? false)) {
            throw new PersonnelStorageException('Die private Eingabequelle muss ein vollständig empfangener Dateistream sein.');
        }
        $size = fstat($source);
        if ($size === false || ($size['mode'] & 0170000) !== 0100000 || $size['size'] !== $expectedBytes || ftell($source) !== 0) {
            throw new PersonnelStorageException('Die private Übertragung ist unvollständig.');
        }
    }

    private function assertCurrentRoot(): void
    {
        PersonnelPrivatePath::requireRoot($this->root, $this->forbiddenRoots);
        if ($this->identity !== $this->readRootIdentity($this->root)) {
            throw new PersonnelStorageException('Der private Speicherpfad wurde geändert.');
        }
        foreach ([self::OBJECTS, self::STAGING, self::QUARANTINE, self::LOCKS] as $directory) {
            $this->directoryPath($directory);
        }
    }

    private function directoryPath(string $directory): string
    {
        if ($directory === self::QUARANTINE && $this->quarantineRoot !== null) {
            PersonnelPrivatePath::requireRoot($this->quarantineRoot, [...$this->forbiddenRoots, $this->root]);
            if (PersonnelPrivatePath::requireTmpfs($this->quarantineRoot) !== $this->quarantineDevice
                || $this->readRootIdentity($this->quarantineRoot) !== $this->quarantineIdentity) {
                throw new PersonnelStorageException('Der private Quarantänepfad wurde geändert.');
            }

            return $this->quarantineRoot;
        }
        $path = $this->root . '/' . $directory;
        PersonnelPrivatePath::requireDirectory($path);

        return $path;
    }

    private function readRootIdentity(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            throw new PersonnelStorageException('Der private Speicherpfad wurde geändert.');
        }

        return $stat['dev'] . ':' . $stat['ino'];
    }

    private function requireCapacity(string $path, int $reservedBytes): void
    {
        $freeBytes = @disk_free_space($path);
        if ($freeBytes === false || $freeBytes < $this->minFreeBytes + $this->maxConcurrent * ($reservedBytes + 65536)) {
            throw new PersonnelStorageException('Der private Speicher hat nicht genügend freie Kapazität.');
        }
    }

    private function entry(string $directory, string $key): string
    {
        if (!self::validStorageKey($key)) {
            throw new PersonnelStorageException('Ungültige private Speicherkennung.');
        }
        return $this->directoryPath($directory) . '/' . $key;
    }

    private function removeEntry(string $directory, string $key): void
    {
        $path = $this->entry($directory, $key);
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            return;
        }
        $file = PersonnelPrivatePath::openPrivateFile($path);
        fclose($file);
        if (!@unlink($path)) {
            throw new PersonnelStorageException('Das verwaiste private Speicherobjekt konnte nicht entfernt werden.');
        }
        PersonnelPrivatePath::syncDirectory(dirname($path));
    }

    /** @return array<string,int> */
    private function listEntries(string $directory): array
    {
        $this->requireExclusive();
        $entries = @scandir($this->directoryPath($directory));
        if ($entries === false) {
            throw new PersonnelStorageException('Der private Speicher konnte nicht geprüft werden.');
        }

        $keys = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $file = PersonnelPrivatePath::openPrivateFile($this->entry($directory, $entry));
            try {
                $stat = fstat($file);
                if ($stat === false) {
                    throw new PersonnelStorageException('Der private Speicher konnte nicht geprüft werden.');
                }
                $keys[$entry] = $stat['mtime'];
            } finally {
                fclose($file);
            }
        }

        return $keys;
    }

    /** @return resource */
    private function openLock(string $id)
    {
        $path = $this->root . '/' . self::LOCKS . '/' . $id;
        try {
            return PersonnelPrivatePath::createExclusive($path);
        } catch (PersonnelStorageException) {
            // A concurrent worker can have created the same persistent lock inode.
            return PersonnelPrivatePath::openPrivateFile($path);
        }
    }

    /** @return resource */
    private function reserveUploadSlot()
    {
        for ($slot = 0; $slot < $this->maxConcurrent; $slot++) {
            $lock = $this->openLock('upload-' . $slot);
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                return $lock;
            }
            fclose($lock);
        }

        throw new PersonnelStorageException('Alle privaten Uploadplätze sind derzeit belegt.');
    }
}
