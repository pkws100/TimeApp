<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\ProjectSessionDirectory;
use PHPUnit\Framework\TestCase;

final class ProjectSessionDirectoryTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/timeapp-session-directory-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0700);
    }

    protected function tearDown(): void
    {
        chmod($this->storageRoot, 0700);
        $paths = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->storageRoot);
    }

    public function testFreshStorageKeepsTheSameSessionDirectoryAfterTheFirstExport(): void
    {
        self::assertDirectoryDoesNotExist($this->storageRoot . '/cache');
        $sessionPath = ProjectSessionDirectory::prepare($this->storageRoot);
        self::assertSame($this->storageRoot . '/cache/sessions', $sessionPath);
        self::assertDirectoryExists($sessionPath);
        file_put_contents($sessionPath . '/sess_example', 'persisted-authentication');

        mkdir($this->storageRoot . '/cache/exports', 0775, true);

        self::assertSame($sessionPath, ProjectSessionDirectory::prepare($this->storageRoot));
        self::assertSame('persisted-authentication', file_get_contents($sessionPath . '/sess_example'));
    }

    public function testWritableCacheParentWorksWhenTheStorageRootIsReadOnly(): void
    {
        mkdir($this->storageRoot . '/cache', 0775);
        chmod($this->storageRoot, 0555);

        self::assertSame($this->storageRoot . '/cache/sessions', ProjectSessionDirectory::prepare($this->storageRoot));
        self::assertDirectoryExists($this->storageRoot . '/cache/sessions');
    }

    public function testUnavailableProjectDirectoryLeavesFallbackSelectionToBootstrap(): void
    {
        file_put_contents($this->storageRoot . '/cache', 'not-a-directory');

        self::assertNull(ProjectSessionDirectory::prepare($this->storageRoot));
        self::assertSame('not-a-directory', file_get_contents($this->storageRoot . '/cache'));
    }
}
