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

    public function testHydrateBookingRowMarksOnlyActiveWorkWithoutProjectAsOpenAssignment(): void
    {
        $service = new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator());
        $method = new ReflectionMethod($service, 'hydrateBookingRow');
        $method->setAccessible(true);

        $activeWorkWithoutProject = $method->invoke($service, $this->bookingRow([
            'project_id' => null,
            'entry_type' => 'work',
            'is_deleted' => 0,
        ]));
        $archivedWorkWithoutProject = $method->invoke($service, $this->bookingRow([
            'project_id' => null,
            'entry_type' => 'work',
            'is_deleted' => 1,
        ]));
        $absenceWithoutProject = $method->invoke($service, $this->bookingRow([
            'project_id' => null,
            'entry_type' => 'sick',
            'is_deleted' => 0,
        ]));
        $assignedWork = $method->invoke($service, $this->bookingRow([
            'project_id' => 5,
            'entry_type' => 'work',
            'is_deleted' => 0,
        ]));

        self::assertTrue($activeWorkWithoutProject['needs_project_assignment']);
        self::assertFalse($archivedWorkWithoutProject['needs_project_assignment']);
        self::assertFalse($absenceWithoutProject['needs_project_assignment']);
        self::assertFalse($assignedWork['needs_project_assignment']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function bookingRow(array $overrides = []): array
    {
        return [
            'id' => 1,
            'user_id' => 7,
            'project_id' => 5,
            'work_date' => '2026-05-08',
            'start_time' => '07:00:00',
            'end_time' => null,
            'gross_minutes' => 0,
            'break_minutes' => 0,
            'net_minutes' => 0,
            'expenses_amount' => '0.00',
            'entry_type' => 'work',
            'source' => 'app',
            'note' => 'Baustelle kurz beschrieben',
            'updated_at' => '2026-05-08 07:00:00',
            'is_deleted' => 0,
            'deleted_at' => null,
            'deleted_by_user_id' => null,
            'employee_number' => 'M-7',
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'user_is_deleted' => 0,
            'project_number' => 'P-5',
            'project_name' => 'Rathaus',
            'project_is_deleted' => 0,
            'change_count' => 0,
            'last_change_at' => null,
            'last_action_type' => null,
            'last_change_reason' => null,
            ...$overrides,
        ];
    }
}
