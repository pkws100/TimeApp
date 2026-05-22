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
        if (!$this->connection->isAvailable()) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_type = "BASE TABLE"
             ORDER BY table_name ASC'
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
        return $this->connection->fetchAll('SELECT * FROM `' . $table . '`');
    }
}
