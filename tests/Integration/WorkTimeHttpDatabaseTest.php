<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use Tests\Support\MariaDbTestCase;

final class WorkTimeHttpDatabaseTest extends MariaDbTestCase
{
    public function testAnonymousAndMissingPermissionsCannotUseNewEndpoints(): void
    {
        $this->withRouter(function (Router $router): void {
            self::assertSame(302, $this->call($router, 'GET', '/admin/work-time')->status());
            self::assertSame(401, $this->call($router, 'GET', '/api/v1/app/me/work-time')->status());
            self::assertSame(401, $this->call($router, 'POST', '/api/v1/app/work-time/proposals/1/respond')->status());
            $_SESSION['auth']['user_id'] = $this->createUser();
            self::assertSame(403, $this->call($router, 'GET', '/admin/work-time')->status());
            self::assertSame(403, $this->call($router, 'POST', '/admin/work-time/templates')->status());
            self::assertSame(403, $this->call($router, 'POST', '/api/v1/app/work-time/proposals/1/respond')->status());
        });
    }

    public function testPreviewAndSaveAreBoundToSameRuleAndCsrf(): void
    {
        $admin = $this->createUser();
        $this->grant($admin, 'administrator');
        $this->withRouter(function (Router $router) use ($admin): void {
            $_SESSION['auth']['user_id'] = $admin;
            $html = $this->body($this->call($router, 'GET', '/admin/work-time'));
            self::assertStringContainsString('Kernarbeitszeit', $html);
            self::assertStringContainsString('data-worktime-preview', $html);
            $payload = $this->policyForm() + ['csrf_token' => $_SESSION['_csrf_token']];
            self::assertSame(403, $this->call($router, 'POST', '/admin/work-time/preview', $this->policyForm())->status());
            $preview = $this->json($this->call($router, 'POST', '/admin/work-time/preview', $payload));
            self::assertSame(503, $preview['data']['actual_work_minutes']);
            self::assertSame(450, $preview['data']['inside_window_minutes']);
            self::assertSame(53, $preview['data']['outside_pending_minutes']);
            $changed = $payload;
            $changed['window_1'] = '09:00-17:00';
            $changed['preview_token'] = $preview['preview_token'];
            self::assertStringContainsString('error=', $this->call($router, 'POST', '/admin/work-time/templates', $changed)->headers()['Location']);
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_policy_templates'));
            $payload['preview_token'] = $preview['preview_token'];
            self::assertSame('/admin/work-time?saved=1', $this->call($router, 'POST', '/admin/work-time/templates', $payload)->headers()['Location']);
            self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_policy_templates'));
            self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_policy_versions'));
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets'));
        });
    }

    public function testUnassignedEmployeeDayRetainsOldContractAndCannotReadOrResolveOthersProposal(): void
    {
        $employee = $this->createUser();
        $this->grant($employee, 'mitarbeiter');
        $this->withRouter(function (Router $router) use ($employee): void {
            $_SESSION['auth']['user_id'] = $employee;
            $day = $this->json($this->call($router, 'GET', '/api/v1/app/me/day'));
            self::assertArrayNotHasKey('work_time_windows_enabled', $day['data']);
            $status = $this->json($this->call($router, 'GET', '/api/v1/app/me/work-time'));
            self::assertNull($status['data']['policy']);
            self::assertSame([], $status['data']['checkout_proposals']);
            self::assertSame(403, $this->call($router, 'POST', '/api/v1/app/work-time/proposals/999/respond', ['action' => 'confirm', 'request_id' => 'own-only'])->status());
            self::assertSame(409, $this->call($router, 'POST', '/api/v1/app/work-time/proposals/999/respond', ['action' => 'confirm', 'request_id' => 'own-only', 'csrf_token' => $status['data']['csrf_token']])->status());
            self::assertSame(409, $this->call($router, 'POST', '/api/v1/app/work-time/proposals/999/respond', ['action' => [], 'actual_end_at' => [], 'request_id' => [], 'csrf_token' => $status['data']['csrf_token']])->status());
            self::assertSame(403, $this->call($router, 'POST', '/api/v1/app/work-time/proposals/999/respond', ['csrf_token' => []])->status());
        });
    }

