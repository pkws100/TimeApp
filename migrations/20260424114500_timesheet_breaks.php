<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimesheetBreaks extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('timesheet_breaks')) {
            return;
        }

        $this->table('timesheet_breaks')
            ->addColumn('timesheet_id', 'integer', ['signed' => false])
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('break_started_at', 'datetime')
            ->addColumn('break_ended_at', 'datetime', ['null' => true])
            ->addColumn('source', 'string', ['limit' => 40, 'default' => 'app'])
            ->addColumn('note', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addIndex(['timesheet_id'])
            ->addIndex(['user_id', 'break_started_at'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('timesheet_breaks')) {
            $this->table('timesheet_breaks')->drop()->save();
        }
    }
}
