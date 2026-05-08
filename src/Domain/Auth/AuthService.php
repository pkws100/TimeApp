<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Users\PermissionMatrix;
use App\Infrastructure\Database\DatabaseConnection;

final class AuthService
{
    public function __construct(
        private DatabaseConnection $connection,
        private PermissionMatrix $permissionMatrix
    ) {
    }

    public function login(string $email, string $password): array
    {
        $email = trim($email);
        $user = $this->findByEmail($email);

        if ($user !== null && password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->persistSession($user);

            return [
                'ok' => true,
                'message' => 'Login erfolgreich.',
                'user_id' => (int) $user['id'],
                'session' => $this->sessionPayload(),
            ];
        }

        return ['ok' => false, 'message' => 'Ungueltige Zugangsdaten.'];
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function currentUser(): ?array
    {
        $userId = $_SESSION['auth']['user_id'] ?? null;

        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }

        $userId = (int) $userId;

        return $this->findById($userId);
    }

    public function sessionPayload(): array
    {
        $user = $this->currentUser();

        return [
            'authenticated' => $user !== null,
            'user' => $user !== null ? $this->publicUser($user) : null,
            'bootstrap_required' => $this->bootstrapRequired(),
        ];
    }

    public function bootstrapRequired(): bool
    {
        foreach (['users', 'roles', 'user_roles'] as $table) {
            if (!$this->connection->tableExists($table)) {
                return true;
            }
        }

        $adminCount = $this->connection->fetchColumn(
            'SELECT COUNT(DISTINCT users.id)
             FROM users
             INNER JOIN user_roles ON user_roles.user_id = users.id
             INNER JOIN roles ON roles.id = user_roles.role_id
             WHERE roles.slug = :role_slug
               AND roles.is_deleted = 0
               AND COALESCE(users.is_deleted, 0) = 0
               AND users.employment_status = :status',
            [
                'role_slug' => 'administrator',
                'status' => 'active',
            ]
        );

        return (int) ($adminCount ?? 0) === 0;
    }

    public function hasPermission(?string $permission): bool
    {
        if ($permission === null || $permission === '') {
            return $this->currentUser() !== null;
        }

        $user = $this->currentUser();

        if ($user === null) {
            return false;
        }

        $permissions = $user['permissions'] ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    private function persistSession(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['auth'] = [
            'user_id' => (int) ($user['id'] ?? 0),
            'logged_in_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'employee_number' => $user['employee_number'] ?? null,
            'first_name' => (string) ($user['first_name'] ?? ''),
            'last_name' => (string) ($user['last_name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'display_name' => trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? ''))),
            'roles' => $user['roles'] ?? [],
            'permissions' => $user['permissions'] ?? [],
        ];
    }

    private function findByEmail(string $email): ?array
    {
        if ($this->connection->tableExists('users')) {
            $user = $this->connection->fetchOne(
                'SELECT
                    users.id,
                    users.employee_number,
                    users.first_name,
                    users.last_name,
                    users.email,
                    users.password_hash,
                    users.employment_status,
                    users.is_deleted
                 FROM users
                 WHERE users.email = :email
                 LIMIT 1',
                ['email' => $email]
            );

            if ($user === null || (int) ($user['is_deleted'] ?? 0) === 1 || ($user['employment_status'] ?? 'active') !== 'active') {
                return null;
            }

            return $this->withRolesAndPermissions($user);
        }

        return null;
    }

    private function findById(int $id): ?array
    {
        if (!$this->connection->tableExists('users')) {
            return null;
        }

        $user = $this->connection->fetchOne(
            'SELECT
                users.id,
                users.employee_number,
                users.first_name,
                users.last_name,
                users.email,
                users.password_hash,
                users.employment_status,
                users.is_deleted
             FROM users
             WHERE users.id = :id
             LIMIT 1',
            ['id' => $id]
        );

        if ($user === null || (int) ($user['is_deleted'] ?? 0) === 1 || ($user['employment_status'] ?? 'active') !== 'active') {
            return null;
        }

        return $this->withRolesAndPermissions($user);
    }

    private function withRolesAndPermissions(array $user): array
    {
        if (!$this->connection->tableExists('user_roles') || !$this->connection->tableExists('roles')) {
            $user['roles'] = [];
            $user['permissions'] = [];

            return $user;
        }

        $roleRows = $this->connection->fetchAll(
            'SELECT roles.slug
             FROM roles
             INNER JOIN user_roles ON user_roles.role_id = roles.id
             WHERE user_roles.user_id = :user_id
               AND roles.is_deleted = 0
             ORDER BY roles.slug ASC',
            ['user_id' => (int) $user['id']]
        );
        $roles = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['slug'] ?? ''), $roleRows)));

        if ($this->connection->tableExists('role_permissions') && $this->connection->tableExists('permissions')) {
            $permissionRows = $this->connection->fetchAll(
                'SELECT DISTINCT permissions.code
                 FROM permissions
                 INNER JOIN role_permissions ON role_permissions.permission_id = permissions.id
                 INNER JOIN roles ON roles.id = role_permissions.role_id
                 INNER JOIN user_roles ON user_roles.role_id = roles.id
                 WHERE user_roles.user_id = :user_id
                   AND roles.is_deleted = 0
                 ORDER BY permissions.code ASC',
                ['user_id' => (int) $user['id']]
            );
            $permissions = array_values(array_filter(array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $permissionRows)));
        } else {
            $permissions = $this->permissionMatrix->permissionsForRoles($roles);
        }

        $user['roles'] = $roles;
        $user['permissions'] = $permissions;

        return $user;
    }
}
