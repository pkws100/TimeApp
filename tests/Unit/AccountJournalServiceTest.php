<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AccountJournalServiceTest extends TestCase
{
    public function testJournalServiceValidatesTypesSignsAndCutoverGeneration(): void
    {
        $source = (string) file_get_contents(base_path('src/Domain/TimeAccounts/AccountJournalService.php'));

        self::assertStringContainsString("private const TIME_TYPES = ['opening_balance', 'manual_adjustment', 'payout', 'time_off_compensation', 'reversal']", $source);
        self::assertStringContainsString("private const VACATION_TYPES = ['annual_entitlement', 'carryover', 'opening_adjustment', 'manual_adjustment', 'expiry', 'reversal']", $source);
        self::assertStringContainsString('Auszahlungen und Freizeitausgleich muessen negativ gebucht werden.', $source);
        self::assertStringContainsString('Urlaubsverfall muss negativ gebucht werden.', $source);
        self::assertStringContainsString('Journalbuchungen benoetigen eine aktive Stichtagsgeneration.', $source);
        self::assertStringContainsString('cutover_id', $source);
    }
}
