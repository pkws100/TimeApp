<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AdminBookingsIssueFilterIndexes extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('timesheets')) {
            return;
        }

        $this->execute('SET @@system_versioning_alter_history = 1;');

        $table = $this->table('timesheets');

        $this->addIndexIfMissing($table, ['is_deleted', 'entry_type', 'project_id', 'work_date'], 'idx_timesheets_issue_project');
        $this->addIndexIfMissing($table, ['is_deleted', 'entry_type', 'start_time', 'work_date'], 'idx_timesheets_issue_start');
        $this->addIndexIfMissing($table, ['is_deleted', 'entry_type', 'end_time', 'work_date'], 'idx_timesheets_issue_end');
    }

    public function down(): void
    {
        // Keep performance indexes on rollback to avoid dropping manually pre-existing indexes with matching names.
    }

    private function addIndexIfMissing(\Phinx\Db\Table $table, array $columns, string $name): void
    {
        if ($table->hasIndexByName($name)) {
            return;
        }

        $table->addIndex($columns, ['name' => $name])->update();
    }
}
