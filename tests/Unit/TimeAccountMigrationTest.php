<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TimeAccountMigrationTest extends TestCase
{
    public function testSystemVersionedTimesheetAlterUsesKeepAndResetsToError(): void
    {
        $migration = (string) file_get_contents(base_path('migrations/20260708120000_time_accounts_and_vacation_requests.php'));

        self::assertStringNotContainsString('SET @@system_versioning_alter_history = 1', $migration);
        self::assertStringContainsString('SET SESSION system_versioning_alter_history = KEEP;', $migration);
        self::assertStringContainsString('SET SESSION system_versioning_alter_history = ERROR;', $migration);
        self::assertStringContainsString('finally', $migration);
    }
}
