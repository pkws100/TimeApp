<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Infrastructure\Database\DatabaseConnection;
use PDOException;
use RuntimeException;
use ZipArchive;

final class BackupService
{
    private const BACKUP_VERSION = 2;
    private const PERSONNEL_TRIGGER_MIGRATION = '20261118203000_harden_personnel_compliance_history';
    private const PREVIOUS_PERSONNEL_SCHEMA = '20261118202000_personnel_compliance_permissions';
    private const PERSONNEL_PHASE_TWO_SCHEMA = '20261118205000_personnel_phase_two_foundation';
    private const PHASE_TWO_PRIVATE_TABLES = ['employee_personnel_scope_events', 'personnel_command_receipts'];
    private const REDACTED_SECRET = '[redacted:backup-secret]';
    private const RUNTIME_OVERRIDE_EXCLUDED_WARNING = 'Eine Runtime-Datenbankkonfiguration ist vorhanden und benoetigt eine separate geschuetzte Sicherung.';
    private const LEGACY_RUNTIME_OVERRIDE_EXCLUDED_WARNING = 'storage/config/database.override.php wurde erkannt, aber nicht ins Backup-Archiv aufgenommen.';
    private const RETAINED_SENSITIVE_FIELDS_WARNING = 'Das Backup enthaelt betriebsnotwendige sensible Datenbankfelder und muss wie ein Secret behandelt werden.';
    private const REDACTED_FIELDS_WARNING = 'Einige bekannte Secret-Felder wurden im Datenbank-JSON redigiert.';
    private const PERSONNEL_ROWS_EXCLUDED_WARNING = 'Personenbezogene Zeilen der Personalakten-Tabellen sind aus dem Systembackup ausgeschlossen.';
    private const PRIVATE_BACKUP_REQUIRED_WARNING = 'Personalaktendaten sind vorhanden und benoetigen eine separate geschuetzte Sicherung.';
    private const VERSIONED_BACKUP_REQUIRED_WARNING = 'Systemversionierte Daten und ihre Historie benoetigen eine separate Sicherung.';
    private const LEGACY_COVERAGE_WARNING = 'Bei diesem aelteren Backup ist der ausgeschlossene Datenbestand unbekannt.';
    private const PERSONNEL_PRIVATE_TABLES = [
        'employee_employment_contexts',
        'employee_documents',
        'employee_document_versions',
        'employee_document_audit',
        'employee_document_access_log',
        ...self::PHASE_TWO_PRIVATE_TABLES,
    ];
    private const PERSONNEL_CATALOG_TABLES = [
        'personnel_compliance_sources',
        'personnel_document_types',
        'personnel_document_requirements',
    ];
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

