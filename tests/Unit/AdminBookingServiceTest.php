<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class AdminBookingServiceTest extends TestCase
{
    public function testNormalizeFiltersStabilizesExpectedAdminShape(): void
    {
        $service = new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator());
        $filters = $service->normalizeFilters([
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
            'project_id' => '__none__',
            'user_id' => '7',
            'entry_type' => 'work',
            'scope' => 'archived',
        ]);

        self::assertSame('2026-04-01', $filters['date_from']);
        self::assertSame('2026-04-30', $filters['date_to']);
        self::assertSame('__none__', $filters['project_id']);
        self::assertSame('7', $filters['user_id']);
        self::assertSame('work', $filters['entry_type']);
        self::assertSame('archived', $filters['scope']);
    }

    public function testEntryTypeOptionsExposeStableAdminEditingSet(): void
    {
        $service = new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator());

        self::assertSame(
            ['work', 'sick', 'vacation', 'holiday', 'absent'],
            array_keys($service->entryTypeOptions())
        );
    }
}
