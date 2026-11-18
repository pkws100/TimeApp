<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PersonnelPermissionDelegation extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
INSERT INTO permissions (code, label, scope, created_at)
VALUES ('personnel_documents.delegate', 'Rechte fuer Personalunterlagen delegieren', 'personnel_compliance', NOW())
ON DUPLICATE KEY UPDATE code = VALUES(code)
SQL);

        $this->execute(<<<'SQL'
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'administrator' AND r.is_system_role = 1 AND r.is_deleted = 0
  AND p.code = 'personnel_documents.delegate'
ON DUPLICATE KEY UPDATE permission_id = VALUES(permission_id)
SQL);
    }

    public function down(): void
    {
        // Preserve assignments and the reference-seed grant across a rollback.
    }
}
