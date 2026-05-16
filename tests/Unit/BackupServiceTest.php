<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Backup\BackupService;
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

        self::assertSame(1, $manifest['backup_version']);
        self::assertSame('database/users.json', $manifest['database']['tables'][0]['filename']);
        self::assertSame(1, $manifest['uploads']['files']);
        self::assertTrue($manifest['runtime']['database_override_included']);
        self::assertFalse($manifest['compatibility']['import_apply_supported']);
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
        self::assertSame(['database/users.json'], $result['database_files']);
        self::assertSame(['uploads/example.txt'], $result['upload_files']);
        self::assertArrayHasKey('restore_plan', $result);
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

    private function currentSchemaVersion(): string
    {
        $migrations = glob(base_path('migrations/*.php')) ?: [];
        sort($migrations);

        return pathinfo((string) end($migrations), PATHINFO_FILENAME);
    }
}
