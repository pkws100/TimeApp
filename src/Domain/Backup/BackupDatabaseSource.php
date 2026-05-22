<?php

declare(strict_types=1);

namespace App\Domain\Backup;

interface BackupDatabaseSource
{
    public function applicationTables(): array;

    public function fetchRows(string $table): array;
}
