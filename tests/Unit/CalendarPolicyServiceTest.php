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
        self::assertSame('Karfreitag', $holidays['2026-04-03']['name']);
        self::assertSame('Ostermontag', $holidays['2026-04-06']['name']);
        self::assertSame('Christi Himmelfahrt', $holidays['2026-05-14']['name']);
        self::assertSame('Pfingstmontag', $holidays['2026-05-25']['name']);
        self::assertSame('Fronleichnam', $holidays['2026-06-04']['name']);
        self::assertSame('Allerheiligen', $holidays['2026-11-01']['name']);
    }

    public function testPublicHolidayCalculationUsesGregorianEasterForAnotherYear(): void
    {
        $service = new CalendarPolicyService(new DatabaseConnection([]));
        $holidays = $service->publicHolidays(2025, 'NW');

        self::assertSame('Karfreitag', $holidays['2025-04-18']['name']);
        self::assertSame('Ostermontag', $holidays['2025-04-21']['name']);
        self::assertSame('Christi Himmelfahrt', $holidays['2025-05-29']['name']);
        self::assertSame('Pfingstmontag', $holidays['2025-06-09']['name']);
        self::assertSame('Fronleichnam', $holidays['2025-06-19']['name']);
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
