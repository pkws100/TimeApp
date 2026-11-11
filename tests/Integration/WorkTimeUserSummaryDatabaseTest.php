<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Assets\AssetService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Files\DocumentStatusService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\RoleService;
use App\Domain\Users\UserService;
use App\Domain\WorkTime\WorkTimeAccessService;
use App\Domain\WorkTime\WorkTimeDeviationService;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Domain\WorkTime\WorkTimeUserSummaryService;
use App\Http\Controllers\AdminManagementController;
use App\Http\Controllers\AdminWorkTimeController;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use App\Presentation\Admin\WorkTimeUserSummaryRenderer;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\MariaDbTestCase;

final class WorkTimeUserSummaryDatabaseTest extends MariaDbTestCase
{
    public function testBerlinDateAndInclusiveValiditySelectCurrentAndNearestFutureWithoutExpiredRules(): void
    {
        [$actor, $employee, $policies, $version] = $this->fixture();
        $this->assign($policies, $actor, $employee, $version, 1, 9);
        $current = $this->assign($policies, $actor, $employee, $version, 10, 13);
        $next = $this->assign($policies, $actor, $employee, $version, 14, 19);
        $later = $this->assign($policies, $actor, $employee, $version, 20);
        $asOfBerlin = new DateTimeImmutable($this->date(10) . ' 00:30:00', new DateTimeZone('Europe/Berlin'));
        $asOfUtc = $asOfBerlin->setTimezone(new DateTimeZone('UTC'));
        self::assertNotSame($asOfBerlin->format('Y-m-d'), $asOfUtc->format('Y-m-d'));
        $service = new WorkTimeUserSummaryService($this->connection(), $policies);
        $summary = $service->forUser($this->admin($actor), $employee, $asOfUtc);
        self::assertSame($this->date(10), $summary['as_of']);
        self::assertSame(1, $summary['current_count']);
        self::assertSame(1, $summary['additional_future_count']);
        self::assertSame([$current['id'], $next['id']], array_column($summary['assignments'], 'id'));
        self::assertSame(['current', 'planned'], array_column($summary['assignments'], 'status'));
        $lastDay = $service->forUser($this->admin($actor), $employee, new DateTimeImmutable($this->date(13) . ' 23:59:00', new DateTimeZone('Europe/Berlin')));
        self::assertSame($current['id'], $lastDay['assignments'][0]['id']);
        $followingDay = $service->forUser($this->admin($actor), $employee, new DateTimeImmutable($this->date(14) . ' 00:00:00', new DateTimeZone('Europe/Berlin')));
        self::assertSame([$next['id'], $later['id']], array_column($followingDay['assignments'], 'id'));
        self::assertSame(0, $followingDay['additional_future_count']);
    }

    public function testSupervisorScopeIsAssignmentSpecificAndFutureScopeDoesNotPromiseCurrentManagement(): void
    {
        [$actor, $employee, $policies, $version] = $this->fixture();
        $currentSupervisor = $this->createUser();
        $futureSupervisor = $this->createUser();
        $outsider = $this->createUser();
        $current = $this->assign($policies, $actor, $employee, $version, 1, 20, [$currentSupervisor]);
        $future = $this->assign($policies, $actor, $employee, $version, 21, null, [$futureSupervisor]);
        $service = new WorkTimeUserSummaryService($this->connection(), $policies);
        $asOf = new DateTimeImmutable($this->date(10), new DateTimeZone('Europe/Berlin'));
        $currentSummary = $service->forUser($this->supervisor($currentSupervisor), $employee, $asOf);
        self::assertSame([$current['id']], array_column($currentSummary['assignments'], 'id'));
        self::assertSame(1, $currentSummary['current_count']);
        self::assertTrue($currentSummary['can_manage']);
        $futureSummary = $service->forUser($this->supervisor($futureSupervisor), $employee, $asOf);
        self::assertSame([$future['id']], array_column($futureSummary['assignments'], 'id'));
        self::assertSame(0, $futureSummary['current_count']);
        self::assertFalse($futureSummary['can_manage']);
        $html = (new WorkTimeUserSummaryRenderer())->render($futureSummary);
        self::assertStringContainsString('Geplant', $html);
        self::assertStringContainsString('Kernarbeitszeit für diesen Mitarbeiter ansehen', $html);
        self::assertStringNotContainsString('Kernarbeitszeit für diesen Mitarbeiter verwalten', $html);
        self::assertNull($service->forUser($this->supervisor($outsider), $employee, $asOf));
    }

