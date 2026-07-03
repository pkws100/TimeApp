<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Assets\AssetService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Files\DocumentStatusService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\RoleService;
use App\Domain\Users\UserService;
use App\Http\Controllers\AdminManagementController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminManagementControllerTest extends TestCase
{
    public function testProjectLifecycleFormRendersArchiveConfirmationForActiveProjects(): void
    {
        $html = $this->invokeProjectLifecycleForm('/admin/projects/5', '/admin/projects/5/restore', false);

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('action="/admin/projects/5"', $html);
        self::assertStringContainsString('name="_method" value="DELETE"', $html);
        self::assertStringContainsString("confirm('Projekt wirklich archivieren?')", $html);
        self::assertStringContainsString('Archivieren', $html);
        self::assertStringNotContainsString('Wiederherstellen', $html);
    }

    public function testProjectLifecycleFormRendersRestoreForArchivedProjects(): void
    {
        $html = $this->invokeProjectLifecycleForm('/admin/projects/5', '/admin/projects/5/restore', true);

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('action="/admin/projects/5/restore"', $html);
        self::assertStringContainsString('Wiederherstellen', $html);
        self::assertStringNotContainsString('name="_method" value="DELETE"', $html);
        self::assertStringNotContainsString('Projekt wirklich archivieren?', $html);
    }

    public function testRestoredNoticeUsesProjectSpecificMessage(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'notice');
        $method->setAccessible(true);
        $html = (string) $method->invoke($controller, new Request('GET', '/admin/projects', ['notice' => 'restored'], [], [], [], []));

        self::assertStringContainsString('Projekt erfolgreich wiederhergestellt.', $html);
    }

    public function testMembershipNoticeUsesProjectSpecificMessage(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'notice');
        $method->setAccessible(true);
        $html = (string) $method->invoke($controller, new Request('GET', '/admin/projects/5/edit', ['notice' => 'memberships-updated'], [], [], [], []));

        self::assertStringContainsString('Projektfreigaben erfolgreich gespeichert.', $html);
    }

    public function testProjectMembershipSectionRendersActiveUsersWithRolesAndSelection(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderProjectMembershipSection');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, ['id' => 5], [
            ['id' => 1, 'first_name' => 'Anna', 'last_name' => 'Aktiv', 'employee_number' => 'A-1', 'role_names' => 'Mitarbeiter'],
            ['id' => 2, 'first_name' => 'Ben', 'last_name' => 'Bau', 'employee_number' => 'B-2', 'role_names' => 'Bauleiter, Mitarbeiter'],
            ['id' => 3, 'first_name' => 'Rita', 'last_name' => 'Rollenlos', 'employee_number' => '', 'role_names' => ''],
            ['id' => 4, 'first_name' => 'Ina', 'last_name' => 'Inaktiv', 'employee_number' => '', 'role_names' => 'Mitarbeiter', 'employment_status' => 'inactive'],
            ['id' => 5, 'first_name' => 'Archiv', 'last_name' => 'User', 'employee_number' => '', 'role_names' => 'Mitarbeiter', 'is_deleted' => 1],
        ], [2]);

        self::assertStringContainsString('action="/admin/projects/5/memberships"', $html);
        self::assertStringContainsString('App-Projektfreigaben', $html);
        self::assertStringContainsString('name="user_ids[]" value="1" ', $html);
        self::assertStringContainsString('name="user_ids[]" value="2" checked', $html);
        self::assertStringContainsString('Anna Aktiv', $html);
        self::assertStringContainsString('A-1', $html);
        self::assertStringContainsString('Bauleiter, Mitarbeiter', $html);
        self::assertStringContainsString('<br><small class="muted">', $html);
        self::assertStringContainsString('Keine Rolle', $html);
        self::assertStringNotContainsString('Ina Inaktiv', $html);
        self::assertStringNotContainsString('Archiv User', $html);
        self::assertLessThan(strpos($html, 'Anna Aktiv'), strpos($html, 'Ben Bau'));
        self::assertStringContainsString('Projektfreigaben speichern', $html);
    }

    public function testManualProjectBookingFormUsesOnlyActiveUsersAndForcedProjectAction(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderManualProjectBookingForm');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, 5, [
            ['id' => 1, 'first_name' => 'Anna', 'last_name' => 'Aktiv', 'employee_number' => 'A-1', 'employment_status' => 'active', 'is_deleted' => 0],
            ['id' => 2, 'first_name' => 'Archiv', 'last_name' => 'User', 'employee_number' => 'A-2', 'employment_status' => 'active', 'is_deleted' => 1],
            ['id' => 3, 'first_name' => 'Inaktiv', 'last_name' => 'User', 'employee_number' => 'A-3', 'employment_status' => 'inactive', 'is_deleted' => 0],
        ], ['work' => 'Arbeit', 'sick' => 'Krank'], 'csrf-token');

        self::assertStringContainsString('action="/admin/projects/5/bookings"', $html);
        self::assertStringContainsString('name="csrf_token" value="csrf-token"', $html);
        self::assertStringContainsString('Buchung nacherfassen', $html);
        self::assertStringContainsString('Anna Aktiv (A-1)', $html);
        self::assertStringNotContainsString('Archiv User', $html);
        self::assertStringNotContainsString('Inaktiv User', $html);
        self::assertStringContainsString('name="change_reason"', $html);
    }

    public function testProjectStoreRendersValidationErrorsAndKeepsSubmittedData(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $response = $this->controller()->projectStore(new Request('POST', '/admin/projects', [], [
            'csrf_token' => 'valid-token',
            'project_number' => '',
            'name' => '',
            'customer_name' => 'Stadt Muster',
            'customer_signature_required' => '1',
            'customer_signature_name' => 'Bauherr Muster',
            'status' => 'active',
            'address_line_1' => 'Musterstrasse 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            'starts_on' => '2026-07-10',
            'ends_on' => '2026-07-01',
        ], [], [], []));

        $html = $this->responseContent($response);

        self::assertSame(422, $response->status());
        self::assertArrayNotHasKey('Location', $response->headers());
        self::assertStringNotContainsString('name="_method" value="PUT"', $html);
        self::assertStringContainsString('Projekt konnte nicht angelegt werden.', $html);
        self::assertStringContainsString('Bitte geben Sie eine Projektnummer ein.', $html);
        self::assertStringContainsString('Bitte geben Sie einen Projektnamen ein.', $html);
        self::assertStringContainsString('Das Enddatum darf nicht vor dem Startdatum liegen.', $html);
        self::assertStringContainsString('name="customer_name" value="Stadt Muster"', $html);
        self::assertStringContainsString('name="customer_signature_required" value="1" checked', $html);
        self::assertStringContainsString('name="customer_signature_name" value="Bauherr Muster"', $html);
        self::assertStringContainsString('<option value="active" selected>Aktiv</option>', $html);
        self::assertStringContainsString('name="address_line_1" value="Musterstrasse 1"', $html);
        self::assertStringContainsString('name="postal_code" value="12345"', $html);
        self::assertStringContainsString('name="city" value="Musterstadt"', $html);
        self::assertStringContainsString('name="starts_on" value="2026-07-10"', $html);
        self::assertStringContainsString('name="ends_on" value="2026-07-01"', $html);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="field-error-project_number"', $html);
    }

    public function testProjectStoreKeepsDataOnCsrfError(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $response = $this->controller()->projectStore(new Request('POST', '/admin/projects', [], [
            'csrf_token' => 'expired-token',
            'project_number' => 'P-11',
            'name' => 'Rathaus',
            'customer_name' => 'Gemeinde Mitte',
            'status' => 'planning',
            'city' => 'Lueneburg',
        ], [], [], []));

        $html = $this->responseContent($response);

        self::assertSame(422, $response->status());
        self::assertArrayNotHasKey('Location', $response->headers());
        self::assertStringNotContainsString('name="_method" value="PUT"', $html);
        self::assertStringContainsString('Die Sicherheitspruefung ist abgelaufen. Bitte erneut versuchen.', $html);
        self::assertStringContainsString('name="project_number" value="P-11"', $html);
        self::assertStringContainsString('name="name" value="Rathaus"', $html);
        self::assertStringContainsString('name="customer_name" value="Gemeinde Mitte"', $html);
        self::assertStringContainsString('name="city" value="Lueneburg"', $html);
    }

    public function testProjectFormCanRenderStorageErrorWithoutSwitchingToEditForm(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderProjectForm');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, '/admin/projects', 'Projekt anlegen', [
            'project_number' => 'P-10',
            'name' => 'Kita Nord',
            'customer_name' => 'Stadt Nord',
            'customer_signature_required' => 1,
            'customer_signature_name' => 'Max Muster',
            'status' => 'planning',
        ], [
            '_form' => ['Das Projekt konnte nicht angelegt werden. Bitte pruefen Sie die Projektnummer auf doppelte Werte.'],
        ]);

        self::assertStringContainsString('Das Projekt konnte nicht angelegt werden. Bitte pruefen Sie die Projektnummer auf doppelte Werte.', $html);
        self::assertStringNotContainsString('name="_method" value="PUT"', $html);
        self::assertStringContainsString('name="project_number" value="P-10"', $html);
        self::assertStringContainsString('name="customer_signature_required" value="1" checked', $html);
    }

    public function testProjectsTableRendersSelectableRowsWithEditUrl(): void
    {
        $response = $this->controller()->projects(new Request('GET', '/admin/projects', [], [], [], [], []));
        $html = $this->responseContent($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('data-admin-table="projects"', $html);
        self::assertStringContainsString('data-row-selectable="true"', $html);
        self::assertStringContainsString('data-edit-url="/admin/projects/1/edit"', $html);
        self::assertStringContainsString('<td>2026-001</td><td>Neubau Kita Nord</td>', $html);
        self::assertStringContainsString('<a class="button" href="/admin/projects/1/edit">Bearbeiten</a>', $html);
    }

    public function testUserFormRendersTimeTrackingRequirementCheckbox(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderUserForm');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, '/admin/users', 'User anlegen', null, []);

        self::assertStringContainsString('name="time_tracking_required" value="0"', $html);
        self::assertStringContainsString('name="time_tracking_required" value="1" checked', $html);
        self::assertStringContainsString('Zeiterfassung erforderlich', $html);
    }

    public function testUsersTableRendersEmployeeNumberColumnAndSelectableRows(): void
    {
        $response = $this->controller()->users(new Request('GET', '/admin/users', [], [], [], [], []));
        $html = $this->responseContent($response);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('data-admin-table="users"', $html);
        self::assertStringContainsString('<th>Mitarbeiternummer</th><th>Name</th>', $html);
        self::assertStringContainsString('data-row-selectable="true"', $html);
        self::assertStringContainsString('data-edit-url="/admin/users/1/edit"', $html);
        self::assertStringContainsString('<td>MA-001</td><td>Jana Kluge</td>', $html);
        self::assertStringContainsString('<a class="button" href="/admin/users/1/edit">Bearbeiten</a>', $html);
    }

    public function testUserFormCanRenderVoluntaryTimeTrackingUnchecked(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderUserForm');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, '/admin/users/5', 'User bearbeiten', [
            'id' => 5,
            'first_name' => 'Notfall',
            'last_name' => 'Admin',
            'email' => 'notfall@example.test',
            'employment_status' => 'active',
            'time_tracking_required' => 0,
            'role_ids' => [],
        ], []);

        self::assertStringContainsString('name="time_tracking_required" value="1" >', $html);
        self::assertStringContainsString('werden aber nicht als fehlend gewertet', $html);
    }

    public function testUserFormRendersAppDisplaySettings(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderUserForm');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, '/admin/users/5', 'User bearbeiten', [
            'id' => 5,
            'first_name' => 'Anzeige',
            'last_name' => 'Test',
            'email' => 'anzeige@example.test',
            'employment_status' => 'active',
            'time_tracking_required' => 1,
            'role_ids' => [],
            'app_ui_settings' => [
                'show_today_total_minutes' => false,
                'show_project_today_minutes' => true,
            ],
        ], []);

        self::assertStringContainsString('Mitarbeiter-App Anzeige', $html);
        self::assertStringContainsString('name="app_ui_settings[show_today_total_minutes]" value="0"', $html);
        self::assertStringContainsString('name="app_ui_settings[show_today_total_minutes]" value="1" >', $html);
        self::assertStringContainsString('name="app_ui_settings[show_project_today_minutes]" value="1" checked', $html);
        self::assertStringContainsString('name="app_ui_settings[show_personnel_overview]" value="1" checked', $html);
        self::assertStringContainsString('Personal: Labels und Events', $html);
        self::assertStringContainsString('Zusatzkachel Aktueller Einsatz', $html);
        self::assertStringNotContainsString('name="app_ui_settings[show_project_total_minutes]"', $html);
        self::assertStringContainsString('Tagesstatus, Start, Ende, Pausen, Nettozeit, Projekt und Zeiterfassungsaktionen bleiben immer sichtbar', $html);
    }

    public function testUserStoreRendersValidationErrorsAndKeepsSubmittedData(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $response = $this->controllerWithRoles()->userStore(new Request('POST', '/admin/users', [], [
            'csrf_token' => 'valid-token',
            'employee_number' => 'M-42',
            'first_name' => '',
            'last_name' => 'Muster',
            'email' => 'ungueltig',
            'phone' => '+49 151 123',
            'password' => 'secret123',
            'employment_status' => 'active',
            'target_hours_month' => '-1',
            'time_tracking_required' => '0',
            'app_ui_settings' => [
                'show_today_total_minutes' => '0',
                'show_project_today_minutes' => '1',
            ],
            'role_ids' => ['1'],
        ], [], [], []));

        $html = $this->responseContent($response);

        self::assertSame(422, $response->status());
        self::assertArrayNotHasKey('Location', $response->headers());
        self::assertStringNotContainsString('name="_method" value="PUT"', $html);
        self::assertStringContainsString('Benutzer konnte nicht angelegt werden.', $html);
        self::assertStringContainsString('Bitte geben Sie einen Vornamen ein.', $html);
        self::assertStringContainsString('Bitte geben Sie eine gueltige E-Mail-Adresse ein.', $html);
        self::assertStringContainsString('Sollstunden muessen eine Zahl groesser oder gleich 0 sein.', $html);
        self::assertStringContainsString('name="employee_number" value="M-42"', $html);
        self::assertStringContainsString('name="last_name" value="Muster"', $html);
        self::assertStringContainsString('name="email" type="email" value="ungueltig"', $html);
        self::assertStringContainsString('name="phone" value="+49 151 123"', $html);
        self::assertStringContainsString('name="password" type="password" required', $html);
        self::assertStringNotContainsString('value="secret123"', $html);
        self::assertStringContainsString('name="target_hours_month" type="number" step="0.01" value="-1"', $html);
        self::assertStringContainsString('name="time_tracking_required" value="1" >', $html);
        self::assertStringContainsString('name="app_ui_settings[show_today_total_minutes]" value="1" >', $html);
        self::assertStringContainsString('name="role_ids[]" value="1" checked', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
    }

    public function testUserStoreKeepsDataButClearsPasswordOnCsrfError(): void
    {
        $_SESSION['_csrf_token'] = 'valid-token';

        $response = $this->controllerWithRoles()->userStore(new Request('POST', '/admin/users', [], [
            'csrf_token' => 'expired-token',
            'employee_number' => 'M-43',
            'first_name' => 'Clara',
            'last_name' => 'Csrf',
            'email' => 'clara@example.test',
            'password' => 'topsecret',
            'employment_status' => 'active',
            'role_ids' => ['1'],
        ], [], [], []));

        $html = $this->responseContent($response);

        self::assertSame(422, $response->status());
        self::assertArrayNotHasKey('Location', $response->headers());
        self::assertStringNotContainsString('name="_method" value="PUT"', $html);
        self::assertStringContainsString('Die Sicherheitspruefung ist abgelaufen. Bitte erneut versuchen.', $html);
        self::assertStringContainsString('name="employee_number" value="M-43"', $html);
        self::assertStringContainsString('name="first_name" value="Clara"', $html);
        self::assertStringContainsString('name="email" type="email" value="clara@example.test"', $html);
        self::assertStringNotContainsString('value="topsecret"', $html);
        self::assertStringContainsString('name="role_ids[]" value="1" checked', $html);
    }

    public function testUserFormClearsPasswordWhenRenderingStorageError(): void
    {
        $controller = $this->controllerWithRoles();
        $method = new ReflectionMethod($controller, 'renderUserForm');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, '/admin/users', 'User anlegen', [
            'first_name' => 'Dora',
            'last_name' => 'Doppel',
            'email' => 'dora@example.test',
            'employment_status' => 'active',
            'role_ids' => [1],
        ], [
            ['id' => 1, 'name' => 'Mitarbeiter'],
        ], [], [], [
            '_form' => ['Der Benutzer konnte nicht angelegt werden. Bitte pruefen Sie E-Mail-Adresse und Mitarbeiternummer auf doppelte Werte.'],
        ]);

        self::assertStringContainsString('Der Benutzer konnte nicht angelegt werden. Bitte pruefen Sie E-Mail-Adresse und Mitarbeiternummer auf doppelte Werte.', $html);
        self::assertStringNotContainsString('name="_method" value="PUT"', $html);
        self::assertStringContainsString('name="password" type="password" required', $html);
        self::assertStringNotContainsString('stored-response-only', $html);
        self::assertStringContainsString('name="role_ids[]" value="1" checked', $html);
    }

    public function testUserUpdateRendersDuplicateEmployeeNumberValidationError(): void
    {
        [$connection, $ids] = $this->userEditFixtureConnection();
        $_SESSION['_csrf_token'] = 'valid-token';

        $response = $this->controllerWithRoles($connection)->userUpdate(new Request('POST', '/admin/users/' . $ids['target'] . '/edit', [], [
            'csrf_token' => 'valid-token',
            'employee_number' => 'M-2',
            'first_name' => 'Anna',
            'last_name' => 'Aktualisiert',
            'email' => 'anna.neu@example.test',
            'phone' => '+49 151 555',
            'password' => '',
            'employment_status' => 'active',
            'target_hours_month' => '120',
            'time_tracking_required' => '0',
            'app_ui_settings' => [
                'show_today_total_minutes' => '0',
                'show_project_today_minutes' => '1',
            ],
            'role_ids' => ['1'],
        ], [], [], []), ['id' => (string) $ids['target']]);

        $html = $this->responseContent($response);

        self::assertSame(422, $response->status());
        self::assertArrayNotHasKey('Location', $response->headers());
        self::assertStringContainsString('Benutzer konnte nicht gespeichert werden.', $html);
        self::assertStringContainsString('Diese Mitarbeiternummer ist bereits vergeben.', $html);
        self::assertStringContainsString('name="_method" value="PUT"', $html);
        self::assertStringContainsString('action="/admin/users/' . $ids['target'] . '"', $html);
        self::assertStringContainsString('name="employee_number" value="M-2"', $html);
        self::assertStringContainsString('name="last_name" value="Aktualisiert"', $html);
        self::assertStringContainsString('name="email" type="email" value="anna.neu@example.test"', $html);
        self::assertStringContainsString('name="phone" value="+49 151 555"', $html);
        self::assertStringContainsString('name="target_hours_month" type="number" step="0.01" value="120"', $html);
        self::assertStringContainsString('name="time_tracking_required" value="1" >', $html);
        self::assertStringContainsString('name="app_ui_settings[show_today_total_minutes]" value="1" >', $html);
        self::assertStringContainsString('name="role_ids[]" value="1" checked', $html);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="field-error-employee_number"', $html);
        self::assertStringNotContainsString('value="secret', $html);
    }

    public function testUserUpdateAllowsOwnEmployeeNumber(): void
    {
        [$connection, $ids] = $this->userEditFixtureConnection();
        $_SESSION['_csrf_token'] = 'valid-token';

        $response = $this->controllerWithRoles($connection)->userUpdate(new Request('POST', '/admin/users/' . $ids['target'] . '/edit', [], [
            'csrf_token' => 'valid-token',
            'employee_number' => 'M-1',
            'first_name' => 'Anna',
            'last_name' => 'Erlaubt',
            'email' => 'anna@example.test',
            'password' => '',
            'employment_status' => 'active',
            'target_hours_month' => '80',
            'time_tracking_required' => '1',
            'role_ids' => ['1'],
        ], [], [], []), ['id' => (string) $ids['target']]);

        self::assertSame(302, $response->status());
        self::assertSame('/admin/users/' . $ids['target'] . '/edit?notice=updated', $response->headers()['Location'] ?? null);
    }

    public function testUserUpdateRendersDuplicateEmailValidationError(): void
    {
        [$connection, $ids] = $this->userEditFixtureConnection();
        $_SESSION['_csrf_token'] = 'valid-token';

        $response = $this->controllerWithRoles($connection)->userUpdate(new Request('POST', '/admin/users/' . $ids['target'] . '/edit', [], [
            'csrf_token' => 'valid-token',
            'employee_number' => 'M-1',
            'first_name' => 'Anna',
            'last_name' => 'Mailkonflikt',
            'email' => 'ben@example.test',
            'password' => '',
            'employment_status' => 'active',
            'target_hours_month' => '80',
            'time_tracking_required' => '1',
            'role_ids' => ['1'],
        ], [], [], []), ['id' => (string) $ids['target']]);

        $html = $this->responseContent($response);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Benutzer konnte nicht gespeichert werden.', $html);
        self::assertStringContainsString('Diese E-Mail-Adresse ist bereits einem Benutzer zugeordnet.', $html);
        self::assertStringContainsString('name="email" type="email" value="ben@example.test"', $html);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="field-error-email"', $html);
    }

    public function testProjectBookingRouteIsRegistered(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php')) ?: '';

        self::assertStringContainsString('/admin/projects/{id}/bookings', $bootstrap);
        self::assertStringContainsString('projectBookingStore', $bootstrap);
        self::assertStringContainsString('timesheets.manage', $bootstrap);
    }

    public function testProjectMembershipRouteIsRegistered(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php')) ?: '';

        self::assertStringContainsString('/admin/projects/{id}/memberships', $bootstrap);
        self::assertStringContainsString('projectMembershipUpdate', $bootstrap);
        self::assertStringContainsString('projects.manage', $bootstrap);
    }

    private function invokeProjectLifecycleForm(string $archiveAction, string $restoreAction, bool $archived): string
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'projectLifecycleForm');
        $method->setAccessible(true);

        return (string) $method->invoke($controller, $archiveAction, $restoreAction, $archived);
    }

    private function controller(?DatabaseConnection $connection = null): AdminManagementController
    {
        $connection ??= new DatabaseConnection([]);
        $permissions = new PermissionMatrix([], []);

        return new AdminManagementController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            new ProjectService($connection),
            new UserService($connection),
            new RoleService($connection, $permissions),
            new AssetService($connection),
            new FileAttachmentService($connection, []),
            new DocumentStatusService($connection),
            new AdminBookingService($connection, new TimesheetCalculator()),
            new AuthService($connection, $permissions),
            new CsrfService()
        );
    }

    private function controllerWithRoles(?DatabaseConnection $connection = null): AdminManagementController
    {
        $connection ??= new DatabaseConnection([]);
        $permissions = new PermissionMatrix([
            'mitarbeiter' => [
                'label' => 'Mitarbeiter',
                'permissions' => ['timesheets.create'],
            ],
        ], ['timesheets.create']);

        return new AdminManagementController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            new ProjectService($connection),
            new UserService($connection),
            new RoleService($connection, $permissions),
            new AssetService($connection),
            new FileAttachmentService($connection, []),
            new DocumentStatusService($connection),
            new AdminBookingService($connection, new TimesheetCalculator()),
            new AuthService($connection, $permissions),
            new CsrfService()
        );
    }

    /**
     * @return array{0: DatabaseConnection, 1: array{target: int, duplicate: int}}
     */
    private function userEditFixtureConnection(): array
    {
        $pdo = new UserEditPdoDouble();
        $ids = $pdo->seedDuplicateUsers();
        $connection = new DatabaseConnection(['database' => 'test']);
        $property = new \ReflectionProperty(DatabaseConnection::class, 'pdo');
        $property->setAccessible(true);
        $property->setValue($connection, $pdo);

        return [$connection, $ids];
    }

    private function responseContent(object $response): string
    {
        $property = new \ReflectionProperty($response, 'content');
        $property->setAccessible(true);

        return (string) $property->getValue($response);
    }
}

