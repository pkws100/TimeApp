<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\App\MobileAppService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MobileAppServiceTest extends TestCase
{
    public function testProjectDaySummariesAggregateRepeatedProjectVisitsAndKeepLatestBlock(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'buildProjectDaySummaries');
        $method->setAccessible(true);

        $summaries = $method->invoke($service, '2026-05-08', [
            [
                'id' => 3,
                'project_id' => 1,
                'project_name' => 'Baustelle 1',
                'work_date' => '2026-05-08',
                'start_time' => '12:30:00',
                'end_time' => '15:00:00',
                'break_minutes' => 0,
                'net_minutes' => 150,
                'note' => null,
            ],
            [
                'id' => 2,
                'project_id' => 2,
                'project_name' => 'Baustelle 2',
                'work_date' => '2026-05-08',
                'start_time' => '10:00:00',
                'end_time' => '12:00:00',
                'break_minutes' => 0,
                'net_minutes' => 120,
                'note' => null,
            ],
            [
                'id' => 1,
                'project_id' => 1,
                'project_name' => 'Baustelle 1',
                'work_date' => '2026-05-08',
                'start_time' => '08:00:00',
                'end_time' => '10:00:00',
                'break_minutes' => 0,
                'net_minutes' => 120,
                'note' => null,
            ],
        ]);

        self::assertCount(2, $summaries);
        self::assertSame(1, $summaries[0]['project_id']);
        self::assertSame(270, $summaries[0]['total_net_minutes']);
        self::assertSame('12:30:00', $summaries[0]['start_time']);
        self::assertSame('15:00:00', $summaries[0]['end_time']);
        self::assertSame(3, $summaries[0]['work_entry']['id']);
        self::assertSame('completed', $summaries[0]['status']);
        self::assertSame(2, $summaries[1]['project_id']);
        self::assertSame(120, $summaries[1]['total_net_minutes']);
    }

    public function testMissingWorkdayIsDerivedOnlyForWeekdaysWithoutEntries(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'isMissingWorkday');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, '2026-05-15', null, null));
        self::assertFalse($method->invoke($service, '2026-05-16', null, null));
        self::assertFalse($method->invoke($service, '2026-05-15', ['id' => 1], null));
        self::assertFalse($method->invoke($service, '2026-05-15', null, ['entry_type' => 'sick']));
    }

    private function service(): MobileAppService
    {
        $connection = new DatabaseConnection([]);

        return new MobileAppService(
            $connection,
            new ProjectService($connection),
            new CompanySettingsService($connection, []),
            new WorkdayStateCalculator(),
            new FileAttachmentService($connection, [])
        );
    }
}
