<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTimeTrackingRequiredToUsers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('users')) {
            return;
        }

        $table = $this->table('users');

        if (!$table->hasColumn('time_tracking_required')) {
            $table->addColumn('time_tracking_required', 'boolean', [
                'default' => true,
                'null' => false,
                'after' => 'target_hours_month',
            ])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('users')) {
            return;
        }

        $table = $this->table('users');

        if ($table->hasColumn('time_tracking_required')) {
            $table->removeColumn('time_tracking_required')->update();
        }
    }
}
