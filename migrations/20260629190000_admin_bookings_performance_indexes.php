<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AdminBookingsPerformanceIndexes extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('timesheets')) {
            return;
        }

        $this->execute('SET @@system_versioning_alter_history = 1;');

        $table = $this->table('timesheets');

        $this->addIndexIfMissing($table, ['is_deleted', 'work_date', 'id'], 'idx_timesheets_scope_date_id');
        $this->addIndexIfMissing($table, ['user_id', 'work_date'], 'idx_timesheets_user_date');
        $this->addIndexIfMissing($table, ['project_id', 'work_date'], 'idx_timesheets_project_date');
        $this->addIndexIfMissing($table, ['entry_type', 'is_deleted', 'work_date'], 'idx_timesheets_type_scope_date');
        $this->addIndexIfMissing($table, ['updated_at'], 'idx_timesheets_updated_at');
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
