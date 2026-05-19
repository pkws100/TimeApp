<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CalendarPolicyAndDocumentStatuses extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('company_settings')) {
            $table = $this->table('company_settings');

            if (!$table->hasColumn('holiday_region')) {
                $table->addColumn('holiday_region', 'string', ['limit' => 2, 'null' => true, 'after' => 'country'])->update();
            }
        }

        if (!$this->hasTable('company_closures')) {
            $this->table('company_closures')
                ->addColumn('title', 'string', ['limit' => 150])
                ->addColumn('date_from', 'date')
                ->addColumn('date_to', 'date')
                ->addColumn('year', 'integer')
                ->addColumn('notes', 'text', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['date_from', 'date_to'])
                ->addIndex(['year'])
                ->create();
        }

        if (!$this->hasTable('document_status_profiles')) {
            $this->table('document_status_profiles')
                ->addColumn('label', 'string', ['limit' => 100])
                ->addColumn('slug', 'string', ['limit' => 120])
                ->addColumn('color', 'string', ['limit' => 20, 'default' => '#64748b'])
                ->addColumn('sort_order', 'integer', ['default' => 100])
                ->addColumn('is_default', 'boolean', ['default' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('is_deleted', 'boolean', ['default' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addColumn('deleted_by_user_id', 'integer', ['signed' => false, 'null' => true])
                ->addForeignKey('deleted_by_user_id', 'users', 'id', ['delete' => 'SET_NULL'])
                ->addIndex(['slug'], ['unique' => true])
                ->addIndex(['is_default'])
                ->create();
        }

        $this->seedDocumentStatusProfiles();

        foreach (['project_files', 'asset_files', 'timesheet_files'] as $tableName) {
            if (!$this->hasTable($tableName)) {
                continue;
            }

            $table = $this->table($tableName);

            if (!$table->hasColumn('document_status_id')) {
                $table->addColumn('document_status_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'uploaded_by_user_id'])
                    ->update();
            }

            $table = $this->table($tableName);

            if (!$table->hasForeignKey('document_status_id')) {
                $table->addForeignKey('document_status_id', 'document_status_profiles', 'id', ['delete' => 'SET_NULL'])->update();
            }
        }
    }

    public function down(): void
    {
        foreach (['project_files', 'asset_files', 'timesheet_files'] as $tableName) {
            if (!$this->hasTable($tableName)) {
                continue;
            }

            $table = $this->table($tableName);

            if ($table->hasForeignKey('document_status_id')) {
                $table->dropForeignKey('document_status_id')->update();
            }

            $table = $this->table($tableName);

            if ($table->hasColumn('document_status_id')) {
                $table->removeColumn('document_status_id')->update();
            }
        }

        if ($this->hasTable('document_status_profiles')) {
            $this->table('document_status_profiles')->drop()->save();
        }

        if ($this->hasTable('company_closures')) {
            $this->table('company_closures')->drop()->save();
        }

        if ($this->hasTable('company_settings')) {
            $table = $this->table('company_settings');

            if ($table->hasColumn('holiday_region')) {
                $table->removeColumn('holiday_region')->update();
            }
        }
    }

    private function seedDocumentStatusProfiles(): void
    {
        $this->execute(
            "INSERT IGNORE INTO document_status_profiles (label, slug, color, sort_order, is_default, created_at, updated_at)
             VALUES
                ('Unbearbeitet', 'unbearbeitet', '#f59e0b', 10, 1, NOW(), NOW()),
                ('Bearbeitet', 'bearbeitet', '#2563eb', 20, 0, NOW(), NOW()),
                ('Verrechnet', 'verrechnet', '#16a34a', 30, 0, NOW(), NOW())"
        );

        $this->execute(
            "UPDATE document_status_profiles
             SET is_default = CASE WHEN slug = 'unbearbeitet' THEN 1 ELSE 0 END
             WHERE slug IN ('unbearbeitet', 'bearbeitet', 'verrechnet')"
        );
    }
}
