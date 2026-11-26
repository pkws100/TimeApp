<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class WorkTimeAutomaticCheckouts extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('work_time_automatic_checkouts')) {
            return;
        }

        $this->table('work_time_automatic_checkouts', ['id' => false, 'primary_key' => ['timesheet_id']])
            ->addColumn('timesheet_id', 'integer', ['signed' => false])
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('policy_version_id', 'integer', ['signed' => false])
            ->addColumn('closed_break_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('work_date', 'date')
            ->addColumn('start_time', 'time')
            ->addColumn('ended_at', 'datetime')
            ->addColumn('recorded_at', 'datetime')
            ->addColumn('sync_request_id', 'string', ['limit' => 120])
            ->addIndex(['user_id', 'ended_at'], ['name' => 'idx_work_time_automatic_checkout_employee'])
            ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('policy_version_id', 'work_time_policy_versions', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('closed_break_id', 'timesheet_breaks', 'id', ['delete' => 'RESTRICT'])
            ->create();
    }

    public function preFlightCheck(): void
    {
        parent::preFlightCheck();
        if (!$this->isMigratingUp()) {
            $this->down();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Der Rücklauf würde die Nachweise automatischer Check-outs entfernen.');
    }
}
