<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ProjectMaterialCaptureContext extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('project_material_entries');
        if (!$table->hasColumn('capture_type')) {
            $table->addColumn('capture_type', 'enum', [
                'values' => ['standard', 'cross_project', 'backdated', 'cross_project_backdated'],
                'null' => true,
            ])->update();
        }

        foreach (['captured_work_project_id' => 'projects', 'captured_timesheet_id' => 'timesheets'] as $column => $reference) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'integer', ['signed' => false, 'null' => true])->update();
            }
            if (!$table->hasForeignKey($column)) {
                $table->addForeignKey($column, $reference, 'id', ['delete' => 'RESTRICT'])->update();
            }
        }
    }

    public function down(): void
    {
        $table = $this->table('project_material_entries');
        foreach (['captured_timesheet_id', 'captured_work_project_id', 'capture_type'] as $column) {
            if ($table->hasForeignKey($column)) {
                $table->dropForeignKey($column)->update();
            }
            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->update();
            }
        }
    }
}
