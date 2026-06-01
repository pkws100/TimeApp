<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Http\UploadSizeGuard;
use PHPUnit\Framework\TestCase;

final class UploadSizeGuardTest extends TestCase
{
    public function testIniByteValuesAreParsed(): void
    {
        self::assertSame(40 * 1024 * 1024, UploadSizeGuard::iniBytes('40M'));
        self::assertSame(32 * 1024, UploadSizeGuard::iniBytes('32K'));
        self::assertSame(2 * 1024 * 1024 * 1024, UploadSizeGuard::iniBytes('2G'));
        self::assertSame(512, UploadSizeGuard::iniBytes('512'));
    }

    public function testRequestExceedsPostMaxSizeWhenContentLengthIsTooLarge(): void
    {
        $request = new Request(
            'POST',
            '/api/v1/app/timesheets/1/files',
            [],
            [],
            [],
            [],
            ['CONTENT_LENGTH' => (string) (UploadSizeGuard::iniBytes((string) ini_get('post_max_size')) + 1)]
        );

        self::assertTrue(UploadSizeGuard::exceedsPostMaxSize($request));
    }
}
