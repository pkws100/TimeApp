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

    public function testAccountingClosureGuardsProtectAppSyncWrites(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Timesheets/AppTimesheetSyncService.php'));

        self::assertStringContainsString('assertAccountingWriteAllowed($entry, $userId, $projectId, $workDate)', $source);
        self::assertStringContainsString('assertTimesheetNotLockedByAccountingClosure($timesheetId)', $source);
        self::assertStringContainsString('accounting_closure_items.timesheet_id = :timesheet_id', $source);
        self::assertStringContainsString('period_start <= :work_date_start', $source);
        self::assertStringContainsString('period_end >= :work_date_end', $source);
    }

    public function testTerminalSourceCanBePersistedThroughAppSyncPipeline(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/Timesheets/AppTimesheetSyncService.php'));

        self::assertStringContainsString('$source = $this->normalizeSource($payload[\'source\']', $source);
        self::assertStringContainsString('return in_array($source, [\'app\', \'terminal\'], true) ? $source : \'app\';', $source);
        self::assertStringContainsString('"work", :source, :note', $source);
        self::assertStringContainsString('source = :source', $source);
    }
}
