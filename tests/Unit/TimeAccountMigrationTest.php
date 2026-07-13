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

    public function testRevisableAccountMigrationCreatesJournalsAndTimesheetSnapshots(): void
    {
        $migration = (string) file_get_contents(base_path('migrations/20260712120000_revisable_time_and_vacation_accounts.php'));

        self::assertStringContainsString('employee_account_cutovers', $migration);
        self::assertStringContainsString('time_account_entries', $migration);
        self::assertStringContainsString('vacation_account_entries', $migration);
        self::assertStringContainsString('credited_minutes', $migration);
        self::assertStringContainsString('absence_reason_code', $migration);
        self::assertStringContainsString('SET SESSION system_versioning_alter_history = KEEP;', $migration);
        self::assertStringContainsString('SET SESSION system_versioning_alter_history = ERROR;', $migration);
    }

    public function testHardeningMigrationAddsCutoverGenerationsAndInternalClosureSource(): void
    {
        $migration = (string) file_get_contents(base_path('migrations/20260713120000_harden_revisable_time_and_vacation_accounts.php'));

        self::assertStringContainsString('cutover_id', $migration);
        self::assertStringContainsString('employee_account_cutovers', $migration);
        self::assertStringContainsString('source_type', $migration);
        self::assertStringContainsString('source_id', $migration);
        self::assertStringContainsString('employee_account_cutover', $migration);
        self::assertStringContainsString('RESTRICT', $migration);
        self::assertStringContainsString("'unique' => true", $migration);
    }

    public function testVacationYearOpeningUniqueFollowupMigrationKeepsGenerationInvariant(): void
    {
        $migration = (string) file_get_contents(base_path('migrations/20260713123000_unique_vacation_year_opening_generation.php'));

        self::assertStringContainsString('uniq_vacation_account_entries_year_opening', $migration);
        self::assertStringContainsString("'unique' => true", $migration);
        self::assertStringContainsString("'user_id', 'cutover_id', 'leave_year', 'entry_type', 'source_type'", $migration);
    }
}
