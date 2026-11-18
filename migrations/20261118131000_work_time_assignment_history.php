<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class WorkTimeAssignmentHistory extends AbstractMigration
{
    private const ASSIGNMENTS = 'employee_work_time_assignments';
    private const START_INDEX = 'uniq_work_time_assignment_start';
    private const REVISION_INDEX = 'uniq_work_time_assignment_revision';

    public function up(): void
    {
        $table = $this->table(self::ASSIGNMENTS);
        if (!$table->hasColumn('interval_revision')) {
            $table->addColumn('interval_revision', 'integer', ['signed' => false, 'default' => 0])->update();
        }
        if (!$table->hasColumn('carried_from_assignment_id')) {
            $table->addColumn('carried_from_assignment_id', 'integer', ['signed' => false, 'null' => true])->update();
        }
        if (!$table->hasForeignKey('carried_from_assignment_id')) {
            $table->addForeignKey('carried_from_assignment_id', self::ASSIGNMENTS, 'id', ['delete' => 'RESTRICT'])->update();
        }
        if (!$table->hasColumn('live_from_unix')) {
            $table->addColumn('live_from_unix', 'biginteger', ['signed' => false, 'null' => true])->update();
        }
        if (!$table->hasIndexByName(self::REVISION_INDEX)) {
            $table->addIndex(['user_id', 'effective_from', 'interval_revision'], ['unique' => true, 'name' => self::REVISION_INDEX])->update();
        }
        if ($table->hasIndexByName(self::START_INDEX)) {
            $table->removeIndexByName(self::START_INDEX)->update();
        }
    }

    public function down(): void
    {
        $table = $this->table(self::ASSIGNMENTS);
        if ($table->hasColumn('interval_revision') && $this->fetchRow(
            'SELECT 1 FROM employee_work_time_assignments WHERE interval_revision <> 0 OR carried_from_assignment_id IS NOT NULL OR live_from_unix IS NOT NULL LIMIT 1'
        )) {
            throw new LogicException('Die bereits genutzte Zuordnungshistorie kann nicht verlustfrei zurückgesetzt werden.');
        }
        if (!$table->hasIndexByName(self::START_INDEX)) {
            $table->addIndex(['user_id', 'effective_from'], ['unique' => true, 'name' => self::START_INDEX])->update();
        }
        if ($table->hasIndexByName(self::REVISION_INDEX)) {
            $table->removeIndexByName(self::REVISION_INDEX)->update();
        }
        if ($table->hasForeignKey('carried_from_assignment_id')) {
            $table->dropForeignKey('carried_from_assignment_id')->update();
        }
        foreach (['live_from_unix', 'carried_from_assignment_id', 'interval_revision'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->update();
            }
        }
    }
}
