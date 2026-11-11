<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use DateTimeImmutable;
use DateTimeZone;

/** Shared source classification for queued events and their final send claim. */
final class WorkTimeEventWindows
{
    public static function windowEnd(array $policy, string $date, DateTimeImmutable $startedAt): ?DateTimeImmutable
    {
        $day = new DateTimeImmutable($date, new DateTimeZone('Europe/Berlin'));
        $previous = $day->modify('-1 day');
        foreach ($policy['windows'][(int) $previous->format('N')] ?? [] as $window) {
            if ($window['end'] <= $window['start']) {
                $carriedEnd = WorkTimeLocalTime::parse($date . ' ' . $window['end'] . ':00');
                if ($startedAt < $carriedEnd) {
                    return $carriedEnd;
                }
            }
        }
        $windows = $policy['windows'][(int) $day->format('N')] ?? [];
        $end = null;
        foreach ($windows as $window) {
            $endDate = $window['end'] <= $window['start'] ? $day->modify('+1 day')->format('Y-m-d') : $date;
            $candidate = WorkTimeLocalTime::parse($endDate . ' ' . $window['end'] . ':00');
            if ($end === null || $candidate > $end) {
                $end = $candidate;
            }
        }
        return $end;
    }

    public static function startedOutsideAfterWindow(array $policy, DateTimeImmutable $startedAt): bool
    {
        $day = $startedAt->setTime(0, 0);
        $previous = $day->modify('-1 day');
        $intervals = [];
        $previousNight = false;
        foreach ($policy['windows'][(int) $previous->format('N')] ?? [] as $window) {
            if ($window['end'] > $window['start']) { continue; }
            $previousNight = true;
            $intervals[] = [WorkTimeLocalTime::parse($previous->format('Y-m-d') . ' ' . $window['start'] . ':00'), WorkTimeLocalTime::parse($day->format('Y-m-d') . ' ' . $window['end'] . ':00')];
        }
        $today = $policy['windows'][(int) $day->format('N')] ?? [];
        $lastEnd = null;
        foreach ($today as $window) {
            $endDay = $window['end'] <= $window['start'] ? $day->modify('+1 day') : $day;
            $end = WorkTimeLocalTime::parse($endDay->format('Y-m-d') . ' ' . $window['end'] . ':00');
            $intervals[] = [WorkTimeLocalTime::parse($day->format('Y-m-d') . ' ' . $window['start'] . ':00'), $end];
            if ($lastEnd === null || $end > $lastEnd) { $lastEnd = $end; }
        }
        foreach ($intervals as [$start, $end]) {
            if ($startedAt >= $start && $startedAt < $end) { return false; }
        }
        // No window on this weekday, or an expired carried night window: actual
        // work outside it is independently reportable, without inventing a checkout.
        if ($today === [] || $previousNight) { return true; }
        return $lastEnd !== null && $startedAt >= $lastEnd;
    }
}
