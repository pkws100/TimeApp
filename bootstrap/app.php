<?php

declare(strict_types=1);

use App\Config\ConfigRepository;
use App\Config\EnvironmentLoader;
use App\Domain\Admin\AdminContextService;
use App\Domain\App\MobileAppService;
use App\Domain\Appointments\AppointmentDispatchService;
use App\Domain\Appointments\AppointmentService;
use App\Domain\Assets\AssetService;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\AttendanceReportService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Auth\ProjectSessionDirectory;
use App\Domain\Auth\RouteGuard;
use App\Domain\Backup\BackupService;
use App\Domain\Calendar\AppointmentCalendarFeed;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Exports\AccountingClosureService;
use App\Domain\Exports\AccountingDocumentExportService;
use App\Domain\Exports\AccountingEmployeeSummaryService;
use App\Domain\Exports\AccountingExportService;
use App\Domain\Exports\BookingExportService;
use App\Domain\Exports\ReportService;
use App\Domain\Exports\TimeAccountExportService;
use App\Domain\Files\DocumentStatusService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Personnel\Compliance\EmploymentContextService;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelAdminQueryService;
use App\Domain\Personnel\Compliance\PersonnelComplianceCatalogService;
use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Personnel\Compliance\PersonnelFileRuntime;
use App\Domain\Personnel\Compliance\PersonnelProductionHttpBoundary;
use App\Domain\Personnel\Compliance\PersonnelSyntheticHttpBoundary;
use App\Domain\Personnel\PersonnelEventService;
use App\Domain\Personnel\PersonnelLabelService;
use App\Domain\Personnel\PersonnelReminderService;
use App\Domain\Projects\ProjectService;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectDispatchService;
use App\Domain\Projects\ProjectMaterialService;
use App\Domain\Projects\ProjectWorkReportService;
use App\Domain\Push\PushNotificationService;
use App\Domain\Push\PushSender;
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
use App\Domain\Terminals\TerminalTrustBundleService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\TimeAccounts\VacationAccountYearService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\AbsencePeriodService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetDayConflictService;
use App\Domain\Timesheets\TimesheetAuditService;
use App\Domain\Timesheets\PausePolicyService;
use App\Domain\Timesheets\TimesheetPauseService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetGeoLocationService;
use App\Domain\Timesheets\TimesheetSignatureService;
use App\Domain\Timesheets\TimesheetService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Domain\WorkTime\WorkTimePresenceService;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimeDeviationService;
use App\Domain\WorkTime\WorkTimeAccessService;
use App\Domain\WorkTime\WorkTimePushChannel;
use App\Domain\WorkTime\WorkTimePauseCorrectionService;
use App\Domain\WorkTime\WorkTimeUserSummaryService;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\RoleService;
use App\Domain\Users\StorageUsageService;
use App\Domain\Users\UserService;
use App\Domain\Users\UserWorkdayPolicy;
use App\Domain\Vacation\VacationRequestService;
use App\Http\Controllers\AccountingExportController;
use App\Http\Controllers\AdminAccountingController;
use App\Http\Controllers\AdminAbsencePeriodController;
use App\Http\Controllers\AdminAppointmentController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminBookingController;
use App\Http\Controllers\AdminCalendarController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminManagementController;
use App\Http\Controllers\AdminPersonnelDocumentsController;
use App\Http\Controllers\AdminPushController;
use App\Http\Controllers\AdminTimesheetAttachmentController;
use App\Http\Controllers\AdminTimesheetSignatureController;
use App\Http\Controllers\AdminTimeAccountController;
use App\Http\Controllers\AdminVacationRequestController;
use App\Http\Controllers\AppApiController;
use App\Http\Controllers\AppAppointmentsController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AppProjectAttachmentController;
use App\Http\Controllers\AppProjectMaterialController;
use App\Http\Controllers\AppProjectWorkReportController;
use App\Http\Controllers\AppPushController;
use App\Http\Controllers\AppTimeAccountController;
use App\Http\Controllers\AppTimesheetAttachmentController;
use App\Http\Controllers\AppTimesheetController;
use App\Http\Controllers\AppWorkTimeController;
use App\Http\Controllers\AdminWorkTimeController;
use App\Http\Controllers\AdminPausePolicyController;
use App\Http\Controllers\AdminWorkTimeNotificationController;
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
use App\Http\PersonnelAdminFileTransfer;
use App\Http\Response;
use App\Http\Router;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use App\Presentation\Admin\AdminAppointmentsView;
use App\Presentation\Admin\PersonnelDocumentsView;
use App\Presentation\App\AppView;

require_once __DIR__ . '/autoload.php';

(new EnvironmentLoader())->load(base_path('.env'));
$personnelHttp = PersonnelSyntheticHttpBoundary::discover();

$request = Request::capture();
if ($personnelHttp === null && PersonnelSyntheticHttpBoundary::isRequested()) {
    return [$request, new Router()];
}
$isPublicAppResource = $request->method() === 'GET'
    && (in_array($request->path(), ['/app/sw.js', '/app/manifest.json'], true)
        || ($request->path() === '/app' && $request->query(AppController::OFFLINE_SHELL_QUERY) === '1'));

