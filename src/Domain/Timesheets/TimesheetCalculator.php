<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use DateTimeImmutable;
use InvalidArgumentException;

final class TimesheetCalculator
{
    /**
     * Die gesetzliche Pause wird auf Basis der gesamten erfassten Anwesenheit berechnet.
     * Diese Annahme ist fuer den ersten Projektstand bewusst konservativ:
     * Wird keine oder eine zu kurze Pause angegeben, zieht das System automatisch
     * die mindestens erforderliche Pausenzeit ab, damit Nettozeiten nicht rechtswidrig
     * zu hoch ausgewiesen werden.
     */
    public function calculate(
        string $workDate,
        ?string $startTime,
        ?string $endTime,
        int $manualBreakMinutes = 0,
        string $entryType = 'work'
    ): array {
        if ($entryType !== 'work') {
            return [
                'work_date' => $workDate,
                'entry_type' => $entryType,
                'gross_minutes' => 0,
                'break_minutes' => 0,
                'net_minutes' => 0,
                'net_hours' => 0.0,
            ];
        }

        if ($startTime === null || $endTime === null) {
            throw new InvalidArgumentException('Start- und Endzeit sind fuer Arbeitseintraege erforderlich.');
        }

        $start = new DateTimeImmutable($workDate . ' ' . $startTime);
        $end = new DateTimeImmutable($workDate . ' ' . $endTime);

        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        $grossMinutes = (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
        $legalBreak = $this->requiredBreakMinutes($grossMinutes);
        $breakMinutes = max($manualBreakMinutes, $legalBreak);
        $netMinutes = max(0, $grossMinutes - $breakMinutes);

        return [
            'work_date' => $workDate,
            'entry_type' => $entryType,
            'gross_minutes' => $grossMinutes,
            'break_minutes' => $breakMinutes,
            'net_minutes' => $netMinutes,
            'net_hours' => round($netMinutes / 60, 2),
        ];
    }

    public function requiredBreakMinutes(int $grossMinutes): int
    {
        if ($grossMinutes > 9 * 60) {
            return 45;
        }

        if ($grossMinutes > 6 * 60) {
            return 30;
        }

        return 0;
    }
}

