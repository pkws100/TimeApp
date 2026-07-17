<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\ConfigRepository;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use PDOException;
use RuntimeException;
use PHPUnit\Framework\TestCase;

final class AppTimesheetSyncDatabaseTest extends TestCase
{
    public function testManualFortyFiveMinuteBreakSurvivesCheckout(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-break');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-03';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $pauseResult = $service->sync($user, [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ]);

            self::assertSame(45, $pauseResult['timesheet']['break_minutes'] ?? null);
            self::assertSame(45, $pauseResult['tracked_minutes_live_basis']['completed_break_minutes'] ?? null);

            $checkoutResult = $service->sync($user, [
                'client_request_id' => 'check-out-' . $suffix,
                'action' => 'check_out',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'end_time' => '16:00',
            ]);

            self::assertSame(480, $checkoutResult['timesheet']['gross_minutes'] ?? null);
            self::assertSame(45, $checkoutResult['timesheet']['break_minutes'] ?? null);
            self::assertSame(435, $checkoutResult['timesheet']['net_minutes'] ?? null);
            self::assertSame(45, $checkoutResult['tracked_minutes_live_basis']['completed_break_minutes'] ?? null);
            self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testManualBreakSurvivesUpsert(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-upsert');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-04';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ]);
            $result = $service->sync($user, [
                'client_request_id' => 'upsert-' . $suffix,
                'action' => 'upsert',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]);