final class UserEditPdoDouble extends PDO
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $users = [];

    /**
     * @var array<int, array<int>>
     */
    private array $userRoles = [];

    private bool $inTransaction = false;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new UserEditPdoStatementDouble($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->inTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) max(array_keys($this->users) ?: [0]);
    }

    /**
     * @return array{target: int, duplicate: int}
     */
    public function seedDuplicateUsers(): array
    {
        $this->users = [
            1 => $this->userRow(1, 'M-1', 'Anna', 'Alt', 'anna@example.test'),
            2 => $this->userRow(2, 'M-2', 'Ben', 'Belegt', 'ben@example.test'),
        ];
        $this->userRoles = [1 => [1], 2 => []];

        return ['target' => 1, 'duplicate' => 2];
    }

    public function fetchAllFor(string $sql, array $params): array
    {
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        if (str_contains($normalizedSql, 'FROM information_schema.tables')) {
            $table = (string) ($params['table'] ?? '');

            return [['COUNT(*)' => in_array($table, ['users', 'user_roles'], true) ? 1 : 0]];
        }

        if (str_contains($normalizedSql, 'FROM information_schema.columns')) {
            $column = (string) ($params['column'] ?? '');

            return [['COUNT(*)' => in_array($column, ['time_tracking_required'], true) ? 1 : 0]];
        }

        if (str_contains($normalizedSql, 'FROM users WHERE LOWER(email) = LOWER(:email)')) {
            return [['COUNT(*)' => $this->countUsersByEmail((string) ($params['email'] ?? ''), $params['exclude_user_id'] ?? null)]];
        }

        if (str_contains($normalizedSql, 'FROM users WHERE employee_number = :employee_number')) {
            return [['COUNT(*)' => $this->countUsersByEmployeeNumber((string) ($params['employee_number'] ?? ''), $params['exclude_user_id'] ?? null)]];
        }

        if (str_contains($normalizedSql, 'FROM users') && str_contains($normalizedSql, 'WHERE id = :id')) {
            $user = $this->users[(int) ($params['id'] ?? 0)] ?? null;

            return $user !== null ? [$user] : [];
        }

        if (str_contains($normalizedSql, 'SELECT role_id FROM user_roles WHERE user_id = :user_id')) {
            return array_map(
                static fn (int $roleId): array => ['role_id' => $roleId],
                $this->userRoles[(int) ($params['user_id'] ?? 0)] ?? []
            );
        }

        return [];
    }

    public function executeFor(string $sql, array $params): bool
    {
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        if (str_starts_with($normalizedSql, 'UPDATE users SET')) {
            $id = (int) ($params['id'] ?? 0);

            if (!isset($this->users[$id])) {
                return false;
            }

            foreach ([
                'employee_number',
                'first_name',
                'last_name',
                'email',
                'phone',
                'employment_status',
                'emergency_contact_name',
                'emergency_contact_phone',
                'target_hours_month',
                'time_tracking_required',
            ] as $field) {
                if (array_key_exists($field, $params)) {
                    $this->users[$id][$field] = $params[$field];
                }
            }

            $this->users[$id]['updated_at'] = '2026-07-03 12:00:00';

            return true;
        }

        if (str_starts_with($normalizedSql, 'DELETE FROM user_roles WHERE user_id = :user_id')) {
            $this->userRoles[(int) ($params['user_id'] ?? 0)] = [];

            return true;
        }

        if (str_starts_with($normalizedSql, 'INSERT INTO user_roles')) {
            $userId = (int) ($params['user_id'] ?? 0);
            $roleId = (int) ($params['role_id'] ?? 0);
            $this->userRoles[$userId] ??= [];
            $this->userRoles[$userId][] = $roleId;
            $this->userRoles[$userId] = array_values(array_unique($this->userRoles[$userId]));

            return true;
        }

        return false;
    }

    private function countUsersByEmail(string $email, mixed $excludeUserId): int
    {
        $count = 0;

        foreach ($this->users as $user) {
            if ($excludeUserId !== null && (int) $user['id'] === (int) $excludeUserId) {
                continue;
            }

            if (strcasecmp((string) $user['email'], $email) === 0) {
                ++$count;
            }
        }

        return $count;
    }

    private function countUsersByEmployeeNumber(string $employeeNumber, mixed $excludeUserId): int
    {
        $count = 0;

        foreach ($this->users as $user) {
            if ($excludeUserId !== null && (int) $user['id'] === (int) $excludeUserId) {
                continue;
            }

            if ((string) ($user['employee_number'] ?? '') === $employeeNumber) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(int $id, string $employeeNumber, string $firstName, string $lastName, string $email): array
    {
        return [
            'id' => $id,
            'employee_number' => $employeeNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => null,
            'password_hash' => password_hash('secret' . $id, PASSWORD_DEFAULT),
            'employment_status' => 'active',
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
            'target_hours_month' => '40.00',
            'time_tracking_required' => 1,
            'is_deleted' => 0,
            'deleted_at' => null,
            'created_at' => '2026-07-03 10:00:00',
            'updated_at' => '2026-07-03 10:00:00',
        ];
    }
}

final class UserEditPdoStatementDouble extends PDOStatement
{
    private array $params = [];

    public function __construct(
        private UserEditPdoDouble $pdo,
        private string $sql
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];

        if (preg_match('/^\s*(UPDATE|DELETE|INSERT)\b/i', $this->sql) === 1) {
            return $this->pdo->executeFor($this->sql, $this->params);
        }

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->pdo->fetchAllFor($this->sql, $this->params);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $rows = $this->fetchAll();
        $row = $rows[0] ?? [];

        if ($row === []) {
            return false;
        }

        return array_values($row)[$column] ?? false;
    }
}
