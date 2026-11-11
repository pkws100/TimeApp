<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\WorkTime\MockSmsProvider;
use App\Domain\WorkTime\PhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WorkTimeSmsTest extends TestCase
{
    public function testMockDefaultsToDisabledAndMakesNoSend(): void
    {
        $sms = new MockSmsProvider();
        self::assertFalse($sms->enabled());
        self::assertFalse($sms->send('+491701234567', 'Test', 'event')['ok']);
        self::assertSame([], $sms->messages());
    }

    public function testInternationalNumberIsNormalizedWithoutGuessingCountry(): void
    {
        self::assertSame('+491701234567', PhoneNumber::normalize('0049 (170) 123-45-67'));
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::normalize('01701234567');
    }
}
