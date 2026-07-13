<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmployeeAccountCutoverServiceTest extends TestCase
{
    public function testPreviewRejectsUnknownEmployeeThroughServiceCall(): void
    {
        $connection = new DatabaseConnection([]);
        $service = new EmployeeAccountCutoverService($connection, new AccountJournalService($connection));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mitarbeiter');
        $service->preview([
            'user_id' => 99,
            'effective_from' => '2026-01-01',
            'opening_time_balance' => '0:00',
            'leave_year' => 2026,
        ]);
    }
}
