<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AccountingPauseAssessments extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('accounting_closure_items');
        if (!$table->hasColumn('pause_assessment_json')) {
            $table->addColumn('pause_assessment_json', 'text', ['null' => true])->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('accounting_closure_items');
        if ($table->hasColumn('pause_assessment_json')) {
            if ((int) $this->fetchRow('SELECT COUNT(*) AS count FROM accounting_closure_items WHERE pause_assessment_json IS NOT NULL')['count'] > 0) {
                throw new RuntimeException('Festgeschriebene Pausenprüfungen können nicht entfernt werden.');
            }
            $table->removeColumn('pause_assessment_json')->update();
        }
    }
}
