<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class RevisableTimeAccountServiceTest extends TestCase
{
    public function testMissingEmployeeReturnsNeutralMonthlyAccount(): void
    {
        $connection = new DatabaseConnection([]);
        $account = (new TimeAccountService($connection, new CalendarPolicyService($connection)))
            ->monthlyAccount(99, 2026, 1);

        self::assertSame('missing', $account['cutover_status']);
        self::assertNull($account['closing_balance_minutes']);
        self::assertSame([], $account['time_entries']);
    }
}
