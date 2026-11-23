#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseConnection;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Personnel\PersonnelEventService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\PausePolicyService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePresenceService;
use Tests\Support\MariaDbScratchConfig;

require_once __DIR__ . '/../bootstrap/autoload.php';
date_default_timezone_set('Europe/Berlin');

$databaseName = 'timeapp_ui_' . bin2hex(random_bytes(6));
$temporaryPrefix = sys_get_temp_dir() . '/timeapp-ui-' . bin2hex(random_bytes(6));
$phinxFile = $temporaryPrefix . '-phinx.php';
$overrideFile = $temporaryPrefix . '-database.php';
$serverLog = $temporaryPrefix . '-server.log';
$disabledServerLog = $temporaryPrefix . '-server-disabled.log';
$outputDirectory = $temporaryPrefix . '-playwright';
$serverProcess = null;
$disabledServerProcess = null;
$serverPdo = null;
$exitCode = 1;
$accountYear = (int) date('Y');
$closureYear = (int) date('Y') + 1;
$closureDateFrom = $closureYear . '-12-29';
$closureDateTo = ($closureYear + 1) . '-01-03';
$restoreDateValue = new DateTimeImmutable($accountYear . '-01-08');
while ((int) $restoreDateValue->format('N') !== 1) {
    $restoreDateValue = $restoreDateValue->modify('+1 day');
}
$restoreDate = $restoreDateValue->format('Y-m-d');
$appointmentSpec = 'tests/e2e/appointments.real.spec.js';
$selectedSpec = trim((string) getenv('UI_REAL_TEST_SPEC'));
$withAppointments = $selectedSpec === '' || $selectedSpec === $appointmentSpec;
$vacationDate = null;
$vacationYear = null;
$absenceEarliest = new DateTimeImmutable('+35 days midnight');
// Make only the extension cross into January, so every run exercises warning confirmation.
$absenceStartValue = new DateTimeImmutable($absenceEarliest->format('Y') . '-12-21');
if ($absenceStartValue < $absenceEarliest) {
    $absenceStartValue = $absenceStartValue->modify('+1 year');
}
while ((int) $absenceStartValue->format('N') > 5) {
    $absenceStartValue = $absenceStartValue->modify('+1 day');
}
$absenceDateFrom = $absenceStartValue->format('Y-m-d');
$absenceDateShortTo = $absenceStartValue->modify('+1 day')->format('Y-m-d');
$absenceDateTo = $absenceStartValue->modify('+6 days')->format('Y-m-d');
$absenceDateExtendedTo = ((int) $absenceStartValue->format('Y') + 1) . '-01-07';
$absenceConflictValue = $absenceStartValue->modify('+21 days');
while ((int) $absenceConflictValue->format('N') > 5) {
    $absenceConflictValue = $absenceConflictValue->modify('+1 day');
}
$absenceConflictDate = $absenceConflictValue->format('Y-m-d');

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $removeTree($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
};

$copyTree = static function (string $source, string $destination) use (&$copyTree): void {
    if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('UI-Diagnoseverzeichnis konnte nicht erstellt werden.');
    }
    foreach (new FilesystemIterator($source) as $item) {
        if ($item->isLink()) { continue; }
        $target = $destination . '/' . $item->getFilename();
        if ($item->isDir()) {
            $copyTree($item->getPathname(), $target);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('UI-Diagnoseartefakt konnte nicht gesichert werden.');
        } else {
            chmod($target, 0600);
        }
    }
};

$run = static function (array $command, ?array $environment = null): string {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Testprozess konnte nicht gestartet werden: ' . implode(' ', $command));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $output = $stdout . $stderr;

    if ($code !== 0) {
        throw new RuntimeException($output !== '' ? $output : 'Testprozess endete mit Status ' . $code . '.');
    }

    return $output;
};

