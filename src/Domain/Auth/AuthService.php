<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\App\AppUiSettings;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserWorkdayPolicy;
use App\Infrastructure\Database\DatabaseConnection;

final class AuthService
{
    public const PERSONNEL_DELEGATE = 'personnel_documents.delegate';

    public function __construct(
        private DatabaseConnection $connection,
        private PermissionMatrix $permissionMatrix
    ) {
    }

    public function usesConnection(DatabaseConnection $connection): bool
    {
        return $this->connection === $connection;
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

    public function logout(bool $regenerateSessionId = true): void
    {
        $_SESSION = [];

        if ($regenerateSessionId && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function currentUser(): ?array
    {
        $userId = self::sessionUserId($this->connection);

        return $userId === null ? null : $this->findById($userId);
    }

    public static function sessionUserId(DatabaseConnection $connection): ?int
    {
        $session = $_SESSION['auth'] ?? [];
        $id = $session['user_id'] ?? null;

        if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int) $id <= 0) {
            return null;
        }

        // Browser logins always include a timestamp. Expire their pre-realm sessions on upgrade.
        if (array_key_exists('logged_in_at', $session) || array_key_exists('database_realm', $session)) {
            $realm = $session['database_realm'] ?? null;
            if (!is_string($realm) || !hash_equals($connection->authenticationRealm(), $realm)) {
                return null;
            }
        }

        return (int) $id;
    }

    public static function isPersonnelPermission(string $permission): bool
    {
        $permission = strtolower($permission);

        return str_starts_with($permission, 'personnel_documents.') || str_starts_with($permission, 'personnel_compliance.');
    }

    public function isSystemAdministrator(): bool
    {
        $id = self::sessionUserId($this->connection);
        if ($id === null || !$this->connection->tableExists('user_roles')) {
            return false;
        }

        return (int) $this->connection->fetchColumn(
            'SELECT EXISTS (
                SELECT 1 FROM users u
                JOIN user_roles ur ON ur.user_id = u.id
                JOIN roles r ON r.id = ur.role_id
                WHERE u.id = :id AND u.employment_status = "active" AND u.is_deleted = 0
                  AND r.slug = "administrator" AND r.is_system_role = 1 AND r.is_deleted = 0
            )',
            ['id' => $id]
        ) === 1;
    }

    /** @return array{actor_id:int,system_administrator:bool}|null */
    public function lockPersonnelAuthority(string $permission): ?array
    {
        $actorId = self::sessionUserId($this->connection);
        if ($actorId === null || !self::isPersonnelPermission($permission)
            || !$this->connection->pdo()?->inTransaction()) {
            return null;
        }

        // A locking read sees grants committed while the domain command waited for its subject.
        $grants = $this->connection->fetchAll(
            'SELECT (r.slug = "administrator" AND r.is_system_role = 1) AS system_administrator, p.code
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id AND r.is_deleted = 0
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = :id AND u.employment_status = "active" AND u.is_deleted = 0
             LOCK IN SHARE MODE',
            ['id' => $actorId]
        );
        if (!in_array($permission, array_column($grants, 'code'), true)) {
            return null;
        }

        return ['actor_id' => $actorId, 'system_administrator' => in_array(1, array_map('intval', array_column($grants, 'system_administrator')), true)];
    }

    public function canChangeDatabaseConnection(): bool
    {
        return $this->isSystemAdministrator() && $this->hasPermission(self::PERSONNEL_DELEGATE);
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

        if (self::isPersonnelPermission($permission)) {
            return in_array($permission, $permissions, true);
        }

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
            'database_realm' => $this->connection->authenticationRealm(),
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
            'time_tracking_required' => (int) ($user['time_tracking_required'] ?? 1) === 1,
            'app_ui_settings' => AppUiSettings::normalize($user['app_ui_settings'] ?? null),
            'roles' => $user['roles'] ?? [],
            'permissions' => $user['permissions'] ?? [],
        ];
    }

    private function findByEmail(string $email): ?array
    {
        if ($this->connection->tableExists('users')) {
            $timeTrackingSelect = $this->timeTrackingSelect();
            $workdaysSelect = $this->workdaysSelect();
            $appUiSettingsSelect = $this->appUiSettingsSelect();
            $user = $this->connection->fetchOne(
                'SELECT
                    users.id,
                    users.employee_number,
                    users.first_name,
                    users.last_name,
                    users.email,
                    users.password_hash,
                    users.employment_status,
                    ' . $timeTrackingSelect . ' AS time_tracking_required,
                    ' . $workdaysSelect . ' AS workdays_mask,
                    ' . $appUiSettingsSelect . ' AS app_ui_settings,
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

        $timeTrackingSelect = $this->timeTrackingSelect();
        $workdaysSelect = $this->workdaysSelect();
        $appUiSettingsSelect = $this->appUiSettingsSelect();
        $user = $this->connection->fetchOne(
            'SELECT
                users.id,
                users.employee_number,
                users.first_name,
                users.last_name,
                users.email,
                users.password_hash,
                users.employment_status,
                ' . $timeTrackingSelect . ' AS time_tracking_required,
                ' . $workdaysSelect . ' AS workdays_mask,
                ' . $appUiSettingsSelect . ' AS app_ui_settings,
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
            $permissions = array_values(array_filter(
                $this->permissionMatrix->permissionsForRoles($roles),
                static fn (string $permission): bool => !self::isPersonnelPermission($permission)
            ));
        }

        $user['roles'] = $roles;
        $user['permissions'] = $permissions;

        return $user;
    }

    private function timeTrackingSelect(): string
    {
        if (!$this->connection->columnExists('users', 'time_tracking_required')) {
            return '1';
        }

        return 'COALESCE(users.time_tracking_required, 1)';
    }

    private function appUiSettingsSelect(): string
    {
        if (!$this->connection->columnExists('users', 'app_ui_settings')) {
            return 'NULL';
        }

        return 'users.app_ui_settings';
    }

    private function workdaysSelect(): string
    {
        if (!$this->connection->columnExists('users', 'workdays_mask')) {
            return '"' . UserWorkdayPolicy::DEFAULT_MASK . '"';
        }

        return 'COALESCE(NULLIF(TRIM(users.workdays_mask), ""), "' . UserWorkdayPolicy::DEFAULT_MASK . '")';
    }
}
