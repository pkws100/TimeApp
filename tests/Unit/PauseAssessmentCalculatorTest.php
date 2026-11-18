<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\PauseAssessmentCalculator;
use PHPUnit\Framework\TestCase;

final class PauseAssessmentCalculatorTest extends TestCase
{
    public function testStatutoryThresholdsUseActualWorkAndKeepMissingMinutesSeparate(): void
    {
        $calculator = new PauseAssessmentCalculator();
        foreach (['14:00' => [360, 0], '14:01' => [361, 30], '17:00' => [540, 30], '17:01' => [541, 45]] as $end => [$work, $required]) {
            $result = $calculator->assess([$this->entry('08:00', $end)], ['planned_windows' => [['start' => '12:00', 'end' => '13:00']]]);
            self::assertSame($work, $result['actual_work_minutes']);
            self::assertSame(0, $result['documented_break_minutes']);
            self::assertSame($required, $result['missing_break_minutes']);
        }
    }

    public function testQualifyingSectionsAreAdditiveAcrossProjectsAndShortInterruptionsStayVisible(): void
    {
        $first = $this->entry('07:00', '11:00', [$this->pause('09:15', '09:30')]);
        $second = $this->entry('11:00', '17:00', [$this->pause('11:50', '12:00'), $this->pause('14:00', '14:30')]);
        $result = (new PauseAssessmentCalculator())->assess([$first, $second], []);
        self::assertSame(545, $result['actual_work_minutes']);
        self::assertSame(55, $result['documented_break_minutes']);
        self::assertSame(45, $result['qualifying_break_minutes']);
        self::assertSame(0, $result['missing_break_minutes']);
        self::assertSame('resolved', $result['calculation_status']);

        $second['breaks'][1] = $this->pause('14:00', '14:15');
        $split = (new PauseAssessmentCalculator())->assess([$first, $second], []);
        self::assertSame(30, $split['qualifying_break_minutes']);
        self::assertSame(15, $split['missing_break_minutes']);

        $first['breaks'] = [$this->pause('09:15', '09:22'), $this->pause('09:22', '09:30')];
        $contiguous = (new PauseAssessmentCalculator())->assess([$first, $second], []);
        self::assertSame(30, $contiguous['qualifying_break_minutes']);
        self::assertSame(15, $contiguous['missing_break_minutes']);
    }

    public function testUnplacedDurationAndLongContinuousWorkRequireDifferentEvidence(): void
    {
        $calculator = new PauseAssessmentCalculator();
        $duration = $calculator->assess([$this->entry('08:00', '15:00', [['duration_minutes' => 30]])], []);
        self::assertSame(390, $duration['actual_work_minutes']);
        self::assertSame(30, $duration['unplaced_break_minutes']);
        self::assertNull($duration['longest_uninterrupted_work_minutes']);
        $late = $calculator->assess([$this->entry('08:00', '15:00', [$this->pause('14:10', '14:40')])], []);
        self::assertSame(0, $late['missing_break_minutes']);
        self::assertTrue($late['continuous_work_limit_exceeded']);
        self::assertSame('needs_clarification', $late['calculation_status']);
    }

    public function testOvernightTimeChangesUseElapsedTimeAndRejectAmbiguousClocks(): void
    {
        $calculator = new PauseAssessmentCalculator();
        $spring = ['work_date' => '2026-03-28', 'start_time' => '22:00', 'end_time' => '06:00', 'breaks' => []];
        $fall = ['work_date' => '2026-10-24', 'start_time' => '22:00', 'end_time' => '06:00', 'breaks' => []];
        self::assertSame(420, $calculator->assess([$spring], [])['actual_work_minutes']);
        self::assertSame(540, $calculator->assess([$fall], [])['actual_work_minutes']);
        $fall['end_time'] = '02:30';
        self::assertNull($calculator->assess([$fall], [])['actual_work_minutes']);
    }

    private function entry(string $start, string $end, array $breaks = []): array
    {
        return ['work_date' => '2026-10-01', 'start_time' => $start, 'end_time' => $end, 'breaks' => $breaks];
    }

    private function pause(string $start, string $end): array
    {
        return ['break_started_at' => '2026-10-01 ' . $start, 'break_ended_at' => '2026-10-01 ' . $end];
    }
}
