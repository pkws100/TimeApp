<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use Throwable;

final class PersonnelSyntheticHttpBoundary
{
    private ?DatabaseConnection $connection = null;

    private function __construct(
        private readonly string $root,
        private readonly string $fixtureId,
        private readonly int $port,
        private readonly string $qpdf,
        private readonly array $uploadHashes,
    ) {
    }

    public static function isRequested(): bool
    {
        return PHP_SAPI === 'cli-server' && getenv('APP_ENV', true) === 'test'
            && getenv('PERSONNEL_HTTP_MODE', true) === 'synthetic';
    }

    public static function discover(): ?self
    {
        if (!self::isRequested()) {
            return null;
        }

        try {
            $permit = (string) getenv('PERSONNEL_HTTP_FIXTURE_FILE', true);
            $root = dirname($permit);
            PersonnelPrivatePath::requireRoot($root, [base_path(), '/var/www', sys_get_temp_dir()]);
            if (!preg_match('/\Atimeapp-personnel-http-([a-f0-9]{12})\z/', basename($root), $name)
                || $permit !== $root . '/fixture.json') {
                return null;
            }
            $stream = PersonnelPrivatePath::openSecret($permit);
            try {
                $contents = stream_get_contents($stream, 4097);
            } finally {
                fclose($stream);
            }
            if ($contents === false || strlen($contents) > 4096) {
                return null;
            }
            $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($data) || count($data) !== 4 || ($data['fixture_id'] ?? null) !== $name[1]
                || !is_int($data['listen_port'] ?? null) || $data['listen_port'] < 1024 || $data['listen_port'] > 65535
                || !is_string($data['qpdf'] ?? null)) {
                return null;
            }
            $hashes = $data['upload_sha256'] ?? null;
            if (!is_array($hashes) || !array_is_list($hashes) || $hashes === [] || count($hashes) > 32) {
                return null;
            }
            foreach ($hashes as $hash) {
                if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
                    return null;
                }
            }
            if (count(array_unique($hashes)) !== count($hashes)) {
                return null;
            }
            $boundary = new self($root, $name[1], $data['listen_port'], $data['qpdf'], $hashes);
            if (!$boundary->isLocalServer()) {
                return null;
            }
            foreach (['sessions', 'incoming', 'private-store', 'database', 'uploads', 'exports', 'general-backup'] as $directory) {
                PersonnelPrivatePath::requireDirectory($root . '/' . $directory);
            }
            PersonnelPrivatePath::requireRoot(self::scannerDirectory($name[1]), [base_path(), '/var/www', $root]);
            if (ini_get('upload_tmp_dir') !== $boundary->incomingDirectory()
                || ini_get('session.save_path') !== $boundary->sessionDirectory()
                || getenv('DB_OVERRIDE_FILE', true) !== $root . '/database.php') {
                return null;
            }
            $databaseConfig = PersonnelPrivatePath::openSecret($root . '/database.php');
            fclose($databaseConfig);
            $uploads = require config_path('uploads.php');
            $exports = require config_path('exports.php');
            if (realpath($uploads['root']) !== $root . '/uploads' || realpath($exports['temp_path']) !== $root . '/exports') {
                return null;
            }

            return $boundary;
        } catch (Throwable) {
            return null;
        }
    }

    public function bind(DatabaseConnection $connection): bool
    {
        try {
            $pdo = $connection->pdo();
            if ($this->connection !== null || $pdo === null
                || !str_contains(strtolower((string) $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)), 'unix socket')) {
                return false;
            }
            $server = $connection->fetchOne('SELECT DATABASE() AS schema_name, CURRENT_USER() AS principal, CURRENT_ROLE() AS active_role,
                @@skip_networking AS socket_only, @@socket AS socket_path, @@datadir AS data_path');
            $schema = 'timeapp_personnel_http_' . $this->fixtureId;
            if (($server['schema_name'] ?? null) !== $schema || ($server['principal'] ?? null) !== 'timeapp_ph_' . $this->fixtureId . '@localhost'
                || ($server['active_role'] ?? null) !== null || (int) ($server['socket_only'] ?? 0) !== 1
                || ($server['socket_path'] ?? null) !== $this->root . '/mariadb.sock'
                || rtrim((string) ($server['data_path'] ?? ''), '/') !== $this->root . '/database') {
                return false;
            }
            $target = '`' . str_replace('_', '\\_', $schema) . '`.*';
            $hasScratchAccess = false;
            foreach ($connection->fetchAll('SHOW GRANTS') as $grant) {
                $statement = (string) reset($grant);
                if (str_contains($statement, 'WITH GRANT OPTION')) {
                    return false;
                }
                if (str_starts_with($statement, 'GRANT USAGE ON *.* TO ')) {
                    continue;
                }
                if (!str_starts_with($statement, 'GRANT SELECT, INSERT, UPDATE, DELETE ON ' . $target . ' TO ')) {
                    return false;
                }
                $hasScratchAccess = true;
            }
            if ($hasScratchAccess) {
                $this->connection = $connection;
            }

            return $hasScratchAccess;
        } catch (Throwable) {
            return false;
        }
    }

    public function assertBound(DatabaseConnection $connection): void
    {
        if ($this->connection !== $connection || !$this->isLocalServer()) {
            throw new PersonnelFileUnavailable();
        }
    }

    public function sessionDirectory(): string
    {
        return $this->root . '/sessions';
    }

    public function sessionName(): string
    {
        return 'timeapp_ph_' . $this->fixtureId;
    }

    public function incomingDirectory(): string
    {
        return $this->root . '/incoming';
    }

    public function generalBackupDirectory(): string
    {
        return $this->root . '/general-backup';
    }

    public static function scannerDirectory(string $fixtureId): string
    {
        if (preg_match('/\A[a-f0-9]{12}\z/', $fixtureId) !== 1) {
            throw new PersonnelFileUnavailable();
        }

        return '/tmp/timeapp-personnel-clamd-' . $fixtureId;
    }

    public function origin(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** @param resource $stream */
    public function assertSyntheticUpload($stream, int $length): void
    {
        $hash = hash_init('sha256');
        $bytes = hash_update_stream($hash, $stream, $length + 1);
        if ($bytes !== $length || !in_array(hash_final($hash), $this->uploadHashes, true) || !rewind($stream)) {
            throw new PersonnelFileUnavailable('fixture');
        }
    }

    public function fileConfiguration(DatabaseConnection $connection): array
    {
        $this->assertBound($connection);
        $config = require config_path('personnel_storage.php');
        $scannerDirectory = self::scannerDirectory($this->fixtureId);

        return [
            'root' => $this->root . '/private-store',
            'keyring' => $this->root . '/file-keys.json',
            'scanner_socket' => $scannerDirectory . '/clamd.sock',
            'qpdf' => $this->qpdf,
            'forbidden_roots' => [...$config['forbidden_roots'], $this->sessionDirectory(), $this->root . '/database', $scannerDirectory],
        ];
    }

    private function isLocalServer(): bool
    {
        $listen = '127.0.0.1:' . $this->port;
        if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== $listen
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) !== $this->port
            || ($_SERVER['DOCUMENT_ROOT'] ?? '') !== base_path('public')
            || array_intersect(['HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP'], array_keys($_SERVER)) !== []) {
            return false;
        }

        $arguments = explode("\0", (string) @file_get_contents('/proc/self/cmdline'));
        $listenerIndex = array_search('-S', $arguments, true);

        return $listenerIndex !== false && ($arguments[$listenerIndex + 1] ?? null) === $listen;
    }
}