if (!$isPublicAppResource && session_status() !== PHP_SESSION_ACTIVE) {
    $sessionCandidates = $personnelHttp === null ? [] : [$personnelHttp->sessionDirectory()];
    $projectSessionPath = ProjectSessionDirectory::prepare(storage_path());
    if ($projectSessionPath !== null) {
        $sessionCandidates[] = $projectSessionPath;
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

    session_name($personnelHttp?->sessionName() ?? (string) env('SESSION_NAME', 'baustelle_session'));
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => $sessionSecure,
        'cookie_samesite' => $personnelHttp === null ? 'Lax' : 'Strict',
        'use_strict_mode' => true,
    ]);
}

$config = ConfigRepository::load(['app', 'database', 'permissions', 'uploads', 'exports', 'maps', 'push']);
date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Berlin'));

$databaseOverridePath = (string) $config->get('database.override_file');
$databaseSettings = new DatabaseSettingsManager(
    $config->get('database.connections.mysql', []),
    $databaseOverridePath
);
$databaseConfiguration = $databaseSettings->current();
$connection = new DatabaseConnection($databaseConfiguration);
if ($personnelHttp !== null && !$personnelHttp->bind($connection)) {
    return [$request, new Router()];
}
$personnelProduction = $personnelHttp === null ? PersonnelProductionHttpBoundary::discover($request, $connection, $databaseConfiguration) : null;
$personnelBoundary = $personnelHttp ?? $personnelProduction;
$permissionMatrix = new PermissionMatrix($config->get('permissions.roles', []), $config->get('permissions.available', []));
$storageUsage = new StorageUsageService(storage_path());

$calendarPolicyService = new CalendarPolicyService($connection);
$userWorkdayPolicy = new UserWorkdayPolicy();
$dailyTargetService = new DailyTargetService($calendarPolicyService);
$accountJournalService = new AccountJournalService($connection);
$timesheetWriteGuard = new TimesheetWriteGuard($connection);
$employeeAccountCutoverService = new EmployeeAccountCutoverService($connection, $accountJournalService, $timesheetWriteGuard);
$vacationAccountYearService = new VacationAccountYearService($connection, $accountJournalService, $employeeAccountCutoverService);
$employeeAccountCutoverService->setVacationYearService($vacationAccountYearService);
$timeAccountService = new TimeAccountService($connection, $calendarPolicyService, $dailyTargetService, $accountJournalService, $employeeAccountCutoverService);
$timesheetDayConflictService = new TimesheetDayConflictService($connection);
$timesheetAuditService = new TimesheetAuditService($connection);
$attendanceService = new AttendanceService($connection, $calendarPolicyService, $userWorkdayPolicy);
$userService = new UserService($connection);
$roleService = new RoleService($connection, $permissionMatrix);
$personnelLabelService = new PersonnelLabelService($connection);
$personnelEventService = new PersonnelEventService($connection);
$projectAccessService = new ProjectAccessService($connection);
$projectService = new ProjectService($connection, $projectAccessService);
$appointmentTimezone = (string) $config->get('app.timezone', 'Europe/Berlin');
$appointmentService = new AppointmentService($connection, $projectAccessService, $appointmentTimezone);
$assetService = new AssetService($connection);
$timesheetCalculator = new TimesheetCalculator();
$workdayStateCalculator = new WorkdayStateCalculator();
$timesheetSignatureService = new TimesheetSignatureService($connection, $config->get('uploads', []), (string) $config->get('app.settings_encryption_key', ''));
$timesheetService = new TimesheetService($connection, $timesheetCalculator);
$adminBookingService = new AdminBookingService($connection, $timesheetCalculator, $timesheetSignatureService, $timesheetWriteGuard, $dailyTargetService, $timesheetDayConflictService, $timesheetAuditService);
$absencePeriodService = new AbsencePeriodService(
    $connection,
    $calendarPolicyService,
    $dailyTargetService,
    $timesheetWriteGuard,
    $timesheetDayConflictService,
    $timesheetAuditService,
    $timeAccountService
);
$adminCalendarService = new AdminCalendarService($connection, $adminBookingService, $calendarPolicyService, $personnelEventService, $userWorkdayPolicy);
$timesheetGeoLocationService = new TimesheetGeoLocationService($connection);
$settingsSecretService = new SettingsSecretService((string) $config->get('app.settings_encryption_key', ''));
$companySettingsService = new CompanySettingsService($connection, $config->get('uploads', []), $settingsSecretService);
$pushSettingsService = new PushSettingsService($connection, $config->get('push', []));
$pushSubscriptionService = new PushSubscriptionService($connection);
$pushNotificationService = new PushNotificationService($connection, $pushSettingsService, $pushSubscriptionService);
$appointmentPushSender = PHP_SAPI === 'cli-server' && getenv('APP_ENV') === 'test'
    && getenv('UI_APPOINTMENT_DISPATCH_FIXTURE') === '1' && $request->server('REMOTE_ADDR') === '127.0.0.1'
    && ($appointmentPushSenderOverride ?? null) instanceof PushSender
    ? $appointmentPushSenderOverride : $pushNotificationService;
