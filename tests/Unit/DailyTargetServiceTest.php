<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class DailyTargetServiceTest extends TestCase
{
    public function testMonthlyTargetIsDistributedInDeterministicIntegerMinutes(): void
    {
        $service = new DailyTargetService(new CalendarPolicyService(new DatabaseConnection([])));
        $days = $service->breakdown([
            'id' => 7,
            'target_hours_mode' => 'month',
            'target_hours_month' => 160,
            'workdays_mask' => '1,2,3,4,5',
        ], '2026-06-01', '2026-06-30');

        self::assertSame(9600, array_sum(array_column($days, 'contract_minutes')));
        self::assertSame($days, $service->breakdown([
            'id' => 7,
            'target_hours_mode' => 'month',
            'target_hours_month' => 160,
            'workdays_mask' => '1,2,3,4,5',
        ], '2026-06-01', '2026-06-30'));
    }

    public function testIndividualWorkdaysControlDailyTarget(): void
    {
        $service = new DailyTargetService(new CalendarPolicyService(new DatabaseConnection([])));
        $user = [
            'id' => 8,
            'target_hours_mode' => 'week',
            'target_hours_week' => 24,
            'workdays_mask' => '2,3,4',
        ];

        self::assertSame(0, $service->effectiveTargetForDate($user, '2026-05-11'));
        self::assertSame(480, $service->effectiveTargetForDate($user, '2026-05-12'));
    }
}
