<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Files\FileAttachmentService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class FileAttachmentServiceTest extends TestCase
{
    private string $uploadRoot;

    protected function setUp(): void
    {
        $this->uploadRoot = sys_get_temp_dir() . '/timeapp-file-service-' . uniqid('', true);
        mkdir($this->uploadRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadRoot);
    }

    public function testStoreProjectReturnsPublicDescriptorWithoutStoragePath(): void
    {
        if (!function_exists('imagejpeg')) {
            self::markTestSkipped('GD JPEG support is not available.');
        }

        $source = $this->tempFile('upload.jpg');
        $image = imagecreatetruecolor(4, 3);
        imagejpeg($image, $source);
        imagedestroy($image);

        $stored = $this->service()->storeProject($this->uploadFile($source, 'Foto Baustelle.jpg', 'image/jpeg'), 12, 7);

        self::assertSame('Foto Baustelle.jpg', $stored['original_name']);
        self::assertSame('image/jpeg', $stored['mime_type']);
        self::assertTrue($stored['is_image']);
        self::assertArrayHasKey('download_url', $stored);
        self::assertNull($stored['download_url']);
        self::assertSame($stored['download_url'], $stored['preview_url']);
        self::assertArrayNotHasKey('storage_path', $stored);
        self::assertArrayNotHasKey('stored_name', $stored);
    }

    public function testCameraUploadWithoutFilenameExtensionUsesDetectedImageMimeType(): void
    {
        if (!function_exists('imagejpeg')) {
            self::markTestSkipped('GD JPEG support is not available.');
        }

        $source = $this->tempFile('camera-upload');
        $image = imagecreatetruecolor(4, 3);
        imagejpeg($image, $source);
        imagedestroy($image);

        $stored = $this->service()->storeTimesheet($this->uploadFile($source, 'blob', 'application/octet-stream'), 44, 7);

        self::assertSame('blob.jpg', $stored['original_name']);
        self::assertSame('image/jpeg', $stored['mime_type']);
        self::assertTrue($stored['is_image']);
    }

    public function testHeicUploadIsImageButNotInlinePreviewable(): void
    {
        $service = $this->serviceWithImageTypes(['heic'], ['image/heic']);
        $method = new ReflectionMethod($service, 'publicFile');
        $method->setAccessible(true);

        $descriptor = $method->invoke($service, [
            'id' => 5,
            'original_name' => 'aufnahme.heic',
            'mime_type' => 'image/heic',
            'size_bytes' => 4096,
            'uploaded_at' => '2026-06-01 12:00:00',
            'is_deleted' => 0,
            'deleted_at' => null,
        ], 'timesheet');

        self::assertTrue($descriptor['is_image']);
        self::assertFalse($descriptor['is_previewable']);
        self::assertSame('/api/v1/app/timesheet-files/5/download', $descriptor['download_url']);
        self::assertNull($descriptor['preview_url']);
    }

    public function testUploadLimitErrorUsesActionableMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Die Datei ist zu gross. Bitte eine kleinere Datei verwenden.');

        $this->service()->storeTimesheet([
            'error' => UPLOAD_ERR_INI_SIZE,
            'name' => 'foto.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '',
            'size' => 0,
        ], 44, 7);
    }

    public function testRejectsDisallowedMimeType(): void
    {
        $source = $this->tempFile('fake.jpg');
        file_put_contents($source, 'not an image');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MIME-Typ ist nicht freigegeben.');

        $this->service()->storeProject($this->uploadFile($source, 'fake.jpg', 'image/jpeg'), 12, 7);
    }

    public function testPngUploadIsStoredWithoutExifSupportRequirement(): void
    {
        if (!function_exists('imagepng')) {
            self::markTestSkipped('GD PNG support is not available.');
        }

        $source = $this->tempFile('upload.png');
        imagepng(imagecreatetruecolor(2, 2), $source);

        $stored = $this->service()->storeTimesheet($this->uploadFile($source, 'foto.png', 'image/png'), 44, 7);

        self::assertSame('image/png', $stored['mime_type']);
        self::assertTrue($stored['is_image']);
        self::assertArrayHasKey('download_url', $stored);
        self::assertNull($stored['download_url']);
    }

    public function testOfficeOpenXmlZipMimeIsAcceptedForDocxExtension(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not available.');
        }

        $source = $this->tempFile('document.docx');
        $zip = new \ZipArchive();
        $zip->open($source, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>');
        $zip->close();

        $service = new FileAttachmentService(new DatabaseConnection([]), [
            'root' => $this->uploadRoot,
            'max_filesize' => 5 * 1024 * 1024,
            'allowed_extensions' => ['docx'],
            'allowed_mime_types' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ]);

        $stored = $service->storeProject(
            $this->uploadFile(
                $source,
                'bericht.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ),
            12,
            7
        );

        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $stored['mime_type']);
        self::assertFalse($stored['is_image']);
    }

    public function testAdminTimesheetDescriptorUsesAdminUrlsWithoutStoragePaths(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'adminTimesheetFile');
        $method->setAccessible(true);

        $descriptor = $method->invoke($service, [
            'id' => 22,
            'original_name' => 'beleg.jpg',
            'stored_name' => 'internal-name.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 4096,
            'storage_path' => '/private/storage/beleg.jpg',
            'uploaded_at' => '2026-05-16 09:30:00',
            'is_deleted' => 0,
            'deleted_at' => null,
            'document_status_id' => 5,
            'document_status_label' => 'Bearbeitet',
            'document_status_slug' => 'bearbeitet',
            'document_status_color' => '#2563eb',
            'document_status_is_default' => 0,
            'document_status_is_deleted' => 0,
        ]);

        self::assertSame('/admin/timesheet-files/22/download', $descriptor['download_url']);
        self::assertSame('/admin/timesheet-files/22/download', $descriptor['preview_url']);
        self::assertSame('/admin/timesheet-files/22', $descriptor['archive_url']);
        self::assertTrue($descriptor['is_image']);
        self::assertSame('Bearbeitet', $descriptor['document_status']['label']);
        self::assertSame('#2563eb', $descriptor['document_status']['color']);
        self::assertArrayNotHasKey('storage_path', $descriptor);
        self::assertArrayNotHasKey('stored_name', $descriptor);
    }

    public function testAdminTimesheetDescriptorTreatsPdfAsPreviewableDocument(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'adminTimesheetFile');
        $method->setAccessible(true);

        $descriptor = $method->invoke($service, [
            'id' => 23,
            'original_name' => 'beleg.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4096,
            'uploaded_at' => '2026-05-16 09:30:00',
            'is_deleted' => 0,
            'deleted_at' => null,
        ]);

        self::assertFalse($descriptor['is_image']);
        self::assertTrue($descriptor['is_previewable']);
        self::assertSame('/admin/timesheet-files/23/download', $descriptor['preview_url']);
    }

    public function testJpegExifOrientationIsNormalizedWhenSupported(): void
    {
        if (
            !function_exists('exif_read_data')
            || !function_exists('imagejpeg')
            || !function_exists('imagecreatefromjpeg')
            || !function_exists('imagerotate')
        ) {
            self::markTestSkipped('EXIF/GD JPEG support is not available.');
        }

        $source = $this->tempFile('oriented.jpg');
        $image = imagecreatetruecolor(2, 1);
        imagejpeg($image, $source);
        imagedestroy($image);
        $this->injectExifOrientation($source, 6);

        $this->service()->storeProject($this->uploadFile($source, 'oriented.jpg', 'image/jpeg'), 12, 7);

        $storedPath = $this->firstStoredFile();
        $size = getimagesize($storedPath);

        self::assertIsArray($size);
        self::assertSame(1, $size[0]);
        self::assertSame(2, $size[1]);
    }

    private function service(): FileAttachmentService
    {
        return $this->serviceWithImageTypes(['jpg', 'jpeg', 'png'], ['image/jpeg', 'image/png']);
    }

    private function serviceWithImageTypes(array $extensions, array $mimeTypes): FileAttachmentService
    {
        return new FileAttachmentService(new DatabaseConnection([]), [
            'root' => $this->uploadRoot,
            'max_filesize' => 5 * 1024 * 1024,
            'allowed_extensions' => $extensions,
            'allowed_mime_types' => $mimeTypes,
        ]);
    }

    private function uploadFile(string $path, string $name, string $mimeType): array
    {
        return [
            'error' => UPLOAD_ERR_OK,
            'name' => $name,
            'type' => $mimeType,
            'tmp_name' => $path,
            'size' => filesize($path),
        ];
    }

    private function tempFile(string $name): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('timeapp-upload-', true) . '-' . $name;
        touch($path);

        return $path;
    }

    private function firstStoredFile(): string
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploadRoot));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                return $file->getPathname();
            }
        }

        self::fail('No uploaded file was stored.');
    }

    private function injectExifOrientation(string $path, int $orientation): void
    {
        $jpeg = (string) file_get_contents($path);
        $payload = "Exif\0\0"
            . "II*\0\x08\0\0\0"
            . "\x01\0"
            . "\x12\x01"
            . "\x03\0"
            . "\x01\0\0\0"
            . chr($orientation) . "\0\0\0"
            . "\0\0\0\0";
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        file_put_contents($path, substr($jpeg, 0, 2) . $segment . substr($jpeg, 2));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
