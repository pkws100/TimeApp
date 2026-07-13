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
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Exports\AccountingClosureService;
use App\Domain\Exports\AccountingDocumentExportService;
use App\Domain\Exports\AccountingExportService;
use App\Domain\Exports\BookingExportService;
use App\Domain\Exports\ReportService;
use App\Domain\Exports\TimeAccountExportService;
use App\Domain\Files\DocumentStatusService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Personnel\PersonnelEventService;
use App\Domain\Personnel\PersonnelLabelService;
use App\Domain\Personnel\PersonnelReminderService;
use App\Domain\Projects\ProjectService;
use App\Domain\Push\PushNotificationService;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Domain\Settings\SettingsSecretService;
use App\Domain\Settings\SmtpMailService;
use App\Domain\Settings\SmtpTestService;
use App\Domain\Terminals\NfcTagService;
use App\Domain\Terminals\TerminalPunchService;
use App\Domain\Terminals\TerminalService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetGeoLocationService;
use App\Domain\Timesheets\TimesheetSignatureService;
use App\Domain\Timesheets\TimesheetService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\RoleService;
use App\Domain\Users\StorageUsageService;
use App\Domain\Users\UserService;
use App\Domain\Vacation\VacationRequestService;
use App\Http\Controllers\AccountingExportController;
use App\Http\Controllers\AdminAccountingController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\AdminCalendarController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminManagementController;
use App\Http\Controllers\AdminPushController;
use App\Http\Controllers\AdminTimesheetAttachmentController;
use App\Http\Controllers\AdminTimesheetSignatureController;
use App\Http\Controllers\AdminTimeAccountController;
use App\Http\Controllers\AdminVacationRequestController;
use App\Http\Controllers\AppApiController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AppProjectAttachmentController;
use App\Http\Controllers\AppPushController;
use App\Http\Controllers\AppTimeAccountController;
use App\Http\Controllers\AppTimesheetAttachmentController;
use App\Http\Controllers\AppTimesheetController;
use App\Http\Controllers\AppTimesheetSignatureController;
use App\Http\Controllers\AppVacationRequestController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CalendarSettingsController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentStatusController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TerminalAdminController;
use App\Http\Controllers\TerminalApiController;
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

    $sessionSecureDefault = str_starts_with(strtolower((string) env('APP_URL', '')), 'https://');
    $sessionSecure = (bool) env('SESSION_SECURE_COOKIE', $sessionSecureDefault);

    session_name((string) env('SESSION_NAME', 'baustelle_session'));
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $sessionSecure,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

$config = ConfigRepository::load(['app', 'database', 'permissions', 'uploads', 'exports', 'maps', 'push']);
date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Berlin'));

$databaseSettings = new DatabaseSettingsManager(
    $config->get('database.connections.mysql', []),
    (string) $config->get('database.override_file')
);
$connection = new DatabaseConnection($databaseSettings->current());
$permissionMatrix = new PermissionMatrix($config->get('permissions.roles', []), $config->get('permissions.available', []));
$storageUsage = new StorageUsageService(storage_path());

