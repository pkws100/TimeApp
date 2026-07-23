<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ProjectWorkOrders extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('projects')) {
            $projects = $this->table('projects');

            if (!$projects->hasColumn('work_instructions')) {
                $projects->addColumn('work_instructions', 'text', ['null' => true, 'after' => 'customer_name']);
            }

            if (!$projects->hasColumn('work_instructions_updated_at')) {
                $projects->addColumn('work_instructions_updated_at', 'datetime', ['null' => true, 'after' => 'work_instructions']);
            }

            if (!$projects->hasColumn('work_instructions_updated_by_user_id')) {
                $projects->addColumn('work_instructions_updated_by_user_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'work_instructions_updated_at',
                ]);
            }

            $projects->update();
            $projects = $this->table('projects');

            if (!$projects->hasForeignKey('work_instructions_updated_by_user_id')) {
                $projects->addForeignKey(
                    'work_instructions_updated_by_user_id',
                    'users',
                    'id',
                    ['delete' => 'SET_NULL']
                )->update();
            }
        }

        if (!$this->hasTable('project_material_entries')) {
            $this->table('project_material_entries')
                ->addColumn('project_id', 'integer', ['signed' => false])
                ->addColumn('created_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('work_date', 'date')
                ->addColumn('description', 'string', ['limit' => 255])
                ->addColumn('quantity', 'decimal', ['precision' => 12, 'scale' => 3])
                ->addColumn('unit', 'string', ['limit' => 40, 'null' => true])
                ->addColumn('note', 'text', ['null' => true])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['project_id'])
                ->addIndex(['work_date'])
                ->addIndex(['created_by_user_id'])
                ->addIndex(['project_id', 'is_deleted', 'work_date'], ['name' => 'idx_project_material_active_date'])
                ->create();
        }

        if (!$this->hasTable('project_dispatches')) {
            $this->table('project_dispatches')
                ->addColumn('project_id', 'integer', ['signed' => false])
                ->addColumn('dispatch_kind', 'string', ['limit' => 20])
                ->addColumn('title', 'string', ['limit' => 180])
                ->addColumn('body', 'text')
                ->addColumn('target_url', 'string', ['limit' => 500])
                ->addColumn('sent_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('recipient_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('delivered_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('skipped_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('error_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'RESTRICT'])
                ->addForeignKey('sent_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['project_id', 'created_at'], ['name' => 'idx_project_dispatch_project_date'])
                ->create();
        }

        if (!$this->hasTable('project_dispatch_recipients')) {
            $this->table('project_dispatch_recipients')
                ->addColumn('dispatch_id', 'integer', ['signed' => false])
                ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('status', 'string', ['limit' => 20])
                ->addColumn('active_subscription_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('successful_subscription_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('failed_subscription_count', 'integer', ['signed' => false, 'default' => 0])
                ->addColumn('result_message', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('dispatch_id', 'project_dispatches', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['dispatch_id', 'user_id'], ['unique' => true, 'name' => 'uniq_project_dispatch_recipient'])
                ->addIndex(['user_id'])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['project_dispatch_recipients', 'project_dispatches', 'project_material_entries'] as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }

        if (!$this->hasTable('projects')) {
            return;
        }

        $projects = $this->table('projects');

        if ($projects->hasForeignKey('work_instructions_updated_by_user_id')) {
            $projects->dropForeignKey('work_instructions_updated_by_user_id')->update();
        }

        $projects = $this->table('projects');

        foreach (['work_instructions_updated_by_user_id', 'work_instructions_updated_at', 'work_instructions'] as $column) {
            if ($projects->hasColumn($column)) {
                $projects->removeColumn($column);
            }
        }

        $projects->update();
    }
}
