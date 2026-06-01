<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UniqueAccountingClosureTimesheets extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('accounting_closure_items')) {
            return;
        }

        $this->execute('ALTER TABLE accounting_closure_items ADD UNIQUE KEY uniq_accounting_closure_items_timesheet (timesheet_id)');
    }

    public function down(): void
    {
        if (!$this->hasTable('accounting_closure_items')) {
            return;
        }

        $this->execute('ALTER TABLE accounting_closure_items DROP INDEX uniq_accounting_closure_items_timesheet');
    }
}
