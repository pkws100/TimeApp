<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Personnel\Compliance\PersonnelAccessDenied;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelCommandStore;
use App\Domain\Personnel\Compliance\PersonnelConflict;
use App\Domain\Users\PermissionMatrix;
use Tests\Support\MariaDbTestCase;

final class PersonnelAccessScopeDatabaseTest extends MariaDbTestCase
{
    public function testExplicitEmployeeScopeAndPermissionAreBothRequiredAndRevokedImmediately(): void
    {
        $admin = $this->createUser();
        $viewer = $this->createUser();
        $employee = $this->createUser();
        $otherEmployee = $this->createUser();
        $this->giveRole($admin, 'administrator', [PersonnelAccessService::VIEW, PersonnelAccessService::DELEGATE], true);
        $this->giveRole($viewer, 'viewer', [PersonnelAccessService::VIEW]);
        $this->giveRole($viewer, 'other-rights', ['users.manage', 'personnel.manage']);
        $access = $this->access();

        $_SESSION['auth']['user_id'] = $viewer;
        $this->assertDenied(fn () => $access->requireEmployee(PersonnelAccessService::VIEW, $employee));
        $this->assertDenied(fn () => $access->changeEmployeeScope($employee, $viewer, true, 'assignment', 'self-grant'));

        $_SESSION['auth']['user_id'] = $admin;
        self::assertSame($admin, $access->requireEmployee(PersonnelAccessService::VIEW, $otherEmployee));
        $grant = $access->changeEmployeeScope($employee, $viewer, true, 'assignment', 'grant-1');
        self::assertSame($grant, $access->changeEmployeeScope($employee, $viewer, true, 'assignment', 'grant-1'));
        self::assertSame($grant, $access->changeEmployeeScope($employee, $viewer, true, 'assignment', 'same-effect'));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_personnel_scope_events'));

        $_SESSION['auth']['user_id'] = $viewer;
        self::assertSame($viewer, $access->requireEmployee(PersonnelAccessService::VIEW, $employee));
        $this->assertDenied(fn () => $access->requireEmployee(PersonnelAccessService::VIEW, $otherEmployee));
        $this->assertDenied(fn () => $access->requireEmployee(PersonnelAccessService::MANAGE, $employee));
        $this->assertDenied(fn () => $access->requireEmployee(PersonnelAccessService::EXPORT, $employee));

        $_SESSION['auth']['user_id'] = $admin;
        $revocation = $access->changeEmployeeScope($employee, $viewer, false, 'revocation', 'revoke-1');
        self::assertFalse($revocation['is_granted']);
        self::assertNotSame($grant['scope_event_id'], $revocation['scope_event_id']);
        self::assertSame($grant, $access->changeEmployeeScope($employee, $viewer, true, 'assignment', 'grant-1'));
        $_SESSION['auth']['user_id'] = $viewer;
        $this->assertDenied(fn () => $access->requireEmployee(PersonnelAccessService::VIEW, $employee));
    }

    public function testReceiptIsAtomicAndReplayRechecksCurrentScope(): void
    {
        $admin = $this->createUser();
        $editor = $this->createUser();
        $employee = $this->createUser();
        $this->giveRole($admin, 'administrator', [PersonnelAccessService::DELEGATE], true);
        $this->giveRole($editor, 'editor', [PersonnelAccessService::MANAGE]);
        $access = $this->access();
        $_SESSION['auth']['user_id'] = $admin;
        $access->changeEmployeeScope($employee, $editor, true, 'assignment', 'grant-1');

        $_SESSION['auth']['user_id'] = $editor;
        $authorize = fn () => $access->requireEmployee(PersonnelAccessService::MANAGE, $employee);
        $commands = new PersonnelCommandStore($this->connection());
        $payload = ['second' => ['y' => true, 'x' => false], 'first' => $employee];
        $write = function () use ($employee): array {
            $this->connection()->execute('UPDATE users SET phone = "synthetic-command" WHERE id = :id', ['id' => $employee]);

            return ['id' => $employee];
        };
        try {
            $commands->execute($editor, 'receipt-failure', 'fixture.update', $payload, $authorize, function () use ($write): array {
                $write();
                throw new \RuntimeException('Synthetic fault');
            });
            self::fail('A failed operation must roll back its write.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic fault', $exception->getMessage());
        }
        self::assertNull($this->connection()->fetchColumn('SELECT phone FROM users WHERE id = :id', ['id' => $employee]));

        self::assertSame(['id' => $employee], $commands->execute($editor, 'receipt-failure', 'fixture.update', $payload, $authorize, $write));
        $neverWrite = static fn () => self::fail('A command replay must never execute the mutation.');
        self::assertSame(['id' => $employee], $commands->execute($editor, 'receipt-failure', 'fixture.update',
            ['first' => $employee, 'second' => ['x' => false, 'y' => true]], $authorize, $neverWrite));
        try {
            $commands->execute($editor, 'receipt-failure', 'fixture.other', $payload, $authorize, $neverWrite);
            self::fail('Duplicate request keys with a changed operation must conflict.');
        } catch (PersonnelConflict) {
            self::assertSame(1, (int) $this->connection()->fetchColumn(
                'SELECT COUNT(*) FROM personnel_command_receipts WHERE actor_user_id = :id', ['id' => $editor]
            ));
        }

        $_SESSION['auth']['user_id'] = $admin;
        $access->changeEmployeeScope($employee, $editor, false, 'revocation', 'revoke-1');
        $_SESSION['auth']['user_id'] = $editor;
        $this->assertDenied(fn () => $commands->execute($editor, 'receipt-failure', 'fixture.update', $payload, $authorize, $neverWrite));
    }

    private function access(): PersonnelAccessService
    {
        $permissions = require base_path('config/permissions.php');
        $auth = new AuthService($this->connection(), new PermissionMatrix($permissions['roles'], $permissions['available']));

        return new PersonnelAccessService($this->connection(), $auth);
    }

    private function giveRole(int $user, string $slug, array $codes, bool $system = false): void
    {
        $this->connection()->execute('INSERT INTO roles (slug, name, is_system_role) VALUES (:slug, :label, :system)',
            ['slug' => $slug, 'label' => $slug, 'system' => (int) $system]);
        $role = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', ['user' => $user, 'role' => $role]);
        foreach ($codes as $code) {
            $this->connection()->execute('INSERT INTO permissions (code, label) VALUES (:code, :label) ON DUPLICATE KEY UPDATE code = VALUES(code)',
                ['code' => $code, 'label' => $code]);
            $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE code = :code',
                ['role' => $role, 'code' => $code]);
        }
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('The operation must deny access.');
        } catch (PersonnelAccessDenied $exception) {
            self::assertSame('Der Personalaktenzugriff ist nicht verfügbar.', $exception->getMessage());
        }
    }
}