try {
    if (!str_starts_with($databaseName, 'timeapp_ui_')) {
        throw new RuntimeException('Unsicherer Scratch-Datenbankname wurde abgewiesen.');
    }
    $serverPdo = MariaDbScratchConfig::connectServer();
    $serverPdo->exec('CREATE DATABASE ' . MariaDbScratchConfig::quoteIdentifier($databaseName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connectionConfig = MariaDbScratchConfig::connection($databaseName);
    $phinxConfig = [
        'paths' => ['migrations' => base_path('migrations'), 'seeds' => base_path('seeds')],
        'environments' => [
            'default_migration_table' => 'phinxlog',
            'default_environment' => 'test',
            'test' => MariaDbScratchConfig::phinxEnvironment($databaseName),
        ],
        'version_order' => 'creation',
    ];

    if (file_put_contents($phinxFile, "<?php\nreturn " . var_export($phinxConfig, true) . ";\n") === false
        || file_put_contents($overrideFile, "<?php\nreturn " . var_export($connectionConfig, true) . ";\n") === false) {
        throw new RuntimeException('Die temporaere UI-Testkonfiguration konnte nicht geschrieben werden.');
    }
    chmod($phinxFile, 0600);
    chmod($overrideFile, 0600);

    $phinx = base_path('vendor/bin/phinx');
    fwrite(STDOUT, $run([PHP_BINARY, $phinx, 'migrate', '-c', $phinxFile, '-e', 'test']));
    fwrite(STDOUT, $run([PHP_BINARY, $phinx, 'seed:run', '-c', $phinxFile, '-e', 'test', '-s', 'InitialReferenceSeeder']));

    $connection = new DatabaseConnection($connectionConfig);
    $password = 'Ui-' . bin2hex(random_bytes(12));
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $userIds = [];
    foreach ([
        ['UI-ADMIN', 'UI', 'Administrator', 'ui-admin@example.test', 'administrator'],
        ['UI-EMPLOYEE', 'UI', 'Mitarbeiter', 'ui-employee@example.test', 'mitarbeiter'],
        ['UI-MATERIAL', 'UI', 'Materialerfassung', 'ui-material@example.test', 'mitarbeiter'],
        ['UI-PAUSE-CONTROLS', 'UI', 'Pausenerfassung', 'ui-pause-controls@example.test', 'mitarbeiter'],
        ['UI-PAGINATION', 'UI', 'Pagination', 'ui-pagination@example.test', 'mitarbeiter'],
        ['UI-WORKTIME-CONTINUE', 'UI', 'Weiterarbeit', 'ui-worktime-continue@example.test', 'mitarbeiter'],
        ['UI-WORKTIME-CONFIRM', 'UI', 'Endbestaetigung', 'ui-worktime-confirm@example.test', 'mitarbeiter'],
        ['UI-WORKTIME-HISTORY', 'UI', 'Historische Kernzeit', 'ui-worktime-history@example.test', 'mitarbeiter'],
        ['UI-HARDENING-EMPLOYEE', 'UI', 'Hardening Ablauf', 'ui-hardening-employee@example.test', 'mitarbeiter'],
        ['UI-HARDENING-PAUSE', 'UI', 'Hardening Pausennachweis', 'ui-hardening-pause@example.test', 'mitarbeiter'],
        ['UI-HARDENING-OFF', 'UI', 'Hardening Deaktiviert', 'ui-hardening-off@example.test', 'mitarbeiter'],
    ] as [$number, $firstName, $lastName, $email, $role]) {
        $connection->execute(
            'INSERT INTO users (
                employee_number, first_name, last_name, email, password_hash, employment_status,
                target_hours_month, target_hours_mode, target_hours_week, workdays_mask,
                vacation_days_year, vacation_carryover_days, time_tracking_required,
                created_at, updated_at, is_deleted
             ) VALUES (
                :employee_number, :first_name, :last_name, :email, :password_hash, "active",
                160, "month", NULL, "1,2,3,4,5", 30, 0, 1, NOW(), NOW(), 0
             )',
            [
                'employee_number' => $number,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'password_hash' => $passwordHash,
            ]
        );
        $userId = $connection->lastInsertId();
        $userIds[$email] = $userId;
        $connection->execute(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT :user_id, id FROM roles WHERE slug = :role_slug LIMIT 1',
            ['user_id' => $userId, 'role_slug' => $role]
        );
    }

    $adminId = $userIds['ui-admin@example.test'];
    $pauseEmployeeId = $userIds['ui-pause-controls@example.test'];
    $pauseShiftStart = new DateTimeImmutable('-3 hours');
    $pausePolicies = new PausePolicyService($connection);
    $pauseTemplate = $pausePolicies->createTemplate(['name' => 'UI Pausenmodell Stempeluhr', 'policy' => ['capture_mode' => 'stamped']], $adminId);
    $pauseAssignment = $pausePolicies->assign([
        'user_id' => $pauseEmployeeId,
        'policy_version_id' => $pauseTemplate['version']['id'],
        'effective_from' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d'),
        'reason' => 'Isolierter Browser-Test für tatsächliche Pausen',
    ], $adminId);
    $connection->execute('UPDATE employee_pause_policy_assignments SET effective_from = :fixture_date WHERE id = :id', [
        'fixture_date' => $pauseShiftStart->format('Y-m-d'), 'id' => $pauseAssignment['id'],
    ]);
    $employeeId = $userIds['ui-employee@example.test'];
    $paginationUserId = $userIds['ui-pagination@example.test'];
    // This feature-enabled server contains only synthetic data. Existing UI users
    // deliberately receive no assignment, exercising their unchanged workflows.
    $workTimeNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $workTimeStart = $workTimeNow->modify('-3 hours')->setTime((int) $workTimeNow->modify('-3 hours')->format('H'), (int) $workTimeNow->format('i'));
    $workTimeEnd = $workTimeStart->modify('+2 hours');
    $workTimePolicies = new WorkTimePolicyService($connection, true);
    $workTimeTemplate = $workTimePolicies->createTemplate(['name' => 'UI Kernarbeitszeit', 'policy' => [
        'windows' => array_fill(1, 7, [['start' => $workTimeStart->format('H:i'), 'end' => $workTimeEnd->format('H:i')]]),
        'early_check_in_mode' => 'work_started', 'checkout_proposals_enabled' => true,
    ]], $adminId);
    $workTimeSync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
    $workTimeSync->setWorkTimePresenceService(new WorkTimePresenceService($connection, $workTimePolicies));
    $workTimeIds = [];
    foreach (['continue', 'confirm'] as $flow) {
        $workTimeUserId = $userIds['ui-worktime-' . $flow . '@example.test'];
        $assignment = $workTimePolicies->assign(['user_id' => $workTimeUserId, 'policy_version_id' => $workTimeTemplate['version']['id'], 'effective_from' => $workTimeNow->modify('+1 day')->format('Y-m-d')], $adminId);
        // Backdate only this random scratch DB's fixture to exercise real current UI.
        $connection->execute('UPDATE employee_work_time_assignments SET effective_from = :fixture_date WHERE id = :id', ['fixture_date' => $workTimeStart->format('Y-m-d'), 'id' => $assignment['id']]);
        $in = $workTimeSync->sync($workTimeUserId, ['client_request_id' => 'ui-worktime-' . $flow, 'action' => 'check_in', 'work_date' => $workTimeStart->format('Y-m-d'), 'start_time' => $workTimeStart->format('H:i'), 'note' => 'Isolierter Worktime-Browserworkflow']);
        $workTimeIds[$flow] = $in['timesheet']['id'];
    }
    $workTimeSummary = (new WorkTimeEventService($connection, new WorkTimePolicyService($connection, true), $workTimeSync))->process(false, $workTimeNow);
    if ($workTimeSummary['proposals'] !== 2 || $workTimeSummary['errors'] !== 0) {
        throw new RuntimeException('Arbeitszeitfenster-UI-Fixture konnte keine zwei offenen Vorschlaege erzeugen.');
    }
    $historyEmployeeId = $userIds['ui-worktime-history@example.test'];
    $historyWorkDate = $workTimeNow->modify('-4 days')->format('Y-m-d');
    $historyTemplate = $workTimePolicies->createTemplate(['name' => 'UI Historischer Referenzplan', 'policy' => [
        'windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']]),
    ]], $adminId);
    $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time, end_time, entry_type, source, gross_minutes, break_minutes, net_minutes) VALUES (:user_id, :work_date, "07:42:00", "16:35:00", "work", "app", 533, 30, 503)', ['user_id' => $historyEmployeeId, 'work_date' => $historyWorkDate]);
    $historyBookingId = $connection->lastInsertId();
    $connection->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source) VALUES (:booking, :user, :started, :ended, "app")', [
        'booking' => $historyBookingId, 'user' => $historyEmployeeId, 'started' => $historyWorkDate . ' 12:00:00', 'ended' => $historyWorkDate . ' 12:30:00',
    ]);
    $historyCutover = new EmployeeAccountCutoverService($connection, new AccountJournalService($connection));
    $historyCutover->finalize([
        'user_id' => $historyEmployeeId, 'effective_from' => $workTimeNow->format('Y-m-d'), 'opening_time_balance' => '+01:30',
        'leave_year' => (int) $workTimeNow->format('Y'), 'annual_leave_entitlement_days' => 30, 'opening_remaining_leave_days' => 20,
    ], $adminId);
    $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time, end_time, entry_type, source, gross_minutes, break_minutes, net_minutes) VALUES (:user_id, :work_date, "07:42:00", "08:10:00", "work", "app", 28, 0, 28)', [
        'user_id' => $historyEmployeeId, 'work_date' => $workTimeNow->format('Y-m-d'),
    ]);
    $hardeningDate = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    $hardeningTemplate = $workTimePolicies->createTemplate(['name' => 'UI Hardening 08 bis 16', 'policy' => [
        'windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']]),
        'early_check_in_mode' => 'presence_only', 'checkout_proposals_enabled' => true,
        'notify_employee_push' => true, 'outside_window_notifications_enabled' => true, 'outside_window_delay_minutes' => 5,
    ]], $adminId);
    foreach (['pause', 'off'] as $flow) {
        $hardeningUser = $userIds['ui-hardening-' . $flow . '@example.test'];
        $assignment = $workTimePolicies->assign(['user_id' => $hardeningUser, 'policy_version_id' => $hardeningTemplate['version']['id'], 'effective_from' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d')], $adminId);
        $connection->execute('UPDATE employee_work_time_assignments SET effective_from = :fixture_date WHERE id = :id', ['fixture_date' => $hardeningDate, 'id' => $assignment['id']]);
    }
    $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time, end_time, entry_type, source, gross_minutes, break_minutes, net_minutes) VALUES (:user_id, :work_date, "07:42:00", "16:35:00", "work", "app", 533, 30, 503)', ['user_id' => $userIds['ui-hardening-pause@example.test'], 'work_date' => $hardeningDate]);
    $hardeningPauseId = $connection->lastInsertId();
    $calendarPolicy = new CalendarPolicyService($connection);
    $vacationDateValue = new DateTimeImmutable('+14 days');
    while ((int) $vacationDateValue->format('N') > 5
        || !(bool) ($calendarPolicy->dayPolicy($vacationDateValue->format('Y-m-d'))['time_tracking_required'] ?? false)) {
        $vacationDateValue = $vacationDateValue->modify('+1 day');
    }
    $vacationDate = $vacationDateValue->format('Y-m-d');
    $vacationYear = (int) $vacationDateValue->format('Y');
    $connection->execute(
        'INSERT INTO vacation_requests (
            user_id, date_from, date_to, day_count, status, employee_note,
            requested_at, created_at, updated_at, is_deleted
         ) VALUES (
            :user_id, :date_from, :date_to, 1, "pending", "Playwright Resturlaub",
            NOW(), NOW(), NOW(), 0
         )',
        ['user_id' => $employeeId, 'date_from' => $vacationDate, 'date_to' => $vacationDate]
    );
    $connection->execute(
        'INSERT INTO employee_account_cutovers (
            user_id, active_final_user_id, effective_from, opening_time_balance_minutes, leave_year,
            annual_leave_entitlement_days, leave_carryover_days, opening_remaining_leave_days,
            status, created_by_user_id, finalized_by_user_id, finalized_at, created_at, updated_at
         ) VALUES (
            :user_id, :active_user_id, :effective_from, 0, :leave_year, 30, 0, 30,
            "final", :created_by_user_id, :finalized_by_user_id, NOW(), NOW(), NOW()
         )',
        [
            'user_id' => $paginationUserId,
            'active_user_id' => $paginationUserId,
            'effective_from' => $accountYear . '-01-01',
            'leave_year' => $accountYear,
            'created_by_user_id' => $adminId,
            'finalized_by_user_id' => $adminId,
        ]
    );
    $paginationCutoverId = $connection->lastInsertId();
    $connection->transaction(function () use ($connection, $paginationUserId, $paginationCutoverId, $adminId, $accountYear): void {
        for ($index = 1; $index <= 105; $index++) {
            $connection->execute(
                'INSERT INTO time_account_entries (
                    user_id, cutover_id, effective_date, minutes, entry_type, description,
                    created_by_user_id, approved_by_user_id, created_at
                 ) VALUES (
                    :user_id, :cutover_id, :effective_date, :minutes, "manual_adjustment", :description,
                    :created_by_user_id, :approved_by_user_id, NOW()
                 )',
                [
                    'user_id' => $paginationUserId,
                    'cutover_id' => $paginationCutoverId,
                    'effective_date' => $accountYear . '-02-01',
                    'minutes' => $index,
                    'description' => 'Pagination ' . $index,
                    'created_by_user_id' => $adminId,
                    'approved_by_user_id' => $adminId,
                ]
            );
        }
    });

    $connection->execute(
        'INSERT INTO projects (project_number, name, status, created_at, updated_at, is_deleted)
         VALUES ("UI-RESTORE", "UI Restore", "active", NOW(), NOW(), 0)'
    );
    $projectId = $connection->lastInsertId();
    $connection->execute(
        'INSERT INTO projects (project_number, name, customer_name, work_instructions, status, created_at, updated_at, is_deleted)
         VALUES ("UI-FORM-STABILITY", "UI Formularstabilitaet", "UI Ausgangskunde", "Urspruengliche Arbeitsanweisung", "active", NOW(), NOW(), 0)'
    );
    $formProjectId = $connection->lastInsertId();
    $connection->execute('INSERT INTO project_memberships (project_id, user_id, assignment_role, assigned_from, assigned_until) VALUES (:project, :user, NULL, NULL, NULL)', ['project' => $formProjectId, 'user' => $employeeId]);
    $connection->execute('INSERT INTO projects (project_number, name, status, created_at, updated_at, is_deleted) VALUES ("UI-FORM-COLLISION", "UI Formularnummer bereits vergeben", "active", NOW(), NOW(), 0)');
    $connection->execute('INSERT INTO project_material_entries (project_id, created_by_user_id, work_date, description, quantity, unit, note) VALUES (:project, :actor, :work_date, "UI Formular Material", 1.5, "Stk", "Synthetischer Navigationstest")', ['project' => $formProjectId, 'actor' => $employeeId, 'work_date' => date('Y-m-d')]);
    $formMaterialId = $connection->lastInsertId();
    $materialUserId = $userIds['ui-material@example.test'];
    $materialProjects = [];
    foreach (['A' => 'UI Zeitbuchungsprojekt', 'B' => 'UI Materialprojekt'] as $key => $name) {
        $connection->execute(
            'INSERT INTO projects (project_number, name, status, created_at, updated_at, is_deleted)
             VALUES (:number, :name, "active", NOW(), NOW(), 0)',
            ['number' => 'UI-MATERIAL-' . $key, 'name' => $name]
        );
        $materialProjects[$key] = $connection->lastInsertId();
        $connection->execute(
            'INSERT INTO project_memberships (project_id, user_id) VALUES (:project, :user)',
            ['project' => $materialProjects[$key], 'user' => $materialUserId]
        );
    }
    $appointmentEnvironment = [];
    if ($withAppointments) {
        $day = (new DateTimeImmutable('+9 days'))->format('Y-m-d');
        $connection->execute(
            'INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, gross_minutes,
                break_minutes, net_minutes, entry_type, source, note, updated_at, is_deleted)
             VALUES (:user, :project, :date, "07:00:00", "08:00:00", 60, 0, 60, "work", "admin",
                "UI-Terminregression Arbeitsbuchung", NOW(), 0)',
            ['user' => $employeeId, 'project' => $projectId, 'date' => $day]
        );
        $eventTitle = 'UI-Terminregression UVV-Fälligkeit';
        $events = new PersonnelEventService($connection);
        $type = $events->createEventType(['name' => 'UI UVV Prüftyp', 'slug' => 'ui-appointment-uvv']);
        $events->createEvent(['user_id' => $employeeId, 'event_type_id' => $type['id'], 'title' => $eventTitle, 'due_on' => $day]);

        foreach (['viewer' => ['appointments.view'], 'denied' => []] as $scope => $permissions) {
            $slug = 'ui_appointment_' . $scope;
            $email = $slug . '@example.test';
            $connection->execute('INSERT INTO roles (slug, name, is_system_role, is_deleted) VALUES (:slug, :name, 0, 0)', ['slug' => $slug, 'name' => $slug]);
            $roleId = $connection->lastInsertId();
            foreach (['dashboard.view', ...$permissions] as $permission) {
                $connection->execute('INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE code = :permission', ['role' => $roleId, 'permission' => $permission]);
            }
            $connection->execute(
                'INSERT INTO users (employee_number, first_name, last_name, email, password_hash, employment_status, time_tracking_required, is_deleted)
                 VALUES (:number, "UI", :last, :email, :hash, "active", 0, 0)',
                ['number' => 'UI-APPOINTMENT-' . strtoupper($scope), 'last' => $slug, 'email' => $email, 'hash' => $passwordHash]
            );
            $connection->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', ['user' => $connection->lastInsertId(), 'role' => $roleId]);
            $appointmentEnvironment['UI_TEST_APPOINTMENT_' . strtoupper($scope) . '_EMAIL'] = $email;
        }
        $appointmentEnvironment += [
            'UI_TEST_APPOINTMENT_DAY' => $day,
            'UI_TEST_APPOINTMENT_EVENT' => $eventTitle,
            'UI_TEST_APPOINTMENT_PROJECT' => (string) $formProjectId,
        ];
    }
    $connection->execute(
        'INSERT INTO timesheets (
            user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes,
            credited_minutes, entry_type, source, note, updated_at, is_deleted
         ) VALUES (
            :user_id, :project_id, :work_date, "07:00:00", "15:00:00", 480, 0, 480,
            0, "work", "admin", "Aktive Konfliktbuchung", NOW(), 0
         )',
        ['user_id' => $employeeId, 'project_id' => $projectId, 'work_date' => $restoreDate]
    );
    $connection->execute(
        'INSERT INTO timesheets (
            user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes,
            credited_minutes, entry_type, source, note, updated_at, is_deleted
         ) VALUES (
            :user_id, :project_id, :work_date, "07:00:00", "15:00:00", 480, 30, 450,
            0, "work", "admin", "Konflikt fuer Zeitraum-Vorschau", NOW(), 0
         )',
        ['user_id' => $employeeId, 'project_id' => $projectId, 'work_date' => $absenceConflictDate]
    );
    $connection->execute(
        'INSERT INTO timesheets (
            user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes,
            credited_minutes, absence_reason_code, entry_type, source, note, updated_at, is_deleted, deleted_at
         ) VALUES (
            :user_id, NULL, :work_date, NULL, NULL, 0, 0, 0,
            480, "sick_paid", "sick", "admin", "Archivierter Restore-Konflikt", NOW(), 1, NOW()
         )',
        ['user_id' => $employeeId, 'work_date' => $restoreDate]
    );
    $restoreBookingId = $connection->lastInsertId();
    $connection->execute(
        'INSERT INTO timesheets (
            user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes,
            credited_minutes, absence_reason_code, entry_type, source, note, updated_at, is_deleted
         ) VALUES (
            :user_id, NULL, :work_date, NULL, NULL, 0, 0, 0,
            0, "unpaid_leave", "vacation", "admin", "Legacy unbezahlter Urlaub", NOW(), 0
         )',
        ['user_id' => $employeeId, 'work_date' => $accountYear . '-06-01']
    );

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if (!is_resource($listener)) {
        throw new RuntimeException('Freier UI-Testport konnte nicht reserviert werden: ' . $errorMessage);
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
    $baseUrl = 'http://127.0.0.1:' . $port;
    $environment = array_merge(getenv(), [
        'APP_ENV' => 'test',
        'DB_OVERRIDE_FILE' => $overrideFile,
        'SESSION_SECURE_COOKIE' => 'false',
        'WORK_TIME_WINDOWS_ENABLED' => 'true',
    ]);
    $serverProcess = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, base_path('tests/Support/php-server-router.php')],
        [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $serverPipes,
        base_path(),
        $environment
    );
    if (!is_resource($serverProcess)) {
        throw new RuntimeException('Der isolierte PHP-UI-Testserver konnte nicht gestartet werden.');
    }

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $curl = curl_init($baseUrl . '/admin/login');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
        curl_exec($curl);
        $ready = curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($curl);
        if ($ready) {
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('Der isolierte PHP-UI-Testserver wurde nicht rechtzeitig erreichbar.');
    }

    $disabledListener = stream_socket_server('tcp://127.0.0.1:0', $disabledErrorCode, $disabledErrorMessage);
    if (!is_resource($disabledListener)) {
        throw new RuntimeException('Freier deaktivierter UI-Testport fehlt: ' . $disabledErrorMessage);
    }
    $disabledAddress = stream_socket_get_name($disabledListener, false);
    fclose($disabledListener);
    $disabledPort = (int) substr((string) $disabledAddress, strrpos((string) $disabledAddress, ':') + 1);
    $disabledBaseUrl = 'http://127.0.0.1:' . $disabledPort;
    $disabledEnvironment = array_merge($environment, ['WORK_TIME_WINDOWS_ENABLED' => 'false']);
    $disabledServerProcess = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $disabledPort, base_path('tests/Support/php-server-router.php')], [1 => ['file', $disabledServerLog, 'a'], 2 => ['file', $disabledServerLog, 'a']], $disabledServerPipes, base_path(), $disabledEnvironment);
    if (!is_resource($disabledServerProcess)) {
        throw new RuntimeException('Der deaktivierte PHP-Testserver konnte nicht gestartet werden.');
    }
    $disabledReady = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $curl = curl_init($disabledBaseUrl . '/admin/login');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
        curl_exec($curl);
        $disabledReady = curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($curl);
        if ($disabledReady) { break; }
        usleep(100000);
    }
    if (!$disabledReady) {
        throw new RuntimeException('Der deaktivierte PHP-Testserver ist nicht erreichbar.');
    }

    $playwrightEnvironment = array_merge($environment, $appointmentEnvironment, [
        'REAL_ACCOUNT_UI' => '1',
        'UI_ISOLATED_ACCOUNT_RUNNER' => '1',
        'UI_BASE_URL' => $baseUrl,
        'UI_TEST_ADMIN_EMAIL' => 'ui-admin@example.test',
        'UI_TEST_ADMIN_PASSWORD' => $password,
        'UI_TEST_FORM_PROJECT_ID' => (string) $formProjectId,
        'UI_TEST_FORM_MATERIAL_ID' => (string) $formMaterialId,
        'UI_TEST_FORM_OTHER_USER_ID' => (string) $paginationUserId,
        'UI_TEST_MATERIAL_USER_ID' => (string) $materialUserId,
        'UI_TEST_MATERIAL_EMAIL' => 'ui-material@example.test',
        'UI_TEST_MATERIAL_PROJECT_A' => (string) $materialProjects['A'],
        'UI_TEST_MATERIAL_PROJECT_B' => (string) $materialProjects['B'],
        'UI_TEST_PAUSE_WORK_DATE' => $pauseShiftStart->format('Y-m-d'),
        'UI_TEST_PAUSE_WORK_START' => $pauseShiftStart->format('H:i'),
        'UI_TEST_PAUSE_FIRST_START' => $pauseShiftStart->modify('+30 minutes')->format('Y-m-d\TH:i'),
        'UI_TEST_PAUSE_FIRST_END' => $pauseShiftStart->modify('+45 minutes')->format('Y-m-d\TH:i'),
        'UI_TEST_PAUSE_SECOND_START' => $pauseShiftStart->modify('+60 minutes')->format('Y-m-d\TH:i'),
        'UI_TEST_PAUSE_SECOND_END' => $pauseShiftStart->modify('+90 minutes')->format('Y-m-d\TH:i'),
        'UI_TEST_EMPLOYEE_EMAIL' => 'ui-employee@example.test',
        'UI_TEST_EMPLOYEE_PASSWORD' => $password,
        'UI_TEST_EMPLOYEE_ID' => (string) $employeeId,
        'UI_TEST_PAGINATION_USER_ID' => (string) $paginationUserId,
        'UI_TEST_RESTORE_BOOKING_ID' => (string) $restoreBookingId,
        'UI_TEST_ACCOUNT_YEAR' => (string) $accountYear,
        'UI_TEST_VACATION_DATE' => (string) $vacationDate,
        'UI_TEST_VACATION_YEAR' => (string) $vacationYear,
        'UI_TEST_CLOSURE_YEAR' => (string) $closureYear,
        'UI_TEST_CLOSURE_DATE_FROM' => $closureDateFrom,
        'UI_TEST_CLOSURE_DATE_TO' => $closureDateTo,
        'UI_TEST_ABSENCE_DATE_FROM' => $absenceDateFrom,
        'UI_TEST_ABSENCE_DATE_SHORT_TO' => $absenceDateShortTo,
        'UI_TEST_ABSENCE_DATE_TO' => $absenceDateTo,
        'UI_TEST_ABSENCE_DATE_EXTENDED_TO' => $absenceDateExtendedTo,
        'UI_TEST_ABSENCE_CONFLICT_DATE' => $absenceConflictDate,
        'UI_TEST_WORKTIME_PASSWORD' => $password,
        'UI_TEST_WORKTIME_CONTINUE_ID' => (string) $workTimeIds['continue'],
        'UI_TEST_WORKTIME_CONFIRM_ID' => (string) $workTimeIds['confirm'],
        'UI_TEST_WORKTIME_WORK_DATE' => $workTimeStart->format('Y-m-d'),
        'UI_TEST_WORKTIME_END' => $workTimeEnd->format('H:i:s'),
        'UI_TEST_WORKTIME_NOW_END' => $workTimeNow->format('H:i'),
        'UI_TEST_WORKTIME_HISTORY_USER_ID' => (string) $historyEmployeeId,
        'UI_TEST_WORKTIME_HISTORY_VERSION_ID' => (string) $historyTemplate['version']['id'],
        'UI_TEST_WORKTIME_HISTORY_DATE' => $historyWorkDate,
        'UI_TEST_WORKTIME_HISTORY_BOOKING_ID' => (string) $historyBookingId,
        'UI_TEST_HARDENING_DATE' => $hardeningDate,
        'UI_TEST_HARDENING_FUTURE_DATE' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d'),
        'UI_TEST_HARDENING_VERSION_ID' => (string) $hardeningTemplate['version']['id'],
        'UI_TEST_HARDENING_EMPLOYEE_ID' => (string) $userIds['ui-hardening-employee@example.test'],
        'UI_TEST_HARDENING_PAUSE_USER_ID' => (string) $userIds['ui-hardening-pause@example.test'],
        'UI_TEST_HARDENING_PAUSE_ID' => (string) $hardeningPauseId,
        'UI_TEST_HARDENING_OFF_ID' => (string) $userIds['ui-hardening-off@example.test'],
        'UI_TEST_HARDENING_DISABLED_URL' => $disabledBaseUrl,
    ]);
    $playwright = base_path('node_modules/.bin/playwright');
    $realSpecs = [
        'tests/e2e/revisable-accounts.real.spec.js',
        'tests/e2e/work-time.real.spec.js',
        'tests/e2e/work-time-hardening.real.spec.js',
        'tests/e2e/admin-project-forms.real.spec.js',
        'tests/e2e/project-material-context.real.spec.js',
        'tests/e2e/pause-models.real.spec.js',
        'tests/e2e/pause-controls.real.spec.js',
        $appointmentSpec,
    ];
    if ($selectedSpec !== '') {
        if (!in_array($selectedSpec, $realSpecs, true)) { throw new RuntimeException('Unbekannte isolierte Browser-Testdatei.'); }
        $realSpecs = [$selectedSpec];
    }
    $browserConfig = getenv('UI_REAL_TEST_BROWSER') === 'webkit' ? ['--config=playwright.webkit.config.js'] : [];
    fwrite(STDOUT, $run([$playwright, 'test', ...$browserConfig, ...$realSpecs, '--trace=retain-on-failure', '--output=' . $outputDirectory], $playwrightEnvironment));
    $exitCode = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);

    if (is_file($serverLog)) {
        $log = file_get_contents($serverLog);
        if (is_string($log) && $log !== '') {
            fwrite(STDERR, substr($log, -8000));
        }
    }
} finally {
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }
    if (is_resource($disabledServerProcess)) {
        proc_terminate($disabledServerProcess);
        proc_close($disabledServerProcess);
    }

    try {
        if ($serverPdo instanceof PDO && str_starts_with($databaseName, 'timeapp_ui_')) {
            $serverPdo->exec('DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier($databaseName));
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Scratch-Datenbank konnte nicht entfernt werden: ' . $exception->getMessage() . PHP_EOL);
        $exitCode = 1;
    } finally {
        $retainOutput = false;
        if ($exitCode !== 0) {
            // Retain synthetic browser diagnostics and request logs privately.
            // Database overrides and migration configuration are always removed.
            $artifactRoot = trim((string) getenv('UI_REVIEW_ARTIFACT_DIR'));
            $artifactDirectory = $artifactRoot !== '' ? rtrim($artifactRoot, '/') . '/' . basename($temporaryPrefix) . '-failure' : $outputDirectory;
            try {
                if ($artifactDirectory !== $outputDirectory && is_dir($outputDirectory)) {
                    $copyTree($outputDirectory, $artifactDirectory);
                } elseif (!is_dir($artifactDirectory) && !mkdir($artifactDirectory, 0700, true) && !is_dir($artifactDirectory)) {
                    throw new RuntimeException('UI-Diagnoseverzeichnis konnte nicht erstellt werden.');
                }
                chmod($artifactDirectory, 0700);
                foreach ([$serverLog, $disabledServerLog] as $logFile) {
                    if (is_file($logFile)) {
                        $target = $artifactDirectory . '/' . basename($logFile);
                        if (!copy($logFile, $target)) { throw new RuntimeException('UI-Serverlog konnte nicht gesichert werden.'); }
                        chmod($target, 0600);
                    }
                }
                $retainOutput = $artifactDirectory === $outputDirectory;
                fwrite(STDERR, 'UI-Diagnoseartefakte: ' . $artifactDirectory . PHP_EOL);
            } catch (Throwable $exception) {
                // Even if the chosen artifact directory fails, keep the original
                // Playwright files instead of losing the evidence a second time.
                $retainOutput = true;
                fwrite(STDERR, $exception->getMessage() . ' Originale UI-Artefakte: ' . $outputDirectory . PHP_EOL);
            }
        }
        foreach ([$phinxFile, $overrideFile, $serverLog, $disabledServerLog] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (!$retainOutput) { $removeTree($outputDirectory); }
    }
}

exit($exitCode);
