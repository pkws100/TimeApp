<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

final class TimesheetOverlap
{
    public const MESSAGE = 'Überlappende Buchungen. Betroffene Zeiten sind bis zur Klärung von der Summe ausgenommen.';

    /**
     * Both sides are active work bookings of one employee, regardless of project.
     * Stored stamps are Berlin wall times with an earlier end clock on the next calendar day.
     * Touching boundaries and zero-duration rows do not overlap.
     * For live displays, the caller also binds the Berlin clock as overlap_live_start and overlap_live_end.
     */
    public static function disputedSql(string $entry, bool $includeLive = false): string
    {
        $other = 'overlap_entry';
        $start = self::startSql($entry);
        $end = self::endSql($entry);
        $otherStart = self::startSql($other);
        $otherEnd = self::endSql($other);
        $hasDuration = "{$entry}.start_time <> {$entry}.end_time";
        if ($includeLive) {
            $hasDuration = "({$hasDuration} OR ({$entry}.end_time IS NULL AND {$start} < :overlap_live_start))";
            $end = "COALESCE({$end}, :overlap_live_end)";
        }

        return "({$entry}.entry_type = 'work' AND COALESCE({$entry}.is_deleted, 0) = 0
            AND {$entry}.start_time IS NOT NULL AND {$hasDuration}
            AND EXISTS (
                SELECT 1 FROM timesheets {$other}
                WHERE {$other}.user_id = {$entry}.user_id AND {$other}.id <> {$entry}.id
                  AND {$other}.work_date BETWEEN DATE_SUB({$entry}.work_date, INTERVAL 1 DAY)
                                             AND DATE_ADD({$entry}.work_date, INTERVAL 1 DAY)
                  AND {$other}.entry_type = 'work' AND COALESCE({$other}.is_deleted, 0) = 0
                  AND {$other}.start_time IS NOT NULL AND {$other}.end_time IS NOT NULL
                  AND {$other}.start_time <> {$other}.end_time
                  AND {$start} < {$otherEnd} AND {$otherStart} < {$end}
            ))";
    }

    public static function startSql(string $alias): string
    {
        return "TIMESTAMP({$alias}.work_date, {$alias}.start_time)";
    }

    public static function endSql(string $alias): string
    {
        return "TIMESTAMP(IF({$alias}.end_time < {$alias}.start_time, DATE_ADD({$alias}.work_date, INTERVAL 1 DAY), {$alias}.work_date), {$alias}.end_time)";
    }
}
