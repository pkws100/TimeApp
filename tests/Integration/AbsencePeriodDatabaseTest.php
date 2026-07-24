<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\Timesheets\AbsencePeriodService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetAuditService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetDayConflictService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Domain\Vacation\VacationRequestService;
use App\Http\Controllers\AdminAbsencePeriodController;
use App\Http\Controllers\AdminVacationRequestController;
use App\Http\Request;
use App\Presentation\Admin\AdminView;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Support\MariaDbTestCase;

final class AbsencePeriodDatabaseTest extends MariaDbTestCase
{
    public function testCreateAndDateDiffKeepStableRowsAndArchiveRemovedDays(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-AP', 'email' => 'admin-ap@example.test']);
        $userId = $this->createUser(['target_hours_mode' => 'week', 'target_hours_week' => 40]);
        $service = $this->service();

        $preview = $service->preview([
            'user_id' => $userId,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-07',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
        ]);
        self::assertSame(5, $preview['booked_day_count']);
        self::assertCount(2, $preview['skipped_dates']);

        $period = $this->createPeriod($service, [
            'user_id' => $userId,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-07',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'note' => 'AU',
            'change_reason' => 'Krankheit nacherfasst',
        ], $adminId);
        $before = $this->activeRows((int) $period['id']);
        self::assertCount(5, $before);
        self::assertSame([480, 480, 480, 480, 480], array_map('intval', array_column($before, 'credited_minutes')));

        $updatePayload = [
            'lock_version' => (int) $period['lock_version'],
            'date_from' => '2026-06-02',
            'date_to' => '2026-06-08',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'note' => 'AU',
            'change_reason' => 'Datumsfehler korrigiert',
        ];
        $updatePreview = $service->preview($updatePayload, (int) $period['id']);
        $updated = $service->update((int) $period['id'], [
            ...$updatePayload,
            'preview_token' => $updatePreview['preview_token'],
        ], $adminId);
        $after = $this->activeRows((int) $period['id']);

        self::assertSame(2, (int) $updated['lock_version']);
        self::assertSame(
            array_column(array_slice($before, 1), 'id'),
            array_column(array_slice($after, 0, 4), 'id')
        );
        self::assertSame('2026-06-08', (string) $after[4]['work_date']);
        self::assertSame(1, (int) $this->connection()->fetchColumn(
            'SELECT is_deleted FROM timesheets WHERE id = :id',
            ['id' => (int) $before[0]['id']]
        ));
        self::assertSame(2, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM absence_period_change_log WHERE absence_period_id = :id',
            ['id' => (int) $period['id']]
        ));
        self::assertSame(7, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM timesheet_change_log
             WHERE timesheet_id IN (SELECT id FROM timesheets WHERE absence_period_id = :id)',
            ['id' => (int) $period['id']]
        ));

        $bookingService = new AdminBookingService($this->connection(), new TimesheetCalculator());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Gesamten Zeitraum');
        $bookingService->update((int) $after[0]['id'], ['note' => 'Nicht erlaubt'], $adminId, 'Einzelkorrektur');
    }

    public function testConflictStopsWholeCreateAndStaleVersionStopsUpdate(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-CF', 'email' => 'admin-cf@example.test']);
        $userId = $this->createUser(['target_hours_mode' => 'week', 'target_hours_week' => 40]);
        $projectId = $this->createProject();
        $bookingService = new AdminBookingService($this->connection(), new TimesheetCalculator());
        $bookingService->createManual([
            'user_id' => $userId,
            'project_id' => $projectId,
            'work_date' => '2026-06-03',
            'entry_type' => 'work',
            'start_time' => '07:00',
            'end_time' => '15:30',
            'break_minutes' => 30,
            'change_reason' => 'Bestehende Arbeit',
        ], $adminId);
        $service = $this->service();
        $preview = $service->preview([
            'user_id' => $userId,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-05',
            'entry_type' => 'absent',
            'absence_reason_code' => 'unexcused_absence',
        ]);

        self::assertNotEmpty($preview['blockers']);
        try {
            $this->createPeriod($service, [
                'user_id' => $userId,
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-05',
                'entry_type' => 'absent',
                'absence_reason_code' => 'unexcused_absence',
                'change_reason' => 'Fehlzeit',
            ], $adminId);
            self::fail('Konflikt muss die gesamte Anlage stoppen.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM absence_periods'));
        }

        $period = $this->createPeriod($service, [
            'user_id' => $userId,
            'date_from' => '2026-06-08',
            'date_to' => '2026-06-08',
            'entry_type' => 'absent',
            'absence_reason_code' => 'unexcused_absence',
            'change_reason' => 'Fehlzeit',
        ], $adminId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zwischenzeitlich');
        $service->update((int) $period['id'], [
            'lock_version' => 99,
            'date_from' => '2026-06-08',
            'date_to' => '2026-06-09',
            'entry_type' => 'absent',
            'absence_reason_code' => 'unexcused_absence',
            'change_reason' => 'Veraltete Aenderung',
        ], $adminId);
    }

    public function testVacationRequestApprovalCreatesAndCancellationArchivesPeriod(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-VR', 'email' => 'admin-vr@example.test']);
        $userId = $this->createUser(['target_hours_mode' => 'week', 'target_hours_week' => 40]);
        $this->connection()->execute(
            'INSERT INTO vacation_requests (
                user_id, date_from, date_to, day_count, status, employee_note,
                requested_at, created_at, updated_at, is_deleted
             ) VALUES (
                :user_id, "2026-06-01", "2026-06-03", 3, "pending", "Sommerurlaub",
                NOW(), NOW(), NOW(), 0
             )',
            ['user_id' => $userId]
        );
        $requestId = $this->connection()->lastInsertId();
        $service = $this->service();
        $request = $service->approveVacationRequest($requestId, $adminId, 'Genehmigt');
        $period = $service->findByVacationRequest($requestId);

        self::assertSame('approved', (string) $request['status']);
        self::assertNotNull($period);
        self::assertSame(3, (int) $period['booked_day_count']);
        self::assertSame(3, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM timesheets WHERE absence_period_id = :id AND is_deleted = 0',
            ['id' => (int) $period['id']]
        ));

        $service->archive(
            (int) $period['id'],
            $adminId,
            'Urlaub vollstaendig storniert',
            (int) ($period['lock_version'] ?? 0)
        );
        self::assertSame('cancelled', (string) $this->connection()->fetchColumn(
            'SELECT status FROM vacation_requests WHERE id = :id',
            ['id' => $requestId]
        ));
        self::assertSame(0, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM timesheets WHERE absence_period_id = :id AND is_deleted = 0',
            ['id' => (int) $period['id']]
        ));
    }

    public function testRejectsRangesLongerThanOneYear(): void
    {
        $userId = $this->createUser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximal 366');
        $this->service()->preview([
            'user_id' => $userId,
            'date_from' => '2026-01-01',
            'date_to' => '2070-12-12',
            'entry_type' => 'vacation',
            'absence_reason_code' => 'vacation_paid',
        ]);
    }

    public function testWeekendRequestOverlapAndStaleArchiveAreRejected(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-OV', 'email' => 'admin-ov@example.test']);
        $userId = $this->createUser(['target_hours_mode' => 'week', 'target_hours_week' => 40]);
        $this->connection()->execute(
            'INSERT INTO vacation_requests (
                user_id, date_from, date_to, day_count, status,
                requested_at, created_at, updated_at, is_deleted
             ) VALUES (
                :user_id, "2026-06-07", "2026-06-07", 0, "pending",
                NOW(), NOW(), NOW(), 0
             )',
            ['user_id' => $userId]
        );
        $requestId = $this->connection()->lastInsertId();
        $service = $this->service();
        $preview = $service->preview([
            'user_id' => $userId,
            'date_from' => '2026-06-05',
            'date_to' => '2026-06-08',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'vacation_request_id' => $requestId,
        ]);

        self::assertContains('vacation_request_overlap', array_column($preview['blockers'], 'code'));
        $this->connection()->execute('UPDATE vacation_requests SET status = "cancelled" WHERE user_id = :user_id', [
            'user_id' => $userId,
        ]);
        $period = $this->createPeriod($service, [
            'user_id' => $userId,
            'date_from' => '2026-06-05',
            'date_to' => '2026-06-08',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'change_reason' => 'Archivversion pruefen',
        ], $adminId);

        try {
            $service->archive((int) $period['id'], $adminId, 'Veraltete Archivierung', 99);
            self::fail('Eine veraltete Archivierung muss abgewiesen werden.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('zwischenzeitlich', $exception->getMessage());
        }

        self::assertSame(2, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM timesheets WHERE absence_period_id = :id AND is_deleted = 0',
            ['id' => (int) $period['id']]
        ));
    }

    public function testClosureAndCutoverBlockWholePeriod(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-LK', 'email' => 'admin-lk@example.test']);
        $userId = $this->createUser(['target_hours_mode' => 'week', 'target_hours_week' => 40]);
        $service = $this->service();
        $this->connection()->execute(
            'INSERT INTO accounting_closures (
                closure_number, closure_type, status, period_start, period_end,
                user_id, snapshot_hash, item_count, total_net_minutes,
                created_by_user_id, finalized_by_user_id, created_at, finalized_at
             ) VALUES (
                "ABS-CLOSED-1", "month", "final", "2026-06-01", "2026-06-30",
                :user_id, "", 0, 0, :admin_id, :finalized_by, NOW(), NOW()
             )',
            ['user_id' => $userId, 'admin_id' => $adminId, 'finalized_by' => $adminId]
        );
        $payload = [
            'user_id' => $userId,
            'date_from' => '2026-06-08',
            'date_to' => '2026-06-10',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'change_reason' => 'Abschlusspruefung',
        ];
        $preview = $service->preview($payload);
        self::assertContains('accounting_lock', array_column($preview['blockers'], 'code'));

        try {
            $service->create([...$payload, 'preview_token' => $preview['preview_token']], $adminId);
            self::fail('Ein Abschluss muss die gesamte Anlage sperren.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('festgeschrieben', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM absence_periods'));

        $this->connection()->execute(
            'UPDATE accounting_closures
             SET closure_number = "ZK-ABS-1", source_type = "employee_account_cutover", source_id = 999
             WHERE closure_number = "ABS-CLOSED-1"'
        );
        $cutoverPreview = $service->preview($payload);
        self::assertStringContainsString('Zeitkonto-Stichtags', (string) $cutoverPreview['blockers'][0]['message']);
        try {
            $service->create([...$payload, 'preview_token' => $cutoverPreview['preview_token']], $adminId);
            self::fail('Ein Zeitkonto-Stichtag muss die gesamte Anlage sperren.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Zeitkonto-Stichtags', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM absence_periods'));
    }

    public function testPaidToUnpaidReasonChangeKeepsIdsAndRemovesCredits(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-TY', 'email' => 'admin-ty@example.test']);
        $userId = $this->createUser(['target_hours_mode' => 'week', 'target_hours_week' => 40]);
        $service = $this->service();
        $period = $this->createPeriod($service, [
            'user_id' => $userId,
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-16',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'change_reason' => 'Bezahlte Krankheit',
        ], $adminId);
        $before = $this->activeRows((int) $period['id']);
        $payload = [
            'lock_version' => (int) $period['lock_version'],
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-16',
            'entry_type' => 'absent',
            'absence_reason_code' => 'unpaid_leave',
            'change_reason' => 'Als unbezahlte Fehlzeit korrigiert',
        ];
        $preview = $service->preview($payload, (int) $period['id']);
        $service->update((int) $period['id'], [
            ...$payload,
            'preview_token' => $preview['preview_token'],
        ], $adminId);
        $after = $this->connection()->fetchAll(
            'SELECT id, entry_type, absence_reason_code, credited_minutes
             FROM timesheets
             WHERE absence_period_id = :id AND is_deleted = 0
             ORDER BY work_date',
            ['id' => (int) $period['id']]
        );

        self::assertSame(array_column($before, 'id'), array_column($after, 'id'));
        self::assertSame(['absent', 'absent'], array_column($after, 'entry_type'));
        self::assertSame(['unpaid_leave', 'unpaid_leave'], array_column($after, 'absence_reason_code'));
        self::assertSame([0, 0], array_map('intval', array_column($after, 'credited_minutes')));
    }

    public function testCalendarVacationNeedsBothCalendarAndVacationPermissionsIncludingDefaultAndTypeChange(): void
    {
        $adminId = $this->createUser(['employee_number' => 'ADMIN-PM', 'email' => 'admin-pm@example.test']);
        $employeeId = $this->createUser(['target_hours_mode' => 'week', 'target_hours_week' => 40]);
        $roleId = $this->createRoleWithPermissions($adminId, ['timesheets.manage', 'timesheets.view']);
        $_SESSION['auth']['user_id'] = $adminId;
        $_SESSION['_csrf_token'] = 'absence-permission-token';
        $service = $this->service();
        $auth = new AuthService($this->connection(), new PermissionMatrix([], []));
        $controller = new AdminAbsencePeriodController($service, $auth, new CsrfService());
        $baseBody = [
            'csrf_token' => 'absence-permission-token',
            'user_id' => $employeeId,
            'date_from' => '2026-06-22',
            'date_to' => '2026-06-22',
            'absence_reason_code' => 'vacation_paid',
        ];

        self::assertSame(403, $controller->previewCalendar(
            new Request('POST', '/admin/absence-periods/preview', [], $baseBody, [], [], [])
        )->status());
        self::assertSame(200, $controller->previewCalendar(
            new Request('POST', '/admin/absence-periods/preview', [], [
                ...$baseBody,
                'entry_type' => 'sick',
                'absence_reason_code' => 'sick_paid',
            ], [], [], [])
        )->status());

        $period = $this->createPeriod($service, [
            'user_id' => $employeeId,
            'date_from' => '2026-06-23',
            'date_to' => '2026-06-23',
            'entry_type' => 'sick',
            'absence_reason_code' => 'sick_paid',
            'change_reason' => 'Krankheit',
        ], $adminId);
        self::assertSame(403, $controller->previewExisting(
            new Request('POST', '/admin/absence-periods/' . $period['id'] . '/preview', [], [
                'csrf_token' => 'absence-permission-token',
                'lock_version' => $period['lock_version'],
                'date_from' => '2026-06-23',
                'date_to' => '2026-06-23',
                'entry_type' => 'vacation',
                'absence_reason_code' => 'vacation_paid',
            ], [], [], []),
            ['id' => (string) $period['id']]
        )->status());

        $this->connection()->execute(
            'INSERT INTO permissions (code, label, scope, created_at)
             VALUES ("vacation_requests.manage", "Urlaub verwalten", "timesheets", NOW())
             ON DUPLICATE KEY UPDATE label = VALUES(label)'
        );
        $this->connection()->execute(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT :role_id, id FROM permissions WHERE code = "vacation_requests.manage"',
            ['role_id' => $roleId]
        );
        self::assertSame(200, $controller->previewCalendar(
            new Request('POST', '/admin/absence-periods/preview', [], $baseBody, [], [], [])
        )->status());
        self::assertSame(200, $controller->previewExisting(
            new Request('POST', '/admin/absence-periods/' . $period['id'] . '/preview', [], [
                'csrf_token' => 'absence-permission-token',
                'lock_version' => $period['lock_version'],
                'date_from' => '2026-06-23',
                'date_to' => '2026-06-23',
                'entry_type' => 'vacation',
                'absence_reason_code' => 'vacation_paid',
            ], [], [], []),
            ['id' => (string) $period['id']]
        )->status());
    }

    public function testDirectVacationListsRenderManageArchiveOnlyAndReadOnlyPermissions(): void
    {
        $archiveUserId = $this->createUser(['employee_number' => 'ADMIN-AR', 'email' => 'admin-ar@example.test']);
        $manageUserId = $this->createUser(['employee_number' => 'ADMIN-MG', 'email' => 'admin-mg@example.test']);
        $readUserId = $this->createUser(['employee_number' => 'ADMIN-RO', 'email' => 'admin-ro@example.test']);
        $this->createRoleWithPermissions($archiveUserId, ['vacation_requests.manage', 'timesheets.archive']);
        $this->createRoleWithPermissions($manageUserId, ['vacation_requests.manage', 'timesheets.manage']);
        $this->createRoleWithPermissions($readUserId, ['vacation_requests.manage']);
        $auth = new AuthService($this->connection(), new PermissionMatrix([], []));
        $calendar = new CalendarPolicyService($this->connection());
        $controller = new AdminVacationRequestController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            new VacationRequestService($this->connection(), $calendar, new TimesheetWriteGuard($this->connection())),
            new TimeAccountService($this->connection(), $calendar),
            new UserService($this->connection()),
            $auth,
            new CsrfService(),
            $this->service()
        );
        $periodController = new AdminAbsencePeriodController($this->service(), $auth, new CsrfService());
        $canViewPeriod = new ReflectionMethod($periodController, 'canView');
        $canViewPeriod->setAccessible(true);
        $canManagePeriod = new ReflectionMethod($periodController, 'canManage');
        $canManagePeriod->setAccessible(true);
        $canArchivePeriod = new ReflectionMethod($periodController, 'canArchive');
        $canArchivePeriod->setAccessible(true);
        $period = [[
            'id' => 88,
            'employee_name' => 'Erika Beispiel',
            'date_from' => '2026-07-27',
            'date_to' => '2026-07-31',
            'booked_day_count' => 5,
            'source' => 'admin_calendar',
        ]];
        $rows = new ReflectionMethod($controller, 'directPeriodRows');
        $rows->setAccessible(true);
        $cards = new ReflectionMethod($controller, 'directPeriodCards');
        $cards->setAccessible(true);

        $_SESSION['auth']['user_id'] = $archiveUserId;
        self::assertTrue((bool) $canViewPeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        self::assertFalse((bool) $canManagePeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        self::assertTrue((bool) $canArchivePeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        foreach ([$rows, $cards] as $renderer) {
            $html = (string) $renderer->invoke($controller, $period);
            self::assertStringContainsString('data-absence-period-edit="88"', $html);
            self::assertStringContainsString('Zeitraum oeffnen', $html);
        }

        $_SESSION['auth']['user_id'] = $manageUserId;
        self::assertTrue((bool) $canViewPeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        self::assertTrue((bool) $canManagePeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        self::assertFalse((bool) $canArchivePeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        foreach ([$rows, $cards] as $renderer) {
            $html = (string) $renderer->invoke($controller, $period);
            self::assertStringContainsString('data-absence-period-edit="88"', $html);
            self::assertStringContainsString('Zeitraum bearbeiten', $html);
        }

        $_SESSION['auth']['user_id'] = $readUserId;
        self::assertTrue((bool) $canViewPeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        self::assertFalse((bool) $canManagePeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        self::assertFalse((bool) $canArchivePeriod->invoke($periodController, [
            ...$period[0],
            'entry_type' => 'vacation',
        ]));
        foreach ([$rows, $cards] as $renderer) {
            $html = (string) $renderer->invoke($controller, $period);
            self::assertStringNotContainsString('data-absence-period-edit="88"', $html);
            self::assertStringContainsString('Nur Lesen', $html);
        }
    }

    private function service(): AbsencePeriodService
    {
        $calendar = new CalendarPolicyService($this->connection());
        $writeGuard = new TimesheetWriteGuard($this->connection());

        return new AbsencePeriodService(
            $this->connection(),
            $calendar,
            new DailyTargetService($calendar),
            $writeGuard,
            new TimesheetDayConflictService($this->connection()),
            new TimesheetAuditService($this->connection())
        );
    }

    private function activeRows(int $periodId): array
    {
        return $this->connection()->fetchAll(
            'SELECT id, work_date, credited_minutes, is_deleted
             FROM timesheets
             WHERE absence_period_id = :absence_period_id
               AND COALESCE(is_deleted, 0) = 0
             ORDER BY work_date ASC',
            ['absence_period_id' => $periodId]
        );
    }

    private function createPeriod(AbsencePeriodService $service, array $payload, int $adminId): array
    {
        $preview = $service->preview($payload);

        return $service->create([
            ...$payload,
            'preview_token' => $preview['preview_token'],
        ], $adminId);
    }

    private function createRoleWithPermissions(int $userId, array $permissions): int
    {
        $this->connection()->execute(
            'INSERT INTO roles (slug, name, description, is_system_role, created_at, updated_at, is_deleted)
             VALUES (:slug, "Abwesenheitsrechte", NULL, 0, NOW(), NOW(), 0)',
            ['slug' => 'absence-permission-test-' . $userId]
        );
        $roleId = $this->connection()->lastInsertId();
        $this->connection()->execute(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $userId, 'role_id' => $roleId]
        );
        foreach ($permissions as $permission) {
            $this->connection()->execute(
                'INSERT INTO permissions (code, label, scope, created_at)
                 VALUES (:code, :label, "timesheets", NOW())
                 ON DUPLICATE KEY UPDATE label = VALUES(label)',
                ['code' => $permission, 'label' => $permission]
            );
            $this->connection()->execute(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT :role_id, id FROM permissions WHERE code = :permission',
                ['role_id' => $roleId, 'permission' => $permission]
            );
        }

        return $roleId;
    }
}