    public function testSupervisorCannotAssignUnknownEmployeeOrGrantOwnScope(): void
    {
        $supervisor = $this->createUser();
        $employee = $this->createUser();
        $this->grant($supervisor, 'window-supervisor', ['work_time_policies.view', 'work_time_policies.manage']);
        $policies = new \App\Domain\WorkTime\WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => 'Test', 'policy' => ['windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']])]], $supervisor);
        $this->withRouter(function (Router $router) use ($supervisor, $employee, $template): void {
            $_SESSION['auth']['user_id'] = $supervisor;
            $this->call($router, 'GET', '/admin/work-time');
            $form = $this->policyForm();
            $form['intent'] = 'assignment';
            $form['user_id'] = $employee;
            $form['policy_version_id'] = $template['version']['id'];
            $form['effective_from'] = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
            $form['supervisor_user_ids'] = [$supervisor];
            $form['csrf_token'] = $_SESSION['_csrf_token'];
            $preview = $this->json($this->call($router, 'POST', '/admin/work-time/preview', $form));
            $form['preview_token'] = $preview['preview_token'];
            self::assertSame(403, $this->call($router, 'POST', '/admin/work-time/assignments', $form)->status());
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_work_time_assignments'));
        });
    }

    public function testNotificationOnlySupervisorCanSelectAssignedEmployeeWithoutReadingWorkPreview(): void
    {
        $supervisor = $this->createUser();
        $employee = $this->createUser();
        $this->grant($supervisor, 'notification-reader', ['work_time_notifications.manage']);
        $policies = new \App\Domain\WorkTime\WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => 'Zuordnung'], $supervisor);
        $assignment = $policies->assign(['user_id' => $employee, 'policy_version_id' => $template['version']['id'], 'effective_from' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'), 'supervisor_user_ids' => [$supervisor]], $supervisor);
        // Only synthetic scratch fixtures move the clock; production assignments remain future-only.
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :today WHERE id = :id', ['today' => (new \DateTimeImmutable('today'))->format('Y-m-d'), 'id' => $assignment['id']]);
        $this->withRouter(function (Router $router) use ($supervisor, $employee): void {
            $_SESSION['auth']['user_id'] = $supervisor;
            $response = $router->dispatch(new Request('GET', '/admin/work-time', ['user_id' => $employee], [], [], [], []));
            self::assertSame(200, $response->status());
            $html = $this->body($response);
            self::assertStringContainsString('Informationen laden', $html);
            self::assertStringContainsString('Benachrichtigungsstatus', $html);
            self::assertStringNotContainsString('· Monatsvorschau', $html);
            self::assertSame(403, $this->call($router, 'POST', '/admin/work-time/preview', ['csrf_token' => $_SESSION['_csrf_token']])->status());
        });
    }

    public function testPreviousNightEntryRemainsVisibleWhenTodaysAssignmentHasEnded(): void
    {
        $employee = $this->createUser();
        $this->grant($employee, 'mitarbeiter');
        $policies = new \App\Domain\WorkTime\WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => 'Nachtschicht', 'policy' => ['windows' => array_fill(1, 7, [['start' => '22:00', 'end' => '06:00']])]], $employee);
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $assignment = $policies->assign(['user_id' => $employee, 'policy_version_id' => $template['version']['id'], 'effective_from' => $tomorrow], $employee);
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date_from, effective_to = :date_to WHERE id = :id', ['date_from' => $yesterday, 'date_to' => $yesterday, 'id' => $assignment['id']]);
        $this->connection()->execute('INSERT INTO timesheets (user_id, created_by_user_id, work_date, start_time, entry_type, source, gross_minutes, break_minutes, net_minutes) VALUES (:user_id, :actor_id, :work_date, "22:00:00", "work", "app", 0, 0, 0)', ['user_id' => $employee, 'actor_id' => $employee, 'work_date' => $yesterday]);
        $timesheetId = $this->connection()->lastInsertId();
        $this->withRouter(function (Router $router) use ($employee, $timesheetId, $yesterday): void {
            $_SESSION['auth']['user_id'] = $employee;
            $day = $this->json($this->call($router, 'GET', '/api/v1/app/me/day'));
            self::assertTrue($day['data']['work_time_windows_enabled']);
            $status = $this->json($this->call($router, 'GET', '/api/v1/app/me/work-time'));
            self::assertNull($status['data']['policy']);
            self::assertSame($timesheetId, (int) $status['data']['active_work_entry']['id']);
            self::assertSame($yesterday, $status['data']['active_work_entry']['work_date']);
        });
    }

    private function policyForm(): array
    {
        $form = ['intent' => 'template', 'name' => 'Früh und spät', 'example_date' => '2026-11-16', 'example_start' => '07:42', 'example_end' => '16:35', 'example_break_start' => '12:00', 'example_break_end' => '12:30'];
        foreach (range(1, 7) as $day) { $form['window_' . $day] = '08:00-16:00'; }
        return $form;
    }

    private function grant(int $userId, string $slug, array $permissions = []): void
    {
        $this->connection()->execute('INSERT INTO roles (slug, name) VALUES (:slug, :name)', ['slug' => $slug, 'name' => $slug]);
        $role = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', ['user_id' => $userId, 'role_id' => $role]);
        $permissions = $permissions ?: ($slug === 'administrator' ? ['work_time_policies.view', 'work_time_policies.manage', 'work_time_deviations.view', 'work_time_deviations.manage', 'work_time_notifications.manage'] : ['timesheets.create', 'timesheets.view_own']);
        foreach ($permissions as $permission) {
            $this->connection()->execute('INSERT INTO permissions (code, label, scope) VALUES (:code, :label, "timesheets") ON DUPLICATE KEY UPDATE code = VALUES(code)', ['code' => $permission, 'label' => $permission]);
            $id = (int) $this->connection()->fetchColumn('SELECT id FROM permissions WHERE code = :code', ['code' => $permission]);
            $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)', ['role_id' => $role, 'permission_id' => $id]);
        }
    }

    private function withRouter(callable $callback): void
    {
        $file = tempnam(sys_get_temp_dir(), 'timeapp-wt-http-');
        file_put_contents($file, '<?php return ' . var_export($this->connectionConfig(), true) . ';');
        chmod($file, 0600);
        $previous = ['DB_OVERRIDE_FILE' => getenv('DB_OVERRIDE_FILE'), 'WORK_TIME_WINDOWS_ENABLED' => getenv('WORK_TIME_WINDOWS_ENABLED')];
        putenv('DB_OVERRIDE_FILE=' . $file);
        putenv('WORK_TIME_WINDOWS_ENABLED=true');
        try {
            [, $router] = require base_path('bootstrap/app.php');
            $callback($router);
        } finally {
            foreach ($previous as $key => $value) { putenv($key . ($value === false ? '' : '=' . $value)); }
            unlink($file);
        }
    }

    private function call(Router $router, string $method, string $path, array $input = []): Response
    {
        return $router->dispatch(new Request($method, $path, [], $input, [], [], []));
    }

    private function body(Response $response): string
    {
        ob_start();
        $response->send();
        return ob_get_clean() ?: '';
    }

    private function json(Response $response): array
    {
        return json_decode($this->body($response), true, 512, JSON_THROW_ON_ERROR);
    }
}
