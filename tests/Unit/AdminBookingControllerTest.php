<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Exports\BookingExportService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
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
            ],
            [],
            [],
            [],
            '',
            '/admin/bookings',
            'csrf-token'
        );

        self::assertStringContainsString('Offene Projektzuordnungen', $html);
        self::assertStringContainsString('/admin/bookings?scope=active&amp;project_id=__none__&amp;entry_type=work', $html);
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
            new AuthService($connection, $permissions),
            new CsrfService()
        );
    }
}