    public function testViewPermissionIsRequiredEvenForAnAdministratorAndWildcardStillWorks(): void
    {
        [$actor, $employee, $policies, $version] = $this->fixture();
        $this->assign($policies, $actor, $employee, $version, 1);
        $service = new WorkTimeUserSummaryService($this->connection(), $policies);
        $asOf = new DateTimeImmutable($this->date(10));
        foreach ([
            ['id' => $actor, 'roles' => ['administrator'], 'permissions' => ['users.manage']],
            ['id' => $actor, 'roles' => [], 'permissions' => ['work_time_policies.manage']],
            ['id' => $actor, 'roles' => [], 'permissions' => []],
        ] as $withoutView) {
            self::assertNull($service->forUser($withoutView, $employee, $asOf));
        }
        $wildcard = $service->forUser(['id' => $actor, 'roles' => [], 'permissions' => ['*']], $employee, $asOf);
        self::assertTrue($wildcard['can_manage']);
        self::assertCount(1, $wildcard['assignments']);
        $viewOnly = $service->forUser(['id' => $actor, 'roles' => ['administrator'], 'permissions' => ['work_time_policies.view']], $employee, $asOf);
        self::assertFalse($viewOnly['can_manage']);
        self::assertNull($service->forUser($this->admin($actor), 0, $asOf));
    }

