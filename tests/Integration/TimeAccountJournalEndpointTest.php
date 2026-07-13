<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Domain\Users\PermissionMatrix;
use App\Http\Controllers\AppTimeAccountController;
use App\Http\Request;
use App\Http\Response;
use Tests\Support\MariaDbTestCase;

final class TimeAccountJournalEndpointTest extends MariaDbTestCase
{
    public function testAppEndpointReturnsOnlyOwnActiveGenerationWithBoundedPagination(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-E', 'email' => 'admin-e@example.test']);
        $userId = $this->createUser(['email' => 'employee-one@example.test']);
        $otherUserId = $this->createUser(['email' => 'employee-two@example.test']);
        [$accounts, $journal, $cutovers] = $this->services();
        $active = $cutovers->finalize($this->payload($userId), $adminId);
        $other = $cutovers->finalize($this->payload($otherUserId), $adminId);
        $this->connection()->execute(
            'INSERT INTO employee_account_cutovers (
                user_id, active_final_user_id, effective_from, opening_time_balance_minutes, leave_year,
                annual_leave_entitlement_days, leave_carryover_days, opening_remaining_leave_days,
                status, created_at, updated_at
             ) VALUES (:user_id, NULL, "2025-01-01", 0, 2025, 30, 0, 30, "reversed", NOW(), NOW())',
            ['user_id' => $userId]
        );
        $historicCutoverId = $this->connection()->lastInsertId();
        $this->connection()->execute(
            'INSERT INTO time_account_entries (user_id, cutover_id, effective_date, minutes, entry_type, description, created_at)
             VALUES (:user_id, :cutover_id, "2025-02-01", 888, "manual_adjustment", "Historisch", NOW())',
            ['user_id' => $userId, 'cutover_id' => $historicCutoverId]
        );

        for ($index = 1; $index <= 105; $index++) {
            $journal->addTimeEntry($userId, '2026-02-01', $index, 'manual_adjustment', null, null, 'Seite ' . $index, $adminId, $adminId, null, (int) $active['id']);
        }
        $journal->addTimeEntry($otherUserId, '2026-02-01', 999, 'manual_adjustment', null, null, 'Fremd', $adminId, $adminId, null, (int) $other['id']);

        $_SESSION['auth']['user_id'] = $userId;
        $auth = new AuthService($this->connection(), new PermissionMatrix([], []));
        $controller = new AppTimeAccountController($accounts, $auth);
        $first = $this->json($controller->entries(new Request('GET', '/api/v1/app/time-account/entries', ['year' => 2026, 'limit' => 500, 'page' => 1], [], [], [], [])));
        $second = $this->json($controller->entries(new Request('GET', '/api/v1/app/time-account/entries', ['year' => 2026, 'limit' => 100, 'page' => 2], [], [], [], [])));

        self::assertCount(100, $first['data']['time_entries']);
        self::assertTrue($first['data']['pagination']['time_has_more']);
        self::assertSame(100, $first['data']['pagination']['limit']);
        self::assertCount(6, $second['data']['time_entries']);
        self::assertSame([(int) $active['id']], array_values(array_unique(array_map('intval', array_column($first['data']['time_entries'], 'cutover_id')))));
        self::assertNotContains(999, array_map('intval', array_column($first['data']['time_entries'], 'minutes')));
        self::assertNotContains(888, array_map('intval', array_column($first['data']['time_entries'], 'minutes')));
    }

    public function testAdminGuardRequiresTimeAccountViewPermission(): void
    {
        $userId = $this->createUser(['employee_number' => 'ADMIN-G', 'email' => 'admin-g@example.test']);
        $this->connection()->execute('INSERT INTO roles (slug, name, is_system_role, created_at, updated_at, is_deleted) VALUES ("journal-reader", "Journal", 0, NOW(), NOW(), 0)');
        $roleId = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', ['user_id' => $userId, 'role_id' => $roleId]);
        $overrideFile = sys_get_temp_dir() . '/timeapp-route-db-' . bin2hex(random_bytes(5)) . '.php';
        file_put_contents($overrideFile, "<?php\nreturn " . var_export($this->connectionConfig(), true) . ";\n");
        chmod($overrideFile, 0600);
        $previousOverride = getenv('DB_OVERRIDE_FILE');
        putenv('DB_OVERRIDE_FILE=' . $overrideFile);
        $request = new Request('GET', '/admin/time-accounts/users/1/entries', [], [], [], [], []);

        try {
            [, $router] = require base_path('bootstrap/app.php');
            $_SESSION = [];
            self::assertSame(302, $router->dispatch($request)->status());

            $_SESSION['auth']['user_id'] = $userId;
            self::assertSame(403, $router->dispatch($request)->status());

            $this->connection()->execute('INSERT INTO permissions (code, label, scope, created_at) VALUES ("time_accounts.view", "Zeitkonten", "backend", NOW())');
            $permissionId = $this->connection()->lastInsertId();
            $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)', ['role_id' => $roleId, 'permission_id' => $permissionId]);
            self::assertSame(200, $router->dispatch($request)->status());
        } finally {
            if ($previousOverride === false) {
                putenv('DB_OVERRIDE_FILE');
            } else {
                putenv('DB_OVERRIDE_FILE=' . $previousOverride);
            }
            @unlink($overrideFile);
        }
    }

    private function services(): array
    {
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal, new TimesheetWriteGuard($this->connection()));
        $calendar = new CalendarPolicyService($this->connection());
        $accounts = new TimeAccountService($this->connection(), $calendar, new DailyTargetService($calendar), $journal, $cutovers);

        return [$accounts, $journal, $cutovers];
    }

    private function payload(int $userId): array
    {
        return [
            'user_id' => $userId, 'effective_from' => '2026-01-01', 'opening_time_balance' => '+01:00',
            'leave_year' => 2026, 'annual_leave_entitlement_days' => 30,
            'leave_carryover_days' => 0, 'opening_remaining_leave_days' => 30,
        ];
    }

    private function json(Response $response): array
    {
        ob_start();
        $response->send();
        $body = ob_get_clean() ?: '';

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