$appointmentDispatchService = new AppointmentDispatchService(
    $connection, $projectAccessService, $pushSettingsService, $pushSubscriptionService, $appointmentPushSender, $appointmentTimezone,
);
$projectWorkReportService = new ProjectWorkReportService($connection, $projectAccessService);
$projectDispatchService = new ProjectDispatchService(
    $connection,
    $projectAccessService,
    $pushSettingsService,
    $pushSubscriptionService,
    $pushNotificationService
);
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
$databaseSettingsController = new SettingsController($databaseSettings, $authService, $csrfService);
$userController = new UserController($userService, $authService);
$roleController = new RoleController($roleService, $authService);
$routeGuard = new RouteGuard($authService);
$dashboardService = new DashboardService($connection, $storageUsage, $attendanceService);
$fileService = new FileAttachmentService($connection, $config->get('uploads', []));
$documentStatusService = new DocumentStatusService($connection);
$reportService = new ReportService($connection, $config->get('exports', []), $timesheetService);
$attendanceReportService = new AttendanceReportService(
    $attendanceService,
    $companySettingsService,
    $config->get('exports', []),
    (string) $config->get('app.timezone', 'Europe/Berlin')
);
$bookingExportService = new BookingExportService($adminBookingService);
$accountingExportService = new AccountingExportService($adminBookingService);
$accountingClosureService = new AccountingClosureService($connection, $adminBookingService);
$accountingDocumentExportService = new AccountingDocumentExportService($companySettingsService, $config->get('exports', []));
$backupService = new BackupService($connection, $config->get('uploads', []),
    backupRoot: $personnelHttp?->generalBackupDirectory() ?? (string) env('BACKUP_TEMP_PATH', storage_path('cache/backups')),
    runtimeOverridePath: $databaseOverridePath);
