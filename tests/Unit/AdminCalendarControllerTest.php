<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Files\DocumentStatusService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetGeoLocationService;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Http\Controllers\AdminCalendarController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PDO;
use PDOStatement;
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
        self::assertStringContainsString('data-calendar-day-panel aria-live="polite" aria-busy="false"', $html);
        self::assertStringContainsString('Buchung hinzufuegen', $html);
        self::assertStringContainsString('Abwesenheit nacherfassen', $html);
        self::assertStringContainsString('/admin/bookings', $html);
    }

    public function testAjaxEndpointsReturnNormalizedCalendarPayloads(): void
    {
        $_SESSION = [];
        $controller = $this->controller(permissions: ['timesheets.view']);

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

    public function testAjaxDayPayloadContainsMissingUsersNotice(): void
    {
        $_SESSION = [];
        $controller = $this->controller([
            [
                'id' => 71,
                'created_at' => '2026-01-01 00:00:00',
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'employee_number' => 'MA-071',
                'email' => 'max@example.test',
                'time_tracking_required' => 1,
            ],
        ], ['timesheets.view']);

        $response = $controller->day(new Request('GET', '/admin/calendar/day', ['date' => '2026-05-15'], [], [], [], []));
        ob_start();
        $response->send();
        $payload = ob_get_clean() ?: '';

        self::assertStringContainsString('calendar-missing-users', $payload);
        self::assertStringContainsString('Max Mustermann', $payload);
        self::assertStringContainsString('MA-071', $payload);
    }

    public function testPersonalViewDoesNotContainBookingDataAndRejectsUnavailableViews(): void
    {
        $_SESSION = [];
        $controller = $this->controller(permissions: ['personnel.view']);
        $view = $controller->day(new Request('GET', '/admin/calendar/day', ['view' => 'personnel'], [], [], [], []));
        $denied = $controller->month(new Request('GET', '/admin/calendar/month', ['view' => 'bookings'], [], [], [], []));
        ob_start();
        $view->send();
        $body = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR)['data'];

        self::assertSame(200, $view->status());
        self::assertSame(403, $denied->status());
        self::assertArrayNotHasKey('active_booking_count', $body['summary']);
        self::assertStringNotContainsString('Buchungen am Tag', $body['html']);
        self::assertStringContainsString('Personal-Events', $body['html']);
    }

    public function testBookingViewKeepsMetricsAndRejectsProtectedPersonalView(): void
    {
        $_SESSION = [];
        $controller = $this->controller(permissions: ['timesheets.view']);
        $view = $controller->day(new Request('GET', '/admin/calendar/day', ['view' => 'bookings'], [], [], [], []));
        $denied = $controller->month(new Request('GET', '/admin/calendar/month', ['view' => 'personnel'], [], [], [], []));
        ob_start();
        $view->send();
        $body = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR)['data'];

        self::assertSame(200, $view->status());
        self::assertSame(403, $denied->status());
        self::assertSame(0, $body['summary']['active_booking_count']);
        self::assertStringNotContainsString('Personal-Events', $body['html']);
    }

    public function testDayPanelHidesArchivedBookingsFromCalendarBookingCard(): void
    {
        $_SESSION = [];
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderDayPanel');
        $method->setAccessible(true);

        $html = (string) $method->invoke(
            $controller,
            [
                'date' => '2026-05-15',
                'label' => 'Freitag, 15.05.2026',
                'summary' => [
                    'status' => 'ok',
                    'status_label' => 'Sauber',
                    'active_booking_count' => 1,
                    'employee_count' => 1,
                    'net_minutes' => 480,
                ],
                'bookings' => [
                    $this->bookingFixture(41, 'Aktiv Person', 'Aktives Projekt', 0),
                    $this->bookingFixture(42, 'Archiv Person', 'Archiv Projekt', 1),
                ],
                'assets' => [],
            ],
            [],
            [],
            '/admin/calendar?month=2026-05&date=2026-05-15',
            'csrf-token',
            false,
            true
        );

        self::assertStringContainsString('calendar-bookings-card', $html);
        self::assertStringContainsString('Aktiv Person', $html);
        self::assertStringContainsString('Aktives Projekt', $html);
        self::assertStringNotContainsString('Archiv Person', $html);
        self::assertStringNotContainsString('Archiv Projekt', $html);
    }

    public function testDayPanelShowsActiveEmptyMessageWhenOnlyArchivedBookingsExist(): void
    {
        $_SESSION = [];
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderDayPanel');
        $method->setAccessible(true);

        $html = (string) $method->invoke(
            $controller,
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
                'bookings' => [
                    $this->bookingFixture(42, 'Archiv Person', 'Archiv Projekt', 1),
                ],
                'assets' => [],
            ],
            [],
            [],
            '/admin/calendar?month=2026-05&date=2026-05-15',
            'csrf-token',
            false,
            true
        );

        self::assertStringContainsString('An diesem Tag sind keine aktiven Buchungen vorhanden.', $html);
        self::assertStringNotContainsString('Archiv Person', $html);
        self::assertStringNotContainsString('Archiv Projekt', $html);
    }

    public function testDayPanelShowsMissingUserNames(): void
    {
        $_SESSION = [];
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderDayPanel');
        $method->setAccessible(true);

        $html = (string) $method->invoke(
            $controller,
            [
                'date' => '2026-05-15',
                'label' => 'Freitag, 15.05.2026',
                'summary' => [
                    'status' => 'missing',
                    'status_label' => 'Fehlend',
                    'active_booking_count' => 0,
                    'employee_count' => 0,
                    'net_minutes' => 0,
                    'missing_count' => 2,
                    'missing_users' => [
                        [
                            'user_id' => 7,
                            'user_name' => 'Max Mustermann',
                            'employee_number' => 'MA-007',
                            'email' => 'max@example.test',
                        ],
                        [
                            'user_id' => 8,
                            'user_name' => 'Erika Beispiel',
                            'employee_number' => '',
                            'email' => '',
                        ],
                    ],
                ],
                'bookings' => [],
                'assets' => [],
            ],
            [],
            [],
            '/admin/calendar?month=2026-05&date=2026-05-15',
            'csrf-token',
            false,
            true
        );

        self::assertStringContainsString('calendar-missing-users', $html);
        self::assertStringContainsString('Buchungen fehlen bei', $html);
        self::assertStringContainsString('Max Mustermann', $html);
        self::assertStringContainsString('MA-007', $html);
        self::assertStringContainsString('max@example.test', $html);
        self::assertStringContainsString('Erika Beispiel', $html);
    }

    public function testDayPanelOmitsMissingUsersNoticeWhenNoUsersAreMissing(): void
    {
        $_SESSION = [];
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderDayPanel');
        $method->setAccessible(true);

        $html = (string) $method->invoke(
            $controller,
            [
                'date' => '2026-05-15',
                'label' => 'Freitag, 15.05.2026',
                'summary' => [
                    'status' => 'ok',
                    'status_label' => 'Sauber',
                    'active_booking_count' => 1,
                    'employee_count' => 1,
                    'net_minutes' => 480,
                    'missing_count' => 0,
                    'missing_users' => [],
                ],
                'bookings' => [
                    $this->bookingFixture(41, 'Aktiv Person', 'Aktives Projekt', 0),
                ],
                'assets' => [],
            ],
            [],
            [],
            '/admin/calendar?month=2026-05&date=2026-05-15',
            'csrf-token',
            false,
            true
        );

        self::assertStringNotContainsString('calendar-missing-users', $html);
        self::assertStringNotContainsString('Buchung fehlt bei', $html);
    }

    private function bookingFixture(int $id, string $employeeName, string $projectName, int $isDeleted): array
    {
        return [
            'id' => $id,
            'work_date' => '2026-05-15',
            'user_id' => $id,
            'employee_name' => $employeeName,
            'employee_number' => 'M-' . $id,
            'project_id' => 2,
            'project_number' => 'P-2',
            'project_name' => $projectName,
            'project_is_deleted' => 0,
            'entry_type' => 'work',
            'source' => 'admin',
            'source_label' => 'Admin-Nacherfassung',
            'start_time' => '07:30:00',
            'end_time' => '16:00:00',
            'break_minutes' => 30,
            'net_minutes' => 480,
            'note' => '',
            'is_deleted' => $isDeleted,
            'version_hint' => 'v1',
        ];
    }

    private function controller(array $activeUserRows = [], array $permissions = []): AdminCalendarController
    {
        $connection = new DatabaseConnection([]);
        $bookingService = new AdminBookingService($connection, new TimesheetCalculator());
        $calendarService = new AdminCalendarService($connection, $bookingService);

        if ($activeUserRows !== []) {
            $property = new \ReflectionProperty($calendarService, 'activeUsers');
            $property->setAccessible(true);
            $property->setValue($calendarService, $activeUserRows);
        }

        return new AdminCalendarController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            $calendarService,
            $bookingService,
            new ProjectService($connection),
            new UserService($connection),
            new FileAttachmentService($connection, []),
            new DocumentStatusService($connection),
            new TimesheetGeoLocationService($connection),
            $this->auth($permissions),
            new CsrfService()
        );
    }

    private function auth(array $permissions): AuthService
    {
        $connection = new DatabaseConnection([]);
        if ($permissions !== []) {
            $_SESSION['auth']['user_id'] = 1;
            $pdo = $this->createStub(PDO::class);
            $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($permissions): PDOStatement {
                $statement = $this->createStub(PDOStatement::class);
                $statement->method('execute')->willReturn(true);
                $statement->method('fetchColumn')->willReturn(1);
                $statement->method('fetchAll')->willReturn(match (true) {
                    str_contains($sql, 'SELECT DISTINCT permissions.code') => array_map(static fn (string $code): array => ['code' => $code], $permissions),
                    str_contains($sql, 'SELECT roles.slug') => [['slug' => 'calendar_test']],
                    str_contains($sql, 'FROM users') => [['id' => 1, 'is_deleted' => 0, 'employment_status' => 'active']],
                    default => [],
                });

                return $statement;
            });
            (new \ReflectionProperty(DatabaseConnection::class, 'pdo'))->setValue($connection, $pdo);
        }

        return new AuthService($connection, new PermissionMatrix([], []));
    }
}
