<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class WorkTimeAdjacentWindowsQueue extends AbstractMigration
{
    private const INSERT_TRIGGER = 'wt_adjacent_queue_assignment_insert';
    private const UPDATE_TRIGGER = 'wt_adjacent_queue_assignment_update';
    private const DELETE_TRIGGER = 'wt_adjacent_queue_assignment_delete';
    private const VERSION_TRIGGER = 'wt_adjacent_queue_version_update';

    public function up(): void
    {
        $this->trigger(self::INSERT_TRIGGER, 'INSERT', 'employee_work_time_assignments',
            'IF NEW.interval_revision = 0 THEN ' . $this->forAssignment('NEW') . '; END IF;'
        );
        $this->trigger(self::DELETE_TRIGGER, 'DELETE', 'employee_work_time_assignments',
            'IF OLD.interval_revision = 0 THEN ' . $this->forAssignment('OLD') . '; END IF;'
        );
        $this->trigger(self::UPDATE_TRIGGER, 'UPDATE', 'employee_work_time_assignments',
            'IF NOT (OLD.user_id <=> NEW.user_id) OR NOT (OLD.policy_version_id <=> NEW.policy_version_id)
                OR NOT (OLD.overrides_json <=> NEW.overrides_json) OR NOT (OLD.interval_revision <=> NEW.interval_revision)
                OR NOT (OLD.effective_from <=> NEW.effective_from) OR NOT (OLD.effective_to <=> NEW.effective_to) THEN
                IF OLD.interval_revision = 0 THEN ' . $this->forAssignment('OLD') . '; END IF;
                IF NEW.interval_revision = 0 THEN ' . $this->forAssignment('NEW') . '; END IF;
            END IF;'
        );
        $this->trigger(self::VERSION_TRIGGER, 'UPDATE', 'work_time_policy_versions',
            'IF NOT (OLD.policy_json <=> NEW.policy_json) THEN ' . $this->forTimeline(' AND a.policy_version_id = NEW.id') . '; END IF;'
        );

        // Only sources on assignment boundaries need a one-time refresh.
        $this->execute($this->forTimeline(''));
    }

    public function down(): void
    {
        foreach ([self::INSERT_TRIGGER, self::UPDATE_TRIGGER, self::DELETE_TRIGGER, self::VERSION_TRIGGER] as $name) {
            $this->execute('DROP TRIGGER IF EXISTS ' . $name);
        }
    }

    private function trigger(string $name, string $event, string $table, string $body): void
    {
        $this->execute('CREATE TRIGGER IF NOT EXISTS ' . $name . ' AFTER ' . $event . ' ON ' . $table . ' FOR EACH ROW BEGIN ' . $body . ' END');
    }

    private function forAssignment(string $row): string
    {
        return $this->enqueue('SELECT t.id FROM timesheets t WHERE t.user_id = ' . $row . '.user_id AND '
            . $this->adjacentDate($row) . ' AND ' . $this->eligibleBooking());
    }

    private function forTimeline(string $filter): string
    {
        return $this->enqueue('SELECT DISTINCT t.id FROM employee_work_time_assignments a JOIN timesheets t
            ON t.user_id = a.user_id AND ' . $this->adjacentDate('a') . '
            WHERE a.interval_revision = 0 AND ' . $this->eligibleBooking() . $filter);
    }

    private function adjacentDate(string $assignment): string
    {
        return '((t.work_date = DATE_SUB(' . $assignment . '.effective_from, INTERVAL 1 DAY)
            AND t.end_time < t.start_time AND t.end_time > "00:00:00")
            OR t.work_date = DATE_ADD(' . $assignment . '.effective_to, INTERVAL 1 DAY))';
    }

    private function eligibleBooking(): string
    {
        return 't.entry_type = "work" AND t.is_deleted = 0 AND t.start_time IS NOT NULL AND t.end_time IS NOT NULL
            AND EXISTS (
                SELECT 1 FROM employee_work_time_assignments anchor
                WHERE anchor.user_id = t.user_id AND anchor.interval_revision = 0
                    AND anchor.effective_from <= t.work_date AND (anchor.effective_to IS NULL OR anchor.effective_to >= t.work_date)
            )';
    }

    private function enqueue(string $select): string
    {
        return 'INSERT INTO work_time_reconciliation_queue (timesheet_id) ' . $select . '
            ON DUPLICATE KEY UPDATE
                revision = work_time_reconciliation_queue.revision + 1,
                attempts = IF(work_time_reconciliation_queue.status = "processing", work_time_reconciliation_queue.attempts, 0),
                status = IF(work_time_reconciliation_queue.status = "processing", "processing", "pending"),
                available_at = NOW(), last_error = NULL, updated_at = NOW()';
    }
}
