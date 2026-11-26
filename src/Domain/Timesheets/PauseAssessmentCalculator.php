<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Domain\WorkTime\WorkTimeLocalTime;
use DateTimeImmutable;
use InvalidArgumentException;

final class PauseAssessmentCalculator
{
    private const MINIMUM_BREAK_SECONDS = 15 * 60;

    public function assess(array $entries, array $policy, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $recordedBookings = $bookings = $placed = [];
        $legacyDeduction = $unplaced = $qualifying = 0;
        $running = false;
        $uncertain = false;

        foreach ($entries as $entry) {
            if (empty($entry['start_time'])) {
                continue;
            }
            try {
                [$start, $end] = self::bounds($entry['work_date'], $entry['start_time'], $entry['end_time'] ?? null, $asOf);
                if ($end < $start) {
                    throw new InvalidArgumentException('Das Buchungsende liegt vor dem Arbeitsbeginn.');
                }
                $evidence = $this->evidence($entry['breaks'] ?? [], $start, $end);
                $running = $running || empty($entry['end_time']);
                $uncertain = $uncertain || $evidence['uncertain'];
                $legacyDeduction += max(0, (int) ($entry['legacy_deduction_minutes'] ?? 0));
                $recordedBookings[] = [$start, $end];
                $bookings[] = [$start, $evidence['open_start'] ?? $end];
                foreach ($evidence['placed'] as $interval) {
                    $placed[] = $interval;
                }
                $unplaced += $evidence['unplaced'];
                $qualifying += $evidence['qualifying_unplaced'];
            } catch (InvalidArgumentException) {
                $uncertain = true;
            }
        }

        [$bookings, $overlappingBookings] = $this->mergeIntervals($bookings);
        if ($running && !$overlappingBookings) {
            // An open pause cuts off elapsed work, but cannot resolve overlapping bookings.
            [, $overlappingBookings] = $this->mergeIntervals($recordedBookings);
        }
        [$placed, $overlappingPauses] = $this->mergeIntervals($placed);
        $documented = $unplaced;
        foreach ($placed as [$start, $end]) {
            $duration = $end - $start;
            $documented += $duration;
            $qualifying += $duration >= self::MINIMUM_BREAK_SECONDS ? $duration : 0;
        }
        $totalBooked = array_sum(array_map(static fn (array $interval): int => $interval[1] - $interval[0], $bookings));
        $actual = max(0, $totalBooked - $documented);
        $longest = $this->longestWorkRun($bookings, $placed);
        $uncertain = $uncertain || $overlappingBookings || $overlappingPauses;

        $required = match (true) {
            $actual > 9 * 3600 => max(45, (int) ($policy['minimum_over_nine_minutes'] ?? 45), (int) ($policy['minimum_over_six_minutes'] ?? 30)),
            $actual > 6 * 3600 => max(30, (int) ($policy['minimum_over_six_minutes'] ?? 30)),
            default => 0,
        };
        if ($actual > 0) {
            $required += max(0, (int) ($policy['additional_minutes'] ?? 0));
        }
        $missing = max(0, $required - intdiv($qualifying, 60));
        $actualKnown = !$uncertain && $legacyDeduction === 0;
        $placementUnclear = $unplaced > 0 && $longest > 6 * 3600;
        $status = match (true) {
            !$actualKnown || $placementUnclear || $missing > 0 || $longest > 6 * 3600 => 'needs_clarification',
            $running => 'pending',
            default => 'resolved',
        };

        return [
            'documented_break_minutes' => intdiv($documented, 60),
            'qualifying_break_minutes' => intdiv($qualifying, 60),
            'required_break_minutes' => $required,
            'missing_break_minutes' => $missing,
            'actual_work_minutes' => $actualKnown ? intdiv($actual, 60) : null,
            'calculation_status' => $status,
            'longest_uninterrupted_work_minutes' => $actualKnown && !$placementUnclear ? intdiv($longest, 60) : null,
            'unplaced_break_minutes' => intdiv($unplaced, 60),
            'legacy_deduction_minutes' => $legacyDeduction,
            'is_running' => $running,
            'continuous_work_limit_exceeded' => $actualKnown && !$placementUnclear && $longest > 6 * 3600,
        ];
    }

