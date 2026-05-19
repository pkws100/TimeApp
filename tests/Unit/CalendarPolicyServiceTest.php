<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarPolicyService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class CalendarPolicyServiceTest extends TestCase
{
    public function testPublicHolidayCalculationIncludesRegionalFixedHoliday(): void
    {
        $service = new CalendarPolicyService(new DatabaseConnection([]));
        $holidays = $service->publicHolidays(2026, 'NW');

        self::assertSame('Neujahr', $holidays['2026-01-01']['name']);
        self::assertSame('Fronleichnam', $holidays['2026-06-04']['name']);
        self::assertSame('Allerheiligen', $holidays['2026-11-01']['name']);
    }

    public function testPublicHolidayCalculationKeepsUnselectedRegionInactive(): void
    {
        $service = new CalendarPolicyService(new DatabaseConnection([]));

        self::assertSame([], $service->publicHolidays(2026, ''));
        self::assertNull($service->publicHolidayForDate('2026-01-01'));
    }

    public function testSaxonyIncludesRepentanceDay(): void
    {
        $service = new CalendarPolicyService(new DatabaseConnection([]));
        $holidays = $service->publicHolidays(2026, 'SN');

        self::assertSame('Buss- und Bettag', $holidays['2026-11-18']['name']);
    }
}
