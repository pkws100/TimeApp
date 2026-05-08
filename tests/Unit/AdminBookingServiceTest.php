<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminBookingServiceTest extends TestCase
{
    public function testNormalizeFiltersStabilizesExpectedAdminShape(): void
    {
        $service = new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator());
        $filters = $service->normalizeFilters([
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
            'project_id' => '__none__',
            'user_id' => '7',
            'entry_type' => 'work',
            'scope' => 'archived',
        ]);

        self::assertSame('2026-04-01', $filters['date_from']);
        self::assertSame('2026-04-30', $filters['date_to']);
        self::assertSame('__none__', $filters['project_id']);
        self::assertSame('7', $filters['user_id']);
        self::assertSame('work', $filters['entry_type']);
        self::assertSame('archived', $filters['scope']);
    }

    public function testEntryTypeOptionsExposeStableAdminEditingSet(): void
    {
        $service = new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator());

        self::assertSame(
            ['work', 'sick', 'vacation', 'holiday', 'absent'],
            array_keys($service->entryTypeOptions())
        );
    }

    public function testNormalizeManualCreatePayloadCalculatesWorkBookingForForcedProject(): void
    {
        $service = new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator());
        $method = new ReflectionMethod($service, 'normalizeManualCreatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'user_id' => '7',
            'work_date' => '2026-05-08',
            'entry_type' => 'work',
            'start_time' => '07:00',
            'end_time' => '15:30',
            'break_minutes' => '30',
            'note' => 'Nacherfasst',
        ], 5);

        self::assertSame(7, $payload['user_id']);
        self::assertSame(5, $payload['project_id']);
        self::assertSame('2026-05-08', $payload['work_date']);
        self::assertSame('07:00:00', $payload['start_time']);
        self::assertSame('15:30:00', $payload['end_time']);
        self::assertSame(510, $payload['gross_minutes']);
        self::assertSame(30, $payload['break_minutes']);
        self::assertSame(480, $payload['net_minutes']);
        self::assertSame('work', $payload['entry_type']);
    }

    public function testNormalizeManualCreatePayloadClearsTimesForAbsence(): void
    {
        $service = new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator());
        $method = new ReflectionMethod($service, 'normalizeManualCreatePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'user_id' => '7',
            'work_date' => '2026-05-08',
            'entry_type' => 'sick',
            'start_time' => '07:00',
            'end_time' => '15:30',
            'break_minutes' => '30',
        ], 5);

        self::assertNull($payload['start_time']);
        self::assertNull($payload['end_time']);
        self::assertSame(0, $payload['gross_minutes']);
        self::assertSame(0, $payload['break_minutes']);
        self::assertSame(0, $payload['net_minutes']);
        self::assertSame('sick', $payload['entry_type']);
    }

    public function testNormalizeManualCreatePayloadRequiresWorkTimes(): void
    {
        $service = new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator());
        $method = new ReflectionMethod($service, 'normalizeManualCreatePayload');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);

        $method->invoke($service, [
            'user_id' => '7',
            'work_date' => '2026-05-08',
            'entry_type' => 'work',
        ], 5);
    }
}
