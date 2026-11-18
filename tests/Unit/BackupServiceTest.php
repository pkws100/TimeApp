<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Backup\BackupDatabaseSource;
use App\Domain\Backup\BackupService;
use App\Http\Controllers\BackupController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class BackupServiceTest extends TestCase
{
    public function testBuildManifestContainsCompatibilityMetadata(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);

        $manifest = $service->buildManifest(
            [['table' => 'users', 'filename' => 'database/users.json', 'rows' => 3, 'size_bytes' => 120]],
            [['name' => 'uploads/example.txt', 'size_bytes' => 25]],
            true
        );

        self::assertSame(2, $manifest['backup_version']);
        self::assertSame('database/users.json', $manifest['database']['tables'][0]['filename']);
        self::assertSame(1, $manifest['uploads']['files']);
        self::assertTrue($manifest['runtime']['database_override_detected']);
        self::assertFalse($manifest['runtime']['database_override_included']);
        self::assertSame('excluded_sensitive_runtime_config', $manifest['runtime']['database_override_policy']);
        self::assertSame('exclude_runtime_overrides', $manifest['security']['runtime_secret_policy']);
        self::assertContains([
            'table' => 'company_settings',
            'field' => 'smtp_password',
            'policy' => 'keep-encrypted-redact-legacy-cleartext',
        ], $manifest['security']['database_secret_fields']);
        self::assertContains([
            'table' => 'users',
            'field' => 'password_hash',
            'policy' => 'retain-sensitive',
        ], $manifest['security']['database_secret_fields']);
        self::assertContains([
            'table' => 'users',
            'field' => 'password_hash',
            'policy' => 'retain-sensitive',
        ], $manifest['security']['retained_sensitive_database_fields']);
        self::assertNotContains([
            'table' => 'push_subscriptions',
            'field' => 'endpoint',
            'policy' => 'retain-sensitive',
        ], $manifest['security']['retained_sensitive_database_fields']);
        self::assertFalse($manifest['compatibility']['import_apply_supported']);
        self::assertSame('partial', $manifest['coverage']['status']);
        self::assertTrue($manifest['coverage']['excluded_areas']['runtime_database_override']['data_present']);
        self::assertSame('warning', $manifest['security']['status']);
    }

    public function testBuildManifestListsRedactedDatabaseFields(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);

        $manifest = $service->buildManifest(
            [[
                'table' => 'push_subscriptions',
                'filename' => 'database/push_subscriptions.json',
                'rows' => 2,
                'size_bytes' => 200,
                'redacted_fields' => ['auth_token' => 2],
            ]],
            [],
            false
        );

        self::assertSame([['field' => 'auth_token', 'rows' => 2]], $manifest['database']['tables'][0]['redacted_fields']);
        self::assertSame(
            [['table' => 'push_subscriptions', 'field' => 'auth_token', 'rows' => 2]],
            $manifest['security']['redacted_database_fields']
        );
        self::assertContains('Einige bekannte Secret-Felder wurden im Datenbank-JSON redigiert.', $manifest['security']['warnings']);
    }

    public function testValidateImportReadsManifestFromZip(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $zipPath = $this->zipWithManifest($this->validManifest());

        $result = $service->validateImport(['tmp_name' => $zipPath]);

        @unlink($zipPath);

        self::assertTrue($result['ok']);
        self::assertTrue($result['dry_run']);
        self::assertFalse($result['apply_supported']);
        self::assertSame(1, $result['manifest']['backup_version']);
        self::assertSame('unknown', $result['coverage']['status']);
        self::assertSame('legacy_unknown', $result['coverage_evidence']);
        self::assertSame(['database/users.json'], $result['database_files']);
        self::assertSame(['uploads/example.txt'], $result['upload_files']);
        self::assertArrayHasKey('restore_plan', $result);
    }

    public function testValidateImportAcceptsCompleteAccountJournalBackup(): void
    {
        $tables = ['employee_account_cutovers', 'time_account_entries', 'users', 'vacation_account_entries'];
        $service = $this->backupServiceExpectingTables($tables);
        $manifest = $this->validManifest(['database' => ['tables' => $this->manifestTables($tables)]]);
        $zipPath = $this->zipWithManifest($manifest, $this->databaseFilesForTables($tables), false);

        try {
            $result = $service->validateImport(['tmp_name' => $zipPath]);

            self::assertTrue($result['ok']);
            self::assertSame([
                'database/employee_account_cutovers.json',
                'database/time_account_entries.json',
                'database/users.json',
                'database/vacation_account_entries.json',
            ], $result['database_files']);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportRejectsMissingAccountJournalTable(): void
    {
        $expectedTables = ['employee_account_cutovers', 'time_account_entries', 'users', 'vacation_account_entries'];
        $manifestTables = ['employee_account_cutovers', 'users', 'vacation_account_entries'];
        $service = $this->backupServiceExpectingTables($expectedTables);
        $manifest = $this->validManifest(['database' => ['tables' => $this->manifestTables($manifestTables)]]);
        $zipPath = $this->zipWithManifest($manifest, $this->databaseFilesForTables($manifestTables), false);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Die Tabellenliste im Backup passt nicht zum aktuellen System.');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportWarnsAboutExcludedRuntimeOverride(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $manifest = $this->validManifest([
            'runtime' => [
                'database_override_detected' => true,
                'database_override_included' => false,
                'database_override_policy' => 'excluded_sensitive_runtime_config',
            ],
            'security' => [
                'warnings' => ['storage/config/database.override.php wurde erkannt, aber nicht ins Backup-Archiv aufgenommen.'],
            ],
        ]);
        $zipPath = $this->zipWithManifest($manifest);

        $result = $service->validateImport(['tmp_name' => $zipPath]);

        @unlink($zipPath);

        self::assertSame([], $result['runtime_files']);
        self::assertContains(
            'storage/config/database.override.php wurde erkannt, aber nicht ins Backup-Archiv aufgenommen.',
            $result['warnings']
        );
    }

    public function testValidateImportDoesNotEchoUnknownManifestSecurityWarnings(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $manifest = $this->validManifest([
            'security' => [
                'warnings' => [
                    'storage/config/database.override.php wurde erkannt, aber nicht ins Backup-Archiv aufgenommen.',
                    'secret-from-manipulated-manifest',
                ],
            ],
        ]);
        $zipPath = $this->zipWithManifest($manifest);

        $result = $service->validateImport(['tmp_name' => $zipPath]);

        @unlink($zipPath);

        self::assertContains(
            'storage/config/database.override.php wurde erkannt, aber nicht ins Backup-Archiv aufgenommen.',
            $result['warnings']
        );
        self::assertNotContains('secret-from-manipulated-manifest', $result['warnings']);
        self::assertStringNotContainsString('secret-from-manipulated-manifest', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testExportRedactsLegacyPlainSecretsKeepsOperationalSecretsAndExcludesRuntimeOverride(): void
    {
        $this->requireZipArchive();
        $uploadsRoot = $this->temporaryDirectory('backup-uploads-');
        $backupRoot = $this->temporaryDirectory('backup-cache-');
        $runtimeDir = $this->temporaryDirectory('backup-runtime-');
        $runtimeOverride = $runtimeDir . '/database.override.php';

        file_put_contents($runtimeOverride, '<?php return ["password" => "runtime-secret"];');

        $service = new BackupService(
            new DatabaseConnection([]),
            ['root' => $uploadsRoot],
            new class implements BackupDatabaseSource {
                public function applicationTables(): array
                {
                    return ['company_settings', 'push_subscriptions', 'users'];
                }

                public function systemVersionedTables(): array
                {
                    return [];
                }

                public function hasRows(string $table, bool $includeHistory = false): bool
                {
                    TestCase::fail('Only excluded scopes may be probed for row existence.');
                }

                public function fetchRows(string $table): array
                {
                    return match ($table) {
                        'company_settings' => [
                            ['id' => 1, 'smtp_password' => 'legacy-smtp-secret'],
                            ['id' => 1, 'smtp_password' => 'enc:v1:stored-secret'],
                        ],
                        'push_subscriptions' => [
                            [
                                'id' => 7,
                                'endpoint' => 'https://push.example.invalid/subscription',
                                'endpoint_hash' => 'endpoint-hash',
                                'public_key' => 'push-public-key',
                                'auth_token' => 'push-auth-token',
                            ],
                        ],
                        'users' => [
                            ['id' => 3, 'email' => 'admin@example.invalid', 'password_hash' => '$2y$10$hash'],
                        ],
                        default => [],
                    };
                }
            },
            $backupRoot,
            $runtimeOverride
        );

        try {
            $export = $service->export();
            $zipPath = tempnam(sys_get_temp_dir(), 'backup-export-test-');
            file_put_contents($zipPath, $export['content']);
            $validation = $service->validateImport(['tmp_name' => $zipPath]);
            self::assertSame('partial', $validation['coverage']['status']);
            self::assertSame('archive_manifest_only', $validation['coverage_evidence']);
            self::assertTrue($validation['coverage']['excluded_areas']['redacted_database_values']['data_present']);

            $zip = new ZipArchive();
            $zip->open($zipPath);

            try {
                self::assertFalse($zip->locateName('runtime/database.override.php') !== false);

                $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                $companySettingsRows = json_decode((string) $zip->getFromName('database/company_settings.json'), true, 512, JSON_THROW_ON_ERROR);
                $pushRows = json_decode((string) $zip->getFromName('database/push_subscriptions.json'), true, 512, JSON_THROW_ON_ERROR);
                $userRows = json_decode((string) $zip->getFromName('database/users.json'), true, 512, JSON_THROW_ON_ERROR);

                self::assertTrue($manifest['runtime']['database_override_detected']);
                self::assertFalse($manifest['runtime']['database_override_included']);
                self::assertSame('[redacted:backup-secret]', $companySettingsRows[0]['smtp_password']);
                self::assertSame('enc:v1:stored-secret', $companySettingsRows[1]['smtp_password']);
                self::assertSame('https://push.example.invalid/subscription', $pushRows[0]['endpoint']);
                self::assertSame('endpoint-hash', $pushRows[0]['endpoint_hash']);
                self::assertSame('push-public-key', $pushRows[0]['public_key']);
                self::assertSame('push-auth-token', $pushRows[0]['auth_token']);
                self::assertSame('$2y$10$hash', $userRows[0]['password_hash']);
                self::assertContains(
                    ['table' => 'users', 'field' => 'password_hash', 'policy' => 'retain-sensitive'],
                    $manifest['security']['retained_sensitive_database_fields']
                );
                self::assertContains(
                    self::retainedSensitiveFieldsWarning(),
                    $manifest['security']['warnings']
                );
            } finally {
                $zip->close();
                @unlink($zipPath);
            }
        } finally {
            @unlink($runtimeOverride);
            @rmdir($runtimeDir);
            @rmdir($uploadsRoot);
            @rmdir($backupRoot);
        }
    }

    public function testValidateImportRejectsRuntimeOverrideNotDeclaredInManifest(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $zipPath = $this->zipWithManifest($this->validManifest(), [
            'runtime/database.override.php' => '<?php return ["password" => "secret"];',
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Das Backup enthaelt Runtime-Dateien, die nicht im Manifest freigegeben sind.');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportStillAcceptsDeclaredLegacyRuntimeOverrideAsDryRun(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $manifest = $this->validManifest([
            'runtime' => ['database_override_included' => true],
        ]);
        $zipPath = $this->zipWithManifest($manifest, [
            'runtime/database.override.php' => '<?php return ["password" => "secret"];',
        ]);

        $result = $service->validateImport(['tmp_name' => $zipPath]);

        @unlink($zipPath);

        self::assertSame(['runtime/database.override.php'], $result['runtime_files']);
        self::assertContains(
            'runtime/database.override.php ist in diesem aelteren Backup enthalten, wird aber im Validate-Dry-Run nicht angewendet.',
            $result['warnings']
        );
    }

    public function testDatabaseSecretSanitizerRedactsPlainSecretsButKeepsEncryptedSettingsSecrets(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $method = new \ReflectionMethod($service, 'sanitizeDatabaseRows');
        $method->setAccessible(true);

        $companySettings = $method->invoke($service, 'company_settings', [
            ['id' => 1, 'smtp_password' => 'legacy-secret'],
            ['id' => 1, 'smtp_password' => 'enc:v1:already-encrypted'],
            ['id' => 1, 'smtp_password' => ''],
        ]);
        $pushSubscriptions = $method->invoke($service, 'push_subscriptions', [
            [
                'id' => 5,
                'endpoint' => 'https://push.example.invalid/subscription',
                'endpoint_hash' => 'endpoint-hash',
                'public_key' => 'browser-public-key',
                'auth_token' => 'browser-auth-token',
            ],
        ]);

        self::assertSame('[redacted:backup-secret]', $companySettings['rows'][0]['smtp_password']);
        self::assertSame('enc:v1:already-encrypted', $companySettings['rows'][1]['smtp_password']);
        self::assertSame('', $companySettings['rows'][2]['smtp_password']);
        self::assertSame(['smtp_password' => 1], $companySettings['redactions']);
        self::assertSame('https://push.example.invalid/subscription', $pushSubscriptions['rows'][0]['endpoint']);
        self::assertSame('endpoint-hash', $pushSubscriptions['rows'][0]['endpoint_hash']);
        self::assertSame('browser-public-key', $pushSubscriptions['rows'][0]['public_key']);
        self::assertSame('browser-auth-token', $pushSubscriptions['rows'][0]['auth_token']);
        self::assertSame([], $pushSubscriptions['redactions']);
    }

    public function testValidateImportRejectsInvalidZip(): void
    {
        $this->requireZipArchive();
        $service = new BackupService(new DatabaseConnection([]), []);
        $path = tempnam(sys_get_temp_dir(), 'backup-test-');
        file_put_contents($path, 'kein zip');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Das Backup-Archiv konnte nicht geoeffnet werden.');

            $service->validateImport(['tmp_name' => $path]);
        } finally {
            @unlink($path);
        }
    }

    public function testValidateImportRejectsMissingManifest(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $zipPath = $this->zipWithFiles(['database/users.json' => '[]']);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Im Backup fehlt die Datei manifest.json.');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportRejectsMissingManifestKeys(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $manifest = $this->validManifest();
        unset($manifest['schema_version']);
        $zipPath = $this->zipWithManifest($manifest);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Im Backup-Manifest fehlt der Schluessel "schema_version".');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportRejectsWrongBackupVersion(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $manifest = $this->validManifest(['backup_version' => 99]);
        $zipPath = $this->zipWithManifest($manifest);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Die Backup-Version wird nicht unterstuetzt.');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportRejectsSchemaMismatch(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $manifest = $this->validManifest(['schema_version' => '20200101000000_old_schema']);
        $zipPath = $this->zipWithManifest($manifest);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Die Backup-Schema-Version passt nicht zum aktuellen System.');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportRejectsPathTraversalInUploadFilename(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $zipPath = $this->zipWithManifest($this->validManifest(), [
            'uploads/../evil.txt' => 'x',
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unsicherer Pfad im Backup-Archiv');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportRejectsPathTraversalInDatabaseManifestFilename(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $manifest = $this->validManifest([
            'database' => [
                'tables' => [[
                    'table' => 'users',
                    'filename' => 'database/../users.json',
                    'rows' => 0,
                    'size_bytes' => 2,
                ]],
            ],
        ]);
        $zipPath = $this->zipWithManifest($manifest, [], false);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unsicherer Pfad im Backup-Archiv');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateImportRejectsInvalidDatabaseJson(): void
    {
        $service = new BackupService(new DatabaseConnection([]), []);
        $zipPath = $this->zipWithManifest($this->validManifest(), [
            'database/users.json' => '{"not":"a list"}',
        ], false);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Eine Datenbank-Datei muss ein JSON-Array enthalten');

            $service->validateImport(['tmp_name' => $zipPath]);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testExportAndValidateACompleteScopeWithNoPrivateRowsOrOtherKnownExclusions(): void
    {
        $this->withCurrentArchive(static function (BackupService $service, string $path, array $export): void {
            self::assertSame('complete_for_scope', $export['headers']['X-TimeApp-Backup-Coverage']);
            self::assertSame('clear', $export['headers']['X-TimeApp-Backup-Security']);
            self::assertSame('no-store', $export['headers']['Cache-Control']);

            $result = $service->validateImport(['tmp_name' => $path]);
            self::assertSame('valid', $result['validation_status']);
            self::assertSame('complete_for_scope', $result['coverage']['status']);
            self::assertSame('archive_manifest_only', $result['coverage_evidence']);
            self::assertSame([
                'data_present' => false,
                'separate_backup_required' => false,
            ], $result['coverage']['excluded_areas']['private_personnel_records']);
            self::assertFalse($result['apply_supported']);
            self::assertSame(['uploads/example.txt'], $result['upload_files']);
        });
    }

    public function testValidateRejectsChangedDatabaseBytesWithTheSameSizeAndRowCount(): void
    {
        $this->withCurrentArchive(static function (BackupService $service, string $path): void {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            $content = $zip->getFromName('database/project_files.json');
            self::assertIsString($content);
            self::assertTrue($zip->addFromString('database/project_files.json', str_replace('synthetic-original', 'synthetic-modified', $content)));
            self::assertTrue($zip->close());

            self::expectExceptionMessageForValidation($service, $path, 'Die Integritaetsangaben einer Datenbank-Datei passen nicht zum Archiv.');
        });
    }

    public function testValidateRejectsChangedUploadBytesWithTheSameSize(): void
    {
        $this->withCurrentArchive(static function (BackupService $service, string $path): void {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            self::assertTrue($zip->addFromString('uploads/example.txt', 'synthetic-modified'));
            self::assertTrue($zip->close());

            self::expectExceptionMessageForValidation($service, $path, 'Die Integritaetsangaben einer Upload-Datei passen nicht zum Archiv.');
        });
    }

    public function testValidateRejectsASymbolicLinkArchiveEntry(): void
    {
        $this->withCurrentArchive(static function (BackupService $service, string $path): void {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            self::assertTrue($zip->setExternalAttributesName('uploads/example.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16));
            self::assertTrue($zip->close());

            self::expectExceptionMessageForValidation($service, $path, 'Das Backup enthaelt einen Archiveintrag, der keine regulaere Datei ist.');
        });
    }

    public function testValidateRejectsJsonThatWouldExceedThePhpMemoryBudget(): void
    {
        $service = $this->backupServiceExpectingTables(['users']);
        $path = $this->zipWithManifest($this->validManifest(), [
            'database/users.json' => '[' . str_repeat('{},', 100000) . '{}]',
            'uploads/example.txt' => '',
        ], false);
        $memoryLimit = ini_get('memory_limit');
        try {
            ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));
            self::expectExceptionMessageForValidation($service, $path, 'Eine JSON-Datei ueberschreitet das Speicherbudget fuer die Backup-Validierung.');
        } finally {
            ini_set('memory_limit', (string) $memoryLimit);
            unlink($path);
        }
    }

    public function testValidateRejectsInconsistentCoverageAndReturnsAnInvalidStatus(): void
    {
        $this->withCurrentArchive(static function (BackupService $service, string $path): void {
            $zip = new ZipArchive();
            self::assertTrue($zip->open($path));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $manifest['coverage']['excluded_areas']['private_personnel_records']['separate_backup_required'] = true;
            $manifest['security']['warnings'][] = 'synthetic-private-text';
            self::assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
            self::assertTrue($zip->close());

            $controller = new BackupController($service);
            $response = $controller->validateImport(new Request('POST', '/', [], [], [], ['backup' => ['tmp_name' => $path]], []));
            self::assertSame(422, $response->status());
            self::expectExceptionMessageForValidation($service, $path, 'Der Sicherungsumfang passt nicht zu den Backup-Metadaten.');
        });
    }

    public function testStrictExportAndOrdinaryExportTreatPrivateRecordsDifferentlyWithoutReadingRows(): void
    {
        $this->withCurrentArchive(static function (BackupService $service, string $path, array $export): void {
            self::assertSame('partial', $export['headers']['X-TimeApp-Backup-Coverage']);
            self::assertSame('warning', $export['headers']['X-TimeApp-Backup-Security']);
            self::assertStringStartsWith('attachment; filename="system-backup-partial-', $export['headers']['Content-Disposition']);

            $result = $service->validateImport(['tmp_name' => $path]);
            self::assertSame('partial', $result['coverage']['status']);
            self::assertSame([
                'data_present' => true,
                'separate_backup_required' => true,
            ], $result['coverage']['excluded_areas']['private_personnel_records']);

            $controller = new BackupController($service);
            $request = new Request('GET', '/', ['require_complete' => '1'], [], [], [], []);
            self::assertSame(503, $controller->export($request)->status());
        }, withPrivateRows: true);
    }

    public function testExportRejectsSymlinksInTheUploadScope(): void
    {
        $directory = $this->temporaryDirectory('backup-symlink-');
        try {
            $private = $directory . '/private';
            self::assertNotFalse(file_put_contents($private, 'synthetic-private-text'));
            self::assertTrue(mkdir($directory . '/uploads'));
            self::assertTrue(symlink($private, $directory . '/uploads/linked.txt'));
            $service = $this->backupServiceExpectingTables(['project_files'], $directory);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Symbolische Verknuepfungen im Upload-Bereich');
            $service->export();
        } finally {
            unlink($directory . '/uploads/linked.txt');
            rmdir($directory . '/uploads');
            unlink($directory . '/private');
            rmdir($directory . '/backups');
            rmdir($directory);
        }
    }

    private static function expectExceptionMessageForValidation(BackupService $service, string $path, string $message): void
    {
        try {
            $service->validateImport(['tmp_name' => $path]);
            self::fail('An inconsistent archive passed validation.');
        } catch (\RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function withCurrentArchive(callable $assertions, bool $withPrivateRows = false): void
    {
        $this->requireZipArchive();
        $directory = $this->temporaryDirectory('backup-coverage-');
        $path = $directory . '/export.zip';
        mkdir($directory . '/uploads');
        file_put_contents($directory . '/uploads/example.txt', 'synthetic-original');
        try {
            $tables = $withPrivateRows ? ['employee_document_audit', 'project_files'] : ['project_files'];
            $service = $this->backupServiceExpectingTables($tables, $directory, $withPrivateRows);
            $export = $service->export(requireComplete: !$withPrivateRows);
            file_put_contents($path, $export['content']);
            $assertions($service, $path, $export);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            unlink($directory . '/uploads/example.txt');
            rmdir($directory . '/uploads');
            rmdir($directory . '/backups');
            rmdir($directory);
        }
    }

    private function validManifest(array $overrides = []): array
    {
        return [
            'backup_version' => 1,
            'created_at' => '2026-05-16T10:00:00+00:00',
            'schema_version' => $this->currentSchemaVersion(),
            'database' => [
                'tables' => [[
                    'table' => 'users',
                    'filename' => 'database/users.json',
                    'rows' => 0,
                    'size_bytes' => 2,
                ]],
            ],
            'uploads' => ['files' => 1, 'size_bytes' => 0],
            'runtime' => ['database_override_included' => false],
            'compatibility' => ['import_apply_supported' => false, 'validate_supported' => true],
            ...$overrides,
        ];
    }

    private function zipWithManifest(array $manifest, array $extraFiles = [], bool $includeDefaultFiles = true): string
    {
        $files = ['manifest.json' => json_encode($manifest, JSON_THROW_ON_ERROR)];

        if ($includeDefaultFiles) {
            $files += [
                'database/users.json' => '[]',
                'uploads/example.txt' => '',
            ];
        }

        return $this->zipWithFiles($files + $extraFiles);
    }

    /**
     * @param list<string> $tables
     */
    private function backupServiceExpectingTables(array $tables, ?string $directory = null, bool $withPrivateRows = false): BackupService
    {
        return new BackupService(
            new DatabaseConnection([]),
            $directory !== null ? ['root' => $directory . '/uploads'] : [],
            new class ($tables, $withPrivateRows) implements BackupDatabaseSource {
                /**
                 * @param list<string> $tables
                 */
                public function __construct(private array $tables, private bool $withPrivateRows)
                {
                }

                public function applicationTables(): array
                {
                    return $this->tables;
                }

                public function fetchRows(string $table): array
                {
                    TestCase::assertNotSame('employee_document_audit', $table, 'No private rows may be passed through the standard reader.');
                    return [['name' => 'synthetic-original']];
                }

                public function systemVersionedTables(): array
                {
                    return [];
                }

                public function hasRows(string $table, bool $includeHistory = false): bool
                {
                    return $this->withPrivateRows && $table === 'employee_document_audit';
                }
            },
            $directory !== null ? $directory . '/backups' : null,
            $directory !== null ? $directory . '/missing-runtime' : null
        );
    }

    /**
     * @param list<string> $tables
     * @return list<array{table: string, filename: string, rows: int, size_bytes: int}>
     */
    private function manifestTables(array $tables): array
    {
        return array_map(
            static fn (string $table): array => [
                'table' => $table,
                'filename' => 'database/' . $table . '.json',
                'rows' => 0,
                'size_bytes' => 2,
            ],
            $tables
        );
    }

    /**
     * @param list<string> $tables
     * @return array<string, string>
     */
    private function databaseFilesForTables(array $tables): array
    {
        $files = ['uploads/example.txt' => ''];

        foreach ($tables as $table) {
            $files['database/' . $table . '.json'] = '[]';
        }

        return $files;
    }

    private function zipWithFiles(array $files): string
    {
        $this->requireZipArchive();
        $zipPath = tempnam(sys_get_temp_dir(), 'backup-test-');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $content) {
            $zip->addFromString((string) $name, (string) $content);
        }

        $zip->close();

        return $zipPath;
    }

    private function requireZipArchive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive ist nicht verfuegbar.');
        }
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Temp-Verzeichnis konnte nicht erstellt werden.');
        }

        return $path;
    }

    private static function retainedSensitiveFieldsWarning(): string
    {
        return 'Das Backup enthaelt betriebsnotwendige sensible Datenbankfelder und muss wie ein Secret behandelt werden.';
    }

    private function currentSchemaVersion(): string
    {
        $migrations = glob(base_path('migrations/*.php')) ?: [];
        sort($migrations);

        return pathinfo((string) end($migrations), PATHINFO_FILENAME);
    }
}
