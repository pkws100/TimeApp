<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RepairJournalCutoverGenerations extends AbstractMigration
{
    public function up(): void
    {
        foreach (['time_account_entries', 'vacation_account_entries'] as $tableName) {
            $this->repairTable($tableName);
        }
    }

    public function down(): void
    {
        // Data assignments are not guessed back into their former state.
    }

    private function repairTable(string $tableName): void
    {
        if (!$this->hasTable($tableName) || !$this->table($tableName)->hasColumn('cutover_id')) {
            return;
        }

        $quotedTable = '`' . $tableName . '`';
        $temporaryTable = 'tmp_' . $tableName . '_cutover_candidates';
        $legacyCutoff = $this->legacyBackfillFinishedAt();

        $this->execute(
            'UPDATE ' . $quotedTable . ' AS entries
             LEFT JOIN employee_account_cutovers AS cutovers
               ON cutovers.id = entries.source_id
              AND cutovers.user_id = entries.user_id
             SET entries.cutover_id = cutovers.id
             WHERE entries.source_type = "employee_account_cutover"'
        );

        $this->execute('DROP TEMPORARY TABLE IF EXISTS `' . $temporaryTable . '`');
        $this->execute(
            'CREATE TEMPORARY TABLE `' . $temporaryTable . '` AS
             SELECT entries.id AS entry_id,
                    COUNT(cutovers.id) AS candidate_count,
                    MIN(cutovers.id) AS candidate_id
             FROM ' . $quotedTable . ' AS entries
             LEFT JOIN employee_account_cutovers AS cutovers
               ON cutovers.user_id = entries.user_id
              AND cutovers.status IN ("final", "reversed")
              AND entries.effective_date >= cutovers.effective_from
              AND entries.created_at >= cutovers.created_at
             WHERE entries.reversal_of_id IS NULL
               AND COALESCE(entries.source_type, "") <> "employee_account_cutover"
               AND entries.created_at <= "' . addslashes($legacyCutoff) . '"
             GROUP BY entries.id'
        );
        $this->execute(
            'UPDATE ' . $quotedTable . ' AS entries
             INNER JOIN `' . $temporaryTable . '` AS candidates ON candidates.entry_id = entries.id
             SET entries.cutover_id = CASE
                 WHEN candidates.candidate_count = 1 THEN candidates.candidate_id
                 ELSE NULL
             END'
        );
        $this->execute(
            'UPDATE ' . $quotedTable . ' AS reversal
             LEFT JOIN ' . $quotedTable . ' AS original ON original.id = reversal.reversal_of_id
             SET reversal.cutover_id = original.cutover_id
             WHERE reversal.reversal_of_id IS NOT NULL'
        );
        $this->execute('DROP TEMPORARY TABLE IF EXISTS `' . $temporaryTable . '`');
    }

    private function legacyBackfillFinishedAt(): string
    {
        if (!$this->hasTable('phinxlog')) {
            return '9999-12-31 23:59:59';
        }

        $rows = $this->fetchAll(
            'SELECT end_time FROM phinxlog WHERE version = 20260713120000 LIMIT 1'
        );
        $endTime = trim((string) ($rows[0]['end_time'] ?? ''));

        return $endTime !== '' ? $endTime : '9999-12-31 23:59:59';
    }
}
