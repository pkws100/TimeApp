<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class HardenPersonnelComplianceHistory extends AbstractMigration
{
    private const PROTECTED_TABLES = [
        'personnel_compliance_sources',
        'personnel_document_types',
        'personnel_document_requirements',
        'employee_employment_contexts',
        'employee_documents',
        'employee_document_versions',
        'employee_document_audit',
        'employee_document_access_log',
    ];

    public function up(): void
    {
        foreach (self::PROTECTED_TABLES as $table) {
            $this->execute(<<<SQL
                CREATE TRIGGER IF NOT EXISTS `deny_delete_{$table}` BEFORE DELETE ON `{$table}` FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Personalunterlagen und Kataloghistorie dürfen nicht direkt gelöscht werden.'
                SQL);
        }

        $this->execute(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS `protect_employee_document_identity` BEFORE UPDATE ON `employee_documents` FOR EACH ROW
            BEGIN
                IF NOT (OLD.id <=> NEW.id
                    AND OLD.user_id <=> NEW.user_id
                    AND OLD.document_type_id <=> NEW.document_type_id
                    AND OLD.employment_context_id <=> NEW.employment_context_id
                    AND OLD.document_requirement_id <=> NEW.document_requirement_id
                    AND OLD.created_by_user_id <=> NEW.created_by_user_id
                    AND OLD.created_at <=> NEW.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Identität und Zuordnung eines Personalunterlagen-Dokuments sind unveränderlich.';
                END IF;
            END
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
        // Phinx cannot atomically roll back the earlier, destructive MariaDB DDL.
        throw new IrreversibleMigrationException(
            'Der Compliance-Katalog ist vor Phinx-Rollbacks geschützt. Eine geprüfte Datenbanksicherung separat wiederherstellen.'
        );
    }
}
