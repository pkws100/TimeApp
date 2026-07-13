<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RevisableTimeAccountServiceTest extends TestCase
{
    public function testTimeAccountServiceHandlesPreCutoverPeriodsAndLazyJournalEntries(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/TimeAccounts/TimeAccountService.php'));

        self::assertStringContainsString('bool $includeEntries = false', $source);
        self::assertStringContainsString('not_active_in_period', $source);
        self::assertStringContainsString('Zeitkonto war in diesem Zeitraum noch nicht aktiv.', $source);
        self::assertStringContainsString('journalEntries', $source);
        self::assertStringContainsString('cutover_id = :cutover_id', $source);
    }
}
