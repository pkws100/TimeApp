<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BackfillReferencePermissions extends AbstractMigration
{
    /**
     * @var array<string, array{label: string, scope: string}>
     */
    private array $permissions = [
        'timesheets.view' => ['label' => 'Buchungen ansehen', 'scope' => 'timesheets'],
        'timesheets.archive' => ['label' => 'Buchungen archivieren', 'scope' => 'timesheets'],
        'timesheets.export' => ['label' => 'Buchungen exportieren', 'scope' => 'timesheets'],
        'push.receive' => ['label' => 'Push-Benachrichtigungen empfangen', 'scope' => 'app'],
        'push.manage' => ['label' => 'Push-Benachrichtigungen verwalten', 'scope' => 'backend'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $rolePermissions = [
        'administrator' => [
            'timesheets.view',
            'timesheets.archive',
            'timesheets.export',
            'push.manage',
            'push.receive',
        ],
        'geschaeftsfuehrung' => [
            'timesheets.view',
            'timesheets.manage',
            'timesheets.archive',
            'timesheets.export',
            'push.manage',
            'push.receive',
        ],
        'bauleiter' => [
            'timesheets.view',
            'timesheets.manage',
            'timesheets.export',
            'push.receive',
        ],
        'kolonnenfuehrer' => [
            'timesheets.view',
            'timesheets.manage',
            'push.receive',
        ],
        'mitarbeiter' => [
            'push.receive',
        ],
        'disposition' => [
            'timesheets.view',
            'timesheets.export',
            'push.receive',
        ],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $code => $permission) {
            $this->execute(sprintf(
                "INSERT INTO permissions (code, label, scope, created_at)
                 VALUES ('%s', '%s', '%s', NOW())
                 ON DUPLICATE KEY UPDATE label = VALUES(label), scope = VALUES(scope)",
                addslashes($code),
                addslashes($permission['label']),
                addslashes($permission['scope'])
            ));
        }

        foreach ($this->rolePermissions as $roleSlug => $permissionCodes) {
            foreach ($permissionCodes as $permissionCode) {
                $this->execute(sprintf(
                    "INSERT IGNORE INTO role_permissions (role_id, permission_id)
                     SELECT roles.id, permissions.id
                     FROM roles
                     INNER JOIN permissions ON permissions.code = '%s'
                     WHERE roles.slug = '%s'",
                    addslashes($permissionCode),
                    addslashes($roleSlug)
                ));
            }
        }
    }

    public function down(): void
    {
        // Backfill only: older migrations own these permissions, so rollback must not remove them.
    }
}
