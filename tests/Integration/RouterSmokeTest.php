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

    public function testFaviconRouteRedirectsToSharedAppIcon(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/favicon.ico';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $html = ob_get_clean() ?: '';

        self::assertSame('', $html);
    }

    public function testHeadRequestsReachGetRoutesWithoutBody(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $_SERVER['REQUEST_URI'] = '/admin/login';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        $response = $router->dispatch($request);

        self::assertSame(200, $response->status());

        ob_start();
        $response->send($request->method() === 'HEAD');
        $body = ob_get_clean() ?: '';

        self::assertSame('', $body);
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
        self::assertTrue(
            str_contains($attendancePayload, '"derived_missing_count"') || str_contains($attendancePayload, '"Nicht authentifiziert."'),
            'Attendance-Route sollte den abgeleiteten Fehlend-Zaehler liefern.'
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/attendance';
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $router->dispatch($request)->send();
        $attendanceHtml = ob_get_clean() ?: '';

        self::assertTrue(
            $attendanceHtml === ''
            || (str_contains($attendanceHtml, 'Krank') && str_contains($attendanceHtml, 'Urlaub') && str_contains($attendanceHtml, 'Feiertag') && str_contains($attendanceHtml, 'Fehlt'))
            || str_contains($attendanceHtml, 'Keine Berechtigung')
            || str_contains($attendanceHtml, '/admin/login?next=%2Fadmin%2Fattendance'),
            'Die Admin-Anwesenheitsseite sollte getrennte Abwesenheitsstatus rendern.'
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
        $attachmentResponse = $router->dispatch($request);
        $attachmentResponse->send();
        $attachmentPayload = ob_get_clean() ?: '';
        $attachmentJson = json_decode($attachmentPayload, true);

        self::assertSame(401, $attachmentResponse->status());
        self::assertIsArray($attachmentJson);
        self::assertSame(false, $attachmentJson['ok'] ?? null);
        self::assertSame('auth_required', $attachmentJson['code'] ?? null);
        self::assertSame('Bitte erneut anmelden.', $attachmentJson['message'] ?? null);

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

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/v1/app/push/test';
        $_GET = [];
        $_POST = [];

        [$request, $router] = require base_path('bootstrap/app.php');

        ob_start();
        $pushTestResponse = $router->dispatch($request);
        $pushTestResponse->send();
        $pushTestPayload = ob_get_clean() ?: '';
        $pushTestJson = json_decode($pushTestPayload, true);

        self::assertSame(401, $pushTestResponse->status());
        self::assertSame(false, $pushTestJson['ok'] ?? null);
        self::assertSame('auth_required', $pushTestJson['code'] ?? null);
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
            || (str_contains($monthPayload, '"days"') && str_contains($monthPayload, '"missing_count"') && str_contains($monthPayload, '"absence_count"')),
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
            || (str_contains($dayPayload, '"html"') && str_contains($dayPayload, 'Fehlt') && str_contains($dayPayload, 'Abwesend')),
            'Die Admin-Kalender-Tagesroute sollte erreichbar sein.'
        );
    }

    public function testAdminSettingsPdfRoutesAreProtected(): void
    {
        foreach ([
            '/admin/settings/company/agb-pdf/preview',
            '/admin/settings/company/agb-pdf/download',
            '/admin/settings/company/datenschutz-pdf/preview',
            '/admin/settings/company/datenschutz-pdf/download',
        ] as $uri) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = $uri;
            $_GET = [];
            $_POST = [];
            $_FILES = [];

            [$request, $router] = require base_path('bootstrap/app.php');

            $response = $router->dispatch($request);
            $headers = $response->headers();

            self::assertSame(302, $response->status(), 'Die Settings-PDF-Route sollte zum Login umleiten: ' . $uri);
            self::assertSame('/admin/login?next=' . rawurlencode($uri), $headers['Location'] ?? null);
        }
    }
}
