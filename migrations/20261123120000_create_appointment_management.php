<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CreateAppointmentManagement extends AbstractMigration
{
    public function up(): void
    {
        $unsigned = ['signed' => false];
        $optionalReference = ['signed' => false, 'null' => true];

        if (!$this->hasTable('appointments')) {
            $this->table('appointments', ['signed' => false])
                ->addColumn('title', 'string', ['limit' => 180])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('starts_at_utc', 'datetime')
                ->addColumn('ends_at_utc', 'datetime')
                ->addColumn('timezone', 'string', ['limit' => 64, 'default' => 'Europe/Berlin'])
                ->addColumn('project_id', 'integer', $optionalReference)
                ->addColumn('location', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('status', 'enum', ['values' => ['planned', 'completed', 'cancelled'], 'default' => 'planned'])
                ->addColumn('created_by_user_id', 'integer', $unsigned)
                ->addColumn('updated_by_user_id', 'integer', $unsigned)
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addColumn('archived_at', 'datetime', ['null' => true])
                ->addColumn('archived_by_user_id', 'integer', $optionalReference)
                ->addColumn('version', 'integer', ['signed' => false, 'default' => 1])
                ->addIndex(['archived_at', 'starts_at_utc', 'id'])
                ->addIndex(['archived_at', 'ends_at_utc'])
                ->addIndex(['project_id', 'archived_at', 'starts_at_utc'])
                ->create();
        }
        $this->ensureReferences('appointments', [
            'project_id' => 'projects',
            'created_by_user_id' => 'users',
            'updated_by_user_id' => 'users',
            'archived_by_user_id' => 'users',
        ]);

        if (!$this->hasTable('appointment_participants')) {
            $this->table('appointment_participants', ['signed' => false])
                ->addColumn('appointment_id', 'integer', $unsigned)
                ->addColumn('user_id', 'integer', $unsigned)
                ->addColumn('created_at', 'datetime')
                ->addIndex(['appointment_id', 'user_id'], ['unique' => true])
                ->addIndex(['user_id', 'appointment_id'])
                ->create();
        }
        $this->ensureReferences('appointment_participants', ['appointment_id' => 'appointments', 'user_id' => 'users']);

        $this->execute(<<<'SQL'
            INSERT INTO permissions (code, label, scope, created_at)
            VALUES ('appointments.view', 'Termine ansehen', 'backend', NOW()),
                   ('appointments.manage', 'Termine verwalten', 'backend', NOW())
            ON DUPLICATE KEY UPDATE label = VALUES(label), scope = VALUES(scope)
            SQL);
        $this->execute(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles JOIN permissions ON permissions.code IN ('appointments.view', 'appointments.manage')
            WHERE roles.is_system_role = 1
              AND roles.slug IN ('administrator', 'geschaeftsfuehrung', 'disposition', 'bauleiter')
            SQL);
    }

    private function ensureReferences(string $tableName, array $references): void
    {
        $table = $this->table($tableName);
        foreach ($references as $column => $reference) {
            if (!$table->hasForeignKey($column)) {
                $table->addForeignKey($column, $reference, 'id', ['delete' => 'RESTRICT', 'update' => 'RESTRICT'])->update();
            }
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
        throw new IrreversibleMigrationException('Der Rücklauf würde gespeicherte Termine und Teilnehmerzuordnungen gefährden.');
    }
}