$smtpTestService = new SmtpTestService();
$nfcTagService = new NfcTagService($connection, (string) $config->get('app.settings_encryption_key', ''));
$pausePolicies = new PausePolicyService($connection);
$timesheetPauses = new TimesheetPauseService($connection, $pausePolicies);
$adminBookingService->setPauseService($timesheetPauses);
$timesheetService->setPauseService($timesheetPauses);
$mobileAppService = new MobileAppService($connection, $projectService, $companySettingsService, $workdayStateCalculator, $fileService, $timesheetGeoLocationService, $calendarPolicyService, $timesheetSignatureService, $personnelEventService, $personnelLabelService, $nfcTagService, $userWorkdayPolicy, $timesheetPauses);
$appTimesheetSyncService = new AppTimesheetSyncService($connection, $timesheetCalculator, $companySettingsService, $workdayStateCalculator, $timesheetSignatureService, $timesheetDayConflictService);
$workTimePolicies = new WorkTimePolicyService($connection, (bool) $config->get('app.work_time_windows_enabled', false));
$workTimePreview = new WorkTimePreviewService($connection, $workTimePolicies);
$workTimePreview->setPauseService($timesheetPauses);
$workTimePolicies->setAssignmentImpactSources($workTimePreview, $timesheetPauses);
$workTimePresence = new WorkTimePresenceService($connection, $workTimePolicies);
$appTimesheetSyncService->setWorkTimePresenceService($workTimePresence);
$appTimesheetSyncService->setPauseService($timesheetPauses);
$workTimeEvents = new WorkTimeEventService($connection, $workTimePolicies, $appTimesheetSyncService, new WorkTimePushChannel($pushSubscriptionService, $pushNotificationService), null, $workTimePreview);
$projectMaterialService = new ProjectMaterialService($connection, $projectAccessService, $workTimeEvents);
$workTimeDeviations = new WorkTimeDeviationService($connection, $workTimePolicies);
$workTimePauseCorrections = new WorkTimePauseCorrectionService($connection, $workTimePolicies, $workTimePreview, $timesheetWriteGuard, $timesheetPauses);
$workTimeUserSummary = new WorkTimeUserSummaryService($connection, $workTimePolicies);
$timeAccountService->setWorkTimePreviewService($workTimePreview);
$timeAccountService->setWorkTimePolicyService($workTimePolicies);
$accountingClosureService->setWorkTimePreviewService($workTimePreview);
$accountingClosureService->setEmployeeSummaryService(new AccountingEmployeeSummaryService(
    $connection, $timeAccountService, $dailyTargetService, $workTimePolicies, $workTimePreview
));
$employeeAccountCutoverService->setWorkTimePreviewService($workTimePreview);
$timeAccountService->setPauseService($timesheetPauses);
$accountingClosureService->setPauseService($timesheetPauses);
$employeeAccountCutoverService->setPauseService($timesheetPauses);
$timeAccountExportService = new TimeAccountExportService($timeAccountService);
$vacationRequestService = new VacationRequestService($connection, $calendarPolicyService, $timesheetWriteGuard, $dailyTargetService, $timesheetDayConflictService, $absencePeriodService);
$terminalService = new TerminalService($connection, $companySettingsService);
$terminalTrustBundleService = new TerminalTrustBundleService(
    (string) env('TERMINAL_TRUST_BUNDLE_FILE', storage_path('app/terminal-trust-bundle.json')),
    (string) env('TERMINAL_TRUST_PUBLIC_KEY_FILE', '')
);
$terminalPunchService = new TerminalPunchService($connection, $terminalService, $nfcTagService, $appTimesheetSyncService, $terminalTrustBundleService, $timeAccountService);
$appDisplayName = trim((string) ($companySettingsService->current()['app_display_name'] ?? '')) ?: (string) $config->get('app.name');
$personnelConnection = $personnelProduction?->connection() ?? $connection;
$personnelAuth = $personnelProduction === null ? $authService : new AuthService($personnelConnection, $permissionMatrix);
$personnelMenuEnabled = $personnelHttp !== null || ($personnelProduction !== null && $personnelAuth->isSystemAdministrator());

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
    (string) $config->get('app.name'),
    $personnelMenuEnabled,
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
$appApiController = new AppApiController($mobileAppService, $authService, $workTimePolicies, $workTimeEvents);
$appWorkTimeController = new AppWorkTimeController($authService, $csrfService, $workTimePolicies, $workTimePreview, $workTimeEvents);
$adminPausePolicyController = new AdminPausePolicyController($pausePolicies, $authService, $csrfService, $userService);
$adminWorkTimeController = new AdminWorkTimeController($adminView, $authService, $csrfService, $userService, $workTimePolicies, $workTimePreview, $workTimeDeviations, new WorkTimeAccessService($workTimePolicies), $workTimeEvents, $workTimePauseCorrections, pausePolicies: $adminPausePolicyController);
$adminWorkTimeNotificationController = new AdminWorkTimeNotificationController($adminView, $authService, $csrfService, $userService, $workTimePolicies, $workTimeEvents->notifications(), new WorkTimeAccessService($workTimePolicies));
$appPushController = new AppPushController($pushSettingsService, $pushSubscriptionService, $pushNotificationService, $authService);
$appAppointmentsController = new AppAppointmentsController(
    $appointmentService,
    $authService,
    $csrfService,
    (string) $config->get('app.url'),
    $config->get('app.appointments_calendar_share_project') === true,
);
$appTimeAccountController = new AppTimeAccountController($timeAccountService, $authService);
$appVacationRequestController = new AppVacationRequestController($vacationRequestService, $authService);
$appTimesheetController = new AppTimesheetController($appTimesheetSyncService, $authService, $projectAccessService);
$appTimesheetAttachmentController = new AppTimesheetAttachmentController($fileService, $authService);
$appTimesheetSignatureController = new AppTimesheetSignatureController($timesheetSignatureService, $authService);
$appProjectAttachmentController = new AppProjectAttachmentController($fileService, $authService, $projectAccessService);
$appProjectMaterialController = new AppProjectMaterialController($projectMaterialService, $authService);
$appProjectWorkReportController = new AppProjectWorkReportController($projectWorkReportService, $authService);
$terminalApiController = new TerminalApiController($terminalService, $terminalPunchService, $terminalTrustBundleService);
$adminTimesheetAttachmentController = new AdminTimesheetAttachmentController($fileService, $authService, $csrfService);
$adminTimesheetSignatureController = new AdminTimesheetSignatureController($timesheetSignatureService, $authService, $csrfService);
$adminController = new AdminController($adminView, $dashboardService, $databaseSettings, $authService);
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
    $personnelEventService,
    $nfcTagService,
    $terminalService,
    $projectMaterialService,
    $projectDispatchService,
    $projectAccessService,
    $projectWorkReportService,
    $workTimeUserSummary
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
$adminTimeAccountController = new AdminTimeAccountController($adminView, $timeAccountService, $timeAccountExportService, $userService, $employeeAccountCutoverService, $authService, $csrfService, $companySettingsService);
$adminVacationRequestController = new AdminVacationRequestController($adminView, $vacationRequestService, $timeAccountService, $userService, $authService, $csrfService, $absencePeriodService);
$adminAbsencePeriodController = new AdminAbsencePeriodController($absencePeriodService, $authService, $csrfService);
$adminAppointmentController = new AdminAppointmentController(
    new AdminAppointmentsView($adminView), $appointmentService, $authService, $csrfService, $appointmentDispatchService,
);
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
    $timesheetSignatureService,
    new AppointmentCalendarFeed($appointmentService, $appointmentTimezone),
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
$attendanceController = new AttendanceController($attendanceService, $attendanceReportService, $adminView);
$accountingExportController = new AccountingExportController($accountingExportService);
$backupController = new BackupController($backupService);
$projectController = new ProjectController($projectService, $authService, $projectAccessService);
$fileController = new FileController($fileService, $authService, $projectAccessService);

