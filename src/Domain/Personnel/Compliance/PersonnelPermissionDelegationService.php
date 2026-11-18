<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class PersonnelPermissionDelegationService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function mutate(callable $operation): mixed
    {
        $database = (string) $this->connection->fetchColumn('SELECT DATABASE()');
        $lock = ['name' => 'personnel-delegation-' . sha1($database)];
        if ((int) $this->connection->fetchColumn('SELECT GET_LOCK(:name, 10)', $lock) !== 1) {
            throw new RuntimeException('Rollenverwaltung ist voruebergehend belegt.');
        }

        try {
            return $this->connection->transaction($operation);
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:name)', $lock);
        }
    }

    public function assertUserMutation(?int $userId, array $requestedRoleIds, ?string $csrfToken): void
    {
        $affectedRoles = $requestedRoleIds;
        if ($userId !== null) {
            $affectedRoles = [...$affectedRoles, ...array_column($this->connection->fetchAll(
                'SELECT role_id FROM user_roles WHERE user_id = :id',
                ['id' => $userId]
            ), 'role_id')];
        }

        if ($affectedRoles === []) {
            return;
        }

        $roles = $this->connection->fetchAll(
            'SELECT r.slug = "administrator" AS reserved_role, p.code
             FROM roles r
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE r.id IN (' . $this->parameters($affectedRoles) . ')',
            $affectedRoles
        );
        $this->assertDelegation($roles, $csrfToken);
    }

    public function assertRoleMutation(?int $roleId, ?string $slug, array $permissionIds, ?string $csrfToken): void
    {
        $current = $roleId === null ? null : $this->connection->fetchOne(
            'SELECT slug, slug = "administrator" AS reserved_role FROM roles WHERE id = :id',
            ['id' => $roleId]
        );
        $reserved = (bool) ($current['reserved_role'] ?? false);
        // The seeder and administrator bootstrap both use this canonical key.
        if ($slug !== null && $slug !== ($current['slug'] ?? null) && ($reserved || (bool) $this->connection->fetchColumn(
            'SELECT CONVERT(:slug USING utf8mb4) COLLATE utf8mb4_unicode_ci = "administrator"',
            ['slug' => $slug]
        ))) {
            throw new PersonnelDelegationDenied();
        }

        $grants = $reserved ? [['reserved_role' => true]] : [];
        if ($current !== null) {
            $grants = [...$grants, ...$this->connection->fetchAll(
                'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :id',
                ['id' => $roleId]
            )];
        }
        if ($permissionIds !== []) {
            $grants = [...$grants, ...$this->connection->fetchAll(
                'SELECT code FROM permissions WHERE id IN (' . $this->parameters($permissionIds) . ')',
                $permissionIds
            )];
        }

        $this->assertDelegation($grants, $csrfToken);
    }

    private function assertDelegation(array $affectedGrants, ?string $csrfToken): void
    {
        $requiresSystemAdministrator = false;
        $requiresDelegation = false;
        foreach ($affectedGrants as $grant) {
            $code = (string) ($grant['code'] ?? '');
            $requiresSystemAdministrator = $requiresSystemAdministrator
                || (bool) ($grant['reserved_role'] ?? false)
                || in_array($code, ['*', AuthService::PERSONNEL_DELEGATE], true);
            $requiresDelegation = $requiresDelegation || AuthService::isPersonnelPermission($code);
        }
        if (!$requiresDelegation && !$requiresSystemAdministrator) {
            return;
        }

        $actor = AuthService::sessionUserId($this->connection);
        if ($actor === null || !(new CsrfService())->isValid($csrfToken)) {
            throw new PersonnelDelegationDenied();
        }

        $grants = $this->connection->fetchAll(
            'SELECT (r.slug = "administrator" AND r.is_system_role = 1) AS system_administrator, p.code
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.is_deleted = 0
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = :id AND u.employment_status = "active" AND u.is_deleted = 0',
            ['id' => $actor]
        );
        $canDelegate = in_array(AuthService::PERSONNEL_DELEGATE, array_column($grants, 'code'), true);
        $isSystemAdministrator = in_array(1, array_map('intval', array_column($grants, 'system_administrator')), true);
        if (!$canDelegate || ($requiresSystemAdministrator && !$isSystemAdministrator)) {
            throw new PersonnelDelegationDenied();
        }
    }

    private function parameters(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