$calendarPolicyService = new CalendarPolicyService($connection);
$dailyTargetService = new DailyTargetService($calendarPolicyService);
$accountJournalService = new AccountJournalService($connection);
$employeeAccountCutoverService = new EmployeeAccountCutoverService($connection, $accountJournalService);
$attendanceService = new AttendanceService($connection, $calendarPolicyService);
$userService = new UserService($connection);
$roleService = new RoleService($connection, $permissionMatrix);
$personnelLabelService = new PersonnelLabelService($connection);
$personnelEventService = new PersonnelEventService($connection);
$projectService = new ProjectService($connection);
$assetService = new AssetService($connection);
$timesheetCalculator = new TimesheetCalculator();
$workdayStateCalculator = new WorkdayStateCalculator();
$timesheetSignatureService = new TimesheetSignatureService($connection, $config->get('uploads', []), (string) $config->get('app.settings_encryption_key', ''));
$timesheetWriteGuard = new TimesheetWriteGuard($connection);
$timesheetService = new TimesheetService($connection, $timesheetCalculator);
$adminBookingService = new AdminBookingService($connection, $timesheetCalculator, $timesheetSignatureService, $timesheetWriteGuard, $dailyTargetService);
$adminCalendarService = new AdminCalendarService($connection, $adminBookingService, $calendarPolicyService, $personnelEventService);
$timesheetGeoLocationService = new TimesheetGeoLocationService($connection);
$settingsSecretService = new SettingsSecretService((string) $config->get('app.settings_encryption_key', ''));
$companySettingsService = new CompanySettingsService($connection, $config->get('uploads', []), $settingsSecretService);
$pushSettingsService = new PushSettingsService($connection, $config->get('push', []));
$pushSubscriptionService = new PushSubscriptionService($connection);
$pushNotificationService = new PushNotificationService($connection, $pushSettingsService, $pushSubscriptionService);
$smtpMailService = new SmtpMailService();
$personnelReminderService = new PersonnelReminderService(
    $personnelEventService,
    $pushSubscriptionService,
    $pushNotificationService,
    $companySettingsService,
    $smtpMailService,
    (string) $config->get('app.timezone', 'Europe/Berlin')
);
$authService = new AuthService($connection, $permissionMatrix);
$csrfService = new CsrfService();
$routeGuard = new RouteGuard($authService);
$dashboardService = new DashboardService($connection, $storageUsage, $attendanceService);
$fileService = new FileAttachmentService($connection, $config->get('uploads', []));
$documentStatusService = new DocumentStatusService($connection);
$reportService = new ReportService($connection, $config->get('exports', []), $timesheetService);
$bookingExportService = new BookingExportService($adminBookingService);
$accountingExportService = new AccountingExportService($adminBookingService);
$accountingClosureService = new AccountingClosureService($connection, $adminBookingService);
$accountingDocumentExportService = new AccountingDocumentExportService($companySettingsService, $config->get('exports', []));
$backupService = new BackupService($connection, $config->get('uploads', []));
$smtpTestService = new SmtpTestService();
$mobileAppService = new MobileAppService($connection, $projectService, $companySettingsService, $workdayStateCalculator, $fileService, $timesheetGeoLocationService, $calendarPolicyService, $timesheetSignatureService, $personnelEventService, $personnelLabelService);
$appTimesheetSyncService = new AppTimesheetSyncService($connection, $timesheetCalculator, $companySettingsService, $workdayStateCalculator, $timesheetSignatureService);
$timeAccountService = new TimeAccountService($connection, $calendarPolicyService, $dailyTargetService, $accountJournalService, $employeeAccountCutoverService);
$timeAccountExportService = new TimeAccountExportService($timeAccountService);
$vacationRequestService = new VacationRequestService($connection, $calendarPolicyService, $timesheetWriteGuard, $dailyTargetService);
$terminalService = new TerminalService($connection, $companySettingsService);
$nfcTagService = new NfcTagService($connection, (string) $config->get('app.settings_encryption_key', ''));
$terminalPunchService = new TerminalPunchService($connection, $terminalService, $nfcTagService, $appTimesheetSyncService);
$appDisplayName = trim((string) ($companySettingsService->current()['app_display_name'] ?? '')) ?: (string) $config->get('app.name');