$admin = static fn (callable $handler, ?string $permission = 'dashboard.view'): callable => $routeGuard->forAdmin($handler, $permission);
$api = static fn (callable $handler, ?string $permission = null, bool $nativeDownload = false): callable => $routeGuard->forApi($handler, $permission, $nativeDownload);

$router = new Router();
if ($personnelBoundary !== null) {
    $personnelAccess = new PersonnelAccessService($personnelConnection, $personnelAuth, systemAdministratorsOnly: $personnelProduction !== null);
    $personnelDocuments = new PersonnelDocumentService(
        $personnelConnection,
        $personnelAccess,
        fileRuntimeFactory: static fn (): PersonnelFileRuntime => $personnelBoundary instanceof PersonnelProductionHttpBoundary
            ? PersonnelFileRuntime::fromProductionHttp($personnelConnection, $personnelBoundary)
            : PersonnelFileRuntime::fromSyntheticHttp($personnelConnection, $personnelBoundary),
    );
    $personnelAdmin = new AdminPersonnelDocumentsController(
        $personnelAuth,
        $csrfService,
        $personnelAccess,
        $personnelDocuments,
        new PersonnelAdminQueryService(
            $personnelConnection,
            $personnelAccess,
            new EmploymentContextService($personnelConnection, $personnelAccess),
            $personnelDocuments,
            new PersonnelComplianceCatalogService($personnelConnection, $personnelAccess),
        ),
        new PersonnelAdminFileTransfer($personnelBoundary, $personnelDocuments),
        new PersonnelDocumentsView($adminView, synthetic: $personnelProduction === null),
        $personnelBoundary->origin(),
    );
    $personnelAdmin->register($router);
}
$router->get('/admin/work-time', $admin([$adminWorkTimeController, 'index'], null));
$router->get('/admin/work-time/notifications', $admin([$adminWorkTimeNotificationController, 'index'], 'work_time_notifications.manage'));
$router->post('/admin/work-time/notifications/{id}/retry', $admin([$adminWorkTimeNotificationController, 'retry'], 'work_time_notifications.manage'));
$router->post('/admin/work-time/preview', $admin([$adminWorkTimeController, 'preview'], 'work_time_policies.manage'));
$router->post('/admin/work-time/templates', $admin([$adminWorkTimeController, 'save'], 'work_time_policies.manage'));
$router->post('/admin/work-time/templates/{id}/archive', $admin([$adminWorkTimeController, 'archiveTemplate'], 'work_time_policies.manage'));
$router->post('/admin/work-time/assignments', $admin([$adminWorkTimeController, 'save'], 'work_time_policies.manage'));
$router->post('/admin/work-time/assignments/{id}/end', $admin([$adminWorkTimeController, 'endAssignment'], 'work_time_policies.manage'));
$router->post('/admin/work-time/deviations/{id}/decide', $admin([$adminWorkTimeController, 'decide'], 'work_time_deviations.manage'));
$router->post('/admin/work-time/timesheets/{id}/pauses', $admin([$adminWorkTimeController, 'documentPauses'], 'work_time_deviations.manage'));
$router->post('/admin/work-time/pause-models/templates', $admin([$adminPausePolicyController, 'saveTemplate'], PausePolicyService::MANAGE));
$router->post('/admin/work-time/pause-models/templates/{id}/archive', $admin([$adminPausePolicyController, 'archiveTemplate'], PausePolicyService::MANAGE));
$router->post('/admin/work-time/pause-models/assignments', $admin([$adminPausePolicyController, 'assign'], PausePolicyService::MANAGE));
$router->post('/admin/work-time/pause-models/defaults', $admin([$adminPausePolicyController, 'scheduleDefault'], PausePolicyService::MANAGE));
$router->post('/admin/work-time/pause-models/{scope}/{id}/end', $admin([$adminPausePolicyController, 'endSchedule'], PausePolicyService::MANAGE));
$router->post('/admin/work-time/pause-models/preview', $admin([$adminPausePolicyController, 'preview'], null));
$router->get('/api/v1/app/me/work-time', $api([$appWorkTimeController, 'status']));
$router->post('/api/v1/app/work-time/proposals/{id}/respond', $api([$appWorkTimeController, 'respond'], 'timesheets.create'));
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
$router->get('/app/termine', [$appController, 'shell']);
$router->get('/app/urlaub', [$appController, 'shell']);
$router->get('/app/profil', [$appController, 'shell']);

