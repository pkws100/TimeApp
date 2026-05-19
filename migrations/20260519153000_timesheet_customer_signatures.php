<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimesheetCustomerSignatures extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('projects')) {
            $projects = $this->table('projects');

            if (!$projects->hasColumn('customer_signature_required')) {
                $projects
                    ->addColumn('customer_signature_required', 'boolean', ['default' => false, 'after' => 'customer_name'])
                    ->update();
            }

            $projects = $this->table('projects');

            if (!$projects->hasColumn('customer_signature_name')) {
                $projects
                    ->addColumn('customer_signature_name', 'string', ['limit' => 190, 'null' => true, 'after' => 'customer_signature_required'])
                    ->update();
            }
        }

        if ($this->hasTable('timesheet_customer_signatures')) {
            return;
        }

        $this->table('timesheet_customer_signatures', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
            ->addColumn('timesheet_id', 'integer', ['signed' => false])
            ->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('customer_name', 'string', ['limit' => 190])
            ->addColumn('customer_name_source', 'string', ['limit' => 32, 'default' => 'manual'])
            ->addColumn('confirmation_text', 'text')
            ->addColumn('signature_storage_path', 'string', ['limit' => 255])
            ->addColumn('signature_mime', 'string', ['limit' => 64, 'default' => 'image/png'])
            ->addColumn('signature_sha256', 'string', ['limit' => 64])
            ->addColumn('signature_size_bytes', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('signed_at', 'datetime')
            ->addColumn('client_signed_at', 'datetime', ['null' => true])
            ->addColumn('client_request_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('ip_hash', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_by_user_id', 'integer', ['signed' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('archived_at', 'datetime', ['null' => true])
            ->addColumn('archived_by_user_id', 'integer', ['signed' => false, 'null' => true])
            ->addForeignKey('timesheet_id', 'timesheets', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('project_id', 'projects', 'id', ['delete' => 'SET_NULL'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('created_by_user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('archived_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
            ->addIndex(['timesheet_id'])
            ->addIndex(['project_id'])
            ->addIndex(['user_id'])
            ->addIndex(['signed_at'])
            ->addIndex(['client_request_id'])
            ->addIndex(['signature_sha256'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('timesheet_customer_signatures')) {
            $this->table('timesheet_customer_signatures')->drop()->save();
        }

        if ($this->hasTable('projects')) {
            $projects = $this->table('projects');

            if ($projects->hasColumn('customer_signature_name')) {
                $projects->removeColumn('customer_signature_name')->update();
            }

            $projects = $this->table('projects');

            if ($projects->hasColumn('customer_signature_required')) {
                $projects->removeColumn('customer_signature_required')->update();
            }
        }
    }
}
