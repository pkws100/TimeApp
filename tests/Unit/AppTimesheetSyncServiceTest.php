<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AppTimesheetSyncServiceTest extends TestCase
{
    public function testProjectMembershipVisibilityQueryUsesUniqueDatePlaceholders(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Timesheets/AppTimesheetSyncService.php'));

        self::assertStringContainsString('project_memberships.assigned_from <= :work_date_from', $source);
        self::assertStringContainsString('project_memberships.assigned_until >= :work_date_until', $source);
        self::assertStringNotContainsString('project_memberships.assigned_from <= :work_date)', $source);
        self::assertStringNotContainsString('project_memberships.assigned_until >= :work_date)', $source);
    }
}
