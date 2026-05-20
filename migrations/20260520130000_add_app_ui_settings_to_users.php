<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAppUiSettingsToUsers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('users')) {
            return;
        }

        $table = $this->table('users');

        if (!$table->hasColumn('app_ui_settings')) {
            $table->addColumn('app_ui_settings', 'text', [
                'null' => true,
                'after' => 'time_tracking_required',
            ])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('users')) {
            return;
        }

        $table = $this->table('users');

        if ($table->hasColumn('app_ui_settings')) {
            $table->removeColumn('app_ui_settings')->update();
        }
    }
}
