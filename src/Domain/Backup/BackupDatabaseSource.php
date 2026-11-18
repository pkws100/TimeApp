<?php

declare(strict_types=1);

namespace App\Domain\Backup;

interface BackupDatabaseSource
{
    public function applicationTables(): array;

    public function fetchRows(string $table): array;

    public function systemVersionedTables(): array;

    public function hasRows(string $table, bool $includeHistory = false): bool;
}
