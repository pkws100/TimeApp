<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\TimesheetCalculator;
use PHPUnit\Framework\TestCase;

final class TimesheetCalculatorTest extends TestCase
{
    public function testLegalBreakThresholdBoundariesRemainUnchanged(): void
    {
        $calculator = new TimesheetCalculator();
        $cases = [
            ['08:00', '14:00', 0],
            ['08:00', '14:01', 30],
            ['08:00', '17:00', 30],
            ['08:00', '17:01', 45],
        ];

        foreach ($cases as [$startTime, $endTime, $expectedBreakMinutes]) {
            $result = $calculator->calculate('2026-06-01', $startTime, $endTime, 0, 'work');

            self::assertSame($expectedBreakMinutes, $result['break_minutes']);
        }
    }

    public function testManualBreakAboveLegalMinimumIsKept(): void
    {
        $calculator = new TimesheetCalculator();
        $result = $calculator->calculate('2026-06-01', '08:00', '16:00', 45, 'work');

        self::assertSame(480, $result['gross_minutes']);
        self::assertSame(45, $result['break_minutes']);
        self::assertSame(435, $result['net_minutes']);
    }

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

    public function testSameStartAndEndMinuteDoesNotBecomeOvernightShift(): void
    {
        $calculator = new TimesheetCalculator();
        $result = $calculator->calculate('2026-05-08', '15:23', '15:23', 0, 'work');

        self::assertSame(0, $result['gross_minutes']);
        self::assertSame(0, $result['break_minutes']);
        self::assertSame(0, $result['net_minutes']);
    }

    public function testEarlierEndTimeStillCountsAsOvernightShift(): void
    {
        $calculator = new TimesheetCalculator();
        $result = $calculator->calculate('2026-05-08', '22:00', '06:00', 30, 'work');

        self::assertSame(480, $result['gross_minutes']);
        self::assertSame(30, $result['break_minutes']);
        self::assertSame(450, $result['net_minutes']);
    }

    public function testNonWorkEntriesKeepTimeValuesAtZero(): void
    {
        $calculator = new TimesheetCalculator();
        $result = $calculator->calculate('2026-04-20', null, null, 0, 'sick');

        self::assertSame(0, $result['net_minutes']);
        self::assertSame(0, $result['break_minutes']);
    }
}
