<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\TimeAccounts\AccountJournalService;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AccountJournalServiceTest extends TestCase
{
    public function testJournalWriteRejectsMissingMigratedJournal(): void
    {
        $service = new AccountJournalService(new DatabaseConnection([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('noch nicht migriert');
        $service->addTimeEntry(1, '2026-01-01', 60, 'manual_adjustment', null, null, 'Test', 1);
    }
}
