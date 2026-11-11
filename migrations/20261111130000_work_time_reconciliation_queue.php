<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Transactional dirty tracking without altering the system-versioned source table. */
final class WorkTimeReconciliationQueue extends AbstractMigration
{
    private const TRIGGERS = [
        'wt_queue_timesheet_insert', 'wt_queue_timesheet_update', 'wt_queue_timesheet_delete',
        'wt_queue_break_insert', 'wt_queue_break_update', 'wt_queue_break_delete',
        'wt_queue_assignment_insert', 'wt_queue_assignment_update', 'wt_queue_assignment_delete',
        'wt_queue_version_update', 'wt_queue_decision_insert', 'wt_queue_deviation_status',
    ];

    public function up(): void
    {
        if (!$this->hasTable('work_time_reconciliation_queue')) {
            $this->table('work_time_reconciliation_queue', ['id' => false, 'primary_key' => 'timesheet_id'])
                ->addColumn('timesheet_id', 'integer', ['signed' => false])
                ->addColumn('revision', 'biginteger', ['signed' => false, 'default' => 1])
                ->addColumn('processed_revision', 'biginteger', ['signed' => false, 'default' => 0])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'pending'])
                ->addColumn('attempts', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('lock_token', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('locked_at', 'datetime', ['null' => true])
                ->addColumn('available_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('last_error', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('last_source_hash', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('last_calculation_hash', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('processed_at', 'datetime', ['null' => true])
                ->addIndex(['status', 'available_at', 'timesheet_id'], ['name' => 'idx_work_time_queue_ready'])
                ->create();
        }
        if (!$this->hasTable('work_time_reconciliation_runs')) {
            $this->table('work_time_reconciliation_runs')
                ->addColumn('timesheet_id', 'integer', ['signed' => false])
                ->addColumn('queue_revision', 'biginteger', ['signed' => false])
                ->addColumn('lock_token', 'string', ['limit' => 64])
                ->addColumn('status', 'string', ['limit' => 20, 'default' => 'processing'])
                ->addColumn('source_hash', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('calculation_hash', 'string', ['limit' => 64, 'null' => true])
                ->addColumn('result_json', 'text', ['null' => true])
                ->addColumn('error_code', 'string', ['limit' => 100, 'null' => true])
                ->addColumn('started_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('finished_at', 'datetime', ['null' => true])
                ->addIndex(['timesheet_id', 'id'], ['name' => 'idx_work_time_reconciliation_history'])
                ->addIndex(['lock_token', 'status'], ['name' => 'idx_work_time_reconciliation_claim'])
                ->create();
        }

        // Queue identities intentionally survive missing/deleted sources. They are
        // bookkeeping tombstones, not a new cascade that could destroy evidence.
        $newRelevant = $this->relevant('NEW');
        $oldRelevant = $this->relevant('OLD');
        $this->trigger('wt_queue_timesheet_insert', 'INSERT', 'timesheets', 'IF ' . $newRelevant . ' THEN ' . $this->dirty('NEW.id') . '; END IF;');
        $this->trigger('wt_queue_timesheet_update', 'UPDATE', 'timesheets', 'IF ' . $newRelevant . ' OR ' . $oldRelevant . ' THEN ' . $this->dirty('NEW.id') . '; END IF;');
        $this->trigger('wt_queue_timesheet_delete', 'DELETE', 'timesheets', 'IF ' . $oldRelevant . ' THEN ' . $this->dirty('OLD.id') . '; END IF;');

        foreach (['INSERT' => 'NEW', 'DELETE' => 'OLD'] as $event => $row) {
            $this->trigger('wt_queue_break_' . strtolower($event), $event, 'timesheet_breaks', $this->dirtySource($row . '.timesheet_id') . ';');
        }
        $this->trigger('wt_queue_break_update', 'UPDATE', 'timesheet_breaks', $this->dirtySource('OLD.timesheet_id') . '; IF NOT (OLD.timesheet_id <=> NEW.timesheet_id) THEN ' . $this->dirtySource('NEW.timesheet_id') . '; END IF;');
        foreach (['INSERT' => 'NEW', 'DELETE' => 'OLD'] as $event => $row) {
            $this->trigger('wt_queue_assignment_' . strtolower($event), $event, 'employee_work_time_assignments', $this->dirtyAssignment($row) . ';');
        }
        $this->trigger('wt_queue_assignment_update', 'UPDATE', 'employee_work_time_assignments',
            'IF NOT (OLD.user_id <=> NEW.user_id) OR NOT (OLD.policy_version_id <=> NEW.policy_version_id) OR NOT (OLD.overrides_json <=> NEW.overrides_json) THEN '
            . $this->dirtyAssignment('OLD') . '; ' . $this->dirtyAssignment('NEW') . '; ELSE '
            . $this->dirtySelect('SELECT t.id FROM timesheets t WHERE t.user_id = NEW.user_id AND (t.entry_type = "work" OR EXISTS (SELECT 1 FROM work_time_deviations d WHERE d.timesheet_id = t.id)) AND ((t.work_date >= OLD.effective_from AND (OLD.effective_to IS NULL OR t.work_date <= OLD.effective_to)) XOR (t.work_date >= NEW.effective_from AND (NEW.effective_to IS NULL OR t.work_date <= NEW.effective_to)))')
            . '; END IF;');
        $this->trigger('wt_queue_version_update', 'UPDATE', 'work_time_policy_versions', 'IF NOT (OLD.policy_json <=> NEW.policy_json) THEN ' . $this->dirtySelect('SELECT DISTINCT t.id FROM timesheets t JOIN employee_work_time_assignments a ON a.user_id = t.user_id WHERE a.policy_version_id = NEW.id AND t.work_date >= a.effective_from AND (a.effective_to IS NULL OR t.work_date <= a.effective_to)') . '; END IF;');
        $this->trigger('wt_queue_decision_insert', 'INSERT', 'work_time_deviation_decisions', $this->dirtySelect('SELECT d.timesheet_id FROM work_time_deviations d WHERE d.id = NEW.deviation_id') . ';');
        $this->trigger('wt_queue_deviation_status', 'UPDATE', 'work_time_deviations', 'IF NOT (OLD.status <=> NEW.status) THEN ' . $this->dirty('NEW.timesheet_id') . '; END IF;');

        // One initial backfill at upgrade. Normal minute workers only read ready queue rows.
        $this->execute($this->dirtySelect('SELECT t.id FROM timesheets t WHERE ' . $this->sourceRelevant('t')));
    }

    public function down(): void
    {
        foreach (self::TRIGGERS as $name) { $this->execute('DROP TRIGGER IF EXISTS `' . $name . '`'); }
        foreach (['work_time_reconciliation_runs', 'work_time_reconciliation_queue'] as $table) {
            if ($this->hasTable($table)) { $this->table($table)->drop()->save(); }
        }
    }

    private function trigger(string $name, string $event, string $table, string $body): void
    {
        $this->execute('CREATE TRIGGER IF NOT EXISTS `' . $name . '` AFTER ' . $event . ' ON `' . $table . '` FOR EACH ROW BEGIN ' . $body . ' END');
    }

    private function dirty(string $id): string
    {
        return 'INSERT INTO work_time_reconciliation_queue (timesheet_id) VALUES (' . $id . ') ' . $this->duplicate();
    }

    private function dirtySelect(string $select): string
    {
        return 'INSERT INTO work_time_reconciliation_queue (timesheet_id) ' . $select . ' ' . $this->duplicate();
    }

    private function duplicate(): string
    {
        return 'ON DUPLICATE KEY UPDATE revision = work_time_reconciliation_queue.revision + 1, attempts = IF(work_time_reconciliation_queue.status = "processing", work_time_reconciliation_queue.attempts, 0), status = IF(work_time_reconciliation_queue.status = "processing", "processing", "pending"), available_at = NOW(), last_error = NULL, updated_at = NOW()';
    }

    private function relevant(string $row): string
    {
        return '(' . $row . '.entry_type = "work" AND EXISTS (SELECT 1 FROM employee_work_time_assignments a WHERE a.user_id = ' . $row . '.user_id AND a.effective_from <= ' . $row . '.work_date AND (a.effective_to IS NULL OR a.effective_to >= ' . $row . '.work_date))) OR EXISTS (SELECT 1 FROM work_time_reconciliation_queue q WHERE q.timesheet_id = ' . $row . '.id) OR EXISTS (SELECT 1 FROM work_time_deviations d WHERE d.timesheet_id = ' . $row . '.id)';
    }

    private function sourceRelevant(string $alias): string
    {
        return '(' . $alias . '.entry_type = "work" AND EXISTS (SELECT 1 FROM employee_work_time_assignments a WHERE a.user_id = ' . $alias . '.user_id AND a.effective_from <= ' . $alias . '.work_date AND (a.effective_to IS NULL OR a.effective_to >= ' . $alias . '.work_date))) OR EXISTS (SELECT 1 FROM work_time_deviations d WHERE d.timesheet_id = ' . $alias . '.id)';
    }

    private function dirtySource(string $id): string
    {
        return $this->dirtySelect('SELECT t.id FROM timesheets t WHERE t.id = ' . $id . ' AND (' . $this->sourceRelevant('t') . ')');
    }

    private function dirtyAssignment(string $row): string
    {
        return $this->dirtySelect('SELECT t.id FROM timesheets t WHERE t.user_id = ' . $row . '.user_id AND t.work_date >= ' . $row . '.effective_from AND (' . $row . '.effective_to IS NULL OR t.work_date <= ' . $row . '.effective_to) AND (t.entry_type = "work" OR EXISTS (SELECT 1 FROM work_time_deviations d WHERE d.timesheet_id = t.id))');
    }
}
