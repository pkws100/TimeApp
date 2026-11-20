<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Personnel\Backup\PersonnelBackupTables;
use App\Infrastructure\Database\DatabaseConnection;
use PDOException;
use RuntimeException;

final class PdoBackupDatabaseSource implements BackupDatabaseSource
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function applicationTables(): array
    {
        if (!$this->connection->isAvailable()) {
            return [];
        }

        $tables = array_values(array_unique([...$this->tablesOfType('BASE TABLE'), ...array_keys($this->privatePresence())]));
        sort($tables);

        return $tables;
    }

    public function systemVersionedTables(): array
    {
        return $this->tablesOfType('SYSTEM VERSIONED');
    }

    private function tablesOfType(string $type): array
    {
        if (!$this->connection->isAvailable()) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_type = :table_type
             ORDER BY table_name ASC',
            ['table_type' => $type]
        );

        return array_values(
            array_filter(
                array_map(static fn (array $row): string => (string) ($row['table_name'] ?? ''), $rows),
                static fn (string $table): bool => $table !== ''
            )
        );
    }

    public function fetchRows(string $table): array
    {
        if (isset(PersonnelBackupTables::TABLES[strtolower($table)])) {
            throw new RuntimeException('Personenbezogene Zeilen der Personalakten-Tabellen sind vom Systembackup ausgeschlossen.');
        }

        return $this->connection->fetchAll('SELECT * FROM ' . $this->quoteTable($table));
    }

    public function hasRows(string $table, bool $includeHistory = false): bool
    {
        if (isset(PersonnelBackupTables::TABLES[$table])) {
            return $this->privatePresence()[$table];
        }
        $history = $includeHistory ? ' FOR SYSTEM_TIME ALL' : '';

        return (bool) $this->connection->fetchColumn('SELECT EXISTS (SELECT 1 FROM ' . $this->quoteTable($table) . $history . ')');
    }

    /** @return array<string, bool> */
    private function privatePresence(): array
    {
        $incomplete = 'Das installierte Datenbankschema ist fuer das Systembackup nicht vollstaendig sichtbar.';
        try {
            $rows = $this->connection->fetchAll('SELECT * FROM personnel_backup_presence');
        } catch (PDOException) {
            throw new RuntimeException($incomplete);
        }
        if (count($rows) !== 1 || array_diff_key($rows[0], PersonnelBackupTables::TABLES) !== []
            || array_diff_key(PersonnelBackupTables::TABLES, $rows[0]) !== []) {
            throw new RuntimeException($incomplete);
        }

        return array_map(static function (mixed $value) use ($incomplete): bool {
            if (!in_array($value, [0, 1, '0', '1'], true)) {
                throw new RuntimeException($incomplete);
            }

            return (bool) $value;
        }, $rows[0]);
    }

    private function quoteTable(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }
}
