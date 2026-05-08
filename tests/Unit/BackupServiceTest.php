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
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive ist nicht verfuegbar.');
        }

        $service = new BackupService(new DatabaseConnection([]), []);
        $zipPath = tempnam(sys_get_temp_dir(), 'backup-test-');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode([
            'backup_version' => 1,
            'created_at' => '2026-04-23T10:00:00+00:00',
            'schema_version' => '20260423110000_admin_navigation_branding_and_exports',
            'database' => ['tables' => []],
            'uploads' => ['files' => 0, 'size_bytes' => 0],
            'runtime' => ['database_override_included' => false],
            'compatibility' => ['import_apply_supported' => false, 'validate_supported' => true],
        ], JSON_THROW_ON_ERROR));
        $zip->close();

        $result = $service->validateImport(['tmp_name' => $zipPath]);

        @unlink($zipPath);

        self::assertTrue($result['ok']);
        self::assertSame(1, $result['manifest']['backup_version']);
        self::assertArrayHasKey('restore_plan', $result);
    }
}
