<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class PersonnelPrivateStorage extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE employee_document_versions
                ADD COLUMN IF NOT EXISTS storage_key_id VARCHAR(32) NULL,
                ADD COLUMN IF NOT EXISTS storage_format SMALLINT UNSIGNED NULL,
                ADD COLUMN IF NOT EXISTS format_verified_at DATETIME NULL,
                ADD COLUMN IF NOT EXISTS malware_scanned_at DATETIME NULL,
                ADD CONSTRAINT IF NOT EXISTS ck_personnel_private_object CHECK (
                    (storage_key_id IS NULL AND storage_format IS NULL AND format_verified_at IS NULL AND malware_scanned_at IS NULL)
                    OR (storage_key_id IS NOT NULL AND storage_key_id REGEXP BINARY '^[A-Za-z][A-Za-z0-9._-]{0,31}$'
                        AND storage_format IS NOT NULL AND storage_format = 1
                        AND storage_key IS NOT NULL AND storage_key REGEXP BINARY '^[a-f0-9]{64}$'
                        AND mime_type IS NOT NULL AND mime_type IN ('application/pdf','image/jpeg','image/png')
                        AND format_verified_at IS NOT NULL AND malware_scanned_at IS NOT NULL
                        AND format_verified_at <= malware_scanned_at)
                )
            SQL);

        // Older rows stay immutable. New file references must carry both technical checks.
        $this->execute(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS require_personnel_private_object BEFORE INSERT ON employee_document_versions FOR EACH ROW
            BEGIN
                IF NEW.storage_key IS NOT NULL AND NEW.storage_format IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Für neue Personalaktendateien fehlen technische Prüfdaten.';
                END IF;
            END
            SQL);

        // The restore writer needs to verify the guards without receiving TRIGGER or DDL privileges.
        $this->execute(<<<'SQL'
            CREATE OR REPLACE SQL SECURITY DEFINER VIEW personnel_schema_trigger_manifest AS
            SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT, ACTION_ORDER, SQL_MODE
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
              AND EVENT_OBJECT_TABLE IN (
                  'personnel_compliance_sources', 'personnel_document_types', 'personnel_document_requirements',
                  'employee_employment_contexts', 'employee_documents', 'employee_document_versions',
                  'employee_document_audit', 'employee_document_access_log',
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
        throw new IrreversibleMigrationException('Versionierte Personalaktendateien erfordern eine separat geprüfte Wiederherstellung.');
    }
}
