<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Users\StorageUsageService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class DashboardServiceTest extends TestCase
{
    public function testChartsProvideCompatibleDatasetsForRequestedPeriod(): void
    {
        $service = new DashboardService(
            new DatabaseConnection([]),
            new StorageUsageService(storage_path()),
            new AttendanceService(new DatabaseConnection([]))
        );

        $charts = $service->buildChartsFromRows([
            [
                'work_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'user_id' => 1,
                'entry_type' => 'work',
                'net_minutes' => 480,
                'project_name' => 'Sanierung Rathaus',
            ],
            [
                'work_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'user_id' => 2,
                'entry_type' => 'sick',
                'net_minutes' => 0,
                'project_name' => null,
            ],
        ], 'week');

        self::assertSame('week', $charts['period']);
        self::assertArrayHasKey('headcount', $charts);
        self::assertArrayHasKey('hours', $charts);
        self::assertArrayHasKey('entry_types', $charts);
        self::assertArrayHasKey('project_allocations', $charts);
        self::assertNotEmpty($charts['headcount']['labels']);
        self::assertContains(1, $charts['headcount']['datasets'][0]['data']);
    }

    public function testChartsReturnEmptyDatasetsWithoutDatabase(): void
    {
        $service = new DashboardService(
            new DatabaseConnection([]),
            new StorageUsageService(storage_path()),
            new AttendanceService(new DatabaseConnection([]))
        );

        $charts = $service->charts('week');

        self::assertSame('week', $charts['period']);
        self::assertSame('empty', $charts['status']);
        self::assertArrayHasKey('headcount', $charts);
        self::assertArrayHasKey('hours', $charts);
        self::assertArrayHasKey('entry_types', $charts);
        self::assertArrayHasKey('project_allocations', $charts);
        self::assertSame([], $charts['headcount']['labels']);
    }

    public function testOverviewWithoutDatabaseDoesNotExposeDemoValues(): void
    {
        $service = new DashboardService(
            new DatabaseConnection([]),
            new StorageUsageService(storage_path()),
            new AttendanceService(new DatabaseConnection([]))
        );

        $overview = $service->overview();

        self::assertSame('empty', $overview['status']);
        self::assertSame(0, $overview['metrics']['anwesend']);
        self::assertSame(0, $overview['metrics']['abwesend']);
        self::assertSame([], $overview['allocations']);
        self::assertSame([], $overview['absences']);
        self::assertSame([], $overview['contacts']);
        self::assertSame(0, $overview['periods']['day']['entries']);
        self::assertSame(0.0, $overview['periods']['day']['hours']);
    }
}