    public function export(bool $requireComplete = false): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die ZIP-Erweiterung ist nicht verfuegbar.');
        }

        $workspace = $this->backupRoot . '/backup_' . bin2hex(random_bytes(12));
        $zipPath = $workspace . '/backup.zip';
        $databaseDir = $workspace . '/database';
        $uploadsRoot = rtrim((string) ($this->uploadConfig['root'] ?? storage_path('app/uploads')), '/');
        $runtimeOverride = $this->runtimeOverridePath;

        try {
            $this->ensureDirectory($databaseDir);
            [$databaseFiles, $personnelData, $systemVersionedData] = $this->connection->transaction(fn (): array => $this->collectDatabase($databaseDir));
            $hasRuntimeOverride = is_file($runtimeOverride);
            $manifest = $this->buildManifest($databaseFiles, [], $hasRuntimeOverride, $personnelData, $systemVersionedData);
            $status = $manifest['coverage']['status'];
            if ($requireComplete && $status !== 'complete_for_scope') {
                throw new RuntimeException('Vollstaendige Sicherung angefordert. Ausgeschlossene Datenbereiche benoetigen eine separate Sicherung.');
            }

            $uploadFiles = $this->collectFiles($uploadsRoot, 'uploads');
            $manifest['uploads'] = $this->writeArchive($zipPath, $workspace, $databaseFiles, $uploadFiles);
            $this->addArchiveManifest($zipPath, $manifest);
            $content = file_get_contents($zipPath);
            if ($content === false) {
                throw new RuntimeException('Das Backup-Archiv konnte nicht gelesen werden.');
            }
        } catch (PDOException | \JsonException) {
            throw new RuntimeException('Die Datenbank konnte nicht fuer das Systembackup gelesen werden.');
        } finally {
            $this->cleanup($workspace, $zipPath);
        }

        $timestamp = (new \DateTimeImmutable())->format('Ymd-His');
        $filename = $status === 'partial' ? 'system-backup-partial-' : 'system-backup-';

        return [
            'content' => $content,
            'headers' => [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $filename . $timestamp . '.zip"',
                'X-TimeApp-Backup-Coverage' => $status,
                'X-TimeApp-Backup-Security' => $manifest['security']['status'],
            ],
        ];
    }

    private function collectDatabase(string $databaseDir): array
    {
        $this->assertCurrentSchemaApplied($this->schemaVersion());
        $tables = $this->applicationTables();
        if ($tables === []) {
            throw new RuntimeException('Die Datenbank ist fuer das Systembackup nicht verfuegbar.');
        }

        $versionedTables = $this->databaseSource->systemVersionedTables();
        $this->assertCoverageTablesDiscoverable($tables, $versionedTables);
        $personnel = $this->anyRows(array_intersect(self::PERSONNEL_PRIVATE_TABLES, $tables));
        $systemVersioned = $this->anyRows($versionedTables, true);
        $files = [];
        foreach ($tables as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
                throw new RuntimeException('Die Datenbank enthaelt einen fuer das Backup ungeeigneten Tabellennamen.');
            }
            if ($table !== strtolower($table) && in_array(strtolower($table), self::PERSONNEL_PRIVATE_TABLES, true)) {
                throw new RuntimeException('Die Datenbank enthaelt eine unerwartete Variante einer Personalakten-Tabelle.');
            }

            $rows = in_array($table, self::PERSONNEL_PRIVATE_TABLES, true) ? [] : $this->databaseSource->fetchRows($table);
            $sanitized = $this->sanitizeDatabaseRows($table, $rows);
            $json = json_encode($sanitized['rows'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($databaseDir . '/' . $table . '.json', $json) !== strlen($json)) {
                throw new RuntimeException('Eine Datenbank-Datei konnte fuer das Backup nicht geschrieben werden.');
            }

            $files[] = [
                'table' => $table,
                'filename' => 'database/' . $table . '.json',
                'rows' => count($rows),
                'size_bytes' => strlen($json),
                'sha256' => hash('sha256', $json),
                'redacted_fields' => $sanitized['redactions'],
            ];
        }

        return [$files, $personnel, $systemVersioned];
    }

    private function assertCoverageTablesDiscoverable(array $tables, array $versionedTables): void
    {
        if ($this->connection->isAvailable()
            && (array_diff([...self::PERSONNEL_PRIVATE_TABLES, ...self::PERSONNEL_CATALOG_TABLES], $tables) !== []
                || !in_array('timesheets', $versionedTables, true))) {
            throw new RuntimeException('Das installierte Datenbankschema ist fuer das Systembackup nicht vollstaendig sichtbar.');
        }
    }

    private function anyRows(array $tables, bool $includeHistory = false): bool
    {
        foreach ($tables as $table) {
            if ($this->databaseSource->hasRows($table, $includeHistory)) {
                return true;
            }
        }

        return false;
    }

    private function writeArchive(string $zipPath, string $workspace, array $databaseFiles, array $uploadFiles): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Das Backup-Archiv konnte nicht erzeugt werden.');
        }

        try {
            foreach ($databaseFiles as $file) {
                if (!$zip->addFile($workspace . '/' . $file['filename'], $file['filename'])) {
                    throw new RuntimeException('Eine Datenbank-Datei konnte nicht zum Backup hinzugefuegt werden.');
                }
            }
            foreach ($uploadFiles as $file) {
                if (!$zip->addFile($file['path'], $file['name'])) {
                    throw new RuntimeException('Eine Upload-Datei konnte nicht zum Backup hinzugefuegt werden.');
                }
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('Das Backup-Archiv konnte nicht geschrieben werden.');
            }
        }

        // Uploads can change while the ZIP is built. Describe the archived bytes.
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Das Backup-Archiv konnte nicht gelesen werden.');
        }
        try {
            $entries = array_map(fn (array $file): array => $this->archiveFileMetadata($zip, $file['name']), $uploadFiles);
            return $this->uploadManifest($entries);
        } finally {
            $zip->close();
        }
    }

    private function addArchiveManifest(string $zipPath, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Das Backup-Archiv konnte nicht gelesen werden.');
        }
        $added = $zip->addFromString('manifest.json', $json);
        if (!$zip->close() || !$added) {
            throw new RuntimeException('Das Backup-Manifest konnte nicht geschrieben werden.');
        }
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

        try {
            $archiveEntries = $this->archiveEntries($zip);
            $this->assertArchiveEntries($zip, $archiveEntries);
            $manifestContent = $this->readArchiveJson($zip, 'manifest.json', 'Im Backup fehlt die Datei manifest.json.');
            try {
                $manifest = json_decode($manifestContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('Das Backup-Manifest ist ungueltiges JSON.');
            }
            if (!is_array($manifest)) {
                throw new RuntimeException('Das Backup-Manifest ist ungueltig.');
            }
            $validation = $this->validateManifest($manifest, $zip, $archiveEntries);
        } catch (PDOException) {
            throw new RuntimeException('Das aktuelle Datenbankschema konnte nicht fuer das Systembackup geprueft werden.');
        } finally {
            $zip->close();
        }

        return [
            'ok' => true,
            'validation_status' => 'valid',
            'dry_run' => true,
            'apply_supported' => false,
            ...$validation,
            'restore_plan' => [
                'database' => 'JSON-Dateien wurden gegen Manifest und Schema im Dry-Run validiert; es wird nichts importiert.',
                'uploads' => 'Upload-Dateien wurden nur als sichere Restore-Kandidaten geprueft; es wird nichts extrahiert.',
                'runtime' => 'Runtime-Overrides werden nur erkannt und nicht automatisch angewendet.',
                'apply_gate' => 'Ein produktiver Restore-Apply ist bewusst nicht implementiert und muss separat freigegeben werden.',
            ],
        ];
    }

    public function buildManifest(
        array $databaseFiles,
        array $uploadFiles,
        bool $hasRuntimeOverride,
        bool $personnelData = false,
        bool $systemVersionedData = false,
    ): array {
        $redactedFields = $this->manifestRedactedFields($databaseFiles);
        $retainedSensitiveFields = $this->retainedSensitiveFields($databaseFiles);
        $excludedPersonnelTables = $this->excludedPersonnelTables($databaseFiles);
        $security = $this->securityMetadata($redactedFields, $retainedSensitiveFields, $excludedPersonnelTables, $hasRuntimeOverride);
        $coverage = BackupCoverage::describe($personnelData, $systemVersionedData, $hasRuntimeOverride, $redactedFields !== []);
        $warnings = $security['warnings'];
        if ($personnelData) {
            $warnings[] = self::PRIVATE_BACKUP_REQUIRED_WARNING;
        }
        if ($systemVersionedData) {
            $warnings[] = self::VERSIONED_BACKUP_REQUIRED_WARNING;
        }
        $security['warnings'] = $warnings;
        $security['status'] = $warnings === [] ? 'clear' : 'warning';

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
                        'sha256' => (string) ($file['sha256'] ?? ''),
                        'redacted_fields' => $this->tableRedactionManifest($file['redacted_fields'] ?? []),
                    ],
                    $databaseFiles
                ),
            ],
            'uploads' => $this->uploadManifest($uploadFiles),
            'runtime' => [
                'database_override_detected' => $hasRuntimeOverride,
                'database_override_included' => false,
                'database_override_policy' => $hasRuntimeOverride ? 'excluded_sensitive_runtime_config' : 'not_present',
            ],
            'security' => $security,
            'coverage' => $coverage,
            'compatibility' => [
                'import_apply_supported' => false,
                'validate_supported' => true,
            ],
        ];
    }

    private function securityMetadata(array $redactedFields, array $retainedSensitiveFields, array $excludedPersonnelTables, bool $hasRuntimeOverride): array
    {
        return [
            'runtime_secret_policy' => 'exclude_runtime_overrides',
            'database_secret_fields' => $this->secretFieldPolicies(),
            'retained_sensitive_database_fields' => $retainedSensitiveFields,
            'redacted_database_fields' => $redactedFields,
            'excluded_database_table_rows' => $excludedPersonnelTables,
            'warnings' => array_values(array_filter([
                $hasRuntimeOverride ? self::RUNTIME_OVERRIDE_EXCLUDED_WARNING : null,
                $retainedSensitiveFields !== [] ? self::RETAINED_SENSITIVE_FIELDS_WARNING : null,
                $redactedFields !== [] ? self::REDACTED_FIELDS_WARNING : null,
                $excludedPersonnelTables !== [] ? self::PERSONNEL_ROWS_EXCLUDED_WARNING : null,
            ])),
        ];
    }

    private function uploadManifest(array $entries): array
    {
        return [
            'files' => count($entries),
            'size_bytes' => array_sum(array_column($entries, 'size_bytes')),
            'entries' => $entries,
        ];
    }

    private function applicationTables(): array
    {
        return $this->databaseSource->applicationTables();
    }

    private function excludedPersonnelTables(array $tables): array
    {
        return array_values(array_intersect(self::PERSONNEL_PRIVATE_TABLES, array_column($tables, 'table')));
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
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isLink()) {
                throw new RuntimeException('Symbolische Verknuepfungen im Upload-Bereich koennen nicht in das Systembackup aufgenommen werden.');
            }
            if (!$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $name = $prefix . '/' . $relative;
            $this->assertSafeArchivePath($name);
            $files[] = [
                'path' => $file->getPathname(),
                'name' => $name,
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

    private function assertCurrentSchemaApplied(string $schemaVersion): void
    {
        if (!$this->connection->isAvailable()) {
            return;
        }

        $applied = $this->connection->fetchColumn('SELECT MAX(version) FROM phinxlog');
        if ((string) $applied !== explode('_', $schemaVersion, 2)[0]) {
            throw new RuntimeException('Der installierte Datenbankstand passt nicht zur Software-Version des Systembackups.');
        }
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
        $version = $manifest['backup_version'];
        if (!in_array($version, [1, self::BACKUP_VERSION], true)) {
            throw new RuntimeException('Die Backup-Version wird nicht unterstuetzt.');
        }
        $legacy = $version === 1;
        $createdAt = $manifest['created_at'];
        if (!is_string($createdAt) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $createdAt)) {
            throw new RuntimeException('Im Backup-Manifest ist created_at ungueltig.');
        }

        $schemaVersion = $manifest['schema_version'];
        if (!is_string($schemaVersion) || $schemaVersion === '') {
            throw new RuntimeException('Im Backup-Manifest ist schema_version ungueltig.');
        }
        $currentSchema = $this->schemaVersion();
        $previousPersonnelSchema = ($legacy && $schemaVersion === self::PREVIOUS_PERSONNEL_SCHEMA
                && in_array($currentSchema, [self::PERSONNEL_TRIGGER_MIGRATION, self::PERSONNEL_PHASE_TWO_SCHEMA], true))
            || (!$legacy && $schemaVersion === self::PERSONNEL_TRIGGER_MIGRATION && $currentSchema === self::PERSONNEL_PHASE_TWO_SCHEMA);
        if ($schemaVersion !== $currentSchema && !$previousPersonnelSchema) {
            throw new RuntimeException('Die Backup-Schema-Version passt nicht zum aktuellen System.');
        }
        $this->assertCurrentSchemaApplied($currentSchema);

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

        $databaseTables = $this->validateDatabaseFiles($tables, $zip, $legacy);
        $databaseFiles = array_column($databaseTables, 'filename');
        $this->assertExpectedTables(
            $databaseTables,
            !$legacy || $previousPersonnelSchema,
            $previousPersonnelSchema && $currentSchema === self::PERSONNEL_PHASE_TWO_SCHEMA ? self::PHASE_TWO_PRIVATE_TABLES : []
        );
        $this->assertPersonnelArchiveEntries($archiveEntries, $databaseFiles);

        $uploadFiles = $this->uploadEntries($archiveEntries);
        $uploads = $this->validateUploadManifest($manifest['uploads'], $uploadFiles, $zip, $legacy);
        $runtimeFiles = $this->runtimeEntries($archiveEntries);
        $runtime = $this->validateRuntimeEntries($runtimeFiles, $manifest['runtime'], $legacy);

        $declaredPaths = ['manifest.json', ...$databaseFiles, ...$uploadFiles, ...$runtimeFiles];
        if (array_diff($archiveEntries, $declaredPaths) !== []) {
            throw new RuntimeException('Das Backup enthaelt nicht deklarierte Dateien.');
        }

        $excludedPersonnelTables = $this->excludedPersonnelTables($databaseTables);
        $redactedFields = $this->manifestRedactedFields($databaseTables);
        $security = $this->securityMetadata(
            $redactedFields,
            $this->retainedSensitiveFields($databaseTables),
            $excludedPersonnelTables,
            $runtime['database_override_detected'] && !$runtime['database_override_included']
        );

        if ($legacy) {
            // Older archives do not disclose the data state of the excluded scopes.
            $coverage = BackupCoverage::describe(
                $excludedPersonnelTables === [] ? false : null,
                null,
                $runtime['database_override_detected'] && !$runtime['database_override_included'],
                $redactedFields !== []
            );
            $security['warnings'] = [
                ...$security['warnings'],
                ...$this->knownSecurityWarnings($manifest['security']['warnings'] ?? []),
                self::LEGACY_COVERAGE_WARNING,
            ];
        } else {
            $coverage = BackupCoverage::validate(
                $manifest['coverage'] ?? null,
                $excludedPersonnelTables !== [],
                $runtime['database_override_detected'],
                $redactedFields !== []
            );
            if ($coverage['excluded_areas']['private_personnel_records']['data_present']) {
                $security['warnings'][] = self::PRIVATE_BACKUP_REQUIRED_WARNING;
            }
            if ($coverage['excluded_areas']['system_versioned_tables']['data_present']) {
                $security['warnings'][] = self::VERSIONED_BACKUP_REQUIRED_WARNING;
            }
        }

        $security['status'] = $security['warnings'] === [] ? 'clear' : 'warning';
        if (!$legacy && (!is_array($manifest['security'] ?? null)
            || ($manifest['security']['status'] ?? null) !== $security['status']
            || ($manifest['security']['redacted_database_fields'] ?? null) !== $redactedFields
            || ($manifest['security']['excluded_database_table_rows'] ?? null) !== $excludedPersonnelTables)) {
            throw new RuntimeException('Die Sicherheitsangaben passen nicht zu den Backup-Metadaten.');
        }

        foreach ($databaseTables as &$table) {
            $table['redacted_fields'] = $this->tableRedactionManifest($table['redacted_fields']);
        }
        unset($table);
        $compatibility = ['import_apply_supported' => false, 'validate_supported' => true];

        return [
            'coverage' => $coverage,
            'coverage_evidence' => $legacy ? 'legacy_unknown' : 'archive_manifest_only',
            'manifest' => [
                'backup_version' => $version,
                'schema_version' => $schemaVersion,
                'created_at' => $createdAt,
                'database' => ['tables' => $databaseTables],
                'uploads' => $uploads,
                'runtime' => $runtime,
                'security' => $security,
                'compatibility' => $compatibility,
                'coverage' => $coverage,
            ],
            'database_files' => $databaseFiles,
            'upload_files' => $uploadFiles,
            'runtime_files' => $runtimeFiles,
            'warnings' => array_values(array_unique(array_filter([
                ...$security['warnings'],
                $runtime['database_override_included']
                    ? 'runtime/database.override.php ist in diesem aelteren Backup enthalten, wird aber im Validate-Dry-Run nicht angewendet.'
                    : null,
                'Restore-Apply ist nicht implementiert; dieser Endpunkt validiert nur.',
            ]))),
        ];
    }

    private function validateDatabaseFiles(array $tables, ZipArchive $zip, bool $legacy): array
    {
        $databaseFiles = [];
        foreach ($tables as $table) {
            if (!is_array($table) || !is_string($table['table'] ?? null) || !preg_match('/^[A-Za-z0-9_]+$/D', $table['table'])) {
                throw new RuntimeException('Ein Tabellenname im Backup-Manifest ist ungueltig.');
            }

            $tableName = $table['table'];
            $filename = $table['filename'] ?? null;
            if (!is_string($filename)) {
                throw new RuntimeException('Ein Datenbankpfad im Backup-Manifest ist ungueltig.');
            }
            $this->assertSafeArchivePath($filename);
            if ($filename !== 'database/' . $tableName . '.json' || isset($databaseFiles[$tableName])) {
                throw new RuntimeException('Ein Datenbankpfad passt nicht zum Tabellennamen oder wurde doppelt deklariert.');
            }
            if (!is_int($table['rows'] ?? null) || !is_int($table['size_bytes'] ?? null) || $table['rows'] < 0 || $table['size_bytes'] < 0) {
                throw new RuntimeException('Zeilen- oder Groessenangaben im Backup-Manifest sind ungueltig.');
            }

            $personnelTable = in_array(strtolower($tableName), self::PERSONNEL_PRIVATE_TABLES, true);
            $content = $this->readArchiveJson($zip, $filename, 'Eine deklarierte Datenbank-Datei fehlt im Backup.');
            try {
                $rows = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('Eine Datenbank-Datei enthaelt ungueltiges JSON.');
            }
            if (!is_array($rows)) {
                throw new RuntimeException('Eine Datenbank-Datei muss ein JSON-Array enthalten.');
            }
            if ($personnelTable && ($rows !== [] || $table['rows'] !== 0)) {
                throw new RuntimeException('Das Systembackup akzeptiert Personalakten-Tabellen nur ohne personenbezogene Zeilen.');
            }

            $redactions = $this->validateDatabaseRows($tableName, $rows, $legacy);
            if (count($rows) !== $table['rows']) {
                throw new RuntimeException('Die Zeilenanzahl passt nicht zum Manifest.');
            }

            $metadata = [
                'table' => $tableName,
                'filename' => $filename,
                'rows' => count($rows),
                'size_bytes' => strlen($content),
                'redacted_fields' => $redactions,
            ];
            if (!$legacy) {
                $metadata['sha256'] = hash('sha256', $content);
                if ($table['size_bytes'] !== $metadata['size_bytes'] || ($table['sha256'] ?? null) !== $metadata['sha256']
                    || ($table['redacted_fields'] ?? null) !== $this->tableRedactionManifest($redactions)) {
                    throw new RuntimeException('Die Integritaetsangaben einer Datenbank-Datei passen nicht zum Archiv.');
                }
            }
            $databaseFiles[$tableName] = $metadata;
        }

        return array_values($databaseFiles);
    }

    private function validateDatabaseRows(string $table, array $rows, bool $legacy): array
    {
        $policies = self::SECRET_FIELD_POLICIES[$table] ?? [];
        $redactions = [];
        foreach ($rows as $row) {
            if (!$row instanceof \stdClass) {
                throw new RuntimeException('Eine Datenbank-Datei enthaelt ungueltige Zeilen.');
            }

            foreach ($policies as $field => $policy) {
                $value = $row->{$field} ?? null;
                if ($value === null || $value === '' || $policy === 'retain-sensitive') {
                    continue;
                }
                if ($value === self::REDACTED_SECRET) {
                    $redactions[$field] = ($redactions[$field] ?? 0) + 1;
                } elseif (!$legacy && (!is_string($value) || $this->shouldRedactDatabaseValue($policy, $value))) {
                    throw new RuntimeException('Eine Datenbank-Datei enthaelt ein nicht zulaessiges Secret-Feld.');
                }
            }
        }

        return $redactions;
    }

    private function assertExpectedTables(array $tables, bool $requireSchema, array $tablesAddedSinceArchive): void
    {
        $expectedTables = $this->applicationTables();
        if ($expectedTables === []) {
            if ($requireSchema) {
                throw new RuntimeException('Das aktuelle Datenbankschema konnte nicht fuer das Systembackup geprueft werden.');
            }
            return;
        }
        $this->assertCoverageTablesDiscoverable($expectedTables, $this->databaseSource->systemVersionedTables());

        $manifestTables = array_column($tables, 'table');
        $expectedTables = array_values(array_diff($expectedTables, $tablesAddedSinceArchive));
        sort($manifestTables);
        sort($expectedTables);
        if ($manifestTables !== $expectedTables) {
            throw new RuntimeException('Die Tabellenliste im Backup passt nicht zum aktuellen System.');
        }
    }

    private function assertPersonnelArchiveEntries(array $archiveEntries, array $databaseFiles): void
    {
        $seen = [];
        foreach ($archiveEntries as $filename) {
            $table = strtolower(pathinfo($filename, PATHINFO_FILENAME));
            if (!str_starts_with($filename, 'database/') || !in_array($table, self::PERSONNEL_PRIVATE_TABLES, true)) {
                continue;
            }

            if (isset($seen[$table]) || $filename !== 'database/' . $table . '.json' || !in_array($filename, $databaseFiles, true)) {
                throw new RuntimeException('Das Backup enthaelt eine nicht deklarierte oder doppelte Personalakten-Datei.');
            }
            $seen[$table] = true;
        }
    }

    private function validateUploadManifest(array $uploads, array $uploadFiles, ZipArchive $zip, bool $legacy): array
    {
        if (!array_key_exists('files', $uploads) || !array_key_exists('size_bytes', $uploads)) {
            throw new RuntimeException('Im Backup-Manifest fehlen Upload-Metadaten.');
        }

        if (!is_int($uploads['files']) || !is_int($uploads['size_bytes']) || $uploads['files'] !== count($uploadFiles) || $uploads['size_bytes'] < 0) {
            throw new RuntimeException('Die Upload-Metadaten passen nicht zum Backup-Archiv.');
        }

        if ($legacy) {
            return ['files' => $uploads['files'], 'size_bytes' => $uploads['size_bytes']];
        }

        $entries = $uploads['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || count($entries) !== count($uploadFiles)) {
            throw new RuntimeException('Die Upload-Metadaten passen nicht zum Backup-Archiv.');
        }

        $metadata = [];
        $remaining = array_fill_keys($uploadFiles, true);
        foreach ($entries as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            if (!is_string($name) || !isset($remaining[$name])) {
                throw new RuntimeException('Die Upload-Metadaten passen nicht zum Backup-Archiv.');
            }
            unset($remaining[$name]);
            $file = $this->archiveFileMetadata($zip, $name);
            if (($entry['size_bytes'] ?? null) !== $file['size_bytes'] || ($entry['sha256'] ?? null) !== $file['sha256']) {
                throw new RuntimeException('Die Integritaetsangaben einer Upload-Datei passen nicht zum Archiv.');
            }
            $metadata[] = $file;
        }
        $verified = $this->uploadManifest($metadata);
        if ($uploads['size_bytes'] !== $verified['size_bytes']) {
            throw new RuntimeException('Die Upload-Metadaten passen nicht zum Backup-Archiv.');
        }

        return $verified;
    }

    private function archiveFileMetadata(ZipArchive $zip, string $name): array
    {
        $stat = $zip->statName($name);
        $stream = @$zip->getStream($name);
        if ($stream === false) {
            throw new RuntimeException('Eine Datei im Backup-Archiv konnte nicht gelesen werden.');
        }

        try {
            $hash = hash_init('sha256');
            $size = @hash_update_stream($hash, $stream);
            if ($stat === false || $size !== $stat['size']) {
                throw new RuntimeException('Eine Datei im Backup-Archiv konnte nicht vollstaendig gelesen werden.');
            }

            return ['name' => $name, 'size_bytes' => $size, 'sha256' => hash_final($hash)];
        } finally {
            fclose($stream);
        }
    }

    private function readArchiveJson(ZipArchive $zip, string $name, string $missingMessage): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] <= 0) {
            throw new RuntimeException($missingMessage);
        }

        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($memoryLimit > 0 && $stat['size'] > $memoryLimit - memory_get_usage(true)) {
            throw new RuntimeException('Eine JSON-Datei ueberschreitet das Speicherbudget fuer die Backup-Validierung.');
        }

        $json = @$zip->getFromName($name, $stat['size']);
        if (!is_string($json) || strlen($json) !== $stat['size']) {
            throw new RuntimeException('Eine JSON-Datei im Backup konnte nicht vollstaendig gelesen werden.');
        }

        // JSON objects and list entries use more memory after decoding than their stored bytes.
        $containers = substr_count($json, '{') + substr_count($json, '[');
        $elements = substr_count($json, ',');
        if ($memoryLimit > 0 && $stat['size'] + $containers * 384 + $elements * 72 > $memoryLimit - memory_get_usage(true)) {
            throw new RuntimeException('Eine JSON-Datei ueberschreitet das Speicherbudget fuer die Backup-Validierung.');
        }

        return $json;
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

    private function validateRuntimeEntries(array $runtimeFiles, array $runtimeManifest, bool $legacy): array
    {
        foreach ($runtimeFiles as $runtimeFile) {
            if ($runtimeFile !== 'runtime/database.override.php') {
                throw new RuntimeException('Unbekannte Runtime-Datei im Backup.');
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

        $detected = $runtimeManifest['database_override_detected'] ?? $overrideIncluded;
        if (!is_bool($detected)) {
            throw new RuntimeException('Im Backup-Manifest ist runtime.database_override_detected ungueltig.');
        }
        $policy = match (true) {
            $overrideIncluded => 'included_legacy',
            $detected => 'excluded_sensitive_runtime_config',
            default => 'not_present',
        };
        if (!$legacy && ($overrideIncluded || ($runtimeManifest['database_override_detected'] ?? null) !== $detected
            || ($runtimeManifest['database_override_policy'] ?? null) !== $policy)) {
            throw new RuntimeException('Die Runtime-Angaben passen nicht zu den Backup-Metadaten.');
        }

        return [
            'database_override_detected' => $detected,
            'database_override_included' => $overrideIncluded,
            'database_override_policy' => $policy,
        ];
    }

    private function archiveEntries(ZipArchive $zip): array
    {
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[] = (string) $zip->getNameIndex($index);
        }

        return $entries;
    }

    private function assertArchiveEntries(ZipArchive $zip, array $archiveEntries): void
    {
        if (count($archiveEntries) !== count(array_unique($archiveEntries))) {
            throw new RuntimeException('Das Backup enthaelt doppelte Archivpfade.');
        }
        foreach ($archiveEntries as $index => $entry) {
            $this->assertSafeArchivePath($entry);
            if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                && in_array($operatingSystem, [ZipArchive::OPSYS_UNIX, ZipArchive::OPSYS_OS_X], true)
                && !in_array(($attributes >> 16) & 0170000, [0, 0100000], true)) {
                throw new RuntimeException('Das Backup enthaelt einen Archiveintrag, der keine regulaere Datei ist.');
            }
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
            throw new RuntimeException('Unsicherer Pfad im Backup-Archiv.');
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '..' || $segment === '.') {
                throw new RuntimeException('Unsicherer Pfad im Backup-Archiv.');
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

    private function retainedSensitiveFields(array $tables): array
    {
        $fields = [];
        $populatedTables = array_column(array_filter($tables, static fn (array $table): bool => ($table['rows'] ?? 0) > 0), 'table');

        foreach (self::SECRET_FIELD_POLICIES as $table => $policies) {
            if (!in_array($table, $populatedTables, true)) {
                continue;
            }
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
            self::LEGACY_RUNTIME_OVERRIDE_EXCLUDED_WARNING,
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
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Das Arbeitsverzeichnis fuer das Backup konnte nicht angelegt werden.');
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
