<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\TimesheetSignatureException;
use App\Domain\Timesheets\TimesheetSignatureService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TimesheetSignatureServiceTest extends TestCase
{
    private string $uploadRoot;

    protected function setUp(): void
    {
        $this->uploadRoot = sys_get_temp_dir() . '/timeapp-signature-service-' . uniqid('', true);
        mkdir($this->uploadRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadRoot);
    }

    public function testValidPngDataUrlIsDecodedAndStoredBelowProtectedUploadRoot(): void
    {
        if (!function_exists('imagepng')) {
            self::markTestSkipped('GD PNG support is not available.');
        }

        $service = $this->service();
        $decode = new ReflectionMethod($service, 'decodePngDataUrl');
        $decode->setAccessible(true);
        $store = new ReflectionMethod($service, 'storeSignatureFile');
        $store->setAccessible(true);

        $binary = $decode->invoke($service, $this->pngDataUrl(true));
        $stored = $store->invoke($service, 44, $binary);

        self::assertIsArray($stored);
        self::assertFileExists($stored['path']);
        self::assertStringStartsWith($this->uploadRoot . '/timesheet-signatures/', $stored['path']);
        self::assertStringNotContainsString('/public/', $stored['path']);
        self::assertSame(hash_file('sha256', $stored['path']), $stored['sha256']);
        self::assertGreaterThan(200, $stored['size']);
    }

    public function testInvalidDataUrlIsRejected(): void
    {
        $this->expectException(TimesheetSignatureException::class);

        $decode = new ReflectionMethod($this->service(), 'decodePngDataUrl');
        $decode->setAccessible(true);
        $decode->invoke($this->service(), 'data:image/jpeg;base64,abc');
    }

    public function testOversizedDataUrlIsRejected(): void
    {
        $this->expectException(TimesheetSignatureException::class);

        $decode = new ReflectionMethod($this->service(), 'decodePngDataUrl');
        $decode->setAccessible(true);
        $decode->invoke($this->service(), 'data:image/png;base64,' . str_repeat('A', 1400001));
    }

    public function testBlankPngIsRejectedWhenGdCanInspectPixels(): void
    {
        if (!function_exists('imagepng') || !function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD PNG inspection is not available.');
        }

        $this->expectException(TimesheetSignatureException::class);

        $decode = new ReflectionMethod($this->service(), 'decodePngDataUrl');
        $decode->setAccessible(true);
        $decode->invoke($this->service(), $this->pngDataUrl(false));
    }

    public function testUnsafePngDimensionsAreRejectedBeforeGdDecode(): void
    {
        if (!function_exists('imagepng')) {
            self::markTestSkipped('GD PNG support is not available.');
        }

        $this->expectException(TimesheetSignatureException::class);

        $binary = base64_decode(substr($this->pngDataUrl(true), strlen('data:image/png;base64,')), true);
        self::assertIsString($binary);
        $binary = substr_replace($binary, pack('N', 5000), 16, 4);

        $decode = new ReflectionMethod($this->service(), 'decodePngDataUrl');
        $decode->setAccessible(true);
        $decode->invoke($this->service(), 'data:image/png;base64,' . base64_encode($binary));
    }

    private function service(): TimesheetSignatureService
    {
        return new TimesheetSignatureService(new DatabaseConnection([]), [
            'root' => $this->uploadRoot,
        ], 'test-secret');
    }

    private function pngDataUrl(bool $withInk): string
    {
        $image = imagecreatetruecolor(96, 48);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);

        if ($withInk) {
            $black = imagecolorallocate($image, 20, 20, 20);
            imagesetthickness($image, 4);
            imageline($image, 10, 32, 84, 12, $black);
        }

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($binary);
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

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