$adminContextService = new AdminContextService(
    $companySettingsService,
    $attendanceService,
    $projectService,
    $userService,
    $roleService,
    $assetService,
    $adminBookingService,
    $personnelEventService,
    $terminalService,
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
$appPushController = new AppPushController($pushSettingsService, $pushSubscriptionService, $pushNotificationService, $authService);
$appTimeAccountController = new AppTimeAccountController($timeAccountService, $authService);
$appVacationRequestController = new AppVacationRequestController($vacationRequestService, $authService);
$appTimesheetController = new AppTimesheetController($appTimesheetSyncService, $authService);
$appTimesheetAttachmentController = new AppTimesheetAttachmentController($fileService, $authService);
$appTimesheetSignatureController = new AppTimesheetSignatureController($timesheetSignatureService, $authService);
$appProjectAttachmentController = new AppProjectAttachmentController($fileService, $authService);
$terminalApiController = new TerminalApiController($terminalService, $terminalPunchService);
$adminTimesheetAttachmentController = new AdminTimesheetAttachmentController($fileService, $authService, $csrfService);
$adminTimesheetSignatureController = new AdminTimesheetSignatureController($timesheetSignatureService, $authService, $csrfService);
$adminController = new AdminController($adminView, $dashboardService, $databaseSettings);
$adminManagementController = new AdminManagementController(
    $adminView,
    $projectService,
    $userService,
    $roleService,
    $assetService,
    $fileService,
    $documentStatusService,
    $adminBookingService,
    $authService,
    $csrfService,
    $timesheetSignatureService,
    $personnelLabelService,
    $personnelEventService
);
$adminBookingController = new AdminBookingController(
    $adminView,
    $adminBookingService,
    $bookingExportService,
    $projectService,
    $userService,
    $fileService,
    $documentStatusService,
    $timesheetGeoLocationService,
    $authService,
    $csrfService,
    $timesheetSignatureService
);
$adminTimeAccountController = new AdminTimeAccountController($adminView, $timeAccountService, $timeAccountExportService, $userService, $employeeAccountCutoverService, $accountJournalService, $authService, $csrfService, $companySettingsService);
$adminVacationRequestController = new AdminVacationRequestController($adminView, $vacationRequestService, $userService, $authService, $csrfService);
$adminCalendarController = new AdminCalendarController(
    $adminView,
    $adminCalendarService,
    $adminBookingService,
    $projectService,
    $userService,
    $fileService,
    $documentStatusService,
    $timesheetGeoLocationService,
    $authService,
    $csrfService,
    $timesheetSignatureService
);
$adminAccountingController = new AdminAccountingController(
    $adminView,
    $accountingClosureService,
    $accountingDocumentExportService,
    $projectService,
    $userService,
    $authService,
    $csrfService
);
$companySettingsController = new CompanySettingsController($adminView, $companySettingsService, $smtpTestService, $csrfService, $config->get('maps', []));
$terminalAdminController = new TerminalAdminController($adminView, $terminalService, $nfcTagService, $userService, $projectService, $authService, $csrfService);
$calendarSettingsController = new CalendarSettingsController($adminView, $calendarPolicyService, $authService, $csrfService);
$documentStatusController = new DocumentStatusController($adminView, $documentStatusService, $authService, $csrfService);
$adminPushController = new AdminPushController($adminView, $pushSettingsService, $pushSubscriptionService, $pushNotificationService, $csrfService);
$personnelController = new PersonnelController($adminView, $personnelLabelService, $personnelEventService, $userService, $authService, $csrfService);
$attendanceController = new AttendanceController($attendanceService, $adminView);
$accountingExportController = new AccountingExportController($accountingExportService);
$backupController = new BackupController($backupService);

$admin = static fn (callable $handler, ?string $permission = 'dashboard.view'): callable => $routeGuard->forAdmin($handler, $permission);
$api = static fn (callable $handler, ?string $permission = null): callable => $routeGuard->forApi($handler, $permission);

$router = new Router();
$router->get('/favicon.ico', static fn (): Response => Response::redirect('/assets/app-icon.svg'));
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
$router->get('/app/personal', [$appController, 'shell']);
$router->get('/app/urlaub', [$appController, 'shell']);
$router->get('/app/profil', [$appController, 'shell']);

$router->get('/admin', $admin([$adminController, 'dashboard'], 'dashboard.view'));
$router->get('/admin/calendar', $admin([$adminCalendarController, 'index'], 'timesheets.view'));
$router->get('/admin/calendar/month', $admin([$adminCalendarController, 'month'], 'timesheets.view'));
$router->get('/admin/calendar/day', $admin([$adminCalendarController, 'day'], 'timesheets.view'));
$router->get('/admin/accounting', $admin([$adminAccountingController, 'index'], 'reports.accounting.export'));
$router->get('/admin/accounting/export', $admin([$adminAccountingController, 'export'], 'reports.accounting.export'));
$router->post('/admin/accounting/closures', $admin([$adminAccountingController, 'createClosure'], 'accounting.finalize'));
$router->get('/admin/accounting/closures/{id}/download', $admin([$adminAccountingController, 'download'], 'reports.accounting.export'));
$router->get('/admin/attendance', $admin([$attendanceController, 'index'], 'attendance.view'));
$router->get('/admin/personnel', $admin([$personnelController, 'index'], 'personnel.view'));
$router->get('/admin/personnel/charts', $admin([$personnelController, 'charts'], 'personnel.view'));
$router->get('/admin/personnel/labels', $admin([$personnelController, 'labels'], 'personnel.view'));
$router->post('/admin/personnel/labels', $admin([$personnelController, 'createLabel'], 'personnel.manage'));
$router->post('/admin/personnel/labels/{id}', $admin([$personnelController, 'updateLabel'], 'personnel.manage'));
$router->post('/admin/personnel/labels/{id}/archive', $admin([$personnelController, 'archiveLabel'], 'personnel.manage'));
$router->get('/admin/personnel/events', $admin([$personnelController, 'events'], 'personnel.view'));
$router->post('/admin/personnel/events', $admin([$personnelController, 'createEvent'], 'personnel.manage'));
$router->post('/admin/personnel/events/{id}', $admin([$personnelController, 'updateEvent'], 'personnel.manage'));
$router->post('/admin/personnel/events/{id}/archive', $admin([$personnelController, 'archiveEvent'], 'personnel.manage'));
$router->get('/admin/personnel/event-types', $admin([$personnelController, 'eventTypes'], 'personnel.view'));
$router->post('/admin/personnel/event-types', $admin([$personnelController, 'createEventType'], 'personnel.manage'));
$router->post('/admin/personnel/event-types/{id}', $admin([$personnelController, 'updateEventType'], 'personnel.manage'));
$router->post('/admin/personnel/event-types/{id}/archive', $admin([$personnelController, 'archiveEventType'], 'personnel.manage'));
$router->get('/admin/projects', $admin([$adminManagementController, 'projects'], 'projects.view'));
$router->get('/admin/bookings', $admin([$adminBookingController, 'index'], 'timesheets.view'));
$router->get('/admin/bookings/export', $admin([$adminBookingController, 'export'], 'timesheets.export'));
$router->post('/admin/bookings', $admin([$adminBookingController, 'create'], 'timesheets.manage'));
$router->post('/admin/bookings/bulk-assign', $admin([$adminBookingController, 'bulkAssign'], 'timesheets.manage'));
$router->put('/admin/bookings/{id}', $admin([$adminBookingController, 'update'], 'timesheets.manage'));
$router->delete('/admin/bookings/{id}/archive', $admin([$adminBookingController, 'archive'], 'timesheets.archive'));
$router->post('/admin/bookings/{id}/restore', $admin([$adminBookingController, 'restore'], 'timesheets.archive'));
$router->get('/admin/timesheet-files/{id}/download', $admin([$adminTimesheetAttachmentController, 'download'], 'timesheets.view'));
$router->delete('/admin/timesheet-files/{id}', $admin([$adminTimesheetAttachmentController, 'archive'], 'timesheets.archive'));
$router->post('/admin/timesheet-files/{id}/status', $admin([$adminTimesheetAttachmentController, 'status'], 'timesheets.manage'));
$router->get('/admin/timesheet-signatures/{id}/image', $admin([$adminTimesheetSignatureController, 'image'], 'timesheets.view'));
$router->post('/admin/timesheet-signatures/{id}/archive', $admin([$adminTimesheetSignatureController, 'archive'], 'timesheets.archive'));
$router->get('/admin/time-accounts', $admin([$adminTimeAccountController, 'index'], 'time_accounts.view'));
$router->get('/admin/time-accounts/export', $admin([$adminTimeAccountController, 'export'], 'time_accounts.view'));
$router->post('/admin/time-accounts/cutovers/preview', $admin([$adminTimeAccountController, 'previewCutover'], 'time_accounts.manage'));
$router->post('/admin/time-accounts/cutovers/draft', $admin([$adminTimeAccountController, 'saveDraft'], 'time_accounts.manage'));
$router->post('/admin/time-accounts/cutovers/finalize', $admin([$adminTimeAccountController, 'finalizeCutover'], 'time_accounts.manage'));
$router->post('/admin/time-accounts/cutovers/{id}/reverse', $admin([$adminTimeAccountController, 'reverseCutover'], 'time_accounts.manage'));
$router->get('/admin/time-accounts/cutovers/{id}/protocol', $admin([$adminTimeAccountController, 'protocol'], 'time_accounts.view'));
$router->post('/admin/time-accounts/entries/time', $admin([$adminTimeAccountController, 'adjustTime'], 'time_accounts.manage'));
$router->post('/admin/time-accounts/entries/time/{id}/reverse', $admin([$adminTimeAccountController, 'reverseTimeEntry'], 'time_accounts.manage'));
$router->post('/admin/time-accounts/entries/vacation', $admin([$adminTimeAccountController, 'adjustVacation'], 'time_accounts.manage'));
$router->post('/admin/time-accounts/entries/vacation/{id}/reverse', $admin([$adminTimeAccountController, 'reverseVacationEntry'], 'time_accounts.manage'));
$router->get('/admin/vacation-requests', $admin([$adminVacationRequestController, 'index'], 'vacation_requests.view'));
$router->post('/admin/vacation-requests/{id}/approve', $admin([$adminVacationRequestController, 'approve'], 'vacation_requests.manage'));
$router->post('/admin/vacation-requests/{id}/reject', $admin([$adminVacationRequestController, 'reject'], 'vacation_requests.manage'));
$router->get('/admin/projects/create', $admin([$adminManagementController, 'projectCreate'], 'projects.manage'));
$router->get('/admin/projects/{id}/edit', $admin([$adminManagementController, 'projectEdit'], 'projects.manage'));
$router->post('/admin/projects', $admin([$adminManagementController, 'projectStore'], 'projects.manage'));
$router->put('/admin/projects/{id}', $admin([$adminManagementController, 'projectUpdate'], 'projects.manage'));
$router->post('/admin/projects/{id}/memberships', $admin([$adminManagementController, 'projectMembershipUpdate'], 'projects.manage'));
$router->delete('/admin/projects/{id}', $admin([$adminManagementController, 'projectArchive'], 'projects.manage'));
$router->post('/admin/projects/{id}/restore', $admin([$adminManagementController, 'projectRestore'], 'projects.manage'));
$router->post('/admin/projects/{id}/bookings', $admin([$adminManagementController, 'projectBookingStore'], 'timesheets.manage'));
$router->post('/admin/projects/{id}/files', $admin([$adminManagementController, 'projectFileStore'], 'files.upload'));
$router->delete('/admin/project-files/{id}', $admin([$adminManagementController, 'projectFileArchive'], 'files.manage'));
$router->post('/admin/project-files/{id}/status', $admin([$adminManagementController, 'projectFileStatus'], 'files.manage'));
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
$router->post('/admin/asset-files/{id}/status', $admin([$adminManagementController, 'assetFileStatus'], 'assets.manage'));
$router->get('/admin/terminals', $admin([$terminalAdminController, 'index'], 'terminals.manage'));
$router->post('/admin/terminals', $admin([$terminalAdminController, 'store'], 'terminals.manage'));
$router->post('/admin/terminals/{id}', $admin([$terminalAdminController, 'update'], 'terminals.manage'));
$router->post('/admin/terminals/{id}/archive', $admin([$terminalAdminController, 'archive'], 'terminals.manage'));
$router->post('/admin/terminals/{id}/token-reset', $admin([$terminalAdminController, 'resetToken'], 'terminals.manage'));
$router->post('/admin/terminals/{id}/learn', $admin([$terminalAdminController, 'learn'], 'terminals.manage'));
$router->post('/admin/terminals/tags/{id}', $admin([$terminalAdminController, 'updateTag'], 'terminals.manage'));
$router->post('/admin/terminals/tags/{id}/archive', $admin([$terminalAdminController, 'archiveTag'], 'terminals.manage'));
$router->get('/admin/settings/company', $admin([$companySettingsController, 'show'], 'settings.manage'));
$router->post('/admin/settings/company', $admin([$companySettingsController, 'save'], 'settings.manage'));
$router->post('/admin/settings/company/logo', $admin([$companySettingsController, 'saveLogo'], 'settings.manage'));
$router->post('/admin/settings/company/agb-text', $admin([$companySettingsController, 'saveAgbText'], 'settings.manage'));
$router->post('/admin/settings/company/agb-pdf', $admin([$companySettingsController, 'saveAgbPdf'], 'settings.manage'));
$router->get('/admin/settings/company/agb-pdf/preview', $admin([$companySettingsController, 'previewAgbPdf'], 'settings.manage'));
$router->get('/admin/settings/company/agb-pdf/download', $admin([$companySettingsController, 'downloadAgbPdf'], 'settings.manage'));
$router->post('/admin/settings/company/datenschutz-text', $admin([$companySettingsController, 'saveDatenschutzText'], 'settings.manage'));
$router->post('/admin/settings/company/datenschutz-pdf', $admin([$companySettingsController, 'saveDatenschutzPdf'], 'settings.manage'));
$router->get('/admin/settings/company/datenschutz-pdf/preview', $admin([$companySettingsController, 'previewDatenschutzPdf'], 'settings.manage'));
$router->get('/admin/settings/company/datenschutz-pdf/download', $admin([$companySettingsController, 'downloadDatenschutzPdf'], 'settings.manage'));
$router->post('/admin/settings/company/smtp', $admin([$companySettingsController, 'saveSmtp'], 'settings.manage'));
$router->post('/admin/settings/company/geo', $admin([$companySettingsController, 'saveGeo'], 'settings.manage'));
$router->post('/admin/settings/company/terminal', $admin([$companySettingsController, 'saveTerminal'], 'settings.manage'));
$router->post('/admin/settings/company/smtp-test', $admin([$companySettingsController, 'smtpTest'], 'settings.manage'));
$router->get('/admin/settings/calendar', $admin([$calendarSettingsController, 'show'], 'settings.manage'));
$router->post('/admin/settings/calendar', $admin([$calendarSettingsController, 'saveRegion'], 'settings.manage'));
$router->post('/admin/settings/calendar/closures', $admin([$calendarSettingsController, 'createClosure'], 'settings.manage'));
$router->post('/admin/settings/calendar/closures/{id}/archive', $admin([$calendarSettingsController, 'archiveClosure'], 'settings.manage'));
$router->get('/admin/settings/document-statuses', $admin([$documentStatusController, 'index'], 'settings.manage'));
$router->post('/admin/settings/document-statuses', $admin([$documentStatusController, 'create'], 'settings.manage'));
$router->post('/admin/settings/document-statuses/{id}', $admin([$documentStatusController, 'update'], 'settings.manage'));
$router->post('/admin/settings/document-statuses/{id}/archive', $admin([$documentStatusController, 'archive'], 'settings.manage'));
$router->get('/admin/settings/database', $admin([$adminController, 'databaseSettings'], 'settings.database.manage'));
$router->post('/admin/settings/database', $admin([new SettingsController($databaseSettings), 'saveFromAdmin'], 'settings.database.manage'));
$router->get('/admin/settings/push', $admin([$adminPushController, 'show'], 'push.manage'));
$router->post('/admin/settings/push', $admin([$adminPushController, 'save'], 'push.manage'));
$router->post('/admin/settings/push/subscriptions/{id}', $admin([$adminPushController, 'updateDevice'], 'push.manage'));
$router->post('/admin/settings/push/test', $admin([$adminPushController, 'test'], 'push.manage'));

$router->post('/api/v1/auth/login', [$authController, 'login']);
$router->post('/api/v1/auth/logout', [$authController, 'logout']);
$router->get('/api/v1/auth/session', [$authController, 'session']);

$router->get('/api/v1/terminal/config', [$terminalApiController, 'config']);
$router->post('/api/v1/terminal/scan', [$terminalApiController, 'scan']);

$router->get('/api/v1/app/me/day', $api([$appApiController, 'meDay'], 'timesheets.view_own'));
$router->get('/api/v1/app/me/timesheets', $api([$appApiController, 'meTimesheets'], 'timesheets.view_own'));
$router->get('/api/v1/app/time-account/summary', $api([$appTimeAccountController, 'summary'], 'timesheets.view_own'));
$router->get('/api/v1/app/vacation-requests', $api([$appVacationRequestController, 'index'], 'timesheets.view_own'));
$router->get('/api/v1/app/vacation-requests/preview', $api([$appVacationRequestController, 'preview'], 'timesheets.view_own'));
$router->post('/api/v1/app/vacation-requests', $api([$appVacationRequestController, 'store'], 'timesheets.create'));
$router->post('/api/v1/app/vacation-requests/{id}/cancel', $api([$appVacationRequestController, 'cancel'], 'timesheets.create'));
$router->get('/api/v1/app/push/status', $api([$appPushController, 'status']));
$router->post('/api/v1/app/push/subscriptions', $api([$appPushController, 'store'], 'push.receive'));
$router->delete('/api/v1/app/push/subscriptions/{id}', $api([$appPushController, 'disable'], 'push.receive'));
$router->post('/api/v1/app/push/test', $api([$appPushController, 'test'], 'push.receive'));
$router->post('/api/v1/app/timesheets/sync', $api([$appTimesheetController, 'sync'], 'timesheets.create'));
$router->get('/api/v1/app/projects/{id}/files', $api([$appProjectAttachmentController, 'index'], 'files.view'));
$router->post('/api/v1/app/projects/{id}/files', $api([$appProjectAttachmentController, 'upload'], 'files.upload'));
$router->get('/api/v1/app/project-files/{id}/download', $api([$appProjectAttachmentController, 'download'], 'files.view'));
$router->get('/api/v1/app/timesheets/{id}/files', $api([$appTimesheetAttachmentController, 'index'], 'timesheets.view_own'));
$router->post('/api/v1/app/timesheets/{id}/files', $api([$appTimesheetAttachmentController, 'upload'], 'timesheets.create'));
$router->get('/api/v1/app/timesheet-files/{id}/download', $api([$appTimesheetAttachmentController, 'download'], 'timesheets.view_own'));
$router->delete('/api/v1/app/timesheet-files/{id}', $api([$appTimesheetAttachmentController, 'archive'], 'timesheets.create'));
$router->get('/api/v1/app/timesheets/{id}/signature', $api([$appTimesheetSignatureController, 'status'], 'timesheets.view_own'));
$router->post('/api/v1/app/timesheets/{id}/signature', $api([$appTimesheetSignatureController, 'store'], 'timesheets.create'));
$router->get('/api/v1/app/timesheet-signatures/{id}/image', $api([$appTimesheetSignatureController, 'image'], 'timesheets.view_own'));

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
