<?php

declare(strict_types=1);
namespace Tests\Unit;

use App\Domain\WorkTime\WorkTimeWindowCalculator;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorkTimeWindowCalculatorTest extends TestCase
{
    public function testExampleKeepsActualMinutesAndSeparatesOutsideMinutes(): void
    {
        $result = $this->calculate('07:42', '16:35', [['start' => '2030-01-07 12:00:00', 'end' => '2030-01-07 12:30:00']]);
        self::assertSame(503, $result['actual_work_minutes']);
        self::assertSame(450, $result['inside_window_minutes']);
        self::assertSame(18, $result['outside_before_minutes']);
        self::assertSame(35, $result['outside_after_minutes']);
        self::assertSame(53, $result['outside_pending_minutes']);
        self::assertSame(450, $result['accountable_work_minutes']);
        self::assertTrue($result['unresolved']);
    }

    public function testApprovalsOnlyCreditTheIntersectionAndCannotDoubleCount(): void
    {
        $approval = ['start' => '2030-01-07 07:00:00', 'end' => '2030-01-07 09:00:00', 'status' => 'approved'];
        $result = $this->calculate('07:42', '16:35', [], [$approval, $approval]);
        self::assertSame(18, $result['outside_approved_minutes']);
        self::assertSame(35, $result['outside_pending_minutes']);
        self::assertSame(498, $result['accountable_work_minutes']);
    }

    public function testBreakStraddlingWindowStartIsAssignedByItsRealInterval(): void
    {
        $result = $this->calculate('07:30', '09:00', [['start' => '2030-01-07 07:50:00', 'end' => '2030-01-07 08:10:00']]);
        self::assertSame(70, $result['actual_work_minutes']);
        self::assertSame(50, $result['inside_window_minutes']);
        self::assertSame(20, $result['outside_before_minutes']);
    }

    public function testDurationOnlyBreakDoesNotInventPlacement(): void
    {
        $result = $this->calculate('07:30', '16:30', [], [], 30);
        self::assertSame(510, $result['actual_work_minutes']);
        self::assertNull($result['inside_window_minutes']);
        self::assertNull($result['accountable_work_minutes']);
        self::assertSame('needs_clarification', $result['calculation_status']);
        self::assertSame([], $result['outside_segments']);
    }

    public function testAdditionalAutomaticBreakDeductionRemainsUnplaced(): void
    {
        $result = $this->calculate('07:30', '16:30', [['start' => '2030-01-07 12:00:00', 'end' => '2030-01-07 12:20:00']], [], 30);
        self::assertSame(10, $result['unplaced_break_minutes']);
        self::assertNull($result['accountable_work_minutes']);
    }

    public function testOverlappingWorkAndBreakInputsAreUnioned(): void
    {
        $result = (new WorkTimeWindowCalculator())->calculate([
            ['start' => '2030-01-07 08:00:00', 'end' => '2030-01-07 10:00:00'],
            ['start' => '2030-01-07 09:00:00', 'end' => '2030-01-07 11:00:00'],
        ], [
            ['start' => '2030-01-07 09:30:00', 'end' => '2030-01-07 09:50:00'],
            ['start' => '2030-01-07 09:40:00', 'end' => '2030-01-07 10:00:00'],
        ], $this->policy(), '2030-01-07');
        self::assertSame(150, $result['actual_work_minutes']);
        self::assertSame(150, $result['accountable_work_minutes']);
        self::assertFalse($result['unresolved']);
    }

    public function testRejectedTimeStaysActualAndDisputedRemainsProvisional(): void
    {
        $result = $this->calculate('07:30', '16:30', [], [
            ['start' => '2030-01-07 07:30:00', 'end' => '2030-01-07 08:00:00', 'status' => 'rejected'],
            ['start' => '2030-01-07 16:00:00', 'end' => '2030-01-07 16:30:00', 'status' => 'disputed'],
        ]);
        self::assertSame(540, $result['actual_work_minutes']);
        self::assertSame(30, $result['outside_rejected_minutes']);
        self::assertSame(30, $result['outside_disputed_minutes']);
        self::assertSame('disputed', $result['calculation_status']);
    }

    public function testContradictoryDecisionsRequireClarification(): void
    {
        $interval = ['start' => '2030-01-07 07:30:00', 'end' => '2030-01-07 08:00:00'];
        $result = $this->calculate('07:30', '09:00', [], [$interval + ['status' => 'approved'], $interval + ['status' => 'rejected']]);
        self::assertSame('needs_clarification', $result['calculation_status']);
        self::assertNull($result['accountable_work_minutes']);
    }

    public function testNightShiftWindowAndRealElapsedDstMinutes(): void
    {
        $policy = ['timezone' => 'Europe/Berlin', 'windows' => [6 => [['start' => '22:00', 'end' => '06:00']]]];
        foreach ([['2030-03-30T22:00:00+01:00', '2030-03-31T06:00:00+02:00', 420], ['2030-10-26T22:00:00+02:00', '2030-10-27T06:00:00+01:00', 540]] as [$start, $end, $minutes]) {
            $result = (new WorkTimeWindowCalculator())->calculate([['start' => $start, 'end' => $end]], [], $policy, substr($start, 0, 10));
            self::assertSame($minutes, $result['actual_work_minutes']);
            self::assertSame($minutes, $result['inside_window_minutes']);
        }
    }

    public function testAmbiguousLocalTimeCannotBeGuessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkTimeWindowCalculator())->timestamp('2030-10-27 02:30:00', new DateTimeZone('Europe/Berlin'));
    }

    public function testNonexistentLocalTimeCannotBeNormalizedIntoInventedTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkTimeWindowCalculator())->timestamp('2030-03-31 02:30:00', new DateTimeZone('Europe/Berlin'));
    }

    public function testExplicitOffsetResolvesRepeatedHour(): void
    {
        $result = (new WorkTimeWindowCalculator())->calculate([['start' => '2030-10-27T02:30:00+02:00', 'end' => '2030-10-27T02:30:00+01:00']], [], ['timezone' => 'Europe/Berlin', 'windows' => []], '2030-10-27');
        self::assertSame(60, $result['actual_work_minutes']);
        self::assertSame(60, $result['outside_pending_minutes']);
    }

    private function calculate(string $start, string $end, array $breaks = [], array $decisions = [], ?int $duration = null): array
    {
        return (new WorkTimeWindowCalculator())->calculate([['start' => '2030-01-07 ' . $start . ':00', 'end' => '2030-01-07 ' . $end . ':00']], $breaks, $this->policy(), '2030-01-07', $decisions, $duration);
    }

    private function policy(): array { return ['timezone' => 'Europe/Berlin', 'windows' => [1 => [['start' => '08:00', 'end' => '16:00']]]]; }
}
