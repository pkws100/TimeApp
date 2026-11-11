<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Pure interval arithmetic. It never updates a timesheet or decides a payroll entitlement. */
final class WorkTimeWindowCalculator
{
    /**
     * Intervals accept start/end (ISO-8601 with offset is preferred), or start_at/end_at.
     * Unknown placement of duration-only breaks is deliberately not distributed over a window.
     */
    public function calculate(array $workIntervals, array $breakIntervals, array $policy, string $workDate, array $decisions = [], ?int $recordedBreakMinutes = null): array
    {
        $zone = new DateTimeZone((string) ($policy['timezone'] ?? 'Europe/Berlin'));
        $work = $this->intervals($workIntervals, $zone);
        $breaks = $this->intervals($breakIntervals, $zone);
        $grossSeconds = $this->seconds($work);
        $documentedBreakSeconds = $this->seconds($this->intersect($work, $breaks));
        $effective = $this->subtract($work, $breaks);
        $documentedMinutes = (int) floor($documentedBreakSeconds / 60);
        $unknownBreak = max(0, ($recordedBreakMinutes ?? $documentedMinutes) - $documentedMinutes);
        $actual = max(0, (int) floor($this->seconds($effective) / 60) - $unknownBreak);
        $windows = $this->windows($policy, $workDate, $work, $zone);
        $inside = $this->intersect($effective, $windows);
        $outside = $this->subtract($effective, $windows);
        $before = $after = $gap = 0;
        $segments = [];
        $firstWindow = $windows === [] ? null : $windows[0][0];
        $lastWindow = $windows === [] ? null : $windows[count($windows) - 1][1];
        foreach ($outside as [$start, $end]) {
            $minutes = (int) floor(($end - $start) / 60);
            $kind = $firstWindow !== null && $end <= $firstWindow ? 'before' : ($lastWindow !== null && $start >= $lastWindow ? 'after' : 'outside');
            if ($kind === 'before') { $before += $minutes; }
            elseif ($kind === 'after') { $after += $minutes; }
            else { $gap += $minutes; }
            $segments[] = ['start_at' => $this->iso($start, $zone), 'end_at' => $this->iso($end, $zone), 'kind' => $kind, 'minutes' => $minutes];
        }
        $resolved = ['approved' => [], 'rejected' => [], 'disputed' => []];
        foreach ($decisions as $decision) {
            $status = (string) ($decision['status'] ?? 'approved');
            if (isset($resolved[$status])) {
                $resolved[$status] = array_merge($resolved[$status], $this->intervals([$decision], $zone));
            }
        }
        $approved = $this->intersect($outside, $this->merge($resolved['approved']));
        $rejected = $this->intersect($outside, $this->merge($resolved['rejected']));
        $disputed = $this->intersect($outside, $this->merge($resolved['disputed']));
        $decisionConflict = $this->seconds($this->intersect($approved, $rejected)) > 0
            || $this->seconds($this->intersect($approved, $disputed)) > 0
            || $this->seconds($this->intersect($rejected, $disputed)) > 0;
        $pending = $this->subtract($outside, $this->merge(array_merge($approved, $rejected, $disputed)));
        $insideMinutes = (int) floor($this->seconds($inside) / 60);
        $pendingMinutes = (int) floor($this->seconds($pending) / 60);
        $approvedMinutes = (int) floor($this->seconds($approved) / 60);
        $disputedMinutes = (int) floor($this->seconds($disputed) / 60);
        $status = $unknownBreak > 0 || $decisionConflict ? 'needs_clarification' : ($disputedMinutes > 0 ? 'disputed' : ($pendingMinutes > 0 ? 'pending' : 'complete'));
        return [
            'actual_work_minutes' => $actual,
            'documented_work_minutes' => (int) floor($this->seconds($effective) / 60),
            'documented_break_minutes' => $documentedMinutes,
            'unplaced_break_minutes' => $unknownBreak,
            'inside_window_minutes' => $unknownBreak > 0 ? null : $insideMinutes,
            'outside_before_minutes' => $unknownBreak > 0 ? null : $before,
            'outside_after_minutes' => $unknownBreak > 0 ? null : $after,
            'outside_gap_minutes' => $unknownBreak > 0 ? null : $gap,
            'outside_pending_minutes' => $unknownBreak > 0 ? null : $pendingMinutes,
            'outside_approved_minutes' => $unknownBreak > 0 ? null : $approvedMinutes,
            'outside_rejected_minutes' => $unknownBreak > 0 ? null : (int) floor($this->seconds($rejected) / 60),
            'outside_disputed_minutes' => $unknownBreak > 0 ? null : $disputedMinutes,
            'accountable_work_minutes' => $unknownBreak > 0 || $decisionConflict ? null : $insideMinutes + $approvedMinutes,
            'calculation_status' => $status,
            'unresolved' => $status !== 'complete',
            'preview_only' => true,
            'outside_segments' => $unknownBreak > 0 ? [] : $segments,
            'interval_work_seconds' => $grossSeconds,
        ];
    }

