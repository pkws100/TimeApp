<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;
use ZipArchive;

final class BackupService
{
    private const BACKUP_VERSION = 1;

    public function __construct(
        private DatabaseConnection $connection,
        private array $uploadConfig
    ) {
    }

    public function export(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die ZIP-Erweiterung ist nicht verfuegbar.');
        }

        $workspace = storage_path('cache/backups/' . uniqid('backup_', true));
        $zipPath = $workspace . '.zip';
        $databaseDir = $workspace . '/database';
        $uploadsRoot = rtrim((string) ($this->uploadConfig['root'] ?? storage_path('app/uploads')), '/');
        $runtimeOverride = storage_path('config/database.override.php');

        $this->ensureDirectory($workspace);
        $this->ensureDirectory($databaseDir);

        $tables = $this->applicationTables();
        $databaseFiles = [];

        foreach ($tables as $table) {
            $filename = $databaseDir . '/' . $table . '.json';
            $rows = $this->connection->fetchAll('SELECT * FROM `' . $table . '`');
            file_put_contents(
                $filename,
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            $databaseFiles[] = [
                'table' => $table,
                'filename' => 'database/' . $table . '.json',
                'rows' => count($rows),
                'size_bytes' => filesize($filename) ?: 0,
            ];
        }

        $uploadFiles = $this->collectFiles($uploadsRoot, 'uploads');
        $runtimeFiles = is_file($runtimeOverride) ? [['path' => $runtimeOverride, 'name' => 'runtime/database.override.php']] : [];
        $manifest = $this->buildManifest($databaseFiles, $uploadFiles, $runtimeFiles !== []);
        $manifestPath = $workspace . '/manifest.json';
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->cleanup($workspace, $zipPath);

            throw new RuntimeException('Das Backup-Archiv konnte nicht erzeugt werden.');
        }

        $zip->addFile($manifestPath, 'manifest.json');

        foreach ($databaseFiles as $file) {
            $zip->addFile($workspace . '/' . $file['filename'], $file['filename']);
        }

        foreach ($uploadFiles as $file) {
            $zip->addFile($file['path'], $file['name']);
        }

        foreach ($runtimeFiles as $file) {
            $zip->addFile($file['path'], $file['name']);
        }

        $zip->close();

        $content = file_get_contents($zipPath);

        if ($content === false) {
            $this->cleanup($workspace, $zipPath);

            throw new RuntimeException('Das Backup-Archiv konnte nicht gelesen werden.');
        }

        $this->cleanup($workspace, $zipPath);
        $timestamp = (new \DateTimeImmutable())->format('Ymd-His');

        return [
            'content' => $content,
            'headers' => [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="system-backup-' . $timestamp . '.zip"',
            ],
        ];
    }

    public function validateImport(array $file): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die ZIP-Erweiterung ist nicht verfuegbar.');
        }

        $path = (string) ($file['tmp_name'] ?? '');

        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Es wurde keine gueltige Backup-Datei uebergeben.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Das Backup-Archiv konnte nicht geoeffnet werden.');
        }

        $manifestContent = $zip->getFromName('manifest.json');

        if (!is_string($manifestContent) || trim($manifestContent) === '') {
            $zip->close();

            throw new RuntimeException('Im Backup fehlt die Datei manifest.json.');
        }

        $manifest = json_decode($manifestContent, true);

        if (!is_array($manifest)) {
            $zip->close();

            throw new RuntimeException('Das Backup-Manifest ist ungueltig.');
        }

        $this->assertManifest($manifest);

        $databaseEntries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, 'database/') && str_ends_with($name, '.json')) {
                $databaseEntries[] = $name;
            }
        }

        $zip->close();

        return [
            'ok' => true,
            'manifest' => $manifest,
            'database_files' => $databaseEntries,
            'restore_plan' => [
                'database' => 'JSON-Dateien wuerden tabellenweise validiert und spaeter importiert.',
                'uploads' => 'Upload-Dateien sind im Archiv enthalten und koennen spaeter geschuetzt zurueckgespielt werden.',
                'runtime' => 'Runtime-Overrides werden aktuell nur validiert, nicht automatisch angewendet.',
            ],
        ];
    }

    public function buildManifest(array $databaseFiles, array $uploadFiles, bool $hasRuntimeOverride): array
    {
        return [
            'backup_version' => self::BACKUP_VERSION,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'schema_version' => $this->schemaVersion(),
            'database' => [
                'tables' => array_map(
                    static fn (array $file): array => [
                        'table' => (string) ($file['table'] ?? ''),
                        'filename' => (string) ($file['filename'] ?? ''),
                        'rows' => (int) ($file['rows'] ?? 0),
                        'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                    ],
                    $databaseFiles
                ),
            ],
            'uploads' => [
                'files' => count($uploadFiles),
                'size_bytes' => array_sum(array_map(static fn (array $file): int => (int) ($file['size_bytes'] ?? 0), $uploadFiles)),
            ],
            'runtime' => [
                'database_override_included' => $hasRuntimeOverride,
            ],
            'compatibility' => [
                'import_apply_supported' => false,
                'validate_supported' => true,
            ],
        ];
    }

    private function applicationTables(): array
    {
        if (!$this->connection->isAvailable()) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_type = "BASE TABLE"
             ORDER BY table_name ASC'
        );

        return array_values(
            array_filter(
                array_map(static fn (array $row): string => (string) ($row['table_name'] ?? ''), $rows),
                static fn (string $table): bool => $table !== ''
            )
        );
    }

    private function collectFiles(string $root, string $prefix): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $files = [];

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $files[] = [
                'path' => $file->getPathname(),
                'name' => $prefix . '/' . str_replace('\\', '/', $relative),
                'size_bytes' => $file->getSize(),
            ];
        }

        return $files;
    }

    private function schemaVersion(): string
    {
        $migrations = glob(base_path('migrations/*.php')) ?: [];

        if ($migrations === []) {
            return 'unversioned';
        }

        sort($migrations);

        return pathinfo((string) end($migrations), PATHINFO_FILENAME);
    }

    private function assertManifest(array $manifest): void
    {
        foreach (['backup_version', 'created_at', 'schema_version', 'database', 'uploads', 'runtime', 'compatibility'] as $key) {
            if (!array_key_exists($key, $manifest)) {
                throw new RuntimeException('Im Backup-Manifest fehlt der Schluessel "' . $key . '".');
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $path);
        }
    }

    private function cleanup(string $workspace, string $zipPath): void
    {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        if (!is_dir($workspace)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($workspace);
    }
}
