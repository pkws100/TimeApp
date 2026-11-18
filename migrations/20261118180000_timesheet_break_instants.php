<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimesheetBreakInstants extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('timesheet_breaks');
        foreach (['break_started_unix', 'break_ended_unix'] as $column) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'biginteger', ['null' => true]);
            }
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('timesheet_breaks');
        $columns = array_filter(['break_started_unix', 'break_ended_unix'], $table->hasColumn(...));
        foreach ($columns as $column) {
            if ($this->fetchRow('SELECT id FROM timesheet_breaks WHERE ' . $column . ' IS NOT NULL LIMIT 1')) {
                throw new RuntimeException('Pausen mit eindeutig dokumentierter Zeitumstellung sind vorhanden. Rückkehr nur über eine gesicherte Datenbankwiederherstellung.');
            }
            $table->removeColumn($column);
        }
        $table->update();
    }
}
