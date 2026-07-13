<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\MariaDbScratchConfig;

final class RevisableAccountMigrationTest extends TestCase
{
    public function testFreshInstallHasCorrectIndexesForeignKeysAndSystemVersioning(): void
    {
        $environment = $this->createDatabase('fresh');

        try {
            $this->migrate($environment);
            $connection = $environment['connection'];
            self::assertSame(0, (int) $connection->fetchColumn(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = "vacation_account_entries"
                   AND index_name = "uniq_vacation_account_entries_year_opening"'
            ));
            self::assertSame(1, (int) $connection->fetchColumn(
                'SELECT COUNT(*) FROM (
                    SELECT index_name
                    FROM information_schema.statistics
                    WHERE table_schema = DATABASE()
                      AND table_name = "vacation_account_entries"
                      AND non_unique = 0
                    GROUP BY index_name
                    HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ",") = "source_type,source_id,entry_type"
                 ) AS matching_indexes'
            ));
            self::assertSame('SYSTEM VERSIONED', strtoupper((string) $connection->fetchColumn(
                'SELECT table_type FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "timesheets"'
            )));
            self::assertSame('RESTRICT', strtoupper((string) $connection->fetchColumn(
                'SELECT delete_rule FROM information_schema.referential_constraints
                 WHERE constraint_schema = DATABASE()
                   AND table_name = "vacation_account_entries"
                   AND referenced_table_name = "users"
                 LIMIT 1'
            )));
        } finally {
            $this->dropDatabase($environment);
        }
    }

    public function testUpgradeKeepsOnlyProvableGenerationAssignments(): void
    {
        $environment = $this->createDatabase('upgrade');

        try {
            $this->migrate($environment, '20260713123000');
            $connection = $environment['connection'];
            $connection->execute(
                'INSERT INTO users (employee_number, first_name, last_name, email, password_hash, employment_status, created_at, updated_at, is_deleted)
                 VALUES ("M-UPGRADE", "Test", "Upgrade", "upgrade@example.test", "", "active", "2019-01-01", "2019-01-01", 0)'
            );
            $userId = $connection->lastInsertId();
            foreach (['2019-01-01 08:00:00', '2019-02-01 08:00:00'] as $createdAt) {
                $connection->execute(
                    'INSERT INTO employee_account_cutovers (
                        user_id, active_final_user_id, effective_from, opening_time_balance_minutes, leave_year,
                        annual_leave_entitlement_days, leave_carryover_days, opening_remaining_leave_days,
                        status, created_at, updated_at
                     ) VALUES (:user_id, NULL, "2020-01-01", 0, 2020, 30, 0, 30, "reversed", :created_at, :updated_at)',
                    ['user_id' => $userId, 'created_at' => $createdAt, 'updated_at' => $createdAt]
                );
            }
            $cutoverIds = array_map('intval', array_column($connection->fetchAll('SELECT id FROM employee_account_cutovers ORDER BY id'), 'id'));
            $connection->execute(
                'INSERT INTO time_account_entries (
                    user_id, cutover_id, effective_date, minutes, entry_type, source_type, source_id, description, created_at
                 ) VALUES (:user_id, :wrong_cutover, "2020-03-01", 60, "manual_adjustment", NULL, NULL, "Mehrdeutig", "2020-03-01 08:00:00")',
                ['user_id' => $userId, 'wrong_cutover' => $cutoverIds[1]]
            );
            $ambiguousId = $connection->lastInsertId();
            $connection->execute(
                'INSERT INTO time_account_entries (
                    user_id, cutover_id, effective_date, minutes, entry_type, source_type, source_id, description, created_at
                 ) VALUES (:user_id, :wrong_cutover, "2020-01-01", 120, "opening_balance", "employee_account_cutover", :source_id, "Direkt", "2020-03-01 08:00:00")',
                ['user_id' => $userId, 'wrong_cutover' => $cutoverIds[1], 'source_id' => $cutoverIds[0]]
            );
            $directId = $connection->lastInsertId();
            $connection->execute(
                'INSERT INTO time_account_entries (
                    user_id, cutover_id, effective_date, minutes, entry_type, source_type, source_id,
                    description, reversal_of_id, created_at
                 ) VALUES (:user_id, :wrong_cutover, "2020-01-01", -120, "reversal", "time_account_entry", :source_id,
                           "Reversal", :reversal_of_id, "2020-03-02 08:00:00")',
                [
                    'user_id' => $userId,
                    'wrong_cutover' => $cutoverIds[1],
                    'source_id' => $directId,
                    'reversal_of_id' => $directId,
                ]
            );
            $reversalId = $connection->lastInsertId();

            $this->migrate($environment);

            self::assertNull($connection->fetchColumn('SELECT cutover_id FROM time_account_entries WHERE id = :id', ['id' => $ambiguousId]));
            self::assertSame($cutoverIds[0], (int) $connection->fetchColumn('SELECT cutover_id FROM time_account_entries WHERE id = :id', ['id' => $directId]));
            self::assertSame($cutoverIds[0], (int) $connection->fetchColumn('SELECT cutover_id FROM time_account_entries WHERE id = :id', ['id' => $reversalId]));
        } finally {
            $this->dropDatabase($environment);
        }
    }

    private function createDatabase(string $suffix): array
    {
        try {
            $server = MariaDbScratchConfig::connectServer();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Der Migrationstest benoetigt eine erreichbare MariaDB-Testinstanz: ' . $exception->getMessage(), 0, $exception);
        }

        $name = 'timeapp_migration_' . $suffix . '_' . bin2hex(random_bytes(4));
        $phinxFile = sys_get_temp_dir() . '/timeapp-migration-phinx-' . bin2hex(random_bytes(5)) . '.php';

        try {
            $server->exec('CREATE DATABASE ' . MariaDbScratchConfig::quoteIdentifier($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $phinxConfig = [
                'paths' => ['migrations' => base_path('migrations'), 'seeds' => base_path('seeds')],
                'environments' => [
                    'default_migration_table' => 'phinxlog',
                    'default_environment' => 'test',
                    'test' => MariaDbScratchConfig::phinxEnvironment($name),
                ],
                'version_order' => 'creation',
            ];
            if (file_put_contents($phinxFile, "<?php\nreturn " . var_export($phinxConfig, true) . ";\n") === false) {
                throw new RuntimeException('Die temporaere Phinx-Konfiguration konnte nicht geschrieben werden.');
            }
            chmod($phinxFile, 0600);

            return [
                'server' => $server,
                'name' => $name,
                'connection' => new DatabaseConnection(MariaDbScratchConfig::connection($name)),
                'phinx' => $phinxFile,
            ];
        } catch (\Throwable $exception) {
            try {
                $server->exec('DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier($name));
            } finally {
                if (is_file($phinxFile)) {
                    unlink($phinxFile);
                }
            }
            throw new RuntimeException('Der Migrationstest benoetigt CREATE DATABASE fuer eine isolierte Scratch-Datenbank: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function migrate(array $environment, ?string $target = null): void
    {
        $command = [PHP_BINARY, base_path('vendor/bin/phinx'), 'migrate', '-c', $environment['phinx'], '-e', 'test'];
        if ($target !== null) {
            $command[] = '-t';
            $command[] = $target;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (!is_resource($process)) {
            throw new RuntimeException('Phinx konnte nicht gestartet werden.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Phinx-Migration fehlgeschlagen: ' . $output);
        }
    }

    private function dropDatabase(array $environment): void
    {
        $environment['connection'] = null;
        try {
            $environment['server']->exec('DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier($environment['name']));
        } finally {
            if (is_file($environment['phinx'])) {
                unlink($environment['phinx']);
            }
        }
    }
}
