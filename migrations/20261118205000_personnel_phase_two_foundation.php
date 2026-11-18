<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class PersonnelPhaseTwoFoundation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE employee_documents
            ADD COLUMN IF NOT EXISTS lock_version INT UNSIGNED NOT NULL DEFAULT 1,
            ADD CONSTRAINT IF NOT EXISTS ck_employee_document_lock_version CHECK (lock_version > 0)');
        $this->execute(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS protect_employee_document_source BEFORE UPDATE ON employee_documents FOR EACH ROW
            BEGIN
                IF NOT (BINARY OLD.source <=> BINARY NEW.source) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Die Erfassungsquelle des Dokuments ist unveränderlich.';
                END IF;
            END
            SQL);

        $this->execute('ALTER TABLE employee_employment_contexts
            ADD COLUMN IF NOT EXISTS supersedes_context_id INT UNSIGNED NULL,
            ADD UNIQUE INDEX IF NOT EXISTS uq_employee_context_successor (supersedes_context_id),
            ADD UNIQUE INDEX IF NOT EXISTS uq_employee_context_employment (id, user_id, employment_key),
            ADD CONSTRAINT IF NOT EXISTS ck_employee_context_predecessor CHECK (supersedes_context_id IS NULL OR revision > 1)');
        $contexts = $this->table('employee_employment_contexts');
        if (!$contexts->hasForeignKey(['supersedes_context_id', 'user_id', 'employment_key'])) {
            $contexts->addForeignKey(['supersedes_context_id', 'user_id', 'employment_key'], 'employee_employment_contexts', ['id', 'user_id', 'employment_key'], ['delete' => 'RESTRICT'])->update();
        }

        $this->execute('ALTER TABLE employee_document_audit ADD COLUMN IF NOT EXISTS related_document_id INT UNSIGNED NULL');
        $audit = $this->table('employee_document_audit');
        if (!$audit->hasForeignKey('related_document_id')) {
            $audit->addForeignKey('related_document_id', 'employee_documents', 'id', ['delete' => 'RESTRICT'])->update();
        }

        $scope = $this->table('employee_personnel_scope_events', ['engine' => 'InnoDB']);
        if (!$scope->exists()) {
            $scope->addColumn('employee_user_id', 'integer', ['signed' => false])
                ->addColumn('grantee_user_id', 'integer', ['signed' => false])
                ->addColumn('changed_by_user_id', 'integer', ['signed' => false])
                ->addColumn('is_granted', 'boolean')
                ->addColumn('reason_code', 'string', ['limit' => 48])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['grantee_user_id', 'employee_user_id', 'id'], ['name' => 'ix_employee_personnel_scope_latest'])
                ->create();
        }
        $scope = $this->table('employee_personnel_scope_events');
        foreach (['employee_user_id', 'grantee_user_id', 'changed_by_user_id'] as $userColumn) {
            if (!$scope->hasForeignKey($userColumn)) {
                $scope->addForeignKey($userColumn, 'users', 'id', ['delete' => 'RESTRICT']);
            }
        }
        $scope->update();
        $this->execute('ALTER TABLE employee_personnel_scope_events
            ADD CONSTRAINT IF NOT EXISTS ck_employee_personnel_scope_granted CHECK (is_granted IN (0, 1)),
            ADD CONSTRAINT IF NOT EXISTS ck_employee_personnel_scope_reason CHECK (CHAR_LENGTH(TRIM(reason_code)) > 0)');

        $commands = $this->table('personnel_command_receipts', ['id' => false, 'primary_key' => ['actor_user_id', 'request_hash'], 'engine' => 'InnoDB']);
        if (!$commands->exists()) {
            $commands->addColumn('actor_user_id', 'integer', ['signed' => false])
                ->addColumn('request_hash', 'char', ['limit' => 64])
                ->addColumn('payload_hash', 'char', ['limit' => 64])
                ->addColumn('operation', 'string', ['limit' => 64])
                ->addColumn('result_json', 'json')
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }
        $commands = $this->table('personnel_command_receipts');
        if (!$commands->hasForeignKey('actor_user_id')) {
            $commands->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])->update();
        }

        foreach (['employee_personnel_scope_events', 'personnel_command_receipts'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                $name = 'deny_' . strtolower($action) . '_' . $table;
                $this->execute(<<<SQL
                    CREATE TRIGGER IF NOT EXISTS `{$name}` BEFORE {$action} ON `{$table}` FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Personalakten-Historie ist unveränderlich.'
                    SQL);
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
        throw new IrreversibleMigrationException('Historie und Auftragsbelege der Personalakte erfordern eine separat geprüfte Datenbankwiederherstellung.');
    }
}
