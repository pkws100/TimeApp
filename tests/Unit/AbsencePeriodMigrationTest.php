<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AbsencePeriodMigrationTest extends TestCase
{
    public function testMigrationPreservesVersionHistoryAndBackfillsOnlyLinkedRequests(): void
    {
        $migration = (string) file_get_contents(base_path('migrations/20260724120000_editable_absence_periods.php'));

        self::assertStringContainsString('system_versioning_alter_history = KEEP', $migration);
        self::assertStringContainsString('system_versioning_alter_history = ERROR', $migration);
        self::assertStringContainsString('absence_period_id', $migration);
        self::assertStringContainsString('vacation_requests.status = "approved"', $migration);
        self::assertStringContainsString('absence_periods.vacation_request_id = timesheets.vacation_request_id', $migration);
        self::assertStringNotContainsString('GROUP_CONCAT(timesheets.work_date', $migration);
    }

    public function testInspectionScriptIsReadOnlyAndChecksRelationalMismatches(): void
    {
        $script = (string) file_get_contents(base_path('bin/inspect-absence-periods.php'));

        self::assertStringContainsString('vacation_request_period_mismatch', $script);
        self::assertStringContainsString('vacation_request_period_without_request', $script);
        self::assertStringContainsString("request_status'] ?? '') !== 'approved'", $script);
        self::assertStringContainsString("request_status'] ?? '') !== 'cancelled'", $script);
        self::assertStringContainsString('timesheets.work_date < absence_periods.date_from', $script);
        self::assertStringContainsString('timesheets.id IS NOT NULL', $script);
        self::assertStringNotContainsString('UPDATE timesheets', $script);
        self::assertStringNotContainsString('DELETE FROM', $script);
    }
}
