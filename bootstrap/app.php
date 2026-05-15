<?php

declare(strict_types=1);

use App\Config\ConfigRepository;
use App\Config\EnvironmentLoader;
use App\Domain\Admin\AdminContextService;
use App\Domain\App\MobileAppService;
use App\Domain\Assets\AssetService;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Auth\RouteGuard;
use App\Domain\Backup\BackupService;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Exports\AccountingExportService;
use App\Domain\Exports\BookingExportService;
use App\Domain\Exports\ReportService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Domain\Settings\SmtpTestService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\RoleService;
use App\Domain\Users\StorageUsageService;
use App\Domain\Users\UserService;
use App\Http\Controllers\AccountingExportController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\AdminCalendarController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminManagementController;
use App\Http\Controllers\AppApiController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AppTimesheetAttachmentController;
use App\Http\Controllers\AppTimesheetController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\UserController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use App\Presentation\App\AppView;

require_once __DIR__ . '/autoload.php';

(new EnvironmentLoader())->load(base_path('.env'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionCandidates = [];
    $projectSessionPath = storage_path('cache/sessions');
    $projectSessionParent = dirname($projectSessionPath);

    if (is_dir($projectSessionPath) && is_writable($projectSessionPath)) {
        $sessionCandidates[] = $projectSessionPath;
    } elseif (is_dir($projectSessionParent) && is_writable($projectSessionParent)) {
        if (@mkdir($projectSessionPath, 0775, true) || is_dir($projectSessionPath)) {
            $sessionCandidates[] = $projectSessionPath;
        } else {
            error_log('App bootstrap: Session-Verzeichnis im Projekt konnte nicht erstellt werden, verwende Fallback.');
        }
    }

    $configuredSessionPath = trim((string) ini_get('session.save_path'));

    if ($configuredSessionPath !== '') {
        $pathParts = array_values(array_filter(array_map('trim', explode(';', $configuredSessionPath))));
        $normalizedConfiguredPath = $pathParts === [] ? $configuredSessionPath : (string) end($pathParts);

        if (is_dir($normalizedConfiguredPath) && is_writable($normalizedConfiguredPath)) {
            $sessionCandidates[] = $normalizedConfiguredPath;
        }
    }

    $systemTempPath = sys_get_temp_dir();

    if ($systemTempPath !== '' && is_dir($systemTempPath) && is_writable($systemTempPath)) {
        $sessionCandidates[] = $systemTempPath;
    }

    $sessionCandidates = array_values(array_unique(array_filter($sessionCandidates, static fn (string $path): bool => $path !== '')));

    if ($sessionCandidates !== []) {
        session_save_path($sessionCandidates[0]);
    }

    session_name('baustelle_session');
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

$config = ConfigRepository::load(['app', 'database', 'permissions', 'uploads', 'exports', 'maps']);
date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Berlin'));

$databaseSettings = new DatabaseSettingsManager(
    $config->get('database.connections.mysql', []),
    (string) $config->get('database.override_file')
);
$connection = new DatabaseConnection($databaseSettings->current());
$permissionMatrix = new PermissionMatrix($config->get('permissions.roles', []), $config->get('permissions.available', []));
$storageUsage = new StorageUsageService(storage_path());

$attendanceService = new AttendanceService($connection);
$userService = new UserService($connection);
$roleService = new RoleService($connection, $permissionMatrix);
$projectService = new ProjectService($connection);
$assetService = new AssetService($connection);
$timesheetCalculator = new TimesheetCalculator();
$workdayStateCalculator = new WorkdayStateCalculator();
$timesheetService = new TimesheetService($connection, $timesheetCalculator);
$adminBookingService = new AdminBookingService($connection, $timesheetCalculator);
$adminCalendarService = new AdminCalendarService($connection, $adminBookingService);
$companySettingsService = new CompanySettingsService($connection, $config->get('uploads', []));
$authService = new AuthService($connection, $permissionMatrix);
$csrfService = new CsrfService();
$routeGuard = new RouteGuard($authService);
$dashboardService = new DashboardService($connection, $storageUsage, $attendanceService);
$fileService = new FileAttachmentService($connection, $config->get('uploads', []));
$reportService = new ReportService($connection, $config->get('exports', []), $timesheetService);
$bookingExportService = new BookingExportService($adminBookingService);
$accountingExportService = new AccountingExportService($adminBookingService);
$backupService = new BackupService($connection, $config->get('uploads', []));
$smtpTestService = new SmtpTestService();
$mobileAppService = new MobileAppService($connection, $projectService, $companySettingsService, $workdayStateCalculator, $fileService);
$appTimesheetSyncService = new AppTimesheetSyncService($connection, $timesheetCalculator, $companySettingsService, $workdayStateCalculator);
$appDisplayName = trim((string) ($companySettingsService->current()['app_display_name'] ?? '')) ?: (string) $config->get('app.name');

$adminContextService = new AdminContextService(
    $companySettingsService,
    $attendanceService,
    $projectService,
    $userService,
    $roleService,
    $assetService,
    $adminBookingService,
    $authService,
    (string) $config->get('app.name')
);
$adminView = new AdminView(
    (string) $config->get('app.name'),
    (string) $config->get('app.url'),
    static fn (): array => $adminContextService->context()
);
$appView = new AppView($appDisplayName);

$authController = new AuthController($authService);
$adminAuthController = new AdminAuthController($authService, $companySettingsService);
$appController = new AppController($appView, $authService, $appDisplayName, $companySettingsService);
$appApiController = new AppApiController($mobileAppService, $authService);
$appTimesheetController = new AppTimesheetController($appTimesheetSyncService, $authService);
$appTimesheetAttachmentController = new AppTimesheetAttachmentController($fileService, $authService);
$adminController = new AdminController($adminView, $dashboardService, $databaseSettings);
$adminManagementController = new AdminManagementController(
    $adminView,
    $projectService,
    $userService,
    $roleService,
    $assetService,
    $fileService,
    $adminBookingService,
    $authService,
    $csrfService
);
$adminBookingController = new AdminBookingController(
    $adminView,
    $adminBookingService,
    $bookingExportService,
    $projectService,
    $userService,
    $authService,
    $csrfService
);
$adminCalendarController = new AdminCalendarController(
    $adminView,
    $adminCalendarService,
    $adminBookingService,
    $projectService,
    $userService,
    $authService,
    $csrfService
);
$companySettingsController = new CompanySettingsController($adminView, $companySettingsService, $smtpTestService, $csrfService, $config->get('maps', []));
$attendanceController = new AttendanceController($attendanceService, $adminView);
$accountingExportController = new AccountingExportController($accountingExportService);
$backupController = new BackupController($backupService);

$admin = static fn (callable $handler, ?string $permission = 'dashboard.view'): callable => $routeGuard->forAdmin($handler, $permission);
$api = static fn (callable $handler, ?string $permission = null): callable => $routeGuard->forApi($handler, $permission);

$router = new Router();
$router->get('/', static function () use ($authService): Response {
    $user = $authService->currentUser();

    if ($user !== null && $authService->hasPermission('dashboard.view')) {
        return Response::redirect('/admin');
    }

    return Response::redirect('/app');
});

$router->get('/admin/login', [$adminAuthController, 'show']);
$router->post('/admin/login', [$adminAuthController, 'login']);
$router->post('/admin/logout', [$adminAuthController, 'logout']);

$router->get('/app/manifest.json', [$appController, 'manifest']);
$router->get('/app/sw.js', [$appController, 'serviceWorker']);
$router->get('/app', [$appController, 'shell']);
$router->get('/app/login', [$appController, 'shell']);
$router->get('/app/heute', [$appController, 'shell']);
$router->get('/app/zeiten', [$appController, 'shell']);
$router->get('/app/historie', [$appController, 'shell']);
$router->get('/app/projektwahl', [$appController, 'shell']);
$router->get('/app/profil', [$appController, 'shell']);

$router->get('/admin', $admin([$adminController, 'dashboard'], 'dashboard.view'));
$router->get('/admin/calendar', $admin([$adminCalendarController, 'index'], 'timesheets.view'));
$router->get('/admin/calendar/month', $admin([$adminCalendarController, 'month'], 'timesheets.view'));
$router->get('/admin/calendar/day', $admin([$adminCalendarController, 'day'], 'timesheets.view'));
$router->get('/admin/attendance', $admin([$attendanceController, 'index'], 'attendance.view'));
$router->get('/admin/projects', $admin([$adminManagementController, 'projects'], 'projects.view'));
$router->get('/admin/bookings', $admin([$adminBookingController, 'index'], 'timesheets.view'));
$router->get('/admin/bookings/export', $admin([$adminBookingController, 'export'], 'timesheets.export'));
$router->post('/admin/bookings', $admin([$adminBookingController, 'create'], 'timesheets.manage'));
$router->post('/admin/bookings/bulk-assign', $admin([$adminBookingController, 'bulkAssign'], 'timesheets.manage'));
$router->put('/admin/bookings/{id}', $admin([$adminBookingController, 'update'], 'timesheets.manage'));
$router->delete('/admin/bookings/{id}/archive', $admin([$adminBookingController, 'archive'], 'timesheets.archive'));
$router->post('/admin/bookings/{id}/restore', $admin([$adminBookingController, 'restore'], 'timesheets.archive'));
$router->get('/admin/projects/create', $admin([$adminManagementController, 'projectCreate'], 'projects.manage'));
$router->get('/admin/projects/{id}/edit', $admin([$adminManagementController, 'projectEdit'], 'projects.manage'));
$router->post('/admin/projects', $admin([$adminManagementController, 'projectStore'], 'projects.manage'));
$router->put('/admin/projects/{id}', $admin([$adminManagementController, 'projectUpdate'], 'projects.manage'));
$router->delete('/admin/projects/{id}', $admin([$adminManagementController, 'projectArchive'], 'projects.manage'));
$router->post('/admin/projects/{id}/restore', $admin([$adminManagementController, 'projectRestore'], 'projects.manage'));
$router->post('/admin/projects/{id}/bookings', $admin([$adminManagementController, 'projectBookingStore'], 'timesheets.manage'));
$router->post('/admin/projects/{id}/files', $admin([$adminManagementController, 'projectFileStore'], 'files.upload'));
$router->delete('/admin/project-files/{id}', $admin([$adminManagementController, 'projectFileArchive'], 'files.manage'));
$router->get('/admin/users', $admin([$adminManagementController, 'users'], 'users.manage'));
$router->get('/admin/users/create', $admin([$adminManagementController, 'userCreate'], 'users.manage'));
$router->get('/admin/users/{id}/edit', $admin([$adminManagementController, 'userEdit'], 'users.manage'));
$router->post('/admin/users', $admin([$adminManagementController, 'userStore'], 'users.manage'));
$router->put('/admin/users/{id}', $admin([$adminManagementController, 'userUpdate'], 'users.manage'));
$router->delete('/admin/users/{id}', $admin([$adminManagementController, 'userArchive'], 'users.manage'));
$router->get('/admin/roles', $admin([$adminManagementController, 'roles'], 'roles.manage'));
$router->get('/admin/roles/create', $admin([$adminManagementController, 'roleCreate'], 'roles.manage'));
$router->get('/admin/roles/{id}/edit', $admin([$adminManagementController, 'roleEdit'], 'roles.manage'));
$router->post('/admin/roles', $admin([$adminManagementController, 'roleStore'], 'roles.manage'));
$router->put('/admin/roles/{id}', $admin([$adminManagementController, 'roleUpdate'], 'roles.manage'));
$router->delete('/admin/roles/{id}', $admin([$adminManagementController, 'roleArchive'], 'roles.manage'));
$router->get('/admin/assets', $admin([$adminManagementController, 'assets'], 'assets.manage'));
$router->get('/admin/assets/create', $admin([$adminManagementController, 'assetCreate'], 'assets.manage'));
$router->get('/admin/assets/{id}/edit', $admin([$adminManagementController, 'assetEdit'], 'assets.manage'));
$router->post('/admin/assets', $admin([$adminManagementController, 'assetStore'], 'assets.manage'));
$router->put('/admin/assets/{id}', $admin([$adminManagementController, 'assetUpdate'], 'assets.manage'));
$router->delete('/admin/assets/{id}', $admin([$adminManagementController, 'assetArchive'], 'assets.manage'));
$router->post('/admin/assets/{id}/files', $admin([$adminManagementController, 'assetFileStore'], 'assets.manage'));
$router->delete('/admin/asset-files/{id}', $admin([$adminManagementController, 'assetFileArchive'], 'assets.manage'));
$router->get('/admin/settings/company', $admin([$companySettingsController, 'show'], 'settings.manage'));
$router->post('/admin/settings/company', $admin([$companySettingsController, 'save'], 'settings.manage'));
$router->post('/admin/settings/company/logo', $admin([$companySettingsController, 'saveLogo'], 'settings.manage'));
$router->post('/admin/settings/company/agb-text', $admin([$companySettingsController, 'saveAgbText'], 'settings.manage'));
$router->post('/admin/settings/company/agb-pdf', $admin([$companySettingsController, 'saveAgbPdf'], 'settings.manage'));
$router->post('/admin/settings/company/datenschutz-text', $admin([$companySettingsController, 'saveDatenschutzText'], 'settings.manage'));
$router->post('/admin/settings/company/datenschutz-pdf', $admin([$companySettingsController, 'saveDatenschutzPdf'], 'settings.manage'));
$router->post('/admin/settings/company/smtp', $admin([$companySettingsController, 'saveSmtp'], 'settings.manage'));
$router->post('/admin/settings/company/geo', $admin([$companySettingsController, 'saveGeo'], 'settings.manage'));
$router->post('/admin/settings/company/smtp-test', $admin([$companySettingsController, 'smtpTest'], 'settings.manage'));
$router->get('/admin/settings/database', $admin([$adminController, 'databaseSettings'], 'settings.database.manage'));
$router->post('/admin/settings/database', $admin([new SettingsController($databaseSettings), 'saveFromAdmin'], 'settings.database.manage'));

$router->post('/api/v1/auth/login', [$authController, 'login']);
$router->post('/api/v1/auth/logout', [$authController, 'logout']);
$router->get('/api/v1/auth/session', [$authController, 'session']);

$router->get('/api/v1/app/me/day', $api([$appApiController, 'meDay'], 'timesheets.view_own'));
$router->get('/api/v1/app/me/timesheets', $api([$appApiController, 'meTimesheets'], 'timesheets.view_own'));
$router->post('/api/v1/app/timesheets/sync', $api([$appTimesheetController, 'sync'], 'timesheets.create'));
$router->get('/api/v1/app/timesheets/{id}/files', $api([$appTimesheetAttachmentController, 'index'], 'timesheets.view_own'));
$router->post('/api/v1/app/timesheets/{id}/files', $api([$appTimesheetAttachmentController, 'upload'], 'timesheets.create'));
$router->delete('/api/v1/app/timesheet-files/{id}', $api([$appTimesheetAttachmentController, 'archive'], 'timesheets.create'));

$router->get('/api/v1/attendance/today', $api([$attendanceController, 'today'], 'attendance.view'));
$router->get('/api/v1/dashboard/overview', $api([new DashboardController($dashboardService), 'overview'], 'dashboard.view'));
$router->get('/api/v1/dashboard/charts', $api([new DashboardController($dashboardService), 'charts'], 'dashboard.view'));
$router->get('/api/v1/users/{id}', $api([new UserController($userService), 'show'], 'users.manage'));
$router->post('/api/v1/users', $api([new UserController($userService), 'store'], 'users.manage'));
$router->put('/api/v1/users/{id}', $api([new UserController($userService), 'update'], 'users.manage'));
$router->delete('/api/v1/users/{id}', $api([new UserController($userService), 'archive'], 'users.manage'));
$router->get('/api/v1/users', $api([new UserController($userService), 'index'], 'users.manage'));
$router->get('/api/v1/roles/{id}', $api([new RoleController($roleService), 'show'], 'roles.manage'));
$router->post('/api/v1/roles', $api([new RoleController($roleService), 'store'], 'roles.manage'));
$router->put('/api/v1/roles/{id}', $api([new RoleController($roleService), 'update'], 'roles.manage'));
$router->delete('/api/v1/roles/{id}', $api([new RoleController($roleService), 'archive'], 'roles.manage'));
$router->get('/api/v1/roles', $api([new RoleController($roleService), 'index'], 'roles.manage'));
$router->get('/api/v1/projects/{id}', $api([new ProjectController($projectService), 'show'], 'projects.view'));
$router->get('/api/v1/projects', $api([new ProjectController($projectService), 'index'], 'projects.view'));
$router->post('/api/v1/projects', $api([new ProjectController($projectService), 'store'], 'projects.manage'));
$router->put('/api/v1/projects/{id}', $api([new ProjectController($projectService), 'update'], 'projects.manage'));
$router->delete('/api/v1/projects/{id}', $api([new ProjectController($projectService), 'archive'], 'projects.manage'));
$router->get('/api/v1/assets/{id}', $api([new AssetController($assetService), 'show'], 'assets.manage'));
$router->post('/api/v1/assets', $api([new AssetController($assetService), 'store'], 'assets.manage'));
$router->put('/api/v1/assets/{id}', $api([new AssetController($assetService), 'update'], 'assets.manage'));
$router->delete('/api/v1/assets/{id}', $api([new AssetController($assetService), 'archive'], 'assets.manage'));
$router->get('/api/v1/assets', $api([new AssetController($assetService), 'index'], 'assets.manage'));
$router->get('/api/v1/timesheets', $api([new TimesheetController($timesheetService), 'index'], 'timesheets.manage'));
$router->post('/api/v1/timesheets/calculate', $api([new TimesheetController($timesheetService), 'calculate'], 'timesheets.create'));
$router->get('/api/v1/projects/{id}/files', $api([new FileController($fileService), 'listProjectFiles'], 'files.view'));
$router->post('/api/v1/projects/{id}/files', $api([new FileController($fileService), 'uploadProject'], 'files.upload'));
$router->delete('/api/v1/project-files/{id}', $api([new FileController($fileService), 'archiveProjectFile'], 'files.manage'));
$router->get('/api/v1/assets/{id}/files', $api([new FileController($fileService), 'listAssetFiles'], 'assets.manage'));
$router->post('/api/v1/assets/{id}/files', $api([new FileController($fileService), 'uploadAsset'], 'assets.manage'));
$router->delete('/api/v1/asset-files/{id}', $api([new FileController($fileService), 'archiveAssetFile'], 'assets.manage'));
$router->get('/api/v1/reports/export', $api([new ReportController($reportService), 'export'], 'reports.export'));
$router->get('/api/v1/reports/accounting-export', $api([$accountingExportController, 'export'], 'reports.accounting.export'));
$router->get('/api/v1/settings/company', [$companySettingsController, 'publicProfile']);
$router->get('/api/v1/settings/company/logo', [$companySettingsController, 'publicLogo']);
$router->get('/api/v1/settings/database', $api([new SettingsController($databaseSettings), 'show'], 'settings.database.manage'));
$router->post('/api/v1/settings/database', $api([new SettingsController($databaseSettings), 'store'], 'settings.database.manage'));
$router->get('/api/v1/system/backup/export', $api([$backupController, 'export'], 'settings.database.manage'));
$router->post('/api/v1/system/backup/import/validate', $api([$backupController, 'validateImport'], 'settings.database.manage'));

return [Request::capture(), $router];
