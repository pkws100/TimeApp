<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Domain\Users\StorageUsageService;
use App\Http\Controllers\AdminController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;

final class AdminDashboardControllerTest extends TestCase
{
    public function testDashboardShowsTheSameAttendanceStatusChartAsTheAttendancePage(): void
    {
        $connection = new DatabaseConnection([]);
        $controller = new AdminController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            new DashboardService($connection, new StorageUsageService(storage_path()), new AttendanceService($connection)),
            new DatabaseSettingsManager([], sys_get_temp_dir() . '/timeapp-dashboard-settings-' . uniqid('', true) . '.php')
        );

        ob_start();
        $controller->dashboard(new Request('GET', '/admin', [], [], [], [], []))->send();
        $html = ob_get_clean() ?: '';

        self::assertStringContainsString('Belegschaft heute', $html);
        self::assertStringContainsString('id="attendanceStatusChart"', $html);
        self::assertStringContainsString('class="attendance-status-chart"', $html);
        self::assertStringContainsString('id="attendanceStatusChartDescription"', $html);
        self::assertStringContainsString('Noch da: <strong>0</strong>', $html);
        self::assertStringContainsString('Einsatzbereitschaft', $html);
        self::assertStringContainsString('Verhindert', $html);
        self::assertStringContainsString('class="status-card__actions"><a class="button button-secondary" href="/admin/attendance"', $html);
        self::assertStringContainsString('/admin/attendance', $html);
        self::assertStringContainsString('/assets/vendor/chart.umd.js', $html);
        self::assertStringContainsString('/assets/js/admin-attendance.js', $html);
        self::assertStringNotContainsString('id="headcountChart"', $html);
    }
}