            self::assertSame(480, $result['timesheet']['gross_minutes'] ?? null);
            self::assertSame(45, $result['timesheet']['break_minutes'] ?? null);
            self::assertSame(435, $result['timesheet']['net_minutes'] ?? null);
        });
    }

    public function testManualBreakSurvivesProjectSelectionAndNoteUpdate(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-project');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-05';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => null,
                'work_date' => $workDate,
                'start_time' => '08:00',
                'note' => 'Noch ohne Projekt',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => null,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ]);
            $projectResult = $service->sync($user, [
                'client_request_id' => 'project-' . $suffix,
                'action' => 'select_project',
                'project_id' => $projectId,
                'work_date' => $workDate,
            ]);
            $noteResult = $service->sync($user, [
                'client_request_id' => 'note-' . $suffix,
                'action' => 'upsert',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'note' => 'Projekt nachgetragen',
            ]);

            self::assertSame($projectId, $projectResult['timesheet']['project_id'] ?? null);
            self::assertSame(45, $projectResult['timesheet']['break_minutes'] ?? null);
            self::assertSame('Projekt nachgetragen', $noteResult['timesheet']['note'] ?? null);
            self::assertSame(45, $noteResult['timesheet']['break_minutes'] ?? null);
        });
    }

    public function testLegalMinimumOverridesLowerManualBreak(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-minimum');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-08';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 15,
            ]);
            $result = $service->sync($user, [
                'client_request_id' => 'check-out-' . $suffix,
                'action' => 'check_out',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'end_time' => '18:00',
            ]);

            self::assertSame(600, $result['timesheet']['gross_minutes'] ?? null);
            self::assertSame(45, $result['timesheet']['break_minutes'] ?? null);
            self::assertSame(555, $result['timesheet']['net_minutes'] ?? null);
        });
    }

    public function testCompletedStructuredBreakSurvivesCheckout(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'structured-break');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-09';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-start-' . $suffix,
                'action' => 'pause_start',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'break_started_at' => '2026-06-09 12:00:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-end-' . $suffix,
                'action' => 'pause_end',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'break_ended_at' => '2026-06-09 12:45:00',
            ]);
            $result = $service->sync($user, [
                'client_request_id' => 'check-out-' . $suffix,
                'action' => 'check_out',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'end_time' => '16:00',
            ]);

            self::assertSame(45, $result['timesheet']['break_minutes'] ?? null);
            self::assertSame(435, $result['timesheet']['net_minutes'] ?? null);
            self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testOpenStructuredBreakDoesNotDiscardStoredManualBreak(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'mixed-break');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-12';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'manual-pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ]);
            $startedResult = $service->sync($user, [
                'client_request_id' => 'pause-start-' . $suffix,
                'action' => 'pause_start',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'break_started_at' => '2026-06-12 12:00:00',
            ]);

            self::assertSame(45, $startedResult['timesheet']['break_minutes'] ?? null);

            $endedResult = $service->sync($user, [
                'client_request_id' => 'pause-end-' . $suffix,
                'action' => 'pause_end',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'break_ended_at' => '2026-06-12 12:15:00',
            ]);

            self::assertSame(15, $endedResult['timesheet']['break_minutes'] ?? null);
            self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testMultipleStructuredBreaksAreAddedWithoutStoredBreakDoubleCounting(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'multiple-breaks');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-10';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);

            foreach ([
                ['10:00:00', '10:15:00'],
                ['12:00:00', '12:30:00'],
            ] as $index => [$startedAt, $endedAt]) {
                $service->sync($user, [
                    'client_request_id' => 'pause-start-' . $index . '-' . $suffix,
                    'action' => 'pause_start',
                    'project_id' => $projectId,
                    'work_date' => $workDate,
                    'break_started_at' => $workDate . ' ' . $startedAt,
                ]);
                $service->sync($user, [
                    'client_request_id' => 'pause-end-' . $index . '-' . $suffix,
                    'action' => 'pause_end',
                    'project_id' => $projectId,
                    'work_date' => $workDate,
                    'break_ended_at' => $workDate . ' ' . $endedAt,
                ]);
            }

            $result = $service->sync($user, [
                'client_request_id' => 'check-out-' . $suffix,
                'action' => 'check_out',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'end_time' => '16:00',
            ]);

            self::assertSame(45, $result['timesheet']['break_minutes'] ?? null);
            self::assertSame(435, $result['timesheet']['net_minutes'] ?? null);
            self::assertSame(2, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testManualPauseRequestRemainsIdempotent(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-idempotent');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-11';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $payload = [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ];
            $firstResult = $service->sync($user, $payload);
            $replayedResult = $service->sync($user, $payload);

            self::assertSame($firstResult, $replayedResult);
            self::assertSame(45, $replayedResult['timesheet']['break_minutes'] ?? null);
            self::assertSame(2, (int) $connection->fetchColumn('SELECT COUNT(*) FROM app_sync_operations'));
            self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testEmployeeCanBookAssignedProjectWithNativePdoPlaceholders(): void
    {
        $baseConfig = $this->databaseConfig();
        $baseConnection = new DatabaseConnection($baseConfig);

        if (!$baseConnection->isAvailable()) {
            self::fail('Keine Test-Datenbank verfuegbar.');
        }

        $pdo = $baseConnection->pdo();
        self::assertInstanceOf(PDO::class, $pdo);

        $databaseName = 'zeiterfassung_hy093_' . bin2hex(random_bytes(4));
        $quotedDatabaseName = '`' . str_replace('`', '``', $databaseName) . '`';
        $databaseCreated = false;

        try {
            try {
                $pdo->exec('CREATE DATABASE ' . $quotedDatabaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $databaseCreated = true;
            } catch (PDOException $exception) {
                self::fail('Datenbankbenutzer darf keine Scratch-Datenbank anlegen: ' . $exception->getMessage());
            }

            $config = $baseConfig;
            $config['database'] = $databaseName;
            $connection = new DatabaseConnection($config);
            $this->createSchema($connection);

            $suffix = bin2hex(random_bytes(4));
            $userEmail = 'phpunit-hy093-' . $suffix . '@example.test';
            $projectNumber = 'PHP-HY093-' . $suffix;

            $connection->execute(
                'INSERT INTO users (
                    employee_number, first_name, last_name, email, password_hash, employment_status, time_tracking_required, created_at, updated_at, is_deleted
                 ) VALUES (
                    :employee_number, "PHPUnit", "Mitarbeiter", :email, "", "active", 1, NOW(), NOW(), 0
                 )',
                [
                    'employee_number' => 'HY093-' . $suffix,
                    'email' => $userEmail,
                ]
            );
            $userId = $connection->lastInsertId();

            $connection->execute(
                'INSERT INTO projects (
                    project_number, name, status, created_at, updated_at, is_deleted
                 ) VALUES (
                    :project_number, "PHPUnit HY093 Projekt", "active", NOW(), NOW(), 0
                 )',
                ['project_number' => $projectNumber]
            );
            $projectId = $connection->lastInsertId();

            $connection->execute(
                'INSERT INTO project_memberships (project_id, user_id, assignment_role, assigned_from, assigned_until)
                 VALUES (:project_id, :user_id, NULL, :assigned_from, :assigned_until)',
                [
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'assigned_from' => '2026-01-01',
                    'assigned_until' => '2026-12-31',
                ]
            );

            $service = new AppTimesheetSyncService(
                $connection,
                new TimesheetCalculator(),
                new CompanySettingsService($connection, []),
                new WorkdayStateCalculator()
            );

            $result = $service->sync(
                ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']],
                [
                    'client_request_id' => 'phpunit-hy093-' . $suffix,
                    'action' => 'check_in',
                    'project_id' => $projectId,
                    'work_date' => '2026-06-01',
                    'start_time' => '07:00',
                ]
            );

            self::assertSame('synced', $result['status'] ?? null);
            self::assertSame($projectId, $result['timesheet']['project_id'] ?? null);
        } finally {
            if ($databaseCreated) {
                try {
                    $pdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabaseName);
                } catch (PDOException) {
                    // Best-effort cleanup for scratch database.
                }
            }
        }
    }

    public function testEmployeeCannotSyncIntoFinalizedAccountingPeriod(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'locked');

            $connection->execute(
                'INSERT INTO accounting_closures (
                    closure_number, closure_type, status, period_start, period_end, project_id, user_id,
                    snapshot_hash, item_count, total_net_minutes, created_at, finalized_at
                 ) VALUES (
                    "ABR-MONTH-2026-06-LOCKED", "month", "final", "2026-06-01", "2026-06-30", NULL, NULL,
                    "hash", 0, 0, NOW(), NOW()
                 )'
            );

            $service = new AppTimesheetSyncService(
                $connection,
                new TimesheetCalculator(),
                new CompanySettingsService($connection, []),
                new WorkdayStateCalculator()
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('festgeschrieben');

            $service->sync(
                ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']],
                [
                    'client_request_id' => 'phpunit-locked-' . $suffix,
                    'action' => 'check_in',
                    'project_id' => $projectId,
                    'work_date' => '2026-06-02',
                    'start_time' => '07:00',
                ]
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConfig(): array
    {
        $config = ConfigRepository::load(['database']);
        $settings = new DatabaseSettingsManager(
            (array) $config->get('database.connections.mysql', []),
            (string) $config->get('database.override_file')
        );

        return $settings->current();
    }

    /**
     * @param callable(DatabaseConnection): void $callback
     */
    private function withScratchDatabase(callable $callback): void
    {
        $baseConfig = $this->databaseConfig();
        $baseConnection = new DatabaseConnection($baseConfig);

        if (!$baseConnection->isAvailable()) {
            self::fail('Keine Test-Datenbank verfuegbar.');
        }

        $pdo = $baseConnection->pdo();
        self::assertInstanceOf(PDO::class, $pdo);

        $databaseName = 'zeiterfassung_appsync_' . bin2hex(random_bytes(4));
        $quotedDatabaseName = '`' . str_replace('`', '``', $databaseName) . '`';
        $databaseCreated = false;

        try {
            try {
                $pdo->exec('CREATE DATABASE ' . $quotedDatabaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $databaseCreated = true;
            } catch (PDOException $exception) {
                self::fail('Datenbankbenutzer darf keine Scratch-Datenbank anlegen: ' . $exception->getMessage());
            }

            $config = $baseConfig;
            $config['database'] = $databaseName;
            $connection = new DatabaseConnection($config);
            $this->createSchema($connection);

            $callback($connection);
        } finally {
            if ($databaseCreated) {
                try {
                    $pdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabaseName);
                } catch (PDOException) {
                    // Best-effort cleanup for scratch database.
                }
            }
        }
    }

    private function seedAssignedUserAndProject(DatabaseConnection $connection, string $prefix): array
    {
        $suffix = bin2hex(random_bytes(4));
        $userEmail = 'phpunit-' . $prefix . '-' . $suffix . '@example.test';
        $projectNumber = 'PHP-' . strtoupper($prefix) . '-' . $suffix;

        $connection->execute(
            'INSERT INTO users (
                employee_number, first_name, last_name, email, password_hash, employment_status, time_tracking_required, created_at, updated_at, is_deleted
             ) VALUES (
                :employee_number, "PHPUnit", "Mitarbeiter", :email, "", "active", 1, NOW(), NOW(), 0
             )',
            [
                'employee_number' => strtoupper($prefix) . '-' . $suffix,
                'email' => $userEmail,
            ]
        );
        $userId = $connection->lastInsertId();

        $connection->execute(
            'INSERT INTO projects (
                project_number, name, status, created_at, updated_at, is_deleted
             ) VALUES (
                :project_number, "PHPUnit Projekt", "active", NOW(), NOW(), 0
             )',
            ['project_number' => $projectNumber]
        );
        $projectId = $connection->lastInsertId();

        $connection->execute(
            'INSERT INTO project_memberships (project_id, user_id, assignment_role, assigned_from, assigned_until)
             VALUES (:project_id, :user_id, NULL, :assigned_from, :assigned_until)',
            [
                'project_id' => $projectId,
                'user_id' => $userId,
                'assigned_from' => '2026-01-01',
                'assigned_until' => '2026-12-31',
            ]
        );

        return [$userId, $projectId, $suffix];
    }

    private function createSyncService(DatabaseConnection $connection): AppTimesheetSyncService
    {
        return new AppTimesheetSyncService(
            $connection,
            new TimesheetCalculator(),
            new CompanySettingsService($connection, []),
            new WorkdayStateCalculator()
        );
    }

    private function createSchema(DatabaseConnection $connection): void
    {
        $connection->execute(
            'CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                employee_number VARCHAR(30) NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                email VARCHAR(150) NULL,
                password_hash VARCHAR(255) NULL,
                employment_status ENUM("active","inactive","terminated") DEFAULT "active",
                time_tracking_required TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_deleted TINYINT(1) DEFAULT 0
            )'
        );

        $connection->execute(
            'CREATE TABLE projects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_number VARCHAR(40) NULL,
                name VARCHAR(150) NULL,
                customer_name VARCHAR(150) NULL,
                customer_signature_required TINYINT(1) DEFAULT 0,
                customer_signature_name VARCHAR(190) NULL,
                status ENUM("planning","active","paused","completed","archived") DEFAULT "planning",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_deleted TINYINT(1) DEFAULT 0
            )'
        );

        $connection->execute(
            'CREATE TABLE project_memberships (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                assignment_role VARCHAR(80) NULL,
                assigned_from DATE NULL,
                assigned_until DATE NULL
            )'
        );

        $connection->execute(
            'CREATE TABLE timesheets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                project_id INT UNSIGNED NULL,
                created_by_user_id INT UNSIGNED NULL,
                work_date DATE NULL,
                start_time TIME NULL,
                end_time TIME NULL,
                gross_minutes INT DEFAULT 0,
                break_minutes INT DEFAULT 0,
                net_minutes INT DEFAULT 0,
                expenses_amount DECIMAL(10,2) DEFAULT 0.00,
                entry_type ENUM("work","sick","vacation","holiday","absent") DEFAULT "work",
                source VARCHAR(40) DEFAULT "app",
                note TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_deleted TINYINT(1) DEFAULT 0,
                deleted_at DATETIME NULL,
                deleted_by_user_id INT UNSIGNED NULL
            )'
        );

        $connection->execute(
            'CREATE TABLE app_sync_operations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                client_request_id VARCHAR(120) NULL,
                operation_type VARCHAR(50) NULL,
                response_json TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_client_request_id (client_request_id)
            )'
        );

        $connection->execute(
            'CREATE TABLE timesheet_breaks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                timesheet_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                break_started_at DATETIME NOT NULL,
                break_ended_at DATETIME NULL,
                source VARCHAR(40) DEFAULT "app",
                note TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $connection->execute(
            'CREATE TABLE accounting_closures (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                closure_number VARCHAR(80) NOT NULL UNIQUE,
                closure_type ENUM("month","project") NOT NULL,
                status ENUM("draft","final","correction") NOT NULL DEFAULT "final",
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                project_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                original_closure_id INT UNSIGNED NULL,
                snapshot_hash VARCHAR(64) NOT NULL DEFAULT "",
                item_count INT NOT NULL DEFAULT 0,
                total_net_minutes INT NOT NULL DEFAULT 0,
                created_by_user_id INT UNSIGNED NULL,
                finalized_by_user_id INT UNSIGNED NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                finalized_at DATETIME NULL,
                note TEXT NULL
            )'
        );

        $connection->execute(
            'CREATE TABLE accounting_closure_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                closure_id INT UNSIGNED NOT NULL,
                timesheet_id INT UNSIGNED NOT NULL,
                UNIQUE KEY uniq_accounting_closure_items_timesheet (timesheet_id)
            )'
        );
    }
}
