<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixVacationAccountJournalUniqueness extends AbstractMigration
{
    private const BROKEN_INDEX = 'uniq_vacation_account_entries_year_opening';
    private const SOURCE_INDEX = 'uniq_vacation_account_entries_source_entry';

    public function up(): void
    {
        $this->ensureCorrectUniqueness();
    }

    public function down(): void
    {
        // The broken year-wide index is intentionally not restored because valid
        // reversal rows created after this migration would make rollback unsafe.
        $this->ensureCorrectUniqueness();
    }

    private function ensureCorrectUniqueness(): void
    {
        if (!$this->hasTable('vacation_account_entries')) {
            return;
        }

        $table = $this->table('vacation_account_entries');

        if ($table->hasIndexByName(self::BROKEN_INDEX)) {
            $table->removeIndexByName(self::BROKEN_INDEX)->update();
        }

        if ($this->hasSourceEntryUniqueIndex()) {
            return;
        }

        $this->table('vacation_account_entries')
            ->addIndex(
                ['source_type', 'source_id', 'entry_type'],
                ['name' => self::SOURCE_INDEX, 'unique' => true]
            )
            ->update();
    }

    private function hasSourceEntryUniqueIndex(): bool
    {
        $rows = $this->fetchAll(
            'SELECT index_name,
                    GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ",") AS indexed_columns
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = "vacation_account_entries"
               AND non_unique = 0
             GROUP BY index_name'
        );

        foreach ($rows as $row) {
            if ((string) ($row['indexed_columns'] ?? '') === 'source_type,source_id,entry_type') {
                return true;
            }
        }

        return false;
    }
}
