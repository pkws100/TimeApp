<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;

final class TimesheetAuditService
{
    private ?bool $available = null;

    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function log(
        int $timesheetId,
        string $actionType,
        int $changedByUserId,
        string $reason,
        ?array $before,
        ?array $after
    ): void {
        $this->available ??= $this->connection->tableExists('timesheet_change_log');

        if ($timesheetId <= 0 || !$this->available) {
            return;
        }

        $this->connection->execute(
            'INSERT INTO timesheet_change_log (
                timesheet_id, action_type, changed_by_user_id, change_reason,
                before_snapshot, after_snapshot, created_at
             ) VALUES (
                :timesheet_id, :action_type, :changed_by_user_id, :change_reason,
                :before_snapshot, :after_snapshot, NOW()
             )',
            [
                'timesheet_id' => $timesheetId,
                'action_type' => $actionType,
                'changed_by_user_id' => $changedByUserId > 0 ? $changedByUserId : null,
                'change_reason' => trim($reason),
                'before_snapshot' => $before !== null
                    ? json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : null,
                'after_snapshot' => $after !== null
                    ? json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : null,
            ]
        );
    }
}
