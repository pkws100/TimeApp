<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class AppointmentDispatchProjectScope extends AbstractMigration
{
    public function up(): void
    {
        $dispatches = $this->table('appointment_dispatches');
        if (!$dispatches->hasColumn('project_id')) {
            $dispatches->addColumn('project_id', 'integer', ['signed' => false, 'null' => true])->update();
        }
        if (!$dispatches->hasColumn('project_scope_known')) {
            $dispatches->addColumn('project_scope_known', 'boolean', ['default' => false])->update();
        }
        if (!$dispatches->hasForeignKey('project_id')) {
            $dispatches->addForeignKey('project_id', 'projects', 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])->update();
        }
    }

    public function preFlightCheck(): void
    {
        parent::preFlightCheck();
        if (!$this->isMigratingUp()) {
            $this->down();
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Der Rücklauf würde die historische Projektbindung des Terminversands entfernen.');
    }
}
