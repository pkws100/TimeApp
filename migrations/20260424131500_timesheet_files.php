<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimesheetFiles extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('timesheet_files')) {
            return;
        }

        $this->table('timesheet_files')
            ->addColumn('timesheet_id', 'integer', ['signed' => false])
            ->addColumn('original_name', 'string', ['limit' => 255])
            ->addColumn('stored_name', 'string', ['limit' => 255])
            ->addColumn('mime_type', 'string', ['limit' => 150])
            ->addColumn('size_bytes', 'biginteger')
            ->addColumn('storage_path', 'string', ['limit' => 255])
            ->addColumn('uploaded_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('uploaded_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('is_deleted', 'boolean', ['default' => false])
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('uploaded_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['timesheet_id'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('timesheet_files')) {
            $this->table('timesheet_files')->drop()->save();
        }
    }
}
