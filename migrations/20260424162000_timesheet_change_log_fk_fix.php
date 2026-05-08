<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimesheetChangeLogFkFix extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('timesheet_change_log')) {
            return;
        }

        $table = $this->table('timesheet_change_log');

        if ($table->hasForeignKey('timesheet_id')) {
            $table->dropForeignKey('timesheet_id')->update();
        }

        $table = $this->table('timesheet_change_log');

        if (!$table->hasForeignKey('timesheet_id')) {
            $table->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('timesheet_change_log')) {
            return;
        }

        $table = $this->table('timesheet_change_log');

        if ($table->hasForeignKey('timesheet_id')) {
            $table->dropForeignKey('timesheet_id')->update();
        }

        $table = $this->table('timesheet_change_log');

        if (!$table->hasForeignKey('timesheet_id')) {
            $table->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'CASCADE'])->update();
        }
    }
}
