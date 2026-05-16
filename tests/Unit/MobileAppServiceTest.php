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
        self::assertFalse($method->invoke($service, '2026-05-15', null, null, false));
    }

    public function testHistoryMonthBoundsUseFullCalendarMonth(): void
    {
        $service = $this->service();
        $normalize = new ReflectionMethod($service, 'normalizeHistoryMonth');
        $bounds = new ReflectionMethod($service, 'timesheetHistoryBounds');
        $normalize->setAccessible(true);
        $bounds->setAccessible(true);

        self::assertSame('2026-05', $normalize->invoke($service, '2026-05'));
        self::assertSame(['2026-05-01', '2026-05-31'], $bounds->invoke($service, '2026-05'));
        self::assertSame([null, null], $bounds->invoke($service, null));
    }

    public function testInvalidHistoryFiltersAreRejected(): void
    {
        $service = $this->service();
        $month = new ReflectionMethod($service, 'normalizeHistoryMonth');
        $entryType = new ReflectionMethod($service, 'normalizeHistoryEntryType');
        $month->setAccessible(true);
        $entryType->setAccessible(true);

        try {
            $month->invoke($service, '2026-13');
            self::fail('Ungueltiger Monat muss abgewiesen werden.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Bitte einen gueltigen Monat im Format JJJJ-MM angeben.', $exception->getMessage());
        }

        try {
            $entryType->invoke($service, 'deleted');
            self::fail('Ungueltiger Entry-Type muss abgewiesen werden.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Bitte einen gueltigen Buchungstyp auswaehlen.', $exception->getMessage());
        }
    }

    public function testHistoryPayloadAggregatesSummaryAndDays(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'buildTimesheetHistoryPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            [
                'id' => 1,
                'project_id' => 2,
                'work_date' => '2026-05-15',
                'entry_type' => 'work',
                'break_minutes' => 30,
                'net_minutes' => 450,
                'attachment_count' => 2,
                'attachments' => [],
            ],
            [
                'id' => 2,
                'project_id' => null,
                'work_date' => '2026-05-15',
                'entry_type' => 'sick',
                'break_minutes' => 0,
                'net_minutes' => 0,
                'attachment_count' => 0,
                'attachments' => [],
            ],
            [
                'id' => 3,
                'project_id' => 3,
                'work_date' => '2026-05-14',
                'entry_type' => 'work',
                'break_minutes' => 15,
                'net_minutes' => 240,
                'attachment_count' => 1,
                'attachments' => [],
            ],
        ]);

        self::assertSame(690, $payload['summary']['total_net_minutes']);
        self::assertSame(45, $payload['summary']['total_break_minutes']);
        self::assertSame(3, $payload['summary']['entry_count']);
        self::assertSame(2, $payload['summary']['work_entry_count']);
        self::assertSame(1, $payload['summary']['absence_entry_count']);
        self::assertSame(3, $payload['summary']['attachment_count']);
        self::assertSame(3, $payload['summary']['project_count']);
        self::assertCount(2, $payload['projects']);

        self::assertCount(2, $payload['days']);
        self::assertSame('2026-05-15', $payload['days'][0]['date']);
        self::assertSame(2, $payload['days'][0]['entry_count']);
        self::assertSame(1, $payload['days'][0]['status_counts']['work']);
        self::assertSame(1, $payload['days'][0]['status_counts']['sick']);
        self::assertSame(2, $payload['days'][0]['attachment_count']);
    }

    public function testHistoryItemKeepsSafeGeoMetadata(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'normalizeHistoryItem');
        $method->setAccessible(true);

        $item = $method->invoke($service, [
            'id' => 5,
            'project_id' => 2,
            'project_name' => 'Baustelle Mitte',
            'work_date' => '2026-05-15',
            'start_time' => '07:30:00',
            'end_time' => '16:00:00',
            'break_minutes' => 30,
            'net_minutes' => 480,
            'entry_type' => 'work',
            'note' => null,
            'updated_at' => '2026-05-15 16:00:00',
        ], [], [], [[
            'id' => 9,
            'latitude' => 52.520008,
            'longitude' => 13.404954,
            'accuracy_meters' => 24,
            'recorded_at' => '2026-05-15T07:30:00+02:00',
            'map_url' => 'https://www.openstreetmap.org/?mlat=52.5200080&mlon=13.4049540#map=18/52.5200080/13.4049540',
        ]]);

        self::assertSame(1, $item['geo_count']);
        self::assertSame(52.520008, $item['latest_geo']['latitude']);
        self::assertArrayHasKey('map_url', $item['latest_geo']);
        self::assertArrayNotHasKey('user_id', $item['latest_geo']);
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
