<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimesheetPauseRecords extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('timesheet_pause_policy_snapshots')) {
            $this->table('timesheet_pause_policy_snapshots', ['id' => false, 'primary_key' => ['timesheet_id']])
                ->addColumn('timesheet_id', 'integer', ['signed' => false])
                ->addColumn('policy_json', 'json')
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
                ->create();
        }
        if (!$this->hasTable('timesheet_pause_durations')) {
            $this->table('timesheet_pause_durations')
                ->addColumn('timesheet_id', 'integer', ['signed' => false])
                ->addColumn('recorded_by_user_id', 'integer', ['signed' => false])
                ->addColumn('duration_minutes', 'integer', ['signed' => false])
                ->addColumn('source', 'string', ['limit' => 40])
                ->addColumn('note', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('placed_at', 'datetime', ['null' => true])
                ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('recorded_by_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->create();
        }
    }

    public function down(): void
    {
        $tables = ['timesheet_pause_durations', 'timesheet_pause_policy_snapshots'];
        foreach ($tables as $name) {
            if ($this->hasTable($name) && (int) ($this->fetchRow('SELECT COUNT(*) AS records FROM ' . $name)['records'] ?? 0) > 0) {
                throw new RuntimeException('Pausennachweise oder Buchungssnapshots sind vorhanden. Rueckkehr nur ueber eine gesicherte Datenbankwiederherstellung.');
            }
        }
        foreach ($tables as $name) {
            if ($this->hasTable($name)) {
                $this->table($name)->drop()->save();
            }
        }
    }
}
