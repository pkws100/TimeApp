<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\WorkdayStateCalculator;
use PHPUnit\Framework\TestCase;

final class WorkdayStateCalculatorTest extends TestCase
{
    public function testCompletedBreakMinutesAggregatesClosedBreaks(): void
    {
        $calculator = new WorkdayStateCalculator();
        $minutes = $calculator->completedBreakMinutes([
            ['break_started_at' => '2026-04-24T09:00:00+02:00', 'break_ended_at' => '2026-04-24T09:15:00+02:00'],
            ['break_started_at' => '2026-04-24T12:30:00+02:00', 'break_ended_at' => '2026-04-24T12:40:00+02:00'],
            ['break_started_at' => '2026-04-24T14:00:00+02:00', 'break_ended_at' => null],
        ]);

        self::assertSame(25, $minutes);
    }

    public function testStatusReturnsPausedForOpenBreak(): void
    {
        $calculator = new WorkdayStateCalculator();
        $status = $calculator->status(
            ['start_time' => '07:00', 'end_time' => null],
            null,
            ['break_started_at' => '2026-04-24T09:00:00+02:00', 'break_ended_at' => null]
        );

        self::assertSame('paused', $status);
    }

    public function testTrackedMinutesLiveBasisContainsWorkAndBreakAnchors(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $calculator = new WorkdayStateCalculator();
            $basis = $calculator->trackedMinutesLiveBasis(
                '2026-04-24',
                ['start_time' => '07:00', 'end_time' => null],
                [
                    ['break_started_at' => '2026-04-24T09:00:00+02:00', 'break_ended_at' => '2026-04-24T09:15:00+02:00'],
                    ['break_started_at' => '2026-04-24T12:30:00+02:00', 'break_ended_at' => null],
                ]
            );

            self::assertSame('2026-04-24T07:00:00+02:00', $basis['work_started_at']);
            self::assertSame(15, $basis['completed_break_minutes']);
            self::assertSame('2026-04-24T12:30:00+02:00', $basis['current_break_started_at']);
            self::assertTrue($basis['is_paused']);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }
}