    public function testDisabledFeatureShowsConfiguredOverridesAndNightWindowsWithoutClaimingActivation(): void
    {
        [$actor, $employee, $policies, $version] = $this->fixture('Nacht <script>alert(1)</script>');
        $assignment = $this->assign($policies, $actor, $employee, $version, 1, null, [], [
            'windows' => [1 => [['start' => '07:00', 'end' => '15:00']], 5 => [['start' => '22:00', 'end' => '06:00']]],
        ]);
        $disabled = new WorkTimePolicyService($this->connection(), false);
        $summary = (new WorkTimeUserSummaryService($this->connection(), $disabled))->forUser($this->admin($actor), $employee, new DateTimeImmutable($this->date(10)));
        self::assertFalse($summary['enabled']);
        self::assertTrue($summary['schema_available']);
        self::assertSame($assignment['id'], $summary['assignments'][0]['id']);
        self::assertSame([['start' => '07:00', 'end' => '15:00']], $summary['assignments'][0]['windows'][1]);
        $html = (new WorkTimeUserSummaryRenderer())->render($summary);
        self::assertStringContainsString('Global ausgeschaltet', $html);
        self::assertStringContainsString('Für diesen Zeitraum hinterlegt', $html);
        self::assertStringContainsString('Mo: 07:00–15:00', $html);
        self::assertStringContainsString('Fr: 22:00–06:00 (Folgetag)', $html);
        self::assertStringContainsString('unverbindliche Vorschau', $html);
        self::assertStringNotContainsString('Aktuell gültig', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testUnassignedAndExpiredEmployeesHaveNoCurrentRuleAndNoSupervisorInformationLeak(): void
    {
        [$actor, $employee, $policies, $version] = $this->fixture();
        $supervisor = $this->createUser();
        $service = new WorkTimeUserSummaryService($this->connection(), $policies);
        $asOf = new DateTimeImmutable($this->date(10));
        $unassigned = $service->forUser($this->admin($actor), $employee, $asOf);
        self::assertSame([], $unassigned['assignments']);
        self::assertSame(0, $unassigned['current_count']);
        self::assertStringContainsString('Keine aktuelle oder künftige Kernarbeitszeit zugeordnet.', (new WorkTimeUserSummaryRenderer())->render($unassigned));
        $this->assign($policies, $actor, $employee, $version, 1, 4, [$supervisor]);
        $expired = $service->forUser($this->admin($actor), $employee, $asOf);
        self::assertSame([], $expired['assignments']);
        self::assertSame(0, $expired['additional_future_count']);
        self::assertNull($service->forUser($this->supervisor($supervisor), $employee, $asOf));
    }

    public function testSummaryReadsLeaveActualTimesheetsJournalsUsersAndAssignmentHistoryUnchanged(): void
    {
        [$actor, $employee, $policies, $version] = $this->fixture();
        $this->assign($policies, $actor, $employee, $version, 1);
        $this->seedAccountingHistory($employee, $actor);
        $before = $this->snapshot();
        foreach ([true, false] as $enabled) {
            $service = new WorkTimeUserSummaryService($this->connection(), new WorkTimePolicyService($this->connection(), $enabled));
            $summary = $service->forUser($this->admin($actor), $employee, new DateTimeImmutable($this->date(10)));
            self::assertCount(1, $summary['assignments']);
            self::assertStringContainsString('Kernarbeitszeit / Arbeitszeitfenster', (new WorkTimeUserSummaryRenderer())->render($summary));
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testRealUserEditSeparatesTargetHoursAndFutureRulesAndLinksToPrefilledManagementWithoutWrites(): void
    {
        [$actor, $employee, $policies, $version] = $this->fixture('Eindeutige geplante Mitarbeiterregel');
        $this->assign($policies, $actor, $employee, $version, 1);
        $this->grant($actor, 'administrator', ['users.manage', 'work_time_policies.view', 'work_time_policies.manage']);
        $_SESSION['auth']['user_id'] = $actor;
        $disabled = new WorkTimePolicyService($this->connection(), false);
        [$controller, $workTimeController] = $this->controllers($disabled);
        $before = $this->snapshot();
        $response = $controller->userEdit(new Request('GET', '/admin/users/' . $employee . '/edit', [], [], [], [], []), ['id' => (string) $employee]);
        self::assertSame(200, $response->status());
        $html = $this->html($response);
        self::assertStringContainsString('<label for="target_hours_mode">Sollstundenmodell</label>', $html);
        self::assertStringContainsString('data-user-work-time-summary', $html);
        self::assertStringContainsString('Geplant: Eindeutige geplante Mitarbeiterregel', $html);
        self::assertStringContainsString($this->date(1), $html);
        self::assertStringContainsString('Global ausgeschaltet', $html);
        self::assertStringContainsString('href="/admin/work-time?user_id=' . $employee . '"', $html);
        self::assertStringContainsString('unverbindliche Vorschau', $html);
        $management = $workTimeController->index(new Request('GET', '/admin/work-time', ['user_id' => (string) $employee], [], [], [], []));
        self::assertSame(200, $management->status());
        $managementHtml = $this->html($management);
        $document = new \DOMDocument();
        $document->loadHTML($managementHtml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $query = new \DOMXPath($document);
        self::assertSame(1, $query->query('//form[@action="/admin/work-time/assignments"]//select[@name="user_id"]/option[@value="' . $employee . '" and @selected]')->length);
        self::assertSame($before, $this->snapshot());

        $withoutWorkTime = $this->createUser();
        $this->grant($withoutWorkTime, 'user-form-only', ['users.manage']);
        $_SESSION['auth']['user_id'] = $withoutWorkTime;
        $hidden = $this->html($controller->userEdit(new Request('GET', '/admin/users/' . $employee . '/edit', [], [], [], [], []), ['id' => (string) $employee]));
        self::assertStringContainsString('Sollstundenmodell', $hidden);
        self::assertStringNotContainsString('data-user-work-time-summary', $hidden);
        self::assertStringNotContainsString('Eindeutige geplante Mitarbeiterregel', $hidden);
    }

    private function fixture(string $name = 'Fensterregel'): array
    {
        $actor = $this->createUser();
        $employee = $this->createUser();
        $policies = new WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => $name, 'policy' => ['windows' => [1 => [['start' => '08:00', 'end' => '16:00']]]]], $actor);
        return [$actor, $employee, $policies, $template['version']['id']];
    }

    private function assign(WorkTimePolicyService $policies, int $actor, int $employee, int $version, int $from, ?int $to = null, array $supervisors = [], array $overrides = []): array
    {
        return $policies->assign(['user_id' => $employee, 'policy_version_id' => $version, 'effective_from' => $this->date($from),
            'effective_to' => $to === null ? '' : $this->date($to), 'supervisor_user_ids' => $supervisors, 'overrides' => $overrides], $actor);
    }

    private function date(int $offset): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    private function admin(int $id): array { return ['id' => $id, 'roles' => ['administrator'], 'permissions' => ['work_time_policies.view', 'work_time_policies.manage']]; }
    private function supervisor(int $id): array { return ['id' => $id, 'roles' => ['bauleiter'], 'permissions' => ['work_time_policies.view', 'work_time_policies.manage']]; }

    private function grant(int $actor, string $slug, array $permissions): void
    {
        $this->connection()->execute('INSERT INTO roles (slug, name) VALUES (:slug, :name)', ['slug' => $slug, 'name' => $slug]);
        $role = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:actor, :role)', ['actor' => $actor, 'role' => $role]);
        foreach ($permissions as $code) {
            $this->connection()->execute('INSERT INTO permissions (code, label, scope) VALUES (:code, :label, "backend") ON DUPLICATE KEY UPDATE code = VALUES(code)', ['code' => $code, 'label' => $code]);
            $permission = $this->connection()->fetchColumn('SELECT id FROM permissions WHERE code = :code', ['code' => $code]);
            $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)', ['role' => $role, 'permission' => $permission]);
        }
    }

    private function controllers(WorkTimePolicyService $policies): array
    {
        $connection = $this->connection();
        $matrix = new PermissionMatrix([], []);
        $auth = new AuthService($connection, $matrix);
        $csrf = new CsrfService();
        $view = new AdminView('Test', 'http://localhost');
        $users = new UserService($connection);
        $controller = new AdminManagementController($view, new ProjectService($connection), $users, new RoleService($connection, $matrix),
            new AssetService($connection), new FileAttachmentService($connection, []), new DocumentStatusService($connection),
            new AdminBookingService($connection, new TimesheetCalculator()), $auth, $csrf, workTimeUserSummaryService: new WorkTimeUserSummaryService($connection, $policies));
        $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
        $workTime = new AdminWorkTimeController($view, $auth, $csrf, $users, $policies, new WorkTimePreviewService($connection, $policies),
            new WorkTimeDeviationService($connection, $policies), new WorkTimeAccessService($policies), new WorkTimeEventService($connection, $policies, $sync));
        return [$controller, $workTime];
    }

    private function seedAccountingHistory(int $employee, int $actor): void
    {
        $this->connection()->execute('INSERT INTO timesheets (user_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source) VALUES (:user_id, "2026-01-05", "07:42", "16:35", 533, 30, 503, "work", "app")', ['user_id' => $employee]);
        $this->connection()->execute('INSERT INTO employee_account_cutovers (user_id, active_final_user_id, effective_from, opening_time_balance_minutes, leave_year, annual_leave_entitlement_days, leave_carryover_days, opening_remaining_leave_days, status, created_by_user_id, finalized_by_user_id, finalized_at) VALUES (:user_id, :active, "2026-01-01", 60, 2026, 30, 0, 30, "final", :creator, :finalizer, NOW())', ['user_id' => $employee, 'active' => $employee, 'creator' => $actor, 'finalizer' => $actor]);
        $cutover = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO time_account_entries (user_id, cutover_id, effective_date, minutes, entry_type, description) VALUES (:user_id, :cutover, "2026-01-01", 60, "manual_adjustment", "Unveraendert")', ['user_id' => $employee, 'cutover' => $cutover]);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'employee_account_cutovers', 'time_account_entries', 'vacation_account_entries', 'timesheet_breaks', 'employee_work_time_assignments',
            'employee_work_time_supervisors', 'work_time_policy_audit', 'work_time_deviations', 'work_time_deviation_decisions', 'work_time_presence_sessions',
            'work_time_checkout_proposals', 'work_time_checkout_proposal_actions', 'work_time_notification_events', 'work_time_pause_corrections'] as $table) {
            $snapshot[$table] = $this->connection()->fetchAll('SELECT * FROM `' . $table . '` ORDER BY id');
        }
        $snapshot['timesheets'] = $this->connection()->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL ORDER BY id, row_start');
        return $snapshot;
    }

    private function html(Response $response): string
    {
        ob_start(); $response->send(); return (string) ob_get_clean();
    }
}
