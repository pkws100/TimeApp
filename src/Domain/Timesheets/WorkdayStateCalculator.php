<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use DateTimeImmutable;

final class WorkdayStateCalculator
{
    public function currentBreak(array $breaks): ?array
    {
        $currentBreak = null;

        foreach ($breaks as $break) {
            if (($break['break_ended_at'] ?? null) === null || ($break['break_ended_at'] ?? '') === '') {
                $currentBreak = $break;
            }
        }

        return $currentBreak;
    }

    public function completedBreakMinutes(array $breaks): int
    {
        $minutes = 0;

        foreach ($breaks as $break) {
            $minutes += $this->breakDurationMinutes($break);
        }

        return $minutes;
    }

    public function breakDurationMinutes(array $break): int
    {
        $startedAt = $this->parseDateTime($break['break_started_at'] ?? null);
        $endedAt = $this->parseDateTime($break['break_ended_at'] ?? null);

        if (!$startedAt instanceof DateTimeImmutable || !$endedAt instanceof DateTimeImmutable || $endedAt <= $startedAt) {
            return 0;
        }

        return (int) floor(($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 60);
    }

    public function status(?array $workEntry, ?array $statusEntry, ?array $currentBreak): string
    {
        if ($statusEntry !== null) {
            return (string) ($statusEntry['entry_type'] ?? 'unknown');
        }

        if ($workEntry === null || ($workEntry['start_time'] ?? null) === null) {
            return 'not_started';
        }

        if (($workEntry['end_time'] ?? null) !== null) {
            return 'completed';
        }

        if ($currentBreak !== null) {
            return 'paused';
        }

        return 'working';
    }

    public function trackedMinutesLiveBasis(string $workDate, ?array $workEntry, array $breaks): array
    {
        $currentBreak = $this->currentBreak($breaks);

        return [
            'work_started_at' => $this->combineWorkDateAndTime($workDate, $workEntry['start_time'] ?? null),
            'work_ended_at' => $this->combineWorkDateAndTime(
                $workDate,
                $workEntry['end_time'] ?? null,
                $workEntry['start_time'] ?? null
            ),
            'completed_break_minutes' => $this->completedBreakMinutes($breaks),
            'current_break_started_at' => $currentBreak['break_started_at'] ?? null,
            'is_running' => $workEntry !== null
                && ($workEntry['start_time'] ?? null) !== null
                && ($workEntry['end_time'] ?? null) === null
                && $currentBreak === null,
            'is_paused' => $currentBreak !== null,
        ];
    }

    public function combineWorkDateAndTime(string $workDate, ?string $time, ?string $startTime = null): ?string
    {
        $time = trim((string) ($time ?? ''));

        if ($time === '') {
            return null;
        }

        try {
            $dateTime = new DateTimeImmutable($workDate . ' ' . $time);

            if ($startTime !== null && $startTime !== '') {
                $start = new DateTimeImmutable($workDate . ' ' . $startTime);

                if ($dateTime <= $start) {
                    $dateTime = $dateTime->modify('+1 day');
                }
            }

            return $dateTime->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
