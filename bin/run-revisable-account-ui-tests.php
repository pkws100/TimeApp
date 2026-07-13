#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseConnection;
use Tests\Support\MariaDbScratchConfig;

require_once __DIR__ . '/../bootstrap/autoload.php';

$databaseName = 'timeapp_ui_' . bin2hex(random_bytes(6));
$temporaryPrefix = sys_get_temp_dir() . '/timeapp-ui-' . bin2hex(random_bytes(6));
$phinxFile = $temporaryPrefix . '-phinx.php';
$overrideFile = $temporaryPrefix . '-database.php';
$serverLog = $temporaryPrefix . '-server.log';
$outputDirectory = $temporaryPrefix . '-playwright';
$serverProcess = null;
$serverPdo = null;
$exitCode = 1;

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
        ['UI-PAGINATION', 'UI', 'Pagination', 'ui-pagination@example.test', 'mitarbeiter'],
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
    $employeeId = $userIds['ui-employee@example.test'];
    $paginationUserId = $userIds['ui-pagination@example.test'];
    $connection->execute(
        'INSERT INTO employee_account_cutovers (
            user_id, active_final_user_id, effective_from, opening_time_balance_minutes, leave_year,
            annual_leave_entitlement_days, leave_carryover_days, opening_remaining_leave_days,
            status, created_by_user_id, finalized_by_user_id, finalized_at, created_at, updated_at
         ) VALUES (
            :user_id, :active_user_id, "2026-01-01", 0, 2026, 30, 0, 30,
            "final", :created_by_user_id, :finalized_by_user_id, NOW(), NOW(), NOW()
         )',
        [
            'user_id' => $paginationUserId,
            'active_user_id' => $paginationUserId,
            'created_by_user_id' => $adminId,
            'finalized_by_user_id' => $adminId,
        ]
    );
    $paginationCutoverId = $connection->lastInsertId();
    $connection->transaction(function () use ($connection, $paginationUserId, $paginationCutoverId, $adminId): void {
        for ($index = 1; $index <= 105; $index++) {
            $connection->execute(
                'INSERT INTO time_account_entries (
                    user_id, cutover_id, effective_date, minutes, entry_type, description,
                    created_by_user_id, approved_by_user_id, created_at
                 ) VALUES (
                    :user_id, :cutover_id, "2026-02-01", :minutes, "manual_adjustment", :description,
                    :created_by_user_id, :approved_by_user_id, NOW()
                 )',
                [
                    'user_id' => $paginationUserId,
                    'cutover_id' => $paginationCutoverId,
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
        'INSERT INTO timesheets (
            user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes,
            credited_minutes, entry_type, source, note, updated_at, is_deleted
         ) VALUES (
            :user_id, :project_id, "2026-05-04", "07:00:00", "15:00:00", 480, 0, 480,
            0, "work", "admin", "Aktive Konfliktbuchung", NOW(), 0
         )',
        ['user_id' => $employeeId, 'project_id' => $projectId]
    );
    $connection->execute(
        'INSERT INTO timesheets (
            user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes,
            credited_minutes, absence_reason_code, entry_type, source, note, updated_at, is_deleted, deleted_at
         ) VALUES (
            :user_id, NULL, "2026-05-04", NULL, NULL, 0, 0, 0,
            480, "sick_paid", "sick", "admin", "Archivierter Restore-Konflikt", NOW(), 1, NOW()
         )',
        ['user_id' => $employeeId]
    );
    $restoreBookingId = $connection->lastInsertId();

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

    $playwrightEnvironment = array_merge($environment, [
        'REAL_ACCOUNT_UI' => '1',
        'UI_ISOLATED_ACCOUNT_RUNNER' => '1',
        'UI_BASE_URL' => $baseUrl,
        'UI_TEST_ADMIN_EMAIL' => 'ui-admin@example.test',
        'UI_TEST_ADMIN_PASSWORD' => $password,
        'UI_TEST_EMPLOYEE_EMAIL' => 'ui-employee@example.test',
        'UI_TEST_EMPLOYEE_PASSWORD' => $password,
        'UI_TEST_PAGINATION_USER_ID' => (string) $paginationUserId,
        'UI_TEST_RESTORE_BOOKING_ID' => (string) $restoreBookingId,
    ]);
    $playwright = base_path('node_modules/.bin/playwright');
    fwrite(STDOUT, $run([$playwright, 'test', 'tests/e2e/revisable-accounts.real.spec.js', '--output=' . $outputDirectory], $playwrightEnvironment));
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

    try {
        if ($serverPdo instanceof PDO && str_starts_with($databaseName, 'timeapp_ui_')) {
            $serverPdo->exec('DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier($databaseName));
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Scratch-Datenbank konnte nicht entfernt werden: ' . $exception->getMessage() . PHP_EOL);
        $exitCode = 1;
    } finally {
        foreach ([$phinxFile, $overrideFile, $serverLog] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $removeTree($outputDirectory);
    }
}

exit($exitCode);
