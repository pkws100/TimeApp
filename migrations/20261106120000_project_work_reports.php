<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ProjectWorkReports extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('project_work_reports')) {
            return;
        }

        $this->table('project_work_reports')
            ->addColumn('project_id', 'integer', ['signed' => false])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('request_id', 'string', ['limit' => 36])
            ->addColumn('work_date', 'date')
            ->addColumn('description', 'text')
            ->addColumn('is_deleted', 'boolean', ['default' => false])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'RESTRICT'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['created_by_user_id', 'request_id'], ['unique' => true, 'name' => 'uniq_project_report_author_request'])
            ->addIndex(['project_id', 'is_deleted', 'work_date'], ['name' => 'idx_project_report_active_date'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('project_work_reports')) {
            $this->table('project_work_reports')->drop()->save();
        }
    }
}
