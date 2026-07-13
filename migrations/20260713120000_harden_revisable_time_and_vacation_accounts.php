<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class HardenRevisableTimeAndVacationAccounts extends AbstractMigration
{
    public function up(): void
    {
        $this->extendJournals();
        $this->extendAccountingClosures();
        $this->hardenAuditForeignKeys();
        $this->backfillCutoverIds();
    }

    public function down(): void
    {
        foreach (['time_account_entries', 'vacation_account_entries'] as $tableName) {
            if ($this->hasTable($tableName)) {
                $table = $this->table($tableName);

                if ($table->hasForeignKey('cutover_id')) {
                    $table->dropForeignKey('cutover_id')->update();
                }

                foreach ([
                    'idx_' . $tableName . '_cutover',
                    'idx_' . $tableName . '_user_cutover_date',
                    'uniq_' . $tableName . '_year_opening',
                ] as $indexName) {
                    if ($table->hasIndexByName($indexName)) {
                        $table->removeIndexByName($indexName)->update();
                    }
                }

                if ($table->hasColumn('cutover_id')) {
                    $table->removeColumn('cutover_id')->update();
                }
            }
        }

        if ($this->hasTable('accounting_closures')) {
            $table = $this->table('accounting_closures');

            foreach (['idx_accounting_closures_source', 'idx_accounting_closures_public'] as $indexName) {
                if ($table->hasIndexByName($indexName)) {
                    $table->removeIndexByName($indexName)->update();
                }
            }

            foreach (['source_type', 'source_id'] as $column) {
                if ($table->hasColumn($column)) {
                    $table->removeColumn($column)->update();
                }
            }
        }

        $this->replaceForeignKey('employee_account_cutovers', 'user_id', 'users', 'id', 'CASCADE');
        $this->replaceForeignKey('employee_account_cutovers', 'active_final_user_id', 'users', 'id', 'CASCADE');
        $this->replaceForeignKey('time_account_entries', 'user_id', 'users', 'id', 'CASCADE');
        $this->replaceForeignKey('vacation_account_entries', 'user_id', 'users', 'id', 'CASCADE');
    }

    private function extendJournals(): void
    {
        foreach (['time_account_entries', 'vacation_account_entries'] as $tableName) {
            if (!$this->hasTable($tableName) || !$this->hasTable('employee_account_cutovers')) {
                continue;
            }

            $table = $this->table($tableName);

            if (!$table->hasColumn('cutover_id')) {
                $table->addColumn('cutover_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'user_id'])->update();
            }

            $table = $this->table($tableName);

            if (!$table->hasIndexByName('idx_' . $tableName . '_cutover')) {
                $table->addIndex(['cutover_id'], ['name' => 'idx_' . $tableName . '_cutover'])->update();
            }

            $table = $this->table($tableName);

            if (!$table->hasIndexByName('idx_' . $tableName . '_user_cutover_date')) {
                $table->addIndex(['user_id', 'cutover_id', 'effective_date'], ['name' => 'idx_' . $tableName . '_user_cutover_date'])->update();
            }

            if ($tableName === 'vacation_account_entries') {
                $table = $this->table($tableName);

                if (!$table->hasIndexByName('uniq_vacation_account_entries_year_opening')) {
                    $table->addIndex(
                        ['user_id', 'cutover_id', 'leave_year', 'entry_type', 'source_type'],
                        ['name' => 'uniq_vacation_account_entries_year_opening', 'unique' => true]
                    )->update();
                }
            }

            $table = $this->table($tableName);

            if (!$table->hasForeignKey('cutover_id')) {
                $table->addForeignKey('cutover_id', 'employee_account_cutovers', 'id', ['delete' => 'RESTRICT'])->update();
            }
        }

    }

    private function extendAccountingClosures(): void
    {
        if (!$this->hasTable('accounting_closures')) {
            return;
        }

        $table = $this->table('accounting_closures');

        if (!$table->hasColumn('source_type')) {
            $table->addColumn('source_type', 'string', ['limit' => 80, 'null' => true, 'after' => 'original_closure_id'])->update();
        }

        $table = $this->table('accounting_closures');

        if (!$table->hasColumn('source_id')) {
            $table->addColumn('source_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'source_type'])->update();
        }

        $table = $this->table('accounting_closures');

        if (!$table->hasIndexByName('idx_accounting_closures_source')) {
            $table->addIndex(['source_type', 'source_id'], ['name' => 'idx_accounting_closures_source'])->update();
        }

        $this->execute(
            'UPDATE accounting_closures
             SET source_type = "employee_account_cutover",
                 source_id = CAST(SUBSTRING(closure_number, 4) AS UNSIGNED)
             WHERE source_type IS NULL
               AND closure_number LIKE "ZK-%"'
        );
    }

    private function hardenAuditForeignKeys(): void
    {
        $this->replaceForeignKey('employee_account_cutovers', 'user_id', 'users', 'id', 'RESTRICT');
        $this->replaceForeignKey('employee_account_cutovers', 'active_final_user_id', 'users', 'id', 'RESTRICT');
        $this->replaceForeignKey('time_account_entries', 'user_id', 'users', 'id', 'RESTRICT');
        $this->replaceForeignKey('vacation_account_entries', 'user_id', 'users', 'id', 'RESTRICT');
    }

    private function backfillCutoverIds(): void
    {
        foreach (['time_account_entries', 'vacation_account_entries'] as $tableName) {
            if (!$this->hasTable($tableName) || !$this->table($tableName)->hasColumn('cutover_id')) {
                continue;
            }

            $this->execute(
                'UPDATE ' . $tableName . ' AS entries
                 INNER JOIN employee_account_cutovers AS cutovers
                    ON entries.source_type = "employee_account_cutover"
                   AND entries.source_id = cutovers.id
                 SET entries.cutover_id = cutovers.id
                 WHERE entries.cutover_id IS NULL'
            );

            $this->execute(
                'UPDATE ' . $tableName . ' AS reversal
                 INNER JOIN ' . $tableName . ' AS original
                    ON original.id = reversal.reversal_of_id
                 SET reversal.cutover_id = original.cutover_id
                 WHERE reversal.cutover_id IS NULL
                   AND original.cutover_id IS NOT NULL'
            );

            $this->execute(
                'UPDATE ' . $tableName . ' AS entries
                 INNER JOIN employee_account_cutovers AS cutovers
                    ON cutovers.user_id = entries.user_id
                   AND cutovers.status IN ("final", "reversed")
                   AND entries.effective_date >= cutovers.effective_from
                   AND entries.created_at >= cutovers.created_at
                 LEFT JOIN employee_account_cutovers AS newer
                    ON newer.user_id = entries.user_id
                   AND newer.status IN ("final", "reversed")
                   AND newer.effective_from > cutovers.effective_from
                   AND entries.effective_date >= newer.effective_from
                 SET entries.cutover_id = cutovers.id
                 WHERE entries.cutover_id IS NULL
                   AND newer.id IS NULL'
            );
        }
    }

    private function replaceForeignKey(string $tableName, string $columnName, string $referencedTable, string $referencedColumn, string $deleteRule): void
    {
        if (!$this->hasTable($tableName)) {
            return;
        }

        $constraints = $this->fetchAll(
            "SELECT constraint_name
             FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE()
               AND table_name = '" . addslashes($tableName) . "'
               AND column_name = '" . addslashes($columnName) . "'
               AND referenced_table_name IS NOT NULL"
        );

        foreach ($constraints as $constraint) {
            $name = (string) ($constraint['constraint_name'] ?? '');

            if ($name !== '') {
                $this->execute('ALTER TABLE `' . $tableName . '` DROP FOREIGN KEY `' . $name . '`');
            }
        }

        $this->execute(
            'ALTER TABLE `' . $tableName . '`
             ADD CONSTRAINT `fk_' . $tableName . '_' . $columnName . '_hardened`
             FOREIGN KEY (`' . $columnName . '`)
             REFERENCES `' . $referencedTable . '` (`' . $referencedColumn . '`)
             ON DELETE ' . $deleteRule
        );
    }
}
