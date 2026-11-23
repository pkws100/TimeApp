<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Appointments\AppointmentTime;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AppointmentTimeTest extends TestCase
{
    public function testConvertsBerlinerWallClockUniquelyAcrossBothOffsets(): void
    {
        $time = new AppointmentTime(new DateTimeZone('Europe/Berlin'));
        self::assertSame('2026-03-29 00:30:00', $time->toUtc('2026-03-29T01:30', 'Beginn'));
        self::assertSame('2026-03-29 01:30:00', $time->toUtc('2026-03-29T03:30', 'Beginn'));
        self::assertSame('2026-10-25 02:30:00', $time->toUtc('2026-10-25T03:30', 'Beginn'));
    }

    public function testCivilDayBoundariesFollowActualTimeChanges(): void
    {
        $time = new AppointmentTime(new DateTimeZone('Europe/Berlin'));
        self::assertSame('2026-03-28 23:00:00', $time->formatUtc($time->date('2026-03-29')));
        self::assertSame('2026-03-29 22:00:00', $time->formatUtc($time->date('2026-03-29')->modify('+1 day')));
        self::assertSame('2026-10-24 22:00:00', $time->formatUtc($time->date('2026-10-25')));
        self::assertSame('2026-10-25 23:00:00', $time->formatUtc($time->date('2026-10-25')->modify('+1 day')));
        self::assertSame('2026-10-25T02:30', $time->fromUtc('2026-10-25 00:30:00'));
        self::assertSame('2026-10-25T02:30', $time->fromUtc('2026-10-25 01:30:00'));
    }

    #[DataProvider('invalidTimes')]
    public function testRejectsMissingRepeatedAndMalformedLocalTimes(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AppointmentTime(new DateTimeZone('Europe/Berlin')))->toUtc($value, 'Beginn');
    }

    public static function invalidTimes(): array
    {
        return [
            'spring gap' => ['2026-03-29T02:30'],
            'autumn fold' => ['2026-10-25T02:30'],
            'rollover date' => ['2026-02-30T12:00'],
            'rollover time' => ['2026-02-12T24:00'],
            'timezone suffix' => ['2026-02-12T12:00Z'],
            'missing' => [''],
        ];
    }
}
