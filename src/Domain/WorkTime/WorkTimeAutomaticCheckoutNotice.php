<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\TimesheetOverlap;

final class WorkTimeAutomaticCheckoutNotice
{
    /** Aliases are supplied by the two server-side consumers of the recorded checkout. */
    public static function currentSql(string $checkout, string $entry): string
    {
        $laterStart = TimesheetOverlap::startSql('newer_work');

        return "({$entry}.entry_type = 'work' AND COALESCE({$entry}.is_deleted, 0) = 0
            AND {$entry}.work_date = {$checkout}.work_date AND {$entry}.start_time = {$checkout}.start_time
            AND {$entry}.end_time = TIME({$checkout}.ended_at)
            AND NOT EXISTS (
                SELECT 1 FROM timesheets newer_work
                WHERE newer_work.user_id = {$checkout}.user_id AND newer_work.id <> {$checkout}.timesheet_id
                  AND newer_work.entry_type = 'work' AND COALESCE(newer_work.is_deleted, 0) = 0
                  AND newer_work.work_date >= DATE({$checkout}.ended_at) AND {$laterStart} >= {$checkout}.ended_at
            ))";
    }
}
