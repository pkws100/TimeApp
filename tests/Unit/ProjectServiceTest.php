<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Projects\ProjectService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class ProjectServiceTest extends TestCase
{
    public function testSummarizeTrackedNetMinutesOnlyCountsActiveWorkEntries(): void
    {
        $service = new ProjectService(new DatabaseConnection([]));

        $summary = $service->summarizeTrackedNetMinutes([
            ['project_id' => 10, 'entry_type' => 'work', 'net_minutes' => 240, 'is_deleted' => 0],
            ['project_id' => 10, 'entry_type' => 'work', 'net_minutes' => 150, 'is_deleted' => 0],
            ['project_id' => 10, 'entry_type' => 'work', 'net_minutes' => 90, 'is_deleted' => 1],
            ['project_id' => 10, 'entry_type' => 'sick', 'net_minutes' => 480, 'is_deleted' => 0],
            ['project_id' => 10, 'entry_type' => 'vacation', 'net_minutes' => 480, 'is_deleted' => 0],
            ['project_id' => 11, 'entry_type' => 'work', 'net_minutes' => 60, 'is_deleted' => 0],
            ['project_id' => null, 'entry_type' => 'work', 'net_minutes' => 60, 'is_deleted' => 0],
        ]);

        self::assertSame(390, $summary[10]);
        self::assertSame(60, $summary[11]);
        self::assertArrayNotHasKey(0, $summary);
    }

    public function testFallbackProjectsExposeZeroTrackedNetMinutesWithoutDatabase(): void
    {
        $service = new ProjectService(new DatabaseConnection([]));

        $projects = $service->list();

        self::assertNotEmpty($projects);

        foreach ($projects as $project) {
            self::assertArrayHasKey('tracked_net_minutes', $project);
            self::assertSame(0, $project['tracked_net_minutes']);
        }
    }

    public function testArchiveAndRestoreFallbackWithoutDatabase(): void
    {
        $service = new ProjectService(new DatabaseConnection([]));

        self::assertTrue($service->archive(123, 7));
        self::assertTrue($service->restore(123, 7));
    }
}
