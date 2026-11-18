<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class PersonnelTemporalHistoryAndAccess extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE employee_employment_contexts
            ADD COLUMN IF NOT EXISTS continues_context_id INT UNSIGNED NULL,
            ADD CONSTRAINT IF NOT EXISTS ck_employee_context_continuation
                CHECK (continues_context_id IS NULL OR (revision > 1 AND supersedes_context_id IS NULL))');
        $contexts = $this->table('employee_employment_contexts');
        if (!$contexts->hasForeignKey(['continues_context_id', 'user_id', 'employment_key'])) {
            $contexts->addForeignKey(['continues_context_id', 'user_id', 'employment_key'], 'employee_employment_contexts', ['id', 'user_id', 'employment_key'], ['delete' => 'RESTRICT'])->update();
        }

        $this->execute('ALTER TABLE employee_documents
            ADD UNIQUE INDEX IF NOT EXISTS uq_employee_document_owner (id, user_id)');
        $this->execute('ALTER TABLE employee_document_access_log
            ADD COLUMN IF NOT EXISTS employee_user_id INT UNSIGNED NULL,
            ADD INDEX IF NOT EXISTS ix_employee_document_access_employee (employee_user_id, created_at, id),
            ADD CONSTRAINT IF NOT EXISTS ck_employee_access_allowed_employee
                CHECK (employee_user_id IS NULL OR outcome = "allowed")');
        $accessLog = $this->table('employee_document_access_log');
        if (!$accessLog->hasForeignKey('employee_user_id')) {
            $accessLog->addForeignKey('employee_user_id', 'users', 'id', ['delete' => 'RESTRICT']);
        }
        if (!$accessLog->hasForeignKey(['document_id', 'employee_user_id'])) {
            $accessLog->addForeignKey(['document_id', 'employee_user_id'], 'employee_documents', ['id', 'user_id'], ['delete' => 'RESTRICT']);
        }
        $accessLog->update();
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
        throw new IrreversibleMigrationException('Erweiterte Personalakten-Historie erfordert eine separat geprüfte Datenbankwiederherstellung.');
    }
}
