<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MobileAppAuthAndSync extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('app_sync_operations')) {
            $this->table('app_sync_operations')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('client_request_id', 'string', ['limit' => 120])
                ->addColumn('operation_type', 'string', ['limit' => 50])
                ->addColumn('response_json', 'text')
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addIndex(['client_request_id'], ['unique' => true])
                ->create();
        }

        if (!$this->hasTable('timesheet_geo_records')) {
            $this->table('timesheet_geo_records')
                ->addColumn('timesheet_id', 'integer', ['signed' => false])
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('work_date', 'date')
                ->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 7])
                ->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 7])
                ->addColumn('accuracy_meters', 'integer', ['null' => true])
                ->addColumn('recorded_at', 'datetime')
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addIndex(['timesheet_id'])
                ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('timesheet_geo_records')) {
            $this->table('timesheet_geo_records')->drop()->save();
        }

        if ($this->hasTable('app_sync_operations')) {
            $this->table('app_sync_operations')->drop()->save();
        }
    }
}
