<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\ConfigRepository;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RouterSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $sessionPath = sys_get_temp_dir() . '/baustelle-test-sessions';

            if (!is_dir($sessionPath)) {
                mkdir($sessionPath, 0775, true);
            }

            session_save_path($sessionPath);
            session_start();
        }

        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
    }

    public function testConfigurationFilesLoad(): void
    {
        $config = ConfigRepository::load(['app', 'database', 'permissions', 'uploads', 'exports']);

        self::assertSame('Baustellen Zeiterfassung', $config->get('app.name'));
        self::assertSame('mysql', $config->get('database.default'));
    }

    public function testBootstrapExposesAttendanceAndChartRoutes(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/attendance/today';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        self::assertInstanceOf(Request::class, $request);

        ob_start();
        $router->dispatch($request)->send();
        $attendancePayload = ob_get_clean() ?: '';

        self::assertTrue(
            str_contains($attendancePayload, '"present_count"') || str_contains($attendancePayload, '"Nicht authentifiziert."'),
            'Attendance-Route sollte erreichbar sein und entweder Daten oder einen Auth-Fehler liefern.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/dashboard/charts?period=week';
        $_GET = ['period' => 'week'];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $chartPayload = ob_get_clean() ?: '';

        self::assertTrue(
            (str_contains($chartPayload, '"period": "week"') && str_contains($chartPayload, '"headcount"'))
            || str_contains($chartPayload, '"Nicht authentifiziert."'),
            'Chart-Route sollte erreichbar sein und entweder Datensaetze oder einen Auth-Fehler liefern.'
        );
    }

    public function testAppShellAndSessionRoutesAreAvailable(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/app';

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $shell = ob_get_clean() ?: '';

        self::assertStringContainsString('window.__APP_BOOTSTRAP__', $shell);
        self::assertStringContainsString('/assets/js/app.js', $shell);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/app/historie';

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $historyShell = ob_get_clean() ?: '';

        self::assertStringContainsString('window.__APP_BOOTSTRAP__', $historyShell);
        self::assertStringContainsString('/assets/js/app.js', $historyShell);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/auth/session';

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $payload = ob_get_clean() ?: '';

        self::assertStringContainsString('"authenticated"', $payload);
        self::assertStringContainsString('"bootstrap_required"', $payload);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/app/timesheets/1/files';

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $attachmentPayload = ob_get_clean() ?: '';

        self::assertTrue(
            str_contains($attachmentPayload, '"Nicht authentifiziert."') || str_contains($attachmentPayload, '"data"'),
            'Timesheet-Datei-Route sollte erreichbar sein und entweder Daten oder einen Auth-Fehler liefern.'
        );
        self::assertTrue(
            str_contains($attachmentPayload, '"message"') || str_contains($attachmentPayload, '"data"'),
            'Timesheet-Datei-Route sollte strukturierte JSON-Meldungen fuer das Frontend liefern.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/app/projects/1/files';
        $_GET = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $projectFilesPayload = ob_get_clean() ?: '';

        self::assertTrue(
            str_contains($projectFilesPayload, '"Nicht authentifiziert."') || str_contains($projectFilesPayload, '"data"'),
            'App-Projektdatei-Route sollte erreichbar sein und entweder Daten oder einen Auth-Fehler liefern.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/app/timesheet-files/1/download';
        $_GET = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $timesheetDownloadPayload = ob_get_clean() ?: '';

        self::assertTrue(
            str_contains($timesheetDownloadPayload, '"Nicht authentifiziert."') || str_contains($timesheetDownloadPayload, 'Datei nicht gefunden.'),
            'Timesheet-Dateidownload sollte geschuetzt erreichbar sein.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/app/me/timesheets?scope=all';
        $_GET = ['scope' => 'all'];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $timesheetListPayload = ob_get_clean() ?: '';

        self::assertTrue(
            str_contains($timesheetListPayload, '"Nicht authentifiziert."')
            || (str_contains($timesheetListPayload, '"items"') && str_contains($timesheetListPayload, '"scope"')),
            'Die App-Zeitlisten-Route sollte erreichbar sein und entweder Daten oder einen Auth-Fehler liefern.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/app/push/status';
        $_GET = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $pushPayload = ob_get_clean() ?: '';

        self::assertTrue(
            str_contains($pushPayload, '"Nicht authentifiziert."')
            || str_contains($pushPayload, '"can_subscribe"'),
            'Die App-Push-Statusroute sollte erreichbar sein und entweder Daten oder einen Auth-Fehler liefern.'
        );
    }

    public function testAdminBookingsRoutesAreAvailable(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/bookings';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $html = ob_get_clean() ?: '';

        self::assertTrue(
            $html === ''
            || str_contains($html, 'Buchungen')
            || str_contains($html, 'Keine Berechtigung')
            || str_contains($html, '/admin/login?next=%2Fadmin%2Fbookings'),
            'Die Admin-Buchungsseite sollte erreichbar sein und auf Login, Inhalt oder 403 aufloesen.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/timesheet-files/1/download';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $downloadPayload = ob_get_clean() ?: '';

        self::assertTrue(
            $downloadPayload === ''
            || str_contains($downloadPayload, 'Datei nicht gefunden.')
            || str_contains($downloadPayload, 'Keine Berechtigung')
            || str_contains($downloadPayload, '/admin/login?next=%2Fadmin%2Ftimesheet-files%2F1%2Fdownload'),
            'Der Admin-Buchungsdateidownload sollte geschuetzt erreichbar sein.'
        );

        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['REQUEST_URI'] = '/admin/timesheet-files/1';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $archivePayload = ob_get_clean() ?: '';

        self::assertTrue(
            $archivePayload === ''
            || str_contains($archivePayload, 'Keine Berechtigung')
            || str_contains($archivePayload, '/admin/login?next=%2Fadmin%2Ftimesheet-files%2F1'),
            'Die Admin-Buchungsdateiarchivierung sollte geschuetzt erreichbar sein.'
        );
    }

    public function testAdminPushRouteIsAvailable(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/settings/push';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $html = ob_get_clean() ?: '';

        self::assertTrue(
            $html === ''
            || str_contains($html, 'Browser-Push')
            || str_contains($html, 'Keine Berechtigung')
            || str_contains($html, '/admin/login?next=%2Fadmin%2Fsettings%2Fpush'),
            'Die Admin-Push-Seite sollte erreichbar sein und auf Login, Inhalt oder 403 aufloesen.'
        );
    }

    public function testAdminCalendarRoutesAreAvailable(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/calendar';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $html = ob_get_clean() ?: '';

        self::assertTrue(
            $html === ''
            || str_contains($html, 'Kalender')
            || str_contains($html, 'Keine Berechtigung')
            || str_contains($html, '/admin/login?next=%2Fadmin%2Fcalendar'),
            'Die Admin-Kalenderseite sollte erreichbar sein und auf Login, Inhalt oder 403 aufloesen.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/calendar/month?month=2026-05';
        $_GET = ['month' => '2026-05'];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $monthPayload = ob_get_clean() ?: '';

        self::assertTrue(
            $monthPayload === ''
            || str_contains($monthPayload, '"Nicht authentifiziert."')
            || str_contains($monthPayload, '"days"'),
            'Die Admin-Kalender-Monatsroute sollte erreichbar sein.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/calendar/day?date=2026-05-15';
        $_GET = ['date' => '2026-05-15'];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $dayPayload = ob_get_clean() ?: '';

        self::assertTrue(
            $dayPayload === ''
            || str_contains($dayPayload, '"Nicht authentifiziert."')
            || str_contains($dayPayload, '"html"'),
            'Die Admin-Kalender-Tagesroute sollte erreichbar sein.'
        );
    }
}
