<?php

declare(strict_types=1);
namespace Tests\Unit;

use App\Domain\WorkTime\WorkTimePolicyService;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkTimePolicyServiceTest extends TestCase
{
    public function testExamplePreviewIsExplicitAndDoesNotRequireADatabase(): void
    {
        $result = $this->service()->preview(['work_date' => '2030-01-07', 'start_time' => '07:42', 'end_time' => '16:35', 'break_intervals' => [['start' => '12:00', 'end' => '12:30']]]);
        self::assertSame(503, $result['actual_work_minutes']);
        self::assertSame(450, $result['inside_window_minutes']);
        self::assertSame(53, $result['outside_pending_minutes']);
        self::assertTrue($result['preview_only']);
    }

    public function testPreviewDoesNotInventAnUnspecifiedPauseAndUsesSummerTimezone(): void
    {
        $result = $this->service()->preview(['work_date' => '2030-07-01', 'start_time' => '08:00', 'end_time' => '16:00', 'break_intervals' => [['start' => '12:00', 'end' => '12:30']]]);
        self::assertSame(450, $result['actual_work_minutes']);
        self::assertSame(450, $result['inside_window_minutes']);
        $withoutPause = $this->service()->preview(['work_date' => '2030-07-01', 'start_time' => '08:00', 'end_time' => '16:00']);
        self::assertSame(480, $withoutPause['actual_work_minutes']);
    }

    #[DataProvider('invalidPolicies')]
    public function testInvalidPolicyCannotBeStored(array $policy): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->normalizePolicy($policy);
    }

    public static function invalidPolicies(): iterable
    {
        yield 'day eight' => [['windows' => [8 => []]]];
        yield 'invalid hour' => [['windows' => [1 => [['start' => '25:00', 'end' => '16:00']]]]];
        yield 'overlap' => [['windows' => [1 => [['start' => '08:00', 'end' => '16:00'], ['start' => '15:00', 'end' => '17:00']]]]];
        yield 'overnight overlap' => [['windows' => [1 => [['start' => '22:00', 'end' => '06:00']], 2 => [['start' => '05:00', 'end' => '12:00']]]]];
        yield 'Sunday Monday overlap' => [['windows' => [7 => [['start' => '22:00', 'end' => '06:00']], 1 => [['start' => '05:00', 'end' => '12:00']]]]];
        yield '24h ambiguous' => [['windows' => [1 => [['start' => '08:00', 'end' => '08:00']]]]];
        yield 'invalid mode' => [['early_check_in_mode' => 'invent_work_start']];
        yield 'invalid timezone' => [['timezone' => 'Germany']];
        yield 'negative delay' => [['checkout_delay_minutes' => -1]];
        yield 'bad boolean' => [['notify_employee_push' => 'maybe']];
    }

    public function testNightWindowsAndAlternativeWorkingWeekAreAllowed(): void
    {
        $policy = $this->service()->normalizePolicy(['windows' => [7 => [['start' => '22:00', 'end' => '06:00']]], 'early_check_in_mode' => 'presence_only']);
        self::assertSame([], $policy['windows'][1]);
        self::assertSame('22:00', $policy['windows'][7][0]['start']);
        self::assertSame('presence_only', $policy['early_check_in_mode']);
    }

    private function service(): WorkTimePolicyService { return new WorkTimePolicyService(new DatabaseConnection([])); }
}
