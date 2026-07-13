<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UniqueVacationYearOpeningGeneration extends AbstractMigration
{
    private const INDEX_NAME = 'uniq_vacation_account_entries_year_opening';

    public function up(): void
    {
        if (!$this->hasTable('vacation_account_entries')) {
            return;
        }

        $table = $this->table('vacation_account_entries');

        if ($table->hasIndexByName(self::INDEX_NAME)) {
            $table->removeIndexByName(self::INDEX_NAME)->update();
        }

        $table = $this->table('vacation_account_entries');
        $table->addIndex(
            ['user_id', 'cutover_id', 'leave_year', 'entry_type', 'source_type'],
            ['name' => self::INDEX_NAME, 'unique' => true]
        )->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('vacation_account_entries')) {
            return;
        }

        $table = $this->table('vacation_account_entries');

        if ($table->hasIndexByName(self::INDEX_NAME)) {
            $table->removeIndexByName(self::INDEX_NAME)->update();
        }

        $table = $this->table('vacation_account_entries');
        $table->addIndex(
            ['user_id', 'cutover_id', 'leave_year', 'entry_type', 'source_type'],
            ['name' => self::INDEX_NAME]
        )->update();
    }
}
