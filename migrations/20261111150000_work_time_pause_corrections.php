<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Document actual break evidence without rewriting versioned work or net minutes. */
final class WorkTimePauseCorrections extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('work_time_pause_corrections')) {
            return;
        }

        $this->table('work_time_pause_corrections')
            ->addColumn('timesheet_id', 'integer', ['signed' => false])
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('actor_user_id', 'integer', ['signed' => false])
            ->addColumn('request_id', 'string', ['limit' => 80])
            ->addColumn('reason', 'text')
            ->addColumn('source_token', 'string', ['limit' => 64])
            ->addColumn('intervals_json', 'text')
            ->addColumn('before_json', 'text')
            ->addColumn('after_json', 'text')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
            ->addIndex(['actor_user_id', 'request_id'], ['unique' => true, 'name' => 'uniq_work_time_pause_request'])
            ->addIndex(['timesheet_id', 'id'], ['name' => 'idx_work_time_pause_history'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('work_time_pause_corrections')) {
            $this->table('work_time_pause_corrections')->drop()->save();
        }
    }
}
