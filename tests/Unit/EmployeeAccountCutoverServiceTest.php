<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmployeeAccountCutoverServiceTest extends TestCase
{
    public function testCutoverServiceUsesAccountingLockDraftFinalizationAndStructuredClosures(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/TimeAccounts/EmployeeAccountCutoverService.php'));

        self::assertStringContainsString('withUserAndAccountingLocks', $source);
        self::assertStringContainsString('withAccountingWriteLock', $source);
        self::assertStringContainsString('finalizeDraftOrInsert', $source);
        self::assertStringContainsString('status = "draft"', $source);
        self::assertStringContainsString('source_type', $source);
        self::assertStringContainsString('employee_account_cutover', $source);
        self::assertStringContainsString('cutover_id', $source);
        self::assertStringContainsString('public function reverseTimeEntry', $source);
        self::assertStringContainsString('public function reverseVacationEntry', $source);
        self::assertStringContainsString('Die Urlaubskonto-Jahreseroeffnung ist gerade gesperrt.', (string) file_get_contents(base_path('src/Domain/TimeAccounts/VacationAccountYearService.php')));
    }
}
