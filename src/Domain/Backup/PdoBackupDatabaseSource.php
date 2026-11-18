<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Infrastructure\Database\DatabaseConnection;

final class PdoBackupDatabaseSource implements BackupDatabaseSource
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function applicationTables(): array
    {
        return $this->tablesOfType('BASE TABLE');
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
        return $this->connection->fetchAll('SELECT * FROM ' . $this->quoteTable($table));
    }

    public function hasRows(string $table, bool $includeHistory = false): bool
    {
        $history = $includeHistory ? ' FOR SYSTEM_TIME ALL' : '';

        return (bool) $this->connection->fetchColumn('SELECT EXISTS (SELECT 1 FROM ' . $this->quoteTable($table) . $history . ')');
    }

    private function quoteTable(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }
}
