<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AccountingDocumentSummaries extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('accounting_closures');
        if (!$table->hasColumn('document_summary_json')) {
            $table->addColumn('document_summary_json', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG])->update();
        }
    }

    public function down(): void
    {
        // A rollback must also leave the earlier forward-only checkout/compliance boundary intact.
        throw new \Phinx\Migration\IrreversibleMigrationException('Festgeschriebene Abrechnungsnachweise können nicht entfernt werden.');
    }

    public function preFlightCheck(): void
    {
        parent::preFlightCheck();
        if (!$this->isMigratingUp()) { $this->down(); }
    }
}
