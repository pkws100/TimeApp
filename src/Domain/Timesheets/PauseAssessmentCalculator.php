<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Domain\WorkTime\WorkTimeLocalTime;
use DateTimeImmutable;
use InvalidArgumentException;

final class PauseAssessmentCalculator
{
    public function assess(array $entries, array $policy, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable();
        $intervals = [];
        $legacyDeduction = 0;
        $running = false;
        $uncertain = false;

        foreach ($entries as $entry) {
            if (empty($entry['start_time'])) {
                continue;
            }
            try {
                [$start, $end] = self::bounds($entry['work_date'], $entry['start_time'], $entry['end_time'] ?? null, $asOf);
                $evidence = $this->evidence($entry['breaks'] ?? [], $start, $end);
                $running = $running || empty($entry['end_time']);
                $uncertain = $uncertain || $evidence['uncertain'];
                $legacyDeduction += max(0, (int) ($entry['legacy_deduction_minutes'] ?? 0));
                $intervals[] = [$start, $end, $evidence];
            } catch (InvalidArgumentException) {
                $uncertain = true;
            }
        }

        usort($intervals, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $actual = $documented = $qualifying = $unplaced = $longest = $run = 0;
        $previousEnd = null;
        foreach ($intervals as [$start, $end, $evidence]) {
            if ($previousEnd !== null && $start - $previousEnd >= 15 * 60) {
                $run = 0;
            }
            if ($previousEnd !== null && $start < $previousEnd) {
                $uncertain = true;
            }
            $previousEnd = max($previousEnd ?? 0, $end);
            $workEnd = $evidence['open_start'] ?? $end;
            $actual += max(0, $workEnd - $start - $evidence['documented']);
            $documented += $evidence['documented'];
            $qualifying += $evidence['qualifying'];
            $unplaced += $evidence['unplaced'];

            $cursor = $start;
            foreach ($evidence['placed'] as [$from, $to]) {
                $run += $from - $cursor;
                if ($to - $from >= 15 * 60) {
                    $longest = max($longest, $run);
                    $run = 0;
                }
                $cursor = $to;
            }
            $run += max(0, $workEnd - $cursor);
            $longest = max($longest, $run);
        }

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
        $endDate = strcmp(substr($end, 0, 5), substr($start, 0, 5)) < 0
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
                $openStart = WorkTimeLocalTime::fromInput($row['break_started_at'])->getTimestamp();
                $uncertain = $uncertain || $openStart < $start || $openStart > $end;
                continue;
            } else {
                $duration = max(0, (int) ($row['duration_minutes'] ?? 0)) * 60;
                $unplaced += $duration;
                $longUnplaced += $duration >= 15 * 60 ? $duration : 0;
            }
        }
        sort($placed);
        $valid = [];
        $previous = $start;
        foreach ($placed as [$from, $to]) {
            if ($from < $previous) {
                $uncertain = true;
                continue;
            }
            if ($from === $previous && $valid !== []) {
                $valid[array_key_last($valid)][1] = $to;
            } else {
                $valid[] = [$from, $to];
            }
            $previous = $to;
        }
        $documented = $unplaced;
        $qualifying = $longUnplaced;
        foreach ($valid as [$from, $to]) {
            $duration = $to - $from;
            $documented += $duration;
            $qualifying += $duration >= 15 * 60 ? $duration : 0;
        }
        return [
            'placed' => $valid,
            'documented' => $documented,
            'qualifying' => $qualifying,
            'unplaced' => $unplaced,
            'open_start' => $openStart,
            'uncertain' => $uncertain || $documented > ($openStart ?? $end) - $start,
        ];
    }
}
