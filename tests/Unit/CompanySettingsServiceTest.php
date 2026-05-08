<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Settings\CompanySettingsService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class CompanySettingsServiceTest extends TestCase
{
    public function testPublicProfileUsesSensibleDefaultsWithoutDatabase(): void
    {
        $service = new CompanySettingsService(new DatabaseConnection([]), []);

        $profile = $service->publicProfile();

        self::assertSame('', $profile['app_display_name']);
        self::assertSame('', $profile['company_name']);
        self::assertSame('Deutschland', $profile['country']);
        self::assertFalse($profile['geo_capture_enabled']);
        self::assertNull($profile['logo']);
    }

    public function testSaveRejectsInvalidSmtpPort(): void
    {
        $service = new CompanySettingsService(new DatabaseConnection([]), []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP-Port');

        $service->save([
            'company_name' => 'Muster GmbH',
            'smtp_port' => 70000,
        ]);
    }

    public function testSmtpNormalizationPreservesExistingPasswordWhenFieldIsBlank(): void
    {
        $service = new CompanySettingsService(new DatabaseConnection([]), []);
        $normalized = $this->normalizeSmtp($service, [
            'smtp_host' => 'mail.example.test',
            'smtp_port' => '587',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'smtp_from_email' => 'system@example.test',
        ], [
            'smtp_password' => 'stored-secret',
        ]);

        self::assertSame('mail.example.test', $normalized['smtp_host']);
        self::assertSame(587, $normalized['smtp_port']);
        self::assertSame('stored-secret', $normalized['smtp_password']);
        self::assertSame('system@example.test', $normalized['smtp_from_email']);
        self::assertSame([
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'smtp_from_name',
            'smtp_from_email',
            'smtp_reply_to_email',
        ], array_keys($normalized));
    }

    public function testSmtpValidationRejectsInvalidEncryption(): void
    {
        $service = new CompanySettingsService(new DatabaseConnection([]), []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP-Verschluesselung');

        $this->validateSmtp($service, [
            'smtp_port' => 587,
            'smtp_encryption' => 'starttls',
            'smtp_from_email' => 'system@example.test',
            'smtp_reply_to_email' => '',
        ]);
    }

    public function testGeoNormalizationAcceptsCoordinatePairAndCheckboxes(): void
    {
        $service = new CompanySettingsService(new DatabaseConnection([]), []);
        $normalized = $this->normalizeGeo($service, [
            'geo_capture_enabled' => '1',
            'geo_requires_acknowledgement' => '0',
            'geo_notice_text' => ' Standorthinweis ',
            'geo_company_latitude' => '51.123456789',
            'geo_company_longitude' => '10.987654321',
            'geo_company_location_label' => 'Musterstrasse 1',
        ], [], true);

        self::assertSame(1, $normalized['geo_capture_enabled']);
        self::assertSame(0, $normalized['geo_requires_acknowledgement']);
        self::assertSame('Standorthinweis', $normalized['geo_notice_text']);
        self::assertSame('51.1234568', $normalized['geo_company_latitude']);
        self::assertSame('10.9876543', $normalized['geo_company_longitude']);
        self::assertSame('Musterstrasse 1', $normalized['geo_company_location_label']);
        self::assertNotEmpty($normalized['geo_company_geocoded_at']);
    }

    public function testGeoValidationRejectsInvalidLatitude(): void
    {
        $service = new CompanySettingsService(new DatabaseConnection([]), []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Latitude');

        $this->validateGeo($service, [
            'geo_company_latitude' => '91.0000000',
            'geo_company_longitude' => '10.0000000',
        ]);
    }

    public function testLogoUploadStoresValidImageInSettingsDirectory(): void
    {
        $root = sys_get_temp_dir() . '/company-settings-upload-' . bin2hex(random_bytes(6));
        $tmpFile = tempnam(sys_get_temp_dir(), 'logo-upload-');
        file_put_contents($tmpFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true));

        $service = new CompanySettingsService(new DatabaseConnection([]), [
            'root' => $root,
            'max_filesize' => 1024 * 1024,
        ]);

        try {
            $stored = $this->storeSettingsFile($service, [
                'name' => 'Logo Test.png',
                'type' => 'image/png',
                'tmp_name' => $tmpFile,
                'size' => filesize($tmpFile),
                'error' => UPLOAD_ERR_OK,
            ], 'logo', ['png'], ['image/png']);

            self::assertSame('Logo Test.png', $stored['original_name']);
            self::assertSame('image/png', $stored['mime_type']);
            self::assertFileExists($stored['path']);
            self::assertStringContainsString('/settings/company/logo/', $stored['path']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }

            $this->removeDirectory($root);
        }
    }

    public function testLogoUploadRejectsInvalidMimeType(): void
    {
        $root = sys_get_temp_dir() . '/company-settings-upload-' . bin2hex(random_bytes(6));
        $tmpFile = tempnam(sys_get_temp_dir(), 'logo-upload-');
        file_put_contents($tmpFile, 'not an image');

        $service = new CompanySettingsService(new DatabaseConnection([]), [
            'root' => $root,
            'max_filesize' => 1024 * 1024,
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('MIME-Typ');

            $this->storeSettingsFile($service, [
                'name' => 'logo.png',
                'type' => 'image/png',
                'tmp_name' => $tmpFile,
                'size' => filesize($tmpFile),
                'error' => UPLOAD_ERR_OK,
            ], 'logo', ['png'], ['image/png']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }

            $this->removeDirectory($root);
        }
    }

    public function testPdfUploadStoresValidLegalDocument(): void
    {
        $root = sys_get_temp_dir() . '/company-settings-upload-' . bin2hex(random_bytes(6));
        $tmpFile = tempnam(sys_get_temp_dir(), 'agb-upload-');
        file_put_contents($tmpFile, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $service = new CompanySettingsService(new DatabaseConnection([]), [
            'root' => $root,
            'max_filesize' => 1024 * 1024,
        ]);

        try {
            $stored = $this->storeSettingsFile($service, [
                'name' => 'AGB.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmpFile,
                'size' => filesize($tmpFile),
                'error' => UPLOAD_ERR_OK,
            ], 'agb', ['pdf'], ['application/pdf']);

            self::assertSame('AGB.pdf', $stored['original_name']);
            self::assertSame('application/pdf', $stored['mime_type']);
            self::assertFileExists($stored['path']);
            self::assertStringContainsString('/settings/company/agb/', $stored['path']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }

            $this->removeDirectory($root);
        }
    }

    public function testPdfUploadRejectsInvalidMimeType(): void
    {
        $root = sys_get_temp_dir() . '/company-settings-upload-' . bin2hex(random_bytes(6));
        $tmpFile = tempnam(sys_get_temp_dir(), 'agb-upload-');
        file_put_contents($tmpFile, 'not a pdf');

        $service = new CompanySettingsService(new DatabaseConnection([]), [
            'root' => $root,
            'max_filesize' => 1024 * 1024,
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('MIME-Typ');

            $this->storeSettingsFile($service, [
                'name' => 'agb.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmpFile,
                'size' => filesize($tmpFile),
                'error' => UPLOAD_ERR_OK,
            ], 'agb', ['pdf'], ['application/pdf']);
        } finally {
            if (is_file($tmpFile)) {
                unlink($tmpFile);
            }

            $this->removeDirectory($root);
        }
    }

    private function storeSettingsFile(
        CompanySettingsService $service,
        array $file,
        string $subdir,
        array $extensions,
        array $mimeTypes
    ): array {
        $method = new ReflectionMethod($service, 'storeSettingsFile');
        $method->setAccessible(true);

        return $method->invoke($service, $file, $subdir, $extensions, $mimeTypes);
    }

    private function normalizeSmtp(CompanySettingsService $service, array $payload, array $current): array
    {
        $method = new ReflectionMethod($service, 'normalizeSmtp');
        $method->setAccessible(true);

        return $method->invoke($service, $payload, $current);
    }

    private function validateSmtp(CompanySettingsService $service, array $data): void
    {
        $method = new ReflectionMethod($service, 'validateSmtp');
        $method->setAccessible(true);
        $method->invoke($service, $data);
    }

    private function normalizeGeo(CompanySettingsService $service, array $payload, array $current, bool $targetedSave): array
    {
        $method = new ReflectionMethod($service, 'normalizeGeo');
        $method->setAccessible(true);

        return $method->invoke($service, $payload, $current, $targetedSave);
    }

    private function validateGeo(CompanySettingsService $service, array $data): void
    {
        $method = new ReflectionMethod($service, 'validateGeo');
        $method->setAccessible(true);
        $method->invoke($service, $data);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
