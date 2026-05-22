<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;
use ZipArchive;

final class BackupService
{
    private const BACKUP_VERSION = 1;
    private const REDACTED_SECRET = '[redacted:backup-secret]';
    private const RUNTIME_OVERRIDE_EXCLUDED_WARNING = 'storage/config/database.override.php wurde erkannt, aber nicht ins Backup-Archiv aufgenommen.';
    private const RETAINED_SENSITIVE_FIELDS_WARNING = 'Das Backup enthaelt betriebsnotwendige sensible Datenbankfelder und muss wie ein Secret behandelt werden.';
    private const REDACTED_FIELDS_WARNING = 'Einige bekannte Secret-Felder wurden im Datenbank-JSON redigiert.';
    private const SECRET_FIELD_POLICIES = [
        'company_settings' => [
            'smtp_password' => 'keep-encrypted-redact-legacy-cleartext',
        ],
        'users' => [
            'password_hash' => 'retain-sensitive',
        ],
        'push_subscriptions' => [
            'endpoint' => 'retain-sensitive',
            'endpoint_hash' => 'retain-sensitive',
            'public_key' => 'retain-sensitive',
            'auth_token' => 'retain-sensitive',
        ],
    ];
    private BackupDatabaseSource $databaseSource;
    private string $backupRoot;
    private string $runtimeOverridePath;

    public function __construct(
        private DatabaseConnection $connection,
        private array $uploadConfig,
        ?BackupDatabaseSource $databaseSource = null,
        ?string $backupRoot = null,
        ?string $runtimeOverridePath = null
    ) {
        $this->databaseSource = $databaseSource ?? new PdoBackupDatabaseSource($this->connection);
        $this->backupRoot = rtrim($backupRoot ?? storage_path('cache/backups'), '/');
        $this->runtimeOverridePath = $runtimeOverridePath ?? storage_path('config/database.override.php');
    }

    public function export(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die ZIP-Erweiterung ist nicht verfuegbar.');
        }

        $workspace = $this->backupRoot . '/' . uniqid('backup_', true);
        $zipPath = $workspace . '.zip';
        $databaseDir = $workspace . '/database';
        $uploadsRoot = rtrim((string) ($this->uploadConfig['root'] ?? storage_path('app/uploads')), '/');
        $runtimeOverride = $this->runtimeOverridePath;

        $this->ensureDirectory($workspace);
        $this->ensureDirectory($databaseDir);

        $tables = $this->applicationTables();
        $databaseFiles = [];

        foreach ($tables as $table) {
            $filename = $databaseDir . '/' . $table . '.json';
            $sanitized = $this->sanitizeDatabaseRows($table, $this->databaseSource->fetchRows($table));
            $rows = $sanitized['rows'];
            file_put_contents(
                $filename,
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
            $databaseFiles[] = [
                'table' => $table,
                'filename' => 'database/' . $table . '.json',
                'rows' => count($rows),
                'size_bytes' => filesize($filename) ?: 0,
                'redacted_fields' => $sanitized['redactions'],
            ];
        }

        $uploadFiles = $this->collectFiles($uploadsRoot, 'uploads');
        $hasRuntimeOverride = is_file($runtimeOverride);
        $runtimeFiles = [];
        $manifest = $this->buildManifest($databaseFiles, $uploadFiles, $hasRuntimeOverride);
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
        $redactedFields = $this->manifestRedactedFields($databaseFiles);
        $retainedSensitiveFields = $this->retainedSensitiveFields();

        return [
            'backup_version' => self::BACKUP_VERSION,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'schema_version' => $this->schemaVersion(),
            'database' => [
                'tables' => array_map(
                    fn (array $file): array => [
                        'table' => (string) ($file['table'] ?? ''),
                        'filename' => (string) ($file['filename'] ?? ''),
                        'rows' => (int) ($file['rows'] ?? 0),
                        'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                        'redacted_fields' => $this->tableRedactionManifest($file['redacted_fields'] ?? []),
                    ],
                    $databaseFiles
                ),
            ],
            'uploads' => [
                'files' => count($uploadFiles),
                'size_bytes' => array_sum(array_map(static fn (array $file): int => (int) ($file['size_bytes'] ?? 0), $uploadFiles)),
            ],
            'runtime' => [
                'database_override_detected' => $hasRuntimeOverride,
                'database_override_included' => false,
                'database_override_policy' => $hasRuntimeOverride ? 'excluded_sensitive_runtime_config' : 'not_present',
            ],
            'security' => [
                'runtime_secret_policy' => 'exclude_runtime_overrides',
                'database_secret_fields' => $this->secretFieldPolicies(),
                'retained_sensitive_database_fields' => $retainedSensitiveFields,
                'redacted_database_fields' => $redactedFields,
                'warnings' => array_values(array_filter([
                    $hasRuntimeOverride ? self::RUNTIME_OVERRIDE_EXCLUDED_WARNING : null,
                    $retainedSensitiveFields !== [] ? self::RETAINED_SENSITIVE_FIELDS_WARNING : null,
                    $redactedFields !== [] ? self::REDACTED_FIELDS_WARNING : null,
                ])),
            ],
            'compatibility' => [
                'import_apply_supported' => false,
                'validate_supported' => true,
            ],
        ];
    }