    /** @return array{int, int} Berlin-local booking bounds in epoch seconds. */
    public static function bounds(string $date, string $start, ?string $end, DateTimeImmutable $asOf): array
    {
        $from = WorkTimeLocalTime::fromInput($date . ' ' . $start);
        if ($end === null || $end === '') {
            return [$from->getTimestamp(), max($from->getTimestamp(), $asOf->getTimestamp())];
        }
        $endClock = strlen($end) === 5 ? $end . ':00' : $end;
        $endDate = strcmp($endClock, $from->format('H:i:s')) < 0
            ? $from->modify('+1 day')->format('Y-m-d') : $date;
        $to = WorkTimeLocalTime::fromInput($endDate . ' ' . $end);
        return [$from->getTimestamp(), $to->getTimestamp()];
    }

    private function evidence(array $rows, int $start, int $end): array
    {
        $placed = [];
        $unplaced = $longUnplaced = 0;
        $uncertain = false;
        $openStart = null;
        foreach ($rows as $row) {
            if (!empty($row['break_started_at']) && !empty($row['break_ended_at'])) {
                $from = WorkTimeLocalTime::fromInput($row['break_started_at'])->getTimestamp();
                $to = WorkTimeLocalTime::fromInput($row['break_ended_at'])->getTimestamp();
                if ($from < $start || $to > $end || $from >= $to) {
                    $uncertain = true;
                    continue;
                }
                $placed[] = [$from, $to];
            } elseif (!empty($row['break_started_at'])) {
                $open = WorkTimeLocalTime::fromInput($row['break_started_at'])->getTimestamp();
                if ($open < $start || $open > $end || $openStart !== null) {
                    $uncertain = true;
                } else {
                    $openStart = $open;
                }
            } else {
                $duration = max(0, (int) ($row['duration_minutes'] ?? 0)) * 60;
                $unplaced += $duration;
                $longUnplaced += $duration >= self::MINIMUM_BREAK_SECONDS ? $duration : 0;
            }
        }
        $workEnd = $openStart ?? $end;
        $valid = [];
        $documented = $unplaced;
        foreach ($placed as [$from, $to]) {
            if ($to > $workEnd) {
                $uncertain = true;
                continue;
            }
            $valid[] = [$from, $to];
            $documented += $to - $from;
        }
        return [
            'placed' => $valid,
            'qualifying_unplaced' => $longUnplaced,
            'unplaced' => $unplaced,
            'open_start' => $openStart,
            'uncertain' => $uncertain || $documented > $workEnd - $start,
        ];
    }

    /** @return array{list<array{int, int}>, bool} Union of timed intervals and whether any overlapped. */
    private function mergeIntervals(array $intervals): array
    {
        sort($intervals);
        $merged = [];
        $overlapping = false;
        foreach ($intervals as [$start, $end]) {
            $last = array_key_last($merged);
            if ($last === null || $start > $merged[$last][1]) {
                $merged[] = [$start, $end];
                continue;
            }
            $overlapping = $overlapping || $start < $merged[$last][1];
            $merged[$last][1] = max($merged[$last][1], $end);
        }
        return [$merged, $overlapping];
    }

    private function longestWorkRun(array $bookings, array $breaks): int
    {
        $longest = $run = $pauseIndex = 0;
        $breakCount = count($breaks);
        $previousEnd = null;
        foreach ($bookings as [$start, $end]) {
            if ($previousEnd !== null && $start - $previousEnd >= self::MINIMUM_BREAK_SECONDS) {
                $run = 0;
            }
            $cursor = $start;
            while ($pauseIndex < $breakCount && $breaks[$pauseIndex][0] < $end) {
                [$from, $to] = $breaks[$pauseIndex++];
                $run += $from - $cursor;
                if ($to - $from >= self::MINIMUM_BREAK_SECONDS) {
                    $longest = max($longest, $run);
                    $run = 0;
                }
                $cursor = $to;
            }
            $run += $end - $cursor;
            $longest = max($longest, $run);
            $previousEnd = $end;
        }
        return $longest;
    }
}
