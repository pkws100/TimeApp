<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use DateTimeImmutable;
use DateTimeZone;

final class LiveWorkSession
{
    public static function referenceDate(?string $workDate = null, ?DateTimeImmutable $now = null): string
    {
        $berlin = new DateTimeZone('Europe/Berlin');
        $today = ($now ?? new DateTimeImmutable('now', $berlin))->setTimezone($berlin)->format('Y-m-d');

        return $workDate === null ? $today : min($workDate, $today);
    }

    /** The caller binds live_reference_date and supplies trusted SQL identifiers. */
    public static function isCurrentSql(string $userColumn, string $dateColumn): string
    {
        return "NOT EXISTS (
            SELECT 1 FROM employee_account_cutovers AS live_cutover
            WHERE live_cutover.active_final_user_id = {$userColumn}
              AND live_cutover.user_id = {$userColumn}
              AND live_cutover.status = 'final'
              AND live_cutover.effective_from <= :live_reference_date
              AND {$dateColumn} < live_cutover.effective_from
        )";
    }

    public static function isOpenSql(string $alias): string
    {
        return "{$alias}.entry_type = 'work'
            AND {$alias}.start_time IS NOT NULL
            AND {$alias}.end_time IS NULL
            AND COALESCE({$alias}.is_deleted, 0) = 0
            AND " . self::isCurrentSql("{$alias}.user_id", "{$alias}.work_date");
    }
}