    private function applicationTables(): array
    {
        return $this->databaseSource->applicationTables();
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
        $this->validateRuntimeEntries($runtimeFiles, $manifest['runtime']);
        $securityWarnings = $this->knownSecurityWarnings($manifest['security']['warnings'] ?? []);

        return [
            'database_files' => $databaseFiles,
            'upload_files' => $uploadFiles,
            'runtime_files' => $runtimeFiles,
            'warnings' => array_values(array_unique(array_filter([
                ...$securityWarnings,
                ($manifest['runtime']['database_override_detected'] ?? false) && !($manifest['runtime']['database_override_included'] ?? false)
                    ? self::RUNTIME_OVERRIDE_EXCLUDED_WARNING
                    : null,
                ($manifest['runtime']['database_override_included'] ?? false)
                    ? 'runtime/database.override.php ist in diesem aelteren Backup enthalten, wird aber im Validate-Dry-Run nicht angewendet.'
                    : null,
                'Restore-Apply ist nicht implementiert; dieser Endpunkt validiert nur.',
            ]))),
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

    private function validateRuntimeEntries(array $runtimeFiles, array $runtimeManifest): void
    {
        foreach ($runtimeFiles as $runtimeFile) {
            if ($runtimeFile !== 'runtime/database.override.php') {
                throw new RuntimeException('Unbekannte Runtime-Datei im Backup: ' . $runtimeFile);
            }
        }

        $overrideIncluded = $runtimeManifest['database_override_included'] ?? false;

        if (!is_bool($overrideIncluded)) {
            throw new RuntimeException('Im Backup-Manifest ist runtime.database_override_included ungueltig.');
        }

        if ($runtimeFiles !== [] && !$overrideIncluded) {
            throw new RuntimeException('Das Backup enthaelt Runtime-Dateien, die nicht im Manifest freigegeben sind.');
        }

        if ($runtimeFiles === [] && $overrideIncluded) {
            throw new RuntimeException('Das Backup-Manifest meldet eine Runtime-Override-Datei, aber sie fehlt im Archiv.');
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

    private function sanitizeDatabaseRows(string $table, array $rows): array
    {
        $policies = self::SECRET_FIELD_POLICIES[$table] ?? [];
        $redactions = [];

        if ($policies === []) {
            return ['rows' => $rows, 'redactions' => []];
        }

        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($policies as $field => $policy) {
                if (!array_key_exists($field, $row) || $row[$field] === null || (string) $row[$field] === '') {
                    continue;
                }

                if ($this->shouldRedactDatabaseValue($policy, (string) $row[$field])) {
                    $row[$field] = self::REDACTED_SECRET;
                    $redactions[$field] = ($redactions[$field] ?? 0) + 1;
                }
            }
        }
        unset($row);

        return ['rows' => $rows, 'redactions' => $redactions];
    }

    private function shouldRedactDatabaseValue(string $policy, string $value): bool
    {
        return match ($policy) {
            'keep-encrypted-redact-legacy-cleartext' => !str_starts_with($value, 'enc:v1:'),
            'retain-sensitive' => false,
            'redact' => true,
            default => true,
        };
    }

    private function manifestRedactedFields(array $databaseFiles): array
    {
        $fields = [];

        foreach ($databaseFiles as $file) {
            foreach ($this->tableRedactionManifest($file['redacted_fields'] ?? []) as $field) {
                $fields[] = [
                    'table' => (string) ($file['table'] ?? ''),
                    ...$field,
                ];
            }
        }

        return $fields;
    }

    private function tableRedactionManifest(mixed $redactions): array
    {
        if (!is_array($redactions)) {
            return [];
        }

        $fields = [];

        foreach ($redactions as $field => $count) {
            if ((int) $count <= 0) {
                continue;
            }

            $fields[] = [
                'field' => (string) $field,
                'rows' => (int) $count,
            ];
        }

        return $fields;
    }

    private function secretFieldPolicies(): array
    {
        $fields = [];

        foreach (self::SECRET_FIELD_POLICIES as $table => $policies) {
            foreach ($policies as $field => $policy) {
                $fields[] = [
                    'table' => $table,
                    'field' => $field,
                    'policy' => $policy,
                ];
            }
        }

        return $fields;
    }

    private function retainedSensitiveFields(): array
    {
        $fields = [];

        foreach (self::SECRET_FIELD_POLICIES as $table => $policies) {
            foreach ($policies as $field => $policy) {
                if ($policy !== 'retain-sensitive') {
                    continue;
                }

                $fields[] = [
                    'table' => $table,
                    'field' => $field,
                    'policy' => $policy,
                ];
            }
        }

        return $fields;
    }

    private function knownSecurityWarnings(mixed $warnings): array
    {
        if (!is_array($warnings)) {
            return [];
        }

        $allowed = [
            self::RUNTIME_OVERRIDE_EXCLUDED_WARNING,
            self::RETAINED_SENSITIVE_FIELDS_WARNING,
            self::REDACTED_FIELDS_WARNING,
        ];

        return array_values(array_filter(
            $warnings,
            static fn (mixed $warning): bool => is_string($warning) && in_array($warning, $allowed, true)
        ));
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
