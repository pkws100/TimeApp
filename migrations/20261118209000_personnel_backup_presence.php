<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class PersonnelBackupPresence extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE OR REPLACE SQL SECURITY DEFINER VIEW personnel_backup_presence AS
            SELECT
                EXISTS (SELECT 1 FROM employee_employment_contexts) AS employee_employment_contexts,
                EXISTS (SELECT 1 FROM employee_documents) AS employee_documents,
                EXISTS (SELECT 1 FROM employee_document_versions) AS employee_document_versions,
                EXISTS (SELECT 1 FROM employee_document_audit) AS employee_document_audit,
                EXISTS (SELECT 1 FROM employee_document_access_log) AS employee_document_access_log,
                EXISTS (SELECT 1 FROM employee_personnel_scope_events) AS employee_personnel_scope_events,
                EXISTS (SELECT 1 FROM personnel_command_receipts) AS personnel_command_receipts,
                EXISTS (SELECT 1 FROM employee_document_evidence_reviews) AS employee_document_evidence_reviews
            SQL);
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
        throw new IrreversibleMigrationException('Die Abdeckungs-View der Personalakten-Sicherung erfordert einen separat geprüften Rückbau.');
    }
}
