<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class ExpandSmtpPasswordForEncryptedSettings extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('company_settings')) {
            return;
        }

        $table = $this->table('company_settings');

        if ($table->hasColumn('smtp_password')) {
            $table->changeColumn('smtp_password', 'text', ['null' => true])->update();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('smtp_password bleibt text, damit verschluesselte Settings-Secrets nicht abgeschnitten werden.');
    }
}