$router->get('/admin', $admin([$adminController, 'dashboard'], 'dashboard.view'));
$router->get('/admin/calendar', $admin([$adminCalendarController, 'index'], null));
$router->get('/admin/calendar/month', $admin([$adminCalendarController, 'month'], null));
$router->get('/admin/calendar/day', $admin([$adminCalendarController, 'day'], null));
$router->get('/admin/appointments', $admin([$adminAppointmentController, 'index'], 'appointments.view'));
$router->get('/admin/appointments/new', $admin([$adminAppointmentController, 'new'], 'appointments.manage'));
$router->get('/admin/appointments/{id}', $admin([$adminAppointmentController, 'show'], 'appointments.view'));
$router->get('/admin/appointments/{id}/edit', $admin([$adminAppointmentController, 'edit'], 'appointments.manage'));
$router->get('/admin/appointments/{id}/dispatch', $admin([$adminAppointmentController, 'dispatchPreview'], 'appointments.dispatch'));
$router->get('/admin/appointments/{id}/dispatches', $admin([$adminAppointmentController, 'dispatchHistory'], 'appointments.dispatch'));
$router->post('/admin/appointments', $admin([$adminAppointmentController, 'create'], 'appointments.manage'));
$router->post('/admin/appointments/{id}', $admin([$adminAppointmentController, 'update'], 'appointments.manage'));
$router->post('/admin/appointments/{id}/archive', $admin([$adminAppointmentController, 'archive'], 'appointments.manage'));
$router->post('/admin/appointments/{id}/dispatch', $admin([$adminAppointmentController, 'dispatch'], 'appointments.dispatch'));
$router->post('/admin/absence-periods/preview', $admin([$adminAbsencePeriodController, 'previewCalendar'], 'timesheets.manage'));
$router->post('/admin/absence-periods', $admin([$adminAbsencePeriodController, 'createCalendar'], 'timesheets.manage'));
$router->post('/admin/vacation-periods/preview', $admin([$adminAbsencePeriodController, 'previewVacation'], 'vacation_requests.manage'));
$router->post('/admin/vacation-periods', $admin([$adminAbsencePeriodController, 'createVacation'], 'vacation_requests.manage'));
$router->get('/admin/absence-periods/{id}', $admin([$adminAbsencePeriodController, 'show'], null));
$router->post('/admin/absence-periods/{id}/preview', $admin([$adminAbsencePeriodController, 'previewExisting'], null));
$router->put('/admin/absence-periods/{id}', $admin([$adminAbsencePeriodController, 'update'], null));
$router->delete('/admin/absence-periods/{id}/archive', $admin([$adminAbsencePeriodController, 'archive'], null));
$router->get('/admin/accounting', $admin([$adminAccountingController, 'index'], 'reports.accounting.export'));
$router->get('/admin/accounting/export', $admin([$adminAccountingController, 'export'], 'reports.accounting.export'));
$router->post('/admin/accounting/closures', $admin([$adminAccountingController, 'createClosure'], 'accounting.finalize'));
$router->get('/admin/accounting/closures/{id}/download', $admin([$adminAccountingController, 'download'], 'reports.accounting.export'));
$router->get('/admin/attendance', $admin([$attendanceController, 'index'], 'attendance.view'));
$router->get('/admin/attendance/report.pdf', $admin([$attendanceController, 'report'], 'attendance.view'));
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
$router->get('/admin/time-accounts/users/{id}/cutovers', $admin([$adminTimeAccountController, 'cutovers'], 'time_accounts.view'));
$router->get('/admin/time-accounts/users/{id}/entries', $admin([$adminTimeAccountController, 'entries'], 'time_accounts.view'));
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
$router->get('/admin/project-files/{id}/preview', $admin([$adminManagementController, 'projectFilePreview'], null));
$router->get('/admin/project-files/{id}/download', $admin([$adminManagementController, 'projectFileDownload'], null));
$router->delete('/admin/project-files/{id}', $admin([$adminManagementController, 'projectFileArchive'], 'files.manage'));
$router->post('/admin/project-files/{id}/status', $admin([$adminManagementController, 'projectFileStatus'], 'files.manage'));
$router->post('/admin/projects/{id}/dispatch', $admin([$adminManagementController, 'projectDispatch'], 'projects.manage'));
$router->post('/admin/project-materials/{id}/archive', $admin([$adminManagementController, 'projectMaterialArchive'], 'projects.manage'));
$router->post('/admin/project-work-reports/{id}/archive', $admin([$adminManagementController, 'projectWorkReportArchive'], 'projects.manage'));
$router->get('/admin/users', $admin([$adminManagementController, 'users'], 'users.manage'));
$router->get('/admin/users/create', $admin([$adminManagementController, 'userCreate'], 'users.manage'));
$router->get('/admin/users/{id}/edit', $admin([$adminManagementController, 'userEdit'], 'users.manage'));
$router->post('/admin/users', $admin([$adminManagementController, 'userStore'], 'users.manage'));
$router->put('/admin/users/{id}', $admin([$adminManagementController, 'userUpdate'], 'users.manage'));
$router->delete('/admin/users/{id}', $admin([$adminManagementController, 'userArchive'], 'users.manage'));
$router->post('/admin/users/{id}/nfc-tags', $admin([$adminManagementController, 'userAssignTerminalTags'], 'users.manage'));
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
$router->post('/admin/terminals/{id}/settings', $admin([$terminalAdminController, 'saveSettings'], 'terminals.manage'));
$router->post('/admin/terminals/{id}/archive', $admin([$terminalAdminController, 'archive'], 'terminals.manage'));
$router->post('/admin/terminals/{id}/restore', $admin([$terminalAdminController, 'restore'], 'terminals.manage'));
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
if ($personnelHttp === null && env('PERSONNEL_HTTP_MODE') !== 'production_beta') {
    $router->post('/admin/settings/database', $admin([$databaseSettingsController, 'saveFromAdmin'], 'settings.database.manage'));
}
$router->get('/admin/settings/push', $admin([$adminPushController, 'show'], 'push.manage'));
$router->post('/admin/settings/push', $admin([$adminPushController, 'save'], 'push.manage'));
$router->post('/admin/settings/push/subscriptions/{id}', $admin([$adminPushController, 'updateDevice'], 'push.manage'));
$router->post('/admin/settings/push/test', $admin([$adminPushController, 'test'], 'push.manage'));

