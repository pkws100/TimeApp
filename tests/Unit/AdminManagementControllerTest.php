<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Assets\AssetService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
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

    public function testProjectBookingRouteIsRegistered(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php')) ?: '';

        self::assertStringContainsString('/admin/projects/{id}/bookings', $bootstrap);
        self::assertStringContainsString('projectBookingStore', $bootstrap);
        self::assertStringContainsString('timesheets.manage', $bootstrap);
    }

    private function invokeProjectLifecycleForm(string $archiveAction, string $restoreAction, bool $archived): string
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'projectLifecycleForm');
        $method->setAccessible(true);

        return (string) $method->invoke($controller, $archiveAction, $restoreAction, $archived);
    }

    private function controller(): AdminManagementController
    {
        $connection = new DatabaseConnection([]);
        $permissions = new PermissionMatrix([], []);

        return new AdminManagementController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            new ProjectService($connection),
            new UserService($connection),
            new RoleService($connection, $permissions),
            new AssetService($connection),
            new FileAttachmentService($connection, []),
            new AdminBookingService($connection, new TimesheetCalculator()),
            new AuthService($connection, $permissions),
            new CsrfService()
        );
    }
}
