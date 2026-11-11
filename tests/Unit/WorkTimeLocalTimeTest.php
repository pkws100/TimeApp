<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\WorkTime\WorkTimeLocalTime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkTimeLocalTimeTest extends TestCase
{
    public function testUnambiguousBerlinTimeRetainsItsOffset(): void
    {
        self::assertSame('2030-01-07T08:00:00+01:00', WorkTimeLocalTime::parse('2030-01-07 08:00:00')->format(DATE_ATOM));
    }

    public function testOffsetInputIsConvertedToBerlinIncludingJavaScriptMilliseconds(): void
    {
        self::assertSame('2030-01-07T08:30:00+01:00', WorkTimeLocalTime::fromInput('2030-01-07T07:30:00.125Z')->format(DATE_ATOM));
        self::assertSame('2030-07-07T09:30:00+02:00', WorkTimeLocalTime::fromInput('2030-07-07T08:30:00+01:00')->format(DATE_ATOM));
        self::assertSame('2030-01-07T08:30:00+01:00', WorkTimeLocalTime::fromInput('2030-01-07 08:30')->format(DATE_ATOM));
    }

    #[DataProvider('invalidInputTimes')]
    public function testInputIsNeverSilentlyRepaired(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkTimeLocalTime::fromInput($value);
    }

    public static function invalidInputTimes(): array
    {
        return [['2026-03-29 02:30'], ['2026-10-25 02:30:00'], ['2026-10-25T00:30:00.000Z'], ['2026-02-30T07:30:00Z'], ['2026-01-07 28:99'], ['tomorrow'], ['']];
    }

    #[DataProvider('invalidTimes')]
    public function testNonexistentAndAmbiguousTimesRequireClarification(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkTimeLocalTime::parse($value);
    }

    public static function invalidTimes(): array
    {
        return [['2026-03-29 02:30:00'], ['2026-10-25 02:30:00'], ['2026-02-30 08:00:00']];
    }
}
