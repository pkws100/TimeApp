<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\AttendanceReportService;
use App\Domain\Settings\CompanySettingsService;
use App\Http\Controllers\AttendanceController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;

final class AttendanceControllerTest extends TestCase
{
    public function testAttendancePageClearlySeparatesCurrentAndCompletedWork(): void
    {
        $connection = new DatabaseConnection([]);
        $controller = new AttendanceController(
            new AttendanceService($connection),
            new AttendanceReportService(new AttendanceService($connection), new CompanySettingsService($connection, []), ['temp_path' => sys_get_temp_dir()]),
            new AdminView('Baustellen Zeiterfassung', 'http://localhost')
        );

        ob_start();
        $controller->index(new Request('GET', '/admin/attendance', [], [], [], [], []))->send();
        $html = ob_get_clean() ?: '';

        self::assertStringContainsString('Noch anwesend', $html);
        self::assertStringContainsString('href="/admin/attendance/report.pdf"', $html);
        self::assertStringContainsString('Heute abgeschlossen', $html);
        self::assertStringContainsString('Heute gegangen', $html);
        self::assertStringContainsString('Letzter Standort', $html);
        self::assertStringContainsString('Letzter Hinweis', $html);
        self::assertStringContainsString('id="attendanceStatusChart"', $html);
        self::assertStringContainsString('class="attendance-status-chart"', $html);
        self::assertStringContainsString('aria-describedby="attendanceStatusChartDescription"', $html);
        self::assertStringContainsString('id="attendanceStatusChartDescription"', $html);
        self::assertStringContainsString('Noch da: <strong>0</strong>', $html);
        self::assertStringContainsString('Ohne Tagesstatus: <strong>0</strong>', $html);
        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString('Einsatzbereitschaft', $html);
        self::assertStringContainsString('Das Kreisdiagramm konnte nicht dargestellt werden.', (string) file_get_contents(base_path('public/assets/js/admin-attendance.js')));
        self::assertStringContainsString('Chart.js konnte nicht lokal geladen werden.', (string) file_get_contents(base_path('public/assets/js/admin-attendance.js')));
        self::assertStringContainsString('/assets/vendor/chart.umd.js', $html);
        self::assertStringContainsString('8:15 Std.', $html);
        self::assertStringNotContainsString('Anwesend heute', $html);
    }

    public function testAttendanceReportEndpointStreamsThePdfStatusReport(): void
    {
        $connection = new DatabaseConnection([]);
        $controller = new AttendanceController(
            new AttendanceService($connection),
            new AttendanceReportService(new AttendanceService($connection), new CompanySettingsService($connection, []), ['temp_path' => sys_get_temp_dir()]),
            new AdminView('Baustellen Zeiterfassung', 'http://localhost')
        );

        $response = $controller->report(new Request('GET', '/admin/attendance/report.pdf', [], [], [], [], []));
        ob_start();
        $response->send();
        $pdf = ob_get_clean() ?: '';

        self::assertSame(200, $response->status());
        self::assertSame('application/pdf', $response->headers()['Content-Type']);
        self::assertSame('private, no-store, max-age=0', $response->headers()['Cache-Control']);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('anwesenheits-statusbericht-', $response->headers()['Content-Disposition']);
    }

    public function testAttendanceReportEndpointHandlesUnexpectedGenerationFailuresWithoutLeakingDetails(): void
    {
        $connection = new DatabaseConnection([]);
        $controller = new AttendanceController(
            new AttendanceService($connection),
            new AttendanceReportService(new AttendanceService($connection), new CompanySettingsService($connection, []), ['temp_path' => sys_get_temp_dir()], 'invalid/timezone'),
            new AdminView('Baustellen Zeiterfassung', 'http://localhost')
        );

        $response = $controller->report(new Request('GET', '/admin/attendance/report.pdf', [], [], [], [], []));

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/admin/attendance?error=', $response->headers()['Location']);
        self::assertStringNotContainsString('DateTimeZone', $response->headers()['Location']);
    }
}
