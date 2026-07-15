<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class NfcTagRelearnAudit extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('nfc_tags')) {
            return;
        }

        $table = $this->table('nfc_tags');
        if (!$table->hasColumn('relearned_from_archive_at')) {
            $table->addColumn('relearned_from_archive_at', 'datetime', ['null' => true])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('nfc_tags')) {
            return;
        }

        $table = $this->table('nfc_tags');
        if ($table->hasColumn('relearned_from_archive_at')) {
            $table->removeColumn('relearned_from_archive_at')->update();
        }
    }
}
