<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\ConfigRepository;
use App\Domain\Exports\AccountingClosureService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class AccountingClosureDatabaseTest extends TestCase
{
    public function testFinalClosureCreatesSnapshotAndLocksIncludedBooking(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            $ids = $this->seedValidBooking($connection);
            $bookingService = new AdminBookingService($connection, new TimesheetCalculator());
            $service = new AccountingClosureService($connection, $bookingService);

            $closure = $service->createClosure(['type' => 'month', 'period' => '2026-05'], (int) $ids['user_id']);

            self::assertSame('final', $closure['closure']['status']);
            self::assertSame(1, (int) $closure['closure']['item_count']);
            self::assertSame(480, (int) $closure['closure']['total_net_minutes']);
            self::assertSame(64, strlen((string) $closure['closure']['snapshot_hash']));
            self::assertSame('M-7 Nina Feld', $closure['employee_totals'][0]['label']);
            self::assertSame('P-1 Kita Nord', $closure['project_totals'][0]['label']);

            $this->expectException(InvalidArgumentException::class);
            $bookingService->update((int) $ids['timesheet_id'], [
                'project_id' => (string) $ids['project_id'],
                'work_date' => '2026-05-04',
                'entry_type' => 'work',
                'start_time' => '07:15',
                'end_time' => '15:30',
                'break_minutes' => '30',
            ], (int) $ids['user_id'], 'Korrektur nach Abschluss');
        });
    }

    public function testFinalizationValidationBlocksOpenMissingProjectAndArchivedRows(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            $ids = $this->seedValidBooking($connection);
            $connection->execute(
                'INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source, note, updated_at, is_deleted)
                 VALUES (:user_id, NULL, "2026-05-05", "07:00:00", "12:00:00", 300, 0, 300, "work", "app", "Ohne Projekt", NOW(), 0)',
                ['user_id' => $ids['user_id']]
            );
            $connection->execute(
                'INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source, note, updated_at, is_deleted)
                 VALUES (:user_id, :project_id, "2026-05-06", "07:00:00", NULL, 0, 0, 0, "work", "app", "Offen", NOW(), 0)',
                ['user_id' => $ids['user_id'], 'project_id' => $ids['project_id']]
            );
            $connection->execute(
                'INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source, note, updated_at, is_deleted)
                 VALUES (:user_id, :project_id, "2026-05-07", "07:00:00", "08:00:00", 60, 0, 60, "work", "app", "Archiv", NOW(), 1)',
                ['user_id' => $ids['user_id'], 'project_id' => $ids['project_id']]
            );

            $service = new AccountingClosureService($connection, new AdminBookingService($connection, new TimesheetCalculator()));
            $validation = $service->validateFinalization($service->selectionFromInput(['type' => 'month', 'period' => '2026-05']));

            self::assertFalse($validation['ok']);
            self::assertContains('Im Abschlussbereich liegen archivierte Buchungen. Bitte den Bereich pruefen und bewusst bereinigen.', $validation['errors']);
            self::assertContains('Mindestens eine Arbeitsbuchung ist offen oder unvollstaendig.', $validation['errors']);
            self::assertContains('Mindestens eine Arbeitsbuchung hat keine Projektzuordnung.', $validation['errors']);
        });
    }

    public function testDuplicateFinalClosureAndNewNormalBookingInClosedPeriodAreBlocked(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            $ids = $this->seedValidBooking($connection);
            $bookingService = new AdminBookingService($connection, new TimesheetCalculator());
            $service = new AccountingClosureService($connection, $bookingService);
            $service->createClosure(['type' => 'month', 'period' => '2026-05'], (int) $ids['user_id']);

            try {
                $service->createClosure(['type' => 'month', 'period' => '2026-05'], (int) $ids['user_id']);
                self::fail('Duplicate final closure should be blocked.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('bereits', $exception->getMessage());
            }

            $this->expectException(InvalidArgumentException::class);
            $bookingService->createManual([
                'user_id' => (string) $ids['user_id'],
                'project_id' => (string) $ids['project_id'],
                'work_date' => '2026-05-20',
                'entry_type' => 'work',
                'start_time' => '07:00',
                'end_time' => '12:00',
                'break_minutes' => '0',
                'change_reason' => 'Nachtrag nach Abschluss',
            ], (int) $ids['user_id']);
        });
    }

    /**
     * @param callable(DatabaseConnection): void $callback
     */
    private function withScratchDatabase(callable $callback): void
    {
        $baseConfig = $this->databaseConfig();
        $baseConnection = new DatabaseConnection($baseConfig);

        if (!$baseConnection->isAvailable()) {
            self::markTestSkipped('Keine Test-Datenbank verfuegbar.');
        }

        $pdo = $baseConnection->pdo();
        self::assertInstanceOf(PDO::class, $pdo);

        $databaseName = 'zeiterfassung_accounting_' . bin2hex(random_bytes(4));
        $quotedDatabaseName = '`' . str_replace('`', '``', $databaseName) . '`';
        $databaseCreated = false;

        try {
            try {
                $pdo->exec('CREATE DATABASE ' . $quotedDatabaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $databaseCreated = true;
            } catch (PDOException) {
                self::markTestSkipped('Datenbankbenutzer darf keine Scratch-Datenbank anlegen.');
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
                    // Best-effort cleanup.
                }
            }
        }
    }

    private function databaseConfig(): array
    {
        $config = ConfigRepository::load(['database']);
        $settings = new DatabaseSettingsManager(
            (array) $config->get('database.connections.mysql', []),
            (string) $config->get('database.override_file')
        );

        return $settings->current();
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
                status ENUM("planning","active","paused","completed","archived") DEFAULT "active",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_deleted TINYINT(1) DEFAULT 0
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
                work_date DATE NOT NULL,
                user_id INT UNSIGNED NULL,
                employee_number VARCHAR(80) NULL,
                employee_name VARCHAR(220) NOT NULL,
                project_id INT UNSIGNED NULL,
                project_number VARCHAR(80) NULL,
                project_name VARCHAR(220) NOT NULL,
                entry_type VARCHAR(40) NOT NULL,
                source VARCHAR(40) NOT NULL,
                source_label VARCHAR(120) NOT NULL,
                start_time TIME NULL,
                end_time TIME NULL,
                gross_minutes INT NOT NULL DEFAULT 0,
                break_minutes INT NOT NULL DEFAULT 0,
                net_minutes INT NOT NULL DEFAULT 0,
                expenses_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                note TEXT NULL,
                change_count INT NOT NULL DEFAULT 0,
                version_hint VARCHAR(255) NULL,
                booking_updated_at DATETIME NULL,
                row_hash VARCHAR(64) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_accounting_closure_items_timesheet (timesheet_id)
            )'
        );
    }

    private function seedValidBooking(DatabaseConnection $connection): array
    {
        $connection->execute(
            'INSERT INTO users (employee_number, first_name, last_name, email, password_hash, employment_status, created_at, updated_at, is_deleted)
             VALUES ("M-7", "Nina", "Feld", "nina@example.test", "", "active", NOW(), NOW(), 0)'
        );
        $userId = $connection->lastInsertId();
        $connection->execute(
            'INSERT INTO projects (project_number, name, status, created_at, updated_at, is_deleted)
             VALUES ("P-1", "Kita Nord", "active", NOW(), NOW(), 0)'
        );
        $projectId = $connection->lastInsertId();
        $connection->execute(
            'INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source, note, updated_at, is_deleted)
             VALUES (:user_id, :project_id, "2026-05-04", "07:00:00", "15:30:00", 510, 30, 480, "work", "app", "Montage", NOW(), 0)',
            ['user_id' => $userId, 'project_id' => $projectId]
        );

        return [
            'user_id' => $userId,
            'project_id' => $projectId,
            'timesheet_id' => $connection->lastInsertId(),
        ];
    }
}