$router->post('/api/v1/auth/login', [$authController, 'login']);
$router->post('/api/v1/auth/logout', [$authController, 'logout']);
$router->get('/api/v1/auth/session', [$authController, 'session']);

$router->get('/api/v1/terminal/config', [$terminalApiController, 'config']);
$router->post('/api/v1/terminal/scan', [$terminalApiController, 'scan']);
$router->get('/api/v1/terminal/trust-bundle', [$terminalApiController, 'trustBundle']);

$router->get('/api/v1/app/me/day', $api([$appApiController, 'meDay'], 'timesheets.view_own'));
$router->get('/api/v1/app/me/timesheets', $api([$appApiController, 'meTimesheets'], 'timesheets.view_own'));
$router->get('/api/v1/app/time-account/summary', $api([$appTimeAccountController, 'summary'], 'timesheets.view_own'));
$router->get('/api/v1/app/time-account/entries', $api([$appTimeAccountController, 'entries'], 'timesheets.view_own'));
$router->get('/api/v1/app/vacation-requests', $api([$appVacationRequestController, 'index'], 'timesheets.view_own'));
$router->get('/api/v1/app/vacation-requests/preview', $api([$appVacationRequestController, 'preview'], 'timesheets.view_own'));
$router->post('/api/v1/app/vacation-requests', $api([$appVacationRequestController, 'store'], 'timesheets.create'));
$router->post('/api/v1/app/vacation-requests/{id}/cancel', $api([$appVacationRequestController, 'cancel'], 'timesheets.create'));
$router->get('/api/v1/app/push/status', $api([$appPushController, 'status']));
$router->get('/api/v1/app/appointments', $api([$appAppointmentsController, 'index']));
$router->get('/api/v1/app/appointments/{id}', $api([$appAppointmentsController, 'show']));
$router->get('/api/v1/app/appointments/{id}/calendar\.ics', $api([$appAppointmentsController, 'calendar'], nativeDownload: true));
$router->post('/api/v1/app/appointments/{id}/acknowledge', $api([$appAppointmentsController, 'acknowledge']));
$router->post('/api/v1/app/push/subscriptions', $api([$appPushController, 'store'], 'push.receive'));
$router->delete('/api/v1/app/push/subscriptions/{id}', $api([$appPushController, 'disable'], 'push.receive'));
$router->post('/api/v1/app/push/test', $api([$appPushController, 'test'], 'push.receive'));
$router->post('/api/v1/app/timesheets/sync', $api([$appTimesheetController, 'sync'], 'timesheets.create'));
$router->get('/api/v1/app/projects/{id}/files', $api([$appProjectAttachmentController, 'index'], 'files.view'));
$router->post('/api/v1/app/projects/{id}/files', $api([$appProjectAttachmentController, 'upload'], 'files.upload'));
$router->get('/api/v1/app/project-files/{id}/download', $api([$appProjectAttachmentController, 'download'], 'files.view', nativeDownload: true));
$router->get('/api/v1/app/projects/{id}/materials', $api([$appProjectMaterialController, 'index']));
$router->post('/api/v1/app/projects/{id}/materials', $api([$appProjectMaterialController, 'store']));
$router->delete('/api/v1/app/project-materials/{id}', $api([$appProjectMaterialController, 'archive']));
$router->get('/api/v1/app/projects/{id}/work-reports', $api([$appProjectWorkReportController, 'index']));
$router->post('/api/v1/app/projects/{id}/work-reports', $api([$appProjectWorkReportController, 'store']));
$router->delete('/api/v1/app/project-work-reports/{id}', $api([$appProjectWorkReportController, 'archive']));
$router->get('/api/v1/app/timesheets/{id}/files', $api([$appTimesheetAttachmentController, 'index'], 'timesheets.view_own'));
$router->post('/api/v1/app/timesheets/{id}/files', $api([$appTimesheetAttachmentController, 'upload'], 'timesheets.create'));
$router->get('/api/v1/app/timesheet-files/{id}/download', $api([$appTimesheetAttachmentController, 'download'], 'timesheets.view_own', nativeDownload: true));
$router->delete('/api/v1/app/timesheet-files/{id}', $api([$appTimesheetAttachmentController, 'archive'], 'timesheets.create'));
$router->get('/api/v1/app/timesheets/{id}/signature', $api([$appTimesheetSignatureController, 'status'], 'timesheets.view_own'));
$router->post('/api/v1/app/timesheets/{id}/signature', $api([$appTimesheetSignatureController, 'store'], 'timesheets.create'));
$router->get('/api/v1/app/timesheet-signatures/{id}/image', $api([$appTimesheetSignatureController, 'image'], 'timesheets.view_own', nativeDownload: true));

