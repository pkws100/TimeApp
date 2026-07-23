<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use RuntimeException;
use Tests\Support\MariaDbScratchConfig;
use PHPUnit\Framework\TestCase;

final class ProjectWorkOrdersMigrationTest extends TestCase
{
    public function testUpgradePreservesExistingProjectsAndFilesAndCanBeRunAgain(): void
    {
        $environment = $this->scratchEnvironment();

        try {
            $this->migrate($environment, '20260715110000');
            $connection = $environment['connection'];
            $connection->execute(
                'INSERT INTO projects (
                    project_number, name, customer_name, status, created_at, updated_at, is_deleted
                 ) VALUES (
                    "UPGRADE-1", "Bestehendes Projekt", "Bestandskunde", "active", NOW(), NOW(), 0
                 )'
            );
            $projectId = $connection->lastInsertId();
            $connection->execute(
                'INSERT INTO project_files (
                    project_id, original_name, stored_name, mime_type, size_bytes, storage_path,
                    uploaded_at, is_deleted
                 ) VALUES (
                    :project_id, "bestand.pdf", "bestand.pdf", "application/pdf", 42,
                    "projects/bestand.pdf", NOW(), 0
                 )',
                ['project_id' => $projectId]
            );
            $fileId = $connection->lastInsertId();

            $this->migrate($environment);
            $this->migrate($environment);

            self::assertSame('Bestehendes Projekt', $connection->fetchColumn(
                'SELECT name FROM projects WHERE id = :id',
                ['id' => $projectId]
            ));
            self::assertSame('bestand.pdf', $connection->fetchColumn(
                'SELECT original_name FROM project_files WHERE id = :id',
                ['id' => $fileId]
            ));
            self::assertTrue($connection->columnExists('projects', 'work_instructions'));
            self::assertTrue($connection->tableExists('project_material_entries'));
            self::assertTrue($connection->tableExists('project_dispatches'));
            self::assertTrue($connection->tableExists('project_dispatch_recipients'));
        } finally {
            $this->dropScratchEnvironment($environment);
        }
    }

    /**
     * @return array{name:string,server:PDO,connection:DatabaseConnection,phinx:string}
     */
    private function scratchEnvironment(): array
    {
        try {
            $server = MariaDbScratchConfig::connectServer();
            $name = 'timeapp_project_orders_migration_' . bin2hex(random_bytes(5));
            $server->exec(
                'CREATE DATABASE ' . MariaDbScratchConfig::quoteIdentifier($name)
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $phinxFile = sys_get_temp_dir() . '/timeapp-project-orders-phinx-' . bin2hex(random_bytes(5)) . '.php';
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
                'name' => $name,
                'server' => $server,
                'connection' => new DatabaseConnection(MariaDbScratchConfig::connection($name)),
                'phinx' => $phinxFile,
            ];
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Der Projektauftrags-Migrationstest benoetigt MariaDB und CREATE DATABASE: ' . $exception->getMessage(),
                0,
                $exception
            );
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

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException("Phinx-Migration fehlgeschlagen:\n" . $stdout . $stderr);
        }
    }

    private function dropScratchEnvironment(array $environment): void
    {
        try {
            $environment['server']->exec(
                'DROP DATABASE IF EXISTS ' . MariaDbScratchConfig::quoteIdentifier($environment['name'])
            );
        } finally {
            if (is_file($environment['phinx'])) {
                unlink($environment['phinx']);
            }
        }
    }
}
