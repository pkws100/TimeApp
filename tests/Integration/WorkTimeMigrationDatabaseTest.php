<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\MariaDbScratchConfig;

final class WorkTimeMigrationDatabaseTest extends TestCase
{
    public function testUpgradeRetryAndRollbackPreserveExistingSystemVersionedTimesheets(): void
    {
        $server = MariaDbScratchConfig::connectServer();
        $name = 'timeapp_work_time_migration_' . bin2hex(random_bytes(5));
        $configFile = sys_get_temp_dir() . '/timeapp-work-time-phinx-' . bin2hex(random_bytes(5)) . '.php';
        $server->exec('CREATE DATABASE ' . MariaDbScratchConfig::quoteIdentifier($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $config = ['paths' => ['migrations' => base_path('migrations'), 'seeds' => base_path('seeds')], 'environments' => ['default_migration_table' => 'phinxlog', 'default_environment' => 'test', 'test' => MariaDbScratchConfig::phinxEnvironment($name)], 'version_order' => 'creation'];
            file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';');
            chmod($configFile, 0600);
            $this->phinx($configFile, 'migrate', '20261106120000');
            $connection = new DatabaseConnection(MariaDbScratchConfig::connection($name));
            $connection->execute('INSERT INTO users (first_name,last_name,email,password_hash) VALUES ("Alt", "Bestand", "old@example.test", "synthetic-test-hash")');
            $user = $connection->lastInsertId();
            $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time, end_time, net_minutes, entry_type) VALUES (:user, "2025-01-02", "08:00:00", "12:00:00", 240, "work")', ['user' => $user]);
            $timesheet = $connection->lastInsertId();
            $connection->execute('UPDATE timesheets SET end_time = "13:00:00", net_minutes = 300 WHERE id = :id', ['id' => $timesheet]);
            $before = $connection->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL ORDER BY row_start');
            self::assertCount(2, $before);
            $this->phinx($configFile, 'migrate');
            self::assertSame($before, $connection->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL ORDER BY row_start'));
            foreach (['work_time_policy_templates', 'work_time_checkout_proposals', 'work_time_deviation_decisions', 'work_time_presence_sessions', 'work_time_notification_events'] as $table) { self::assertTrue($connection->tableExists($table)); }
            // Simulate a retry after nontransactional DDL committed before Phinx recorded completion.
            $connection->execute('DELETE FROM phinxlog WHERE version = 20261111120000');
            $this->phinx($configFile, 'migrate');
            self::assertSame($before, $connection->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL ORDER BY row_start'));
            $this->phinx($configFile, 'rollback', '20261106120000');
            self::assertFalse($connection->tableExists('work_time_policy_templates'));
            self::assertSame($before, $connection->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL ORDER BY row_start'));
            $this->phinx($configFile, 'migrate');
            self::assertTrue($connection->tableExists('work_time_policy_templates'));
            self::assertSame($before, $connection->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL ORDER BY row_start'));
        } finally {
            $server->exec('DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier($name));
            if (is_file($configFile)) { unlink($configFile); }
        }
    }

    private function phinx(string $configFile, string $action, ?string $target = null): void
    {
        $cmd = [PHP_BINARY, base_path('vendor/bin/phinx'), $action, '-c', $configFile, '-e', 'test'];
        if ($target !== null) { $cmd[] = '-t'; $cmd[] = $target; }
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (!is_resource($process)) { throw new RuntimeException('Phinx konnte nicht gestartet werden.'); }
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException($out . $err); }
    }
}
