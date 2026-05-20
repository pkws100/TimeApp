<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixZeroDurationTimesheets extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('timesheets')) {
            return;
        }

        $this->execute(
            'UPDATE timesheets
             SET gross_minutes = 0,
                 break_minutes = 0,
                 net_minutes = 0,
                 updated_at = NOW()
             WHERE entry_type = "work"
               AND start_time IS NOT NULL
               AND end_time IS NOT NULL
               AND start_time = end_time
               AND (gross_minutes >= 1439 OR net_minutes >= 1350)'
        );
    }

    public function down(): void
    {
        // Data correction only; intentionally not reversible.
    }
}
