<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PersonnelCompliancePermissions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
INSERT INTO permissions (code, label, scope, created_at) VALUES
    ('personnel_documents.view', 'Personal- und Entgeltunterlagen ansehen', 'personnel_compliance', NOW()),
    ('personnel_documents.manage', 'Personal- und Entgeltunterlagen verwalten', 'personnel_compliance', NOW()),
    ('personnel_documents.export', 'Personal- und Entgeltunterlagen exportieren', 'personnel_compliance', NOW()),
    ('personnel_compliance.manage', 'Regelkatalog fuer Personalunterlagen verwalten', 'personnel_compliance', NOW())
ON DUPLICATE KEY UPDATE code = VALUES(code)
SQL);

        $this->execute(<<<'SQL'
INSERT INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id FROM roles CROSS JOIN permissions
WHERE roles.slug = 'administrator' AND roles.is_system_role = 1 AND roles.is_deleted = 0 AND permissions.code IN (
    'personnel_documents.view', 'personnel_documents.manage',
    'personnel_documents.export', 'personnel_compliance.manage'
)
ON DUPLICATE KEY UPDATE permission_id = VALUES(permission_id)
SQL);
    }

    public function down(): void
    {
        // Explicit assignments and reference-seed grants remain intact on rollback.
    }
}
