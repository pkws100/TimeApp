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

        $archiveEntries = $this->archiveEntries($zip);
        $manifestContent = $zip->getFromName('manifest.json');

        if (!is_string($manifestContent) || trim($manifestContent) === '') {
            $zip->close();

            throw new RuntimeException('Im Backup fehlt die Datei manifest.json.');
        }

        try {
            $manifest = json_decode($manifestContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $zip->close();

            throw new RuntimeException('Das Backup-Manifest ist ungueltiges JSON.');
        }

        if (!is_array($manifest)) {
            $zip->close();

            throw new RuntimeException('Das Backup-Manifest ist ungueltig.');
        }

        $this->assertArchiveEntries($archiveEntries);
        $validation = $this->validateManifest($manifest, $zip, $archiveEntries);

        $databaseEntries = $validation['database_files'];
        $uploadEntries = $validation['upload_files'];
        $runtimeEntries = $validation['runtime_files'];
        $warnings = $validation['warnings'];

        $zip->close();

        return [
            'ok' => true,
            'dry_run' => true,
            'apply_supported' => false,
            'manifest' => $manifest,
            'database_files' => $databaseEntries,
            'upload_files' => $uploadEntries,
            'runtime_files' => $runtimeEntries,
            'warnings' => $warnings,
            'restore_plan' => [
                'database' => 'JSON-Dateien wurden gegen Manifest und Schema im Dry-Run validiert; es wird nichts importiert.',
                'uploads' => 'Upload-Dateien wurden nur als sichere Restore-Kandidaten geprueft; es wird nichts extrahiert.',
                'runtime' => 'Runtime-Overrides werden nur erkannt und nicht automatisch angewendet.',
                'apply_gate' => 'Ein produktiver Restore-Apply ist bewusst nicht implementiert und muss separat freigegeben werden.',
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

    private function validateManifest(array $manifest, ZipArchive $zip, array $archiveEntries): array
    {
        $this->assertManifest($manifest);

        if (!is_int($manifest['backup_version']) || $manifest['backup_version'] !== self::BACKUP_VERSION) {
            throw new RuntimeException('Die Backup-Version wird nicht unterstuetzt.');
        }

        if (!is_string($manifest['created_at']) || trim($manifest['created_at']) === '') {
            throw new RuntimeException('Im Backup-Manifest ist created_at ungueltig.');
        }

        $schemaVersion = (string) ($manifest['schema_version'] ?? '');

        if ($schemaVersion === '') {
            throw new RuntimeException('Im Backup-Manifest ist schema_version ungueltig.');
        }

        if ($schemaVersion !== $this->schemaVersion()) {
            throw new RuntimeException('Die Backup-Schema-Version passt nicht zum aktuellen System.');
        }

        foreach (['database', 'uploads', 'runtime', 'compatibility'] as $section) {
            if (!is_array($manifest[$section])) {
                throw new RuntimeException('Im Backup-Manifest ist "' . $section . '" ungueltig.');
            }
        }

        $this->validateCompatibility($manifest['compatibility']);
        $tables = $manifest['database']['tables'] ?? null;

        if (!is_array($tables) || !array_is_list($tables)) {
            throw new RuntimeException('Im Backup-Manifest ist database.tables ungueltig.');
        }

        $databaseFiles = $this->validateDatabaseFiles($tables, $zip);
        $this->assertExpectedTables($tables);

        $uploadFiles = $this->uploadEntries($archiveEntries);
        $this->validateUploadManifest($manifest['uploads'], $uploadFiles);
        $runtimeFiles = $this->runtimeEntries($archiveEntries);
        $this->validateRuntimeEntries($runtimeFiles);

        return [
            'database_files' => $databaseFiles,
            'upload_files' => $uploadFiles,
            'runtime_files' => $runtimeFiles,
            'warnings' => array_values(array_filter([
                ($manifest['runtime']['database_override_included'] ?? false)
                    ? 'runtime/database.override.php ist enthalten, wird aber im Validate-Dry-Run nicht angewendet.'
                    : null,
                'Restore-Apply ist nicht implementiert; dieser Endpunkt validiert nur.',
            ])),
        ];
    }

    private function validateDatabaseFiles(array $tables, ZipArchive $zip): array
    {
        $databaseFiles = [];

        foreach ($tables as $table) {
            if (!is_array($table)) {
                throw new RuntimeException('Ein Tabelleneintrag im Backup-Manifest ist ungueltig.');
            }

            $tableName = (string) ($table['table'] ?? '');
            $filename = (string) ($table['filename'] ?? '');

            if ($tableName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
                throw new RuntimeException('Ein Tabellenname im Backup-Manifest ist ungueltig.');
            }

            $this->assertSafeArchivePath($filename);

            if (!str_starts_with($filename, 'database/') || !str_ends_with($filename, '.json')) {
                throw new RuntimeException('Eine Datenbank-Datei im Backup-Manifest liegt nicht unter database/*.json.');
            }

            if (!is_int($table['rows'] ?? null) || !is_int($table['size_bytes'] ?? null) || $table['rows'] < 0 || $table['size_bytes'] < 0) {
                throw new RuntimeException('Zeilen- oder Groessenangaben im Backup-Manifest sind ungueltig.');
            }

            $content = $zip->getFromName($filename);

            if (!is_string($content)) {
                throw new RuntimeException('Eine deklarierte Datenbank-Datei fehlt im Backup: ' . $filename);
            }

            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('Eine Datenbank-Datei enthaelt ungueltiges JSON: ' . $filename);
            }

            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new RuntimeException('Eine Datenbank-Datei muss ein JSON-Array enthalten: ' . $filename);
            }

            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('Eine Datenbank-Datei enthaelt ungueltige Zeilen: ' . $filename);
                }
            }

            if (count($decoded) !== $table['rows']) {
                throw new RuntimeException('Die Zeilenanzahl passt nicht zum Manifest: ' . $filename);
            }

            $databaseFiles[] = $filename;
        }

        return $databaseFiles;
    }

    private function assertExpectedTables(array $tables): void
    {
        $expectedTables = $this->applicationTables();

        if ($expectedTables === []) {
            return;
        }

        $manifestTables = array_map(static fn (array $table): string => (string) ($table['table'] ?? ''), $tables);
        sort($manifestTables);
        sort($expectedTables);

        if ($manifestTables !== $expectedTables) {
            throw new RuntimeException('Die Tabellenliste im Backup passt nicht zum aktuellen System.');
        }
    }

    private function validateUploadManifest(array $uploads, array $uploadFiles): void
    {
        if (!array_key_exists('files', $uploads) || !array_key_exists('size_bytes', $uploads)) {
            throw new RuntimeException('Im Backup-Manifest fehlen Upload-Metadaten.');
        }

        if (!is_int($uploads['files']) || !is_int($uploads['size_bytes']) || $uploads['files'] !== count($uploadFiles) || $uploads['size_bytes'] < 0) {
            throw new RuntimeException('Die Upload-Metadaten passen nicht zum Backup-Archiv.');
        }
    }

    private function validateCompatibility(array $compatibility): void
    {
        if (!array_key_exists('import_apply_supported', $compatibility) || !array_key_exists('validate_supported', $compatibility)) {
            throw new RuntimeException('Im Backup-Manifest fehlen Kompatibilitaetsangaben.');
        }

        if ($compatibility['validate_supported'] !== true || $compatibility['import_apply_supported'] !== false) {
            throw new RuntimeException('Die Backup-Kompatibilitaetsangaben werden nicht unterstuetzt.');
        }
    }

    private function validateRuntimeEntries(array $runtimeFiles): void
    {
        foreach ($runtimeFiles as $runtimeFile) {
            if ($runtimeFile !== 'runtime/database.override.php') {
                throw new RuntimeException('Unbekannte Runtime-Datei im Backup: ' . $runtimeFile);
            }
        }
    }

    private function archiveEntries(ZipArchive $zip): array
    {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }

        return $entries;
    }

    private function assertArchiveEntries(array $archiveEntries): void
    {
        foreach ($archiveEntries as $entry) {
            $this->assertSafeArchivePath($entry);
        }
    }

    private function uploadEntries(array $archiveEntries): array
    {
        return array_values(array_filter(
            $archiveEntries,
            static fn (string $entry): bool => str_starts_with($entry, 'uploads/')
        ));
    }

    private function runtimeEntries(array $archiveEntries): array
    {
        return array_values(array_filter(
            $archiveEntries,
            static fn (string $entry): bool => str_starts_with($entry, 'runtime/')
        ));
    }

    private function assertSafeArchivePath(string $path): void
    {
        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new RuntimeException('Unsicherer Pfad im Backup-Archiv: ' . $path);
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '..' || $segment === '.') {
                throw new RuntimeException('Unsicherer Pfad im Backup-Archiv: ' . $path);
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
