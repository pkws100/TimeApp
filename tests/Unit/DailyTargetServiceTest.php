<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DailyTargetServiceTest extends TestCase
{
    public function testDailyTargetServiceCachesMonthlyBreakdownsPerRequest(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/TimeAccounts/DailyTargetService.php'));

        self::assertStringContainsString('monthBreakdownCache', $source);
        self::assertStringContainsString('monthCacheKey', $source);
        self::assertStringContainsString('$this->monthBreakdownCache[$cacheKey]', $source);
    }
}
