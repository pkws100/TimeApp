<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Domain\WorkTime\WorkTimeAccessService;
use App\Domain\WorkTime\WorkTimeDeviationService;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Http\Controllers\AdminWorkTimeController;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use Tests\Support\MariaDbTestCase;

final class WorkTimePolicyAdminDatabaseTest extends MariaDbTestCase
{
    public function testNewDelayIsValidatedBeforeItCanBeCoercedIntoAValidInteger(): void
    {
        [$actor, $controller, $csrf] = $this->fixture();
        $this->grantNotifications($actor);
        foreach (['invalid', '1.5', '-1', '1441', []] as $delay) {
            $response = $controller->preview($this->request($this->form($csrf) + ['outside_window_delay_minutes' => $delay]));
            self::assertSame(422, $response->status(), 'Malformed notification delays must never become zero or truncated minutes.');
        }
        foreach (['0', '5', '1440'] as $delay) {
            $response = $controller->preview($this->request($this->form($csrf) + ['outside_window_delay_minutes' => $delay]));
            self::assertSame(200, $response->status());
            self::assertSame((int) $delay, $this->json($response)['data']['policy']['outside_window_delay_minutes']);
        }
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_policy_versions'));
    }

    public function testNotificationPermissionsCoverBothNewFieldsAndRevisionsPreserveTheOldRule(): void
    {
        [$actor, $controller, $csrf, $policies] = $this->fixture();
        $original = $policies->createTemplate(['name' => 'Versionierte Versandregel', 'policy' => ['windows' => [1 => [['start' => '08:00', 'end' => '16:00']]]]], $actor);
        $form = $this->form($csrf) + ['template_id' => $original['id']];
        self::assertSame(200, $controller->preview($this->request($form))->status());
        foreach (['outside_window_notifications_enabled' => '1', 'outside_window_delay_minutes' => '6'] as $key => $value) {
            self::assertSame(422, $controller->preview($this->request($form + [$key => $value]))->status());
        }
        $this->grantNotifications($actor);
        $revision = $form + ['outside_window_notifications_enabled' => '1', 'outside_window_delay_minutes' => '7'];
        $preview = $this->json($controller->preview($this->request($revision)));
        self::assertSame(302, $controller->save($this->request($revision + ['preview_token' => $preview['preview_token']]))->status());
        $versions = $policies->listTemplates()[0]['versions'];
        self::assertCount(2, $versions);
        self::assertTrue($versions[0]['policy']['outside_window_notifications_enabled']);
        self::assertSame(7, $versions[0]['policy']['outside_window_delay_minutes']);
        self::assertSame($original['version']['policy'], $versions[1]['policy']);
        self::assertFalse($versions[1]['policy']['outside_window_notifications_enabled']);
        self::assertSame(5, $versions[1]['policy']['outside_window_delay_minutes']);
    }

    private function fixture(): array
    {
        $actor = $this->createUser();
        $_SESSION['auth']['user_id'] = $actor;
        $connection = $this->connection();
        $policies = new WorkTimePolicyService($connection, true);
        $auth = new AuthService($connection, new PermissionMatrix([], []));
        $csrf = new CsrfService();
        $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
        $controller = new AdminWorkTimeController(new AdminView('Test', 'http://localhost'), $auth, $csrf, new UserService($connection), $policies, new WorkTimePreviewService($connection, $policies), new WorkTimeDeviationService($connection, $policies), new WorkTimeAccessService($policies), new WorkTimeEventService($connection, $policies, $sync));
        return [$actor, $controller, $csrf, $policies];
    }

    private function grantNotifications(int $actor): void
    {
        $this->connection()->execute('INSERT INTO roles (slug, name) VALUES ("notification-policy-review", "Versandregelverwaltung")');
        $role = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:actor, :role)', ['actor' => $actor, 'role' => $role]);
        $this->connection()->execute('INSERT INTO permissions (code, label, scope) VALUES ("work_time_notifications.manage", "Versand", "backend") ON DUPLICATE KEY UPDATE code = VALUES(code)');
        $permission = $this->connection()->fetchColumn('SELECT id FROM permissions WHERE code = "work_time_notifications.manage"');
        $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)', ['role' => $role, 'permission' => $permission]);
    }

    private function form(CsrfService $csrf): array
    {
        return ['intent' => 'template', 'name' => 'Regel', 'csrf_token' => $csrf->token(), 'window_1' => '08:00-16:00', 'example_date' => '2026-11-16', 'example_start' => '07:42', 'example_end' => '16:35', 'example_break_start' => '12:00', 'example_break_end' => '12:30'];
    }

    private function request(array $body): Request
    {
        return new Request('POST', '/admin/work-time/preview', [], $body, [], [], []);
    }

    private function json(Response $response): array
    {
        ob_start();
        $response->send();
        return json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    }
}
