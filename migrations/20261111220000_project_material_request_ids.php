<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ProjectMaterialRequestIds extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('project_material_entries');
        if (!$table->hasColumn('client_request_id')) {
            $table->addColumn('client_request_id', 'string', ['limit' => 36, 'null' => true])->update();
        }
        if (!$table->hasColumn('request_payload_hash')) {
            $table->addColumn('request_payload_hash', 'string', ['limit' => 64, 'null' => true])->update();
        }
        if (!$table->hasIndexByName('uniq_project_material_author_request')) {
            $table->addIndex(['created_by_user_id', 'client_request_id'], [
                'unique' => true, 'name' => 'uniq_project_material_author_request',
            ])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('project_material_entries');
        if ($table->hasIndexByName('uniq_project_material_author_request')) {
            $table->removeIndexByName('uniq_project_material_author_request')->update();
        }
        foreach (['request_payload_hash', 'client_request_id'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->update();
            }
        }
    }
}
