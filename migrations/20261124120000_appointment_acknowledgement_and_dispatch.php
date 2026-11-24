<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class AppointmentAcknowledgementAndDispatch extends AbstractMigration
{
    public function up(): void
    {
        $participants = $this->table('appointment_participants');
        if (!$participants->hasColumn('acknowledged_at_utc')) {
            $participants->addColumn('acknowledged_at_utc', 'datetime', ['null' => true])->update();
        }
        if (!$participants->hasColumn('acknowledged_version')) {
            $participants->addColumn('acknowledged_version', 'integer', ['signed' => false, 'null' => true])->update();
        }

        $unsigned = ['signed' => false];
        $counter = $unsigned + ['default' => 0];
        if (!$this->hasTable('appointment_dispatches')) {
            $this->table('appointment_dispatches', ['signed' => false])
                ->addColumn('appointment_id', 'integer', $unsigned)
                ->addColumn('appointment_version', 'integer', $unsigned)
                ->addColumn('sent_by_user_id', 'integer', $unsigned)
                ->addColumn('request_key', 'string', ['limit' => 32])
                ->addColumn('channel', 'enum', ['values' => ['push'], 'default' => 'push'])
                ->addColumn('title', 'string', ['limit' => 180])
                ->addColumn('body', 'string', ['limit' => 500])
                ->addColumn('target_url', 'string', ['limit' => 255])
                ->addColumn('created_at_utc', 'datetime')
                ->addColumn('completed_at_utc', 'datetime', ['null' => true])
                ->addIndex(['appointment_id', 'sent_by_user_id', 'request_key'], ['unique' => true, 'name' => 'appointment_dispatch_request'])
                ->addIndex(['appointment_id', 'id'])
                ->create();
        }
        $this->ensureReferences('appointment_dispatches', ['appointment_id' => 'appointments', 'sent_by_user_id' => 'users']);

        if (!$this->hasTable('appointment_dispatch_recipients')) {
            $this->table('appointment_dispatch_recipients', ['signed' => false])
                ->addColumn('dispatch_id', 'integer', $unsigned)
                ->addColumn('user_id', 'integer', $unsigned)
                ->addColumn('recipient_name', 'string', ['limit' => 255])
                ->addColumn('has_push_permission', 'boolean')
                ->addColumn('active_subscription_count', 'integer', $counter)
                ->addColumn('accepted_subscription_count', 'integer', $counter)
                ->addColumn('failed_subscription_count', 'integer', $counter)
                ->addColumn('uncertain_subscription_count', 'integer', $counter)
                ->addColumn('status', 'string', ['limit' => 32, 'default' => 'pending'])
                ->addColumn('error_message', 'string', ['limit' => 255, 'null' => true])
                ->addIndex(['dispatch_id', 'user_id'], ['unique' => true])
                ->create();
        }
        $this->ensureReferences('appointment_dispatch_recipients', ['dispatch_id' => 'appointment_dispatches', 'user_id' => 'users']);

        $this->execute(<<<'SQL'
            INSERT INTO permissions (code, label, scope, created_at)
            VALUES ('appointments.dispatch', 'Termine per Push versenden', 'backend', NOW())
            ON DUPLICATE KEY UPDATE label = VALUES(label), scope = VALUES(scope)
            SQL);
        $this->execute(<<<'SQL'
            INSERT IGNORE INTO role_permissions (role_id, permission_id)
            SELECT roles.id, permissions.id
            FROM roles JOIN permissions ON permissions.code = 'appointments.dispatch'
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
        throw new IrreversibleMigrationException('Der Rücklauf würde Terminbestätigungen und Versandhistorie entfernen.');
    }
}
