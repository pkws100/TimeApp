<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Users\StorageUsageService;
use PHPUnit\Framework\TestCase;

final class StorageUsageServiceTest extends TestCase
{
    public function testUsageCountsFilesThatRemainInProtectedStorage(): void
    {
        $root = sys_get_temp_dir() . '/timeapp-storage-usage-' . uniqid('', true);
        mkdir($root . '/app/uploads/timesheet-1', 0775, true);
        file_put_contents($root . '/app/uploads/timesheet-1/beleg.jpg', str_repeat('x', 128));

        try {
            $usage = (new StorageUsageService($root))->usage();

            self::assertSame(128, $usage['bytes']);
            self::assertStringContainsString('B', $usage['human']);
        } finally {
            unlink($root . '/app/uploads/timesheet-1/beleg.jpg');
            rmdir($root . '/app/uploads/timesheet-1');
            rmdir($root . '/app/uploads');
            rmdir($root . '/app');
            rmdir($root);
        }
    }
}
