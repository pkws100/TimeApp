<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimesheetSource extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('timesheets')) {
            return;
        }

        $this->execute('SET @@system_versioning_alter_history = 1;');

        $table = $this->table('timesheets');

        if (!$table->hasColumn('source')) {
            $table->addColumn('source', 'string', ['limit' => 40, 'default' => 'app', 'after' => 'entry_type']);
            $table->update();
        }

        $table = $this->table('timesheets');

        if (!$table->hasIndex(['source'])) {
            $table->addIndex(['source'])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('timesheets')) {
            return;
        }

        $this->execute('SET @@system_versioning_alter_history = 1;');

        $table = $this->table('timesheets');

        if ($table->hasIndex(['source'])) {
            $table->removeIndex(['source'])->update();
        }

        $table = $this->table('timesheets');

        if ($table->hasColumn('source')) {
            $table->removeColumn('source')->update();
        }
    }
}