$router->get('/api/v1/attendance/today', $api([$attendanceController, 'today'], 'attendance.view'));
$router->get('/api/v1/dashboard/overview', $api([new DashboardController($dashboardService), 'overview'], 'dashboard.view'));
$router->get('/api/v1/dashboard/charts', $api([new DashboardController($dashboardService), 'charts'], 'dashboard.view'));
$router->get('/api/v1/users/{id}', $api([$userController, 'show'], 'users.manage'));
$router->post('/api/v1/users', $api([$userController, 'store'], 'users.manage'));
$router->put('/api/v1/users/{id}', $api([$userController, 'update'], 'users.manage'));
$router->delete('/api/v1/users/{id}', $api([$userController, 'archive'], 'users.manage'));
$router->get('/api/v1/users', $api([$userController, 'index'], 'users.manage'));
$router->get('/api/v1/roles/{id}', $api([$roleController, 'show'], 'roles.manage'));
$router->post('/api/v1/roles', $api([$roleController, 'store'], 'roles.manage'));
$router->put('/api/v1/roles/{id}', $api([$roleController, 'update'], 'roles.manage'));
$router->delete('/api/v1/roles/{id}', $api([$roleController, 'archive'], 'roles.manage'));
$router->get('/api/v1/roles', $api([$roleController, 'index'], 'roles.manage'));
$router->get('/api/v1/projects/{id}', $api([$projectController, 'show'], 'projects.view'));
$router->get('/api/v1/projects', $api([$projectController, 'index'], 'projects.view'));
$router->post('/api/v1/projects', $api([$projectController, 'store'], 'projects.manage'));
$router->put('/api/v1/projects/{id}', $api([$projectController, 'update'], 'projects.manage'));
$router->delete('/api/v1/projects/{id}', $api([$projectController, 'archive'], 'projects.manage'));
$router->get('/api/v1/assets/{id}', $api([new AssetController($assetService), 'show'], 'assets.manage'));
$router->post('/api/v1/assets', $api([new AssetController($assetService), 'store'], 'assets.manage'));
$router->put('/api/v1/assets/{id}', $api([new AssetController($assetService), 'update'], 'assets.manage'));
$router->delete('/api/v1/assets/{id}', $api([new AssetController($assetService), 'archive'], 'assets.manage'));
$router->get('/api/v1/assets', $api([new AssetController($assetService), 'index'], 'assets.manage'));
$router->get('/api/v1/timesheets', $api([new TimesheetController($timesheetService), 'index'], 'timesheets.manage'));
$router->post('/api/v1/timesheets/calculate', $api([new TimesheetController($timesheetService, $authService), 'calculate'], 'timesheets.create'));
$router->get('/api/v1/projects/{id}/files', $api([$fileController, 'listProjectFiles'], 'files.view'));
$router->post('/api/v1/projects/{id}/files', $api([$fileController, 'uploadProject'], 'files.upload'));
$router->delete('/api/v1/project-files/{id}', $api([$fileController, 'archiveProjectFile'], 'files.manage'));
$router->get('/api/v1/assets/{id}/files', $api([$fileController, 'listAssetFiles'], 'assets.manage'));
$router->post('/api/v1/assets/{id}/files', $api([$fileController, 'uploadAsset'], 'assets.manage'));
$router->delete('/api/v1/asset-files/{id}', $api([$fileController, 'archiveAssetFile'], 'assets.manage'));
$router->get('/api/v1/reports/export', $api([new ReportController($reportService), 'export'], 'reports.export'));
$router->get('/api/v1/reports/accounting-export', $api([$accountingExportController, 'export'], 'reports.accounting.export'));
$router->get('/api/v1/settings/company', [$companySettingsController, 'publicProfile']);
$router->get('/api/v1/settings/company/logo', [$companySettingsController, 'publicLogo']);
$router->get('/api/v1/settings/database', $api([$databaseSettingsController, 'show'], 'settings.database.manage'));
if ($personnelHttp === null && env('PERSONNEL_HTTP_MODE') !== 'production_beta') {
    $router->post('/api/v1/settings/database', $api([$databaseSettingsController, 'store'], 'settings.database.manage'));
}
$router->get('/api/v1/system/backup/export', $api([$backupController, 'export'], 'settings.database.manage'));
$router->post('/api/v1/system/backup/import/validate', $api([$backupController, 'validateImport'], 'settings.database.manage'));

return [$request, $router];
