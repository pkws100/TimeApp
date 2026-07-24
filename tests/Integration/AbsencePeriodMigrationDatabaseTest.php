<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDOException;
use RuntimeException;
use Tests\Support\MariaDbScratchConfig;
use Tests\Support\MariaDbTestCase;

final class AbsencePeriodMigrationDatabaseTest extends MariaDbTestCase
{
    public function testUpgradeBackfillsOnlyApprovedRequestRowsAndPreservesVersionHistoryAndForeignKey(): void
    {
        $this->runPhinx('rollback', '20260723190000');

        $userId = $this->createUser([
            'employee_number' => 'MIGRATION-AP',
            'email' => 'migration-ap@example.test',
        ]);
        $this->connection()->execute(
            'INSERT INTO vacation_requests (
                user_id, date_from, date_to, day_count, status, employee_note,
                requested_at, decided_at, decided_by_user_id, created_at, updated_at, is_deleted
             ) VALUES (
                :user_id, "2026-08-03", "2026-08-05", 3, "approved", "Altantrag",
                NOW(), NOW(), :decided_by, NOW(), NOW(), 0
             )',
            ['user_id' => $userId, 'decided_by' => $userId]
        );
        $requestId = $this->connection()->lastInsertId();
        $linkedTimesheetId = $this->insertLegacyVacation($userId, '2026-08-03', $requestId, 'Altstand');
        $standaloneTimesheetId = $this->insertLegacyVacation($userId, '2026-08-10', null, 'Freie Einzelbuchung');
        $this->connection()->execute(
            'UPDATE timesheets SET note = "Aktueller Stand" WHERE id = :id',
            ['id' => $linkedTimesheetId]
        );
        $historyBefore = (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id',
            ['id' => $linkedTimesheetId]
        );
        self::assertGreaterThanOrEqual(2, $historyBefore);

        $this->runPhinx('migrate', '20260724120000');

        $period = $this->connection()->fetchOne(
            'SELECT * FROM absence_periods WHERE vacation_request_id = :request_id',
            ['request_id' => $requestId]
        );
        self::assertNotNull($period);
        self::assertSame('vacation_request', (string) $period['source']);
        self::assertSame(1, (int) $period['booked_day_count']);
        self::assertSame((int) $period['id'], (int) $this->connection()->fetchColumn(
            'SELECT absence_period_id FROM timesheets WHERE id = :id',
            ['id' => $linkedTimesheetId]
        ));
        self::assertNull($this->connection()->fetchColumn(
            'SELECT absence_period_id FROM timesheets WHERE id = :id',
            ['id' => $standaloneTimesheetId]
        ));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM absence_periods'));
        self::assertGreaterThanOrEqual($historyBefore, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id',
            ['id' => $linkedTimesheetId]
        ));
        self::assertSame(1, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id AND note = "Altstand"',
            ['id' => $linkedTimesheetId]
        ));
        self::assertSame('ERROR', strtoupper((string) $this->connection()->fetchColumn(
            'SELECT @@SESSION.system_versioning_alter_history'
        )));

        try {
            $this->connection()->execute(
                'UPDATE timesheets SET absence_period_id = 99999999 WHERE id = :id',
                ['id' => $standaloneTimesheetId]
            );
            self::fail('Der Fremdschluessel muss unbekannte Zeitraum-IDs abweisen.');
        } catch (PDOException) {
            self::assertNull($this->connection()->fetchColumn(
                'SELECT absence_period_id FROM timesheets WHERE id = :id',
                ['id' => $standaloneTimesheetId]
            ));
        }
    }

    private function insertLegacyVacation(int $userId, string $date, ?int $requestId, string $note): int
    {
        $this->connection()->execute(
            'INSERT INTO timesheets (
                user_id, project_id, created_by_user_id, work_date,
                start_time, end_time, gross_minutes, break_minutes, net_minutes,
                credited_minutes, expenses_amount, entry_type, absence_reason_code,
                source, vacation_request_id, note, created_at, updated_at, is_deleted
             ) VALUES (
                :user_id, NULL, :created_by, :work_date,
                NULL, NULL, 0, 0, 0,
                480, 0, "vacation", "vacation_paid",
                "admin", :vacation_request_id, :note, NOW(), NOW(), 0
             )',
            [
                'user_id' => $userId,
                'created_by' => $userId,
                'work_date' => $date,
                'vacation_request_id' => $requestId,
                'note' => $note,
            ]
        );

        return $this->connection()->lastInsertId();
    }

    private function runPhinx(string $command, string $target): void
    {
        $database = (string) ($this->connectionConfig()['database'] ?? '');
        $configPath = tempnam(sys_get_temp_dir(), 'timeapp-absence-migration-');

        if ($database === '' || $configPath === false) {
            throw new RuntimeException('Phinx-Testkonfiguration konnte nicht vorbereitet werden.');
        }

        $config = [
            'paths' => ['migrations' => base_path('migrations'), 'seeds' => base_path('seeds')],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'test',
                'test' => MariaDbScratchConfig::phinxEnvironment($database),
            ],
            'version_order' => 'creation',
        ];
        file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");

        try {
            $process = proc_open(
                [PHP_BINARY, base_path('vendor/bin/phinx'), $command, '-c', $configPath, '-e', 'test', '-t', $target],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path()
            );

            if (!is_resource($process)) {
                throw new RuntimeException('Phinx-Prozess konnte nicht gestartet werden.');
            }

            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new RuntimeException('Phinx-' . $command . " fehlgeschlagen:\n" . $output);
            }
        } finally {
            unlink($configPath);
        }
    }
}
