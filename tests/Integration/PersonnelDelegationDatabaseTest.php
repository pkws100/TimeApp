<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\RoleService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Infrastructure\Database\DatabaseConnection;
use Tests\Support\MariaDbTestCase;

final class PersonnelDelegationDatabaseTest extends MariaDbTestCase
{
    private const VIEW = 'personnel_documents.view';
    private const MANAGE = 'personnel_documents.manage';
    private const DELEGATE = AuthService::PERSONNEL_DELEGATE;

    public function testRoleManagerCannotAddRemoveOrAssumeProtectedRightsThroughEitherRouter(): void
    {
        $adminRole = $this->role('administrator', [self::DELEGATE, 'roles.manage'], true);
        $manager = $this->createUser();
        $this->assign($manager, $this->role('roles-only', ['roles.manage']));
        $personal = $this->role('private-view', [self::VIEW]);
        $wildcard = $this->permission('*');
        $view = $this->permission(self::VIEW);

        $this->withRouter(function (Router $router) use ($manager, $personal, $wildcard, $view, $adminRole): void {
            $token = $this->loginAs($manager);
            foreach (['/api/v1/roles', '/admin/roles'] as $prefix) {
                foreach ([$view, $wildcard] as $id) {
                    self::assertSame(403, $this->call($router, 'POST', $prefix, [
                        'slug' => 'denied-role', 'name' => 'Denied role', 'permission_ids' => [$id], 'csrf_token' => $token,
                    ])->status());
                }
                self::assertSame(403, $this->call($router, 'POST', $prefix, [
                    'slug' => 'Admínistrator', 'name' => 'Reserved slug', 'csrf_token' => $token,
                ])->status());
                self::assertSame(403, $this->call($router, 'PUT', $prefix . '/' . $personal, [
                    'slug' => 'private-view', 'name' => 'Attempted change', 'permission_ids' => [], 'csrf_token' => $token,
                ])->status());
                self::assertSame(403, $this->call($router, 'DELETE', $prefix . '/' . $personal, ['csrf_token' => $token])->status());
                self::assertSame(403, $this->call($router, 'PUT', $prefix . '/' . $adminRole, [
                    'slug' => 'administrator', 'name' => 'Attempted change', 'permission_ids' => [], 'csrf_token' => $token,
                ])->status());
            }

            $ordinary = ['slug' => 'ordinary-editor', 'name' => 'Ordinary role', 'permission_ids' => [$this->permission('personnel.manage')]];
            self::assertSame(201, $this->call($router, 'POST', '/api/v1/roles', $ordinary)->status());
            $id = (int) $this->connection()->fetchColumn('SELECT id FROM roles WHERE slug = "ordinary-editor"');
            self::assertSame(302, $this->call($router, 'PUT', '/admin/roles/' . $id, ['csrf_token' => $token, ...$ordinary, 'name' => 'Ordinary edit'])->status());
            self::assertSame('Ordinary edit', $this->connection()->fetchColumn('SELECT name FROM roles WHERE id = :id', ['id' => $id]));
            self::assertSame(302, $this->call($router, 'DELETE', '/admin/roles/' . $id, ['csrf_token' => $token])->status());
            self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT is_deleted FROM roles WHERE id = :id', ['id' => $id]));
        });

        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM role_permissions WHERE role_id = :id', ['id' => $personal]));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT is_deleted FROM roles WHERE id = :id', ['id' => $personal]));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM roles WHERE slug = "denied-role"'));
    }

    public function testUserManagerCannotGrantAdminOrTakeOverAProtectedAccount(): void
    {
        $adminRole = $this->role('administrator', [self::DELEGATE], true);
        $admin = $this->createUser();
        $this->assign($admin, $adminRole);
        $managerRole = $this->role('users-only', ['users.manage']);
        $manager = $this->createUser();
        $this->assign($manager, $managerRole);
        $delegateRole = $this->role('private-manage', [self::MANAGE]);
        $protected = $this->createUser();
        $this->assign($protected, $delegateRole);
        $ordinary = $this->createUser();
        $protectedHash = $this->connection()->fetchColumn('SELECT password_hash FROM users WHERE id = :id', ['id' => $protected]);

        $this->withRouter(function (Router $router) use ($manager, $managerRole, $admin, $adminRole, $protected, $delegateRole, $ordinary): void {
            $token = $this->loginAs($manager);
            foreach (['/admin/users', '/api/v1/users'] as $prefix) {
                foreach ([$delegateRole, $adminRole] as $roleId) {
                    self::assertSame(403, $this->call($router, 'POST', $prefix, [
                        'first_name' => 'Synthetic', 'last_name' => 'Denied', 'email' => 'denied@example.test',
                        'password' => 'Test-desired-123', 'password_confirmation' => 'Test-desired-123',
                        'role_ids' => [$roleId], 'csrf_token' => $token,
                    ])->status());
                    self::assertSame(403, $this->call($router, 'PUT', $prefix . '/' . $manager, [
                        ...$this->userFields($manager, [$managerRole, $roleId]), 'csrf_token' => $token,
                    ])->status());
                }
                self::assertSame(403, $this->call($router, 'PUT', $prefix . '/' . $protected, [
                    ...$this->userFields($protected, [$delegateRole]), 'password' => 'Test-takeover-123',
                    'password_confirmation' => 'Test-takeover-123', 'csrf_token' => $token,
                ])->status());
                self::assertSame(403, $this->call($router, 'PUT', $prefix . '/' . $protected, [
                    ...$this->userFields($protected, []), 'employment_status' => 'inactive', 'csrf_token' => $token,
                ])->status());
                self::assertSame(403, $this->call($router, 'DELETE', $prefix . '/' . $admin, ['csrf_token' => $token])->status());
            }

            self::assertSame(200, $this->call($router, 'PUT', '/api/v1/users/' . $ordinary, $this->userFields($ordinary, []))->status());
            self::assertSame(302, $this->call($router, 'DELETE', '/admin/users/' . $ordinary, ['csrf_token' => $token])->status());
        });

        self::assertSame($protectedHash, $this->connection()->fetchColumn('SELECT password_hash FROM users WHERE id = :id', ['id' => $protected]));
        self::assertSame('active', $this->connection()->fetchColumn('SELECT employment_status FROM users WHERE id = :id', ['id' => $protected]));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT is_deleted FROM users WHERE id = :id', ['id' => $admin]));
    }

    public function testExplicitDelegationWithCsrfRevokesExistingPermissionImmediately(): void
    {
        $adminRole = $this->role('administrator', ['users.manage', 'roles.manage', self::DELEGATE], true);
        $admin = $this->createUser();
        $this->assign($admin, $adminRole);
        $ordinary = $this->createUser();
        $workforce = $this->role('staff', ['personnel.view']);
        $protected = $this->role('private-view', [self::VIEW]);
        $this->assign($ordinary, $workforce);

        $this->withRouter(function (Router $router) use ($admin, $ordinary, $protected, $workforce): void {
            $token = $this->loginAs($admin);
            $assign = $this->userFields($ordinary, [$workforce, $protected]);
            self::assertSame(403, $this->call($router, 'PUT', '/api/v1/users/' . $ordinary, $assign)->status());
            self::assertSame(302, $this->call($router, 'PUT', '/admin/users/' . $ordinary, [...$assign, 'csrf_token' => $token])->status());
            $adminSession = $_SESSION;
            $this->loginAs($ordinary);
            $ordinarySession = $_SESSION;
            self::assertTrue($this->auth()->hasPermission(self::VIEW));
            self::assertTrue($this->auth()->hasPermission('personnel.view'));
            self::assertFalse($this->auth()->hasPermission(self::MANAGE));

            $_SESSION = $adminSession;
            $updateRole = new Request('PUT', '/api/v1/roles/' . $protected, [], [
                'slug' => 'private-view', 'name' => 'Revoked role', 'permission_ids' => [],
            ], ['X-CSRF-Token' => $token], [], []);
            self::assertSame(200, $router->dispatch($updateRole)->status());
            $_SESSION = $ordinarySession;
            self::assertFalse($this->auth()->hasPermission(self::VIEW));
            self::assertTrue($this->auth()->hasPermission('personnel.view'));
        });
    }

    public function testArchiveAuditUsesAuthenticatedHttpCallerOrExplicitInternalCaller(): void
    {
        $operator = $this->createUser();
        $this->assign($operator, $this->role('operator', ['users.manage', 'roles.manage']));
        $internalActor = $this->createUser();
        $userThroughHttp = $this->createUser();
        $userThroughService = $this->createUser();
        $roleThroughHttp = $this->role('role-http', ['personnel.view']);
        $roleThroughService = $this->role('role-service', ['personnel.view']);

        $this->withRouter(function (Router $router) use ($operator, $internalActor, $userThroughHttp, $roleThroughHttp): void {
            $this->loginAs($operator);
            $untrustedInput = ['deleted_by_user_id' => $internalActor];
            self::assertSame(200, $this->call($router, 'DELETE', '/api/v1/users/' . $userThroughHttp, $untrustedInput)->status());
            self::assertSame(200, $this->call($router, 'DELETE', '/api/v1/roles/' . $roleThroughHttp, $untrustedInput)->status());
        });

        $config = require base_path('config/permissions.php');
        $roles = new RoleService($this->connection(), new PermissionMatrix($config['roles'], $config['available']));
        self::assertTrue((new UserService($this->connection()))->archive($userThroughService, $internalActor));
        self::assertTrue($roles->archive($roleThroughService, $internalActor));
        self::assertSame($operator, (int) $this->connection()->fetchColumn('SELECT deleted_by_user_id FROM users WHERE id = :id', ['id' => $userThroughHttp]));
        self::assertSame($operator, (int) $this->connection()->fetchColumn('SELECT deleted_by_user_id FROM roles WHERE id = :id', ['id' => $roleThroughHttp]));
        self::assertSame($internalActor, (int) $this->connection()->fetchColumn('SELECT deleted_by_user_id FROM users WHERE id = :id', ['id' => $userThroughService]));
        self::assertSame($internalActor, (int) $this->connection()->fetchColumn('SELECT deleted_by_user_id FROM roles WHERE id = :id', ['id' => $roleThroughService]));
    }

    public function testDatabaseSwitchRequiresSystemAdministratorAndBrowserSessionCannotCrossRealms(): void
    {
        $administrator = $this->createUser();
        $this->assign($administrator, $this->role('administrator', ['settings.database.manage', self::DELEGATE], true));
        $manager = $this->createUser();
        $this->assign($manager, $this->role('geschaeftsfuehrung', ['settings.database.manage', 'users.manage', 'roles.manage', 'personnel.manage'], true));
        $wildcard = $this->createUser();
        $this->assign($wildcard, $this->role('wildcard', ['*']));

        $this->withRouter(function (Router $router) use ($administrator, $manager, $wildcard): void {
            foreach ([$manager, $wildcard] as $actor) {
                $token = $this->loginAs($actor);
                self::assertTrue($this->auth()->hasPermission('settings.database.manage'));
                self::assertFalse($this->auth()->hasPermission(self::VIEW));
                self::assertFalse($this->auth()->hasPermission(self::DELEGATE));
                self::assertFalse($this->auth()->isSystemAdministrator());
                self::assertSame(200, $this->call($router, 'GET', '/api/v1/settings/database')->status());
                $page = $this->call($router, 'GET', '/admin/settings/database');
                self::assertSame(200, $page->status());
                $html = $this->html($page);
                self::assertStringContainsString('Verbindungseinstellungen', $html);
                self::assertStringContainsString('Verbindungsstatus', $html);
                self::assertStringNotContainsString('action="/admin/settings/database"', $html);
                self::assertStringNotContainsString('name="password"', $html);
                self::assertSame(403, $this->call($router, 'POST', '/api/v1/settings/database', ['csrf_token' => $token])->status());
                self::assertSame(403, $this->call($router, 'POST', '/admin/settings/database', ['csrf_token' => $token])->status());
            }

            $token = $this->loginAs($administrator);
            self::assertTrue($this->auth()->isSystemAdministrator());
            $page = $this->call($router, 'GET', '/admin/settings/database');
            self::assertSame(200, $page->status());
            $html = $this->html($page);
            self::assertStringContainsString('action="/admin/settings/database"', $html);
            self::assertStringContainsString('name="csrf_token" value="' . $token . '"', $html);
            self::assertSame(403, $this->call($router, 'POST', '/api/v1/settings/database')->status());
            self::assertSame(200, $this->call($router, 'POST', '/api/v1/settings/database', [
                'csrf_token' => $token, ...$this->connectionConfig(),
            ])->status());
            $saved = $this->call($router, 'POST', '/admin/settings/database', [
                'csrf_token' => $token, ...$this->connectionConfig(),
            ]);
            self::assertSame(302, $saved->status());
            self::assertSame('/admin/settings/database?notice=saved', $saved->headers()['Location']);

            $wrongRealm = new DatabaseConnection([...$this->connectionConfig(), 'charset' => 'utf8']);
            self::assertNull($this->auth($wrongRealm)->currentUser());
            self::assertFalse($this->auth($wrongRealm)->isSystemAdministrator());
            unset($_SESSION['auth']['database_realm']);
            self::assertNull($this->auth()->currentUser(), 'Pre-realm login cookies must authenticate again.');
        });
    }

    private function permission(string $code): int
    {
        $this->connection()->execute('INSERT IGNORE INTO permissions (code, label) VALUES (:code, :label)', ['code' => $code, 'label' => $code]);

        return (int) $this->connection()->fetchColumn('SELECT id FROM permissions WHERE code = :code', ['code' => $code]);
    }

    private function role(string $slug, array $permissions, bool $system = false): int
    {
        $this->connection()->execute('INSERT INTO roles (slug, name, is_system_role) VALUES (:slug, :name, :system)', [
            'slug' => $slug, 'name' => $slug, 'system' => (int) $system,
        ]);
        $id = $this->connection()->lastInsertId();
        foreach ($permissions as $permission) {
            $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)', [
                'role' => $id, 'permission' => $this->permission($permission),
            ]);
        }

        return $id;
    }

    private function assign(int $userId, int $roleId): void
    {
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', ['user' => $userId, 'role' => $roleId]);
    }

    private function auth(?DatabaseConnection $connection = null): AuthService
    {
        $config = require base_path('config/permissions.php');

        return new AuthService($connection ?? $this->connection(), new PermissionMatrix($config['roles'], $config['available']));
    }

    private function loginAs(int $userId): string
    {
        $_SESSION = [];
        $email = $this->connection()->fetchColumn('SELECT email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertTrue($this->auth()->login((string) $email, 'test-password')['ok']);

        return (new CsrfService())->token();
    }

    private function userFields(int $userId, array $roleIds): array
    {
        return [
            ...$this->connection()->fetchOne('SELECT employee_number, email, first_name, last_name, target_hours_month FROM users WHERE id = :id', ['id' => $userId]),
            'employment_status' => 'active', 'role_ids' => $roleIds,
        ];
    }

    private function withRouter(callable $callback): void
    {
        $file = tempnam(sys_get_temp_dir(), 'timeapp-delegation-router-');
        self::assertIsString($file);
        chmod($file, 0600);
        file_put_contents($file, '<?php return ' . var_export($this->connectionConfig(), true) . ';');
        $previous = $_ENV['DB_OVERRIDE_FILE'] ?? null;
        $_ENV['DB_OVERRIDE_FILE'] = $file;
        try {
            [, $router] = require base_path('bootstrap/app.php');
            $callback($router);
        } finally {
            if ($previous === null) {
                unset($_ENV['DB_OVERRIDE_FILE']);
            } else {
                $_ENV['DB_OVERRIDE_FILE'] = $previous;
            }
            unlink($file);
        }
    }

    private function call(Router $router, string $method, string $path, array $input = []): Response
    {
        return $router->dispatch(new Request($method, $path, [], $input, [], [], []));
    }

    private function html(Response $response): string
    {
        ob_start();
        $response->send();

        return (string) ob_get_clean();
    }
}
