<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use DateTimeImmutable;

/** Shared source classification for queued events and their final send claim. */
final class WorkTimeEventWindows
{
    /** @return array{policy: array, end: DateTimeImmutable}|null */
    public static function checkoutWindow(?array $policy, ?array $previousPolicy, DateTimeImmutable $startedAt): ?array
    {
        $carriedEnd = self::carriedWindowEnd($previousPolicy, $startedAt);
        if ($carriedEnd !== null) {
            return ['policy' => $previousPolicy, 'end' => $carriedEnd];
        }
        $day = $startedAt->setTime(0, 0);
        $end = null;
        foreach ($policy['windows'][(int) $day->format('N')] ?? [] as $window) {
            $endDay = $window['end'] <= $window['start'] ? $day->modify('+1 day') : $day;
            $endDate = $endDay->format('Y-m-d');
            $candidate = WorkTimeLocalTime::parse($endDate . ' ' . $window['end'] . ':00');
            if ($end === null || $candidate > $end) {
                $end = $candidate;
            }
        }
        return $end === null ? null : ['policy' => $policy, 'end' => $end];
    }

    public static function carriedWindowEnd(?array $previousPolicy, DateTimeImmutable $startedAt): ?DateTimeImmutable
    {
        $previous = $startedAt->modify('-1 day');
        foreach ($previousPolicy['windows'][(int) $previous->format('N')] ?? [] as $window) {
            if ($window['end'] <= $window['start']) {
                $end = WorkTimeLocalTime::parse($startedAt->format('Y-m-d') . ' ' . $window['end'] . ':00');
                if ($startedAt < $end) {
                    return $end;
                }
            }
        }

        return null;
    }

    public static function startedOutsideAfterWindow(array $policy, ?array $previousPolicy, DateTimeImmutable $startedAt): bool
    {
        $day = $startedAt->setTime(0, 0);
        $previous = $day->modify('-1 day');
        $intervals = [];
        $previousNight = false;
        foreach ($previousPolicy['windows'][(int) $previous->format('N')] ?? [] as $window) {
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
