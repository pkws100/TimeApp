<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\TimesheetCalculator;
use PHPUnit\Framework\TestCase;

final class TimesheetCalculatorTest extends TestCase
{
    public function testItAppliesThirtyMinuteBreakAfterSixHours(): void
    {
        $calculator = new TimesheetCalculator();
        $result = $calculator->calculate('2026-04-20', '07:00', '14:00', 0, 'work');

        self::assertSame(420, $result['gross_minutes']);
        self::assertSame(30, $result['break_minutes']);
        self::assertSame(390, $result['net_minutes']);
    }

    public function testItAppliesFortyFiveMinuteBreakAfterNineHours(): void
    {
        $calculator = new TimesheetCalculator();
        $result = $calculator->calculate('2026-04-20', '07:00', '17:00', 15, 'work');

        self::assertSame(600, $result['gross_minutes']);
        self::assertSame(45, $result['break_minutes']);
        self::assertSame(555, $result['net_minutes']);
    }

    public function testNonWorkEntriesKeepTimeValuesAtZero(): void
    {
        $calculator = new TimesheetCalculator();
        $result = $calculator->calculate('2026-04-20', null, null, 0, 'sick');

        self::assertSame(0, $result['net_minutes']);
        self::assertSame(0, $result['break_minutes']);
    }
}

