<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Http\Controllers\AdminCalendarController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminCalendarControllerTest extends TestCase
{
    public function testCalendarPageContainsShellAndInitialDayPanel(): void
    {
        $_SESSION = [];
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderPage');
        $method->setAccessible(true);

        $html = (string) $method->invoke(
            $controller,
            [
                'month' => '2026-05',
                'label' => 'Mai 2026',
                'previous_month' => '2026-04',
                'next_month' => '2026-06',
                'today' => '2026-05-15',
                'days' => [[
                    'date' => '2026-05-15',
                    'status' => 'empty',
                    'status_label' => 'Keine Buchung',
                    'is_current_month' => true,
                    'weekday' => 5,
                    'day_number' => 15,
                    'active_booking_count' => 0,
                    'net_minutes' => 0,
                    'issue_count' => 0,
                ]],
                'totals' => [],
            ],
            [
                'date' => '2026-05-15',
                'label' => 'Freitag, 15.05.2026',
                'summary' => [
                    'status' => 'empty',
                    'status_label' => 'Keine Buchung',
                    'active_booking_count' => 0,
                    'employee_count' => 0,
                    'net_minutes' => 0,
                ],
                'bookings' => [],
                'assets' => [],
            ],
            [],
            [],
            '',
            '/admin/calendar?month=2026-05&date=2026-05-15',
            'csrf-token',
            true,
            true
        );

        self::assertStringContainsString('data-admin-calendar', $html);
        self::assertStringContainsString('data-calendar-grid', $html);
        self::assertStringContainsString('Buchung hinzufuegen', $html);
        self::assertStringContainsString('/admin/bookings', $html);
    }

    public function testAjaxEndpointsReturnNormalizedCalendarPayloads(): void
    {
        $_SESSION = [];
        $controller = $this->controller();

        $monthResponse = $controller->month(new Request('GET', '/admin/calendar/month', ['month' => 'invalid'], [], [], [], []));
        ob_start();
        $monthResponse->send();
        $monthPayload = ob_get_clean() ?: '';

        self::assertStringContainsString('"days"', $monthPayload);
        self::assertStringContainsString('"month"', $monthPayload);

        $dayResponse = $controller->day(new Request('GET', '/admin/calendar/day', ['date' => '2026-05-15'], [], [], [], []));
        ob_start();
        $dayResponse->send();
        $dayPayload = ob_get_clean() ?: '';

        self::assertStringContainsString('"date": "2026-05-15"', $dayPayload);
        self::assertStringContainsString('"html"', $dayPayload);
    }

    private function controller(): AdminCalendarController
    {
        $connection = new DatabaseConnection([]);
        $bookingService = new AdminBookingService($connection, new TimesheetCalculator());

        return new AdminCalendarController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            new AdminCalendarService($connection, $bookingService),
            $bookingService,
            new ProjectService($connection),
            new UserService($connection),
            new FileAttachmentService($connection, []),
            new AuthService($connection, new PermissionMatrix([], [])),
            new CsrfService()
        );
    }
}
