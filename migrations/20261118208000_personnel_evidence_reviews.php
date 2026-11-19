<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class PersonnelEvidenceReviews extends AbstractMigration
{
    public function up(): void
    {
        $reviews = $this->table('employee_document_evidence_reviews', ['engine' => 'InnoDB']);
        if (!$reviews->exists()) {
            $reviews->addColumn('document_id', 'integer', ['signed' => false])
                ->addColumn('document_version_id', 'integer', ['signed' => false])
                ->addColumn('actor_user_id', 'integer', ['signed' => false])
                ->addColumn('procedure', 'string', ['limit' => 32])
                ->addColumn('procedure_version', 'integer', ['signed' => false])
                ->addColumn('outcome', 'string', ['limit' => 24])
                ->addColumn('original_kind', 'string', ['limit' => 24])
                ->addColumn('paper_status', 'string', ['limit' => 24])
                ->addColumn('signature_status', 'string', ['limit' => 24])
                ->addColumn('reason', 'string', ['limit' => 40])
                ->addColumn('legible', 'boolean')
                ->addColumn('matches_record', 'boolean')
                ->addColumn('created_at', 'datetime')
                ->addIndex(['document_id', 'document_version_id', 'id'], ['name' => 'ix_employee_document_evidence_version'])
                ->addForeignKey(['document_version_id', 'document_id'], 'employee_document_versions', ['id', 'document_id'], ['delete' => 'RESTRICT'])
                ->addForeignKey('actor_user_id', 'users', 'id', ['delete' => 'RESTRICT'])
                ->create();
        }

        $this->execute(<<<'SQL'
            ALTER TABLE employee_document_evidence_reviews
                ADD CONSTRAINT IF NOT EXISTS ck_employee_evidence_procedure CHECK (
                    BINARY `procedure` = 'manual_visual' AND procedure_version = 1 AND BINARY signature_status = 'unresolved'
                ),
                ADD CONSTRAINT IF NOT EXISTS ck_employee_evidence_original CHECK (
                    (BINARY original_kind = 'unknown' AND BINARY paper_status = 'unknown')
                    OR (BINARY original_kind = 'paper' AND BINARY paper_status IN ('unknown','seen','retained','missing'))
                    OR (BINARY original_kind = 'digital_original' AND BINARY paper_status = 'not_applicable')
                    OR (BINARY original_kind = 'digital_copy' AND BINARY paper_status IN ('unknown','seen','retained','missing','not_applicable'))
                ),
                ADD CONSTRAINT IF NOT EXISTS ck_employee_evidence_outcome CHECK (
                    (BINARY outcome = 'open' AND legible = 0 AND matches_record = 0
                        AND BINARY reason IN ('form_unresolved','original_missing','signature_unresolved','documentation_only'))
                    OR (BINARY outcome = 'objected' AND legible = 0 AND matches_record = 0
                        AND BINARY reason IN ('wrong_assignment','illegible','original_missing','incomplete'))
                    OR (BINARY outcome = 'confirmed_internal' AND legible = 1 AND matches_record = 1
                        AND BINARY reason = 'visual_checks_confirmed'
                        AND (BINARY original_kind = 'digital_original'
                            OR (BINARY original_kind IN ('paper','digital_copy') AND BINARY paper_status IN ('seen','retained'))))
                )
            SQL);

        $this->execute(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS require_personnel_verified_review_version BEFORE INSERT ON employee_document_evidence_reviews FOR EACH ROW
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM employee_document_versions
                    WHERE id = NEW.document_version_id AND document_id = NEW.document_id
                      AND storage_format = 1 AND format_verified_at IS NOT NULL AND malware_scanned_at IS NOT NULL
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ein Nachweis benötigt eine technisch geprüfte Dateiversion.';
                END IF;
            END
            SQL);
        $this->execute(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS immutable_employee_document_evidence_reviews BEFORE UPDATE ON employee_document_evidence_reviews FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ein Nachweis ist unveränderlich.'
            SQL);
        $this->execute(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS deny_delete_employee_document_evidence_reviews BEFORE DELETE ON employee_document_evidence_reviews FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ein Nachweis darf nicht gelöscht werden.'
            SQL);

        $this->execute(<<<'SQL'
            CREATE OR REPLACE SQL SECURITY DEFINER VIEW personnel_schema_trigger_manifest AS
            SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT, ACTION_ORDER, SQL_MODE
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND EVENT_OBJECT_TABLE IN (
                  'personnel_compliance_sources', 'personnel_document_types', 'personnel_document_requirements',
                  'employee_employment_contexts', 'employee_documents', 'employee_document_versions',
                  'employee_document_audit', 'employee_document_access_log', 'employee_document_evidence_reviews',
                  'employee_personnel_scope_events', 'personnel_command_receipts'
              )
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
        throw new IrreversibleMigrationException('Dokumentierte Nachweise erfordern eine separat geprüfte Wiederherstellung.');
    }
}
