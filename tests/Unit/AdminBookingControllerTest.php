<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Exports\BookingExportService;
use App\Domain\Files\DocumentStatusService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetGeoLocationService;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Http\Controllers\AdminBookingController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminBookingControllerTest extends TestCase
{
    public function testBookingsPageContainsOpenProjectAssignmentQuickFilter(): void
    {
        $_SESSION = [];

        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderPage');
        $method->setAccessible(true);

        $html = (string) $method->invoke(
            $controller,
            new Request('GET', '/admin/bookings', [], [], [], [], ['REQUEST_URI' => '/admin/bookings']),
            [
                'date_from' => null,
                'date_to' => null,
                'project_id' => '',
                'user_id' => '',
                'entry_type' => '',
                'scope' => 'active',
                'issue' => '',
                'sort' => 'date',
                'direction' => 'desc',
                'page' => 1,
                'per_page' => 100,
            ],
            [],
            [],
            [],
            '',
            '/admin/bookings',
            'csrf-token',
            [
                'items' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 100,
                'total_pages' => 1,
            ]
        );

        self::assertStringContainsString('Offene Projektzuordnungen', $html);
        self::assertStringContainsString('/admin/bookings?scope=active&amp;project_id=__none__&amp;entry_type=work', $html);
        self::assertStringContainsString('Fehlbuchungen', $html);
        self::assertStringContainsString('/admin/bookings?scope=active&amp;issue=all', $html);
        self::assertStringContainsString('booking-sort-link', $html);
        self::assertStringContainsString('<option value="75">75</option>', $html);
        self::assertStringNotContainsString('<option value="200">200</option>', $html);
        self::assertStringContainsString('Tabellenspalten anpassen', $html);
        self::assertStringContainsString('<span class="button button-secondary is-disabled" aria-disabled="true">Zurueck</span>', $html);
    }

    public function testExportQueryCanStripPaginationButKeepFiltersAndSorting(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'filterQuery');
        $method->setAccessible(true);

        $query = (string) $method->invoke($controller, [
            'date_from' => '2026-04-01',
            'date_to' => null,
            'project_id' => '2',
            'user_id' => '',
            'entry_type' => 'work',
            'scope' => 'active',
            'issue' => 'all',
            'sort' => 'employee',
            'direction' => 'asc',
            'page' => 4,
            'per_page' => 100,
        ], ['page', 'per_page']);

        self::assertStringContainsString('date_from=2026-04-01', $query);
        self::assertStringContainsString('project_id=2', $query);
        self::assertStringContainsString('entry_type=work', $query);
        self::assertStringContainsString('issue=all', $query);
        self::assertStringContainsString('sort=employee', $query);
        self::assertStringContainsString('direction=asc', $query);
        self::assertStringNotContainsString('page=', $query);
        self::assertStringNotContainsString('per_page=', $query);
    }

    private function controller(): AdminBookingController
    {
        $connection = new DatabaseConnection([]);
        $bookingService = new AdminBookingService($connection, new TimesheetCalculator());
        $permissions = new PermissionMatrix([], []);

        return new AdminBookingController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            $bookingService,
            new BookingExportService($bookingService),
            new ProjectService($connection),
            new UserService($connection),
            new FileAttachmentService($connection, []),
            new DocumentStatusService($connection),
            new TimesheetGeoLocationService($connection),
            new AuthService($connection, $permissions),
            new CsrfService()
        );
    }
}