    /** Reject invalid, missing, normalized-away, or ambiguous wall clock timestamps. */
    public function timestamp(string $value, DateTimeZone $zone): int
    {
        $value = trim($value);
        if ($value === '') { throw new InvalidArgumentException('Ein Intervall benoetigt Start und Ende.'); }
        try { $time = new DateTimeImmutable($value, $zone); }
        catch (\Exception) { throw new InvalidArgumentException('Ungueltiger Intervallzeitpunkt.'); }
        if (!preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            $wall = str_replace('T', ' ', substr($value, 0, 19));
            if (strlen($wall) === 16) { $wall .= ':00'; }
            if ($time->format('Y-m-d H:i:s') !== $wall) { throw new InvalidArgumentException('Nicht existierende Ortszeit; bitte Zeit mit UTC-Offset dokumentieren.'); }
            $utcWall = (new DateTimeImmutable($wall, new DateTimeZone('UTC')))->getTimestamp();
            $candidates = [];
            foreach ($zone->getTransitions($utcWall - 172800, $utcWall + 172800) ?: [] as $transition) {
                $candidate = $utcWall - (int) $transition['offset'];
                if ($this->iso($candidate, $zone) !== '' && (new DateTimeImmutable('@' . $candidate))->setTimezone($zone)->format('Y-m-d H:i:s') === $wall) { $candidates[$candidate] = true; }
            }
            if (count($candidates) > 1) { throw new InvalidArgumentException('Mehrdeutige Ortszeit bei Zeitumstellung; UTC-Offset fehlt.'); }
        }
        return $time->getTimestamp();
    }

    private function intervals(array $items, DateTimeZone $zone): array
    {
        $intervals = [];
        foreach ($items as $item) {
            $start = $this->timestamp((string) ($item['start'] ?? $item['start_at'] ?? $item['segment_start_at'] ?? ''), $zone);
            $end = $this->timestamp((string) ($item['end'] ?? $item['end_at'] ?? $item['segment_end_at'] ?? ''), $zone);
            if ($end <= $start) { throw new InvalidArgumentException('Intervallende muss nach dem Beginn liegen.'); }
            $intervals[] = [$start, $end];
        }
        return $this->merge($intervals);
    }

    private function windows(array $policy, string $workDate, array $work, DateTimeZone $zone): array
    {
        $anchor = new DateTimeImmutable($workDate . ' 00:00:00', $zone);
        $first = $work === [] ? $anchor : (new DateTimeImmutable('@' . $work[0][0]))->setTimezone($zone)->setTime(0, 0);
        $last = $work === [] ? $anchor : (new DateTimeImmutable('@' . $work[count($work) - 1][1]))->setTimezone($zone)->setTime(0, 0);
        if ($last > $first->modify('+32 days')) { throw new InvalidArgumentException('Arbeitsintervalle duerfen hoechstens 32 Tage umfassen.'); }
        $windows = [];
        // Previous-day windows can extend into a night shift's first day.
        for ($day = $first->modify('-1 day'); $day <= $last; $day = $day->modify('+1 day')) {
            foreach (($policy['windows'][(int) $day->format('N')] ?? []) as $window) {
                $startTime = (string) $window['start']; $endTime = (string) $window['end'];
                $endDay = $endTime <= $startTime ? $day->modify('+1 day') : $day;
                $start = $this->timestamp($day->format('Y-m-d') . ' ' . $startTime . ':00', $zone);
                $end = $this->timestamp($endDay->format('Y-m-d') . ' ' . $endTime . ':00', $zone);
                if ($day < $first && $work !== [] && $end <= $work[0][0]) { continue; }
                if ($day > $first && $work !== [] && $start >= $work[count($work) - 1][1]) { continue; }
                if ($end > $first->getTimestamp() && $start < $last->modify('+1 day')->getTimestamp()) { $windows[] = [$start, $end]; }
            }
        }
        return $this->merge($windows);
    }

    private function merge(array $intervals): array
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) { $merged[$last][1] = max($merged[$last][1], $end); }
            else { $merged[] = [$start, $end]; }
        }
        return $merged;
    }

    private function intersect(array $left, array $right): array
    {
        $out = [];
        foreach ($left as [$a, $b]) {
            foreach ($right as [$c, $d]) {
                if (min($b, $d) > max($a, $c)) { $out[] = [max($a, $c), min($b, $d)]; }
            }
        }
        return $this->merge($out);
    }

    private function subtract(array $intervals, array $removed): array
    {
        foreach ($removed as [$a, $b]) {
            $next = [];
            foreach ($intervals as [$c, $d]) {
                if ($b <= $c || $a >= $d) { $next[] = [$c, $d]; continue; }
                if ($c < $a) { $next[] = [$c, $a]; }
                if ($d > $b) { $next[] = [$b, $d]; }
            }
            $intervals = $next;
        }
        return $intervals;
    }

    private function seconds(array $intervals): int
    {
        return array_sum(array_map(static fn (array $i): int => $i[1] - $i[0], $intervals));
    }

    private function iso(int $timestamp, DateTimeZone $zone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format(DATE_ATOM);
    }
}
