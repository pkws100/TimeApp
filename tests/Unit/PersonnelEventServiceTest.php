<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Personnel\PersonnelEventService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PersonnelEventServiceTest extends TestCase
{
    public function testNormalizeEventRowCalculatesLifecycleStatus(): void
    {
        $service = new PersonnelEventService(new DatabaseConnection([]));

        self::assertSame('overdue', $service->normalizeEventRow($this->eventRow(['due_on' => '1900-01-01']))['status']);
        self::assertSame('ok', $service->normalizeEventRow($this->eventRow(['due_on' => '2999-01-01', 'default_reminder_days' => 0]))['status']);
        self::assertSame('due_soon', $service->normalizeEventRow($this->eventRow(['due_on' => '2999-01-01', 'default_reminder_days' => 999999]))['status']);
        self::assertSame('completed', $service->normalizeEventRow($this->eventRow(['completed_at' => '2026-06-26 08:00:00']))['status']);
    }

    public function testNormalizeEventRequiresUserTypeAndDueDate(): void
    {
        $service = new PersonnelEventService(new DatabaseConnection([]));
        $method = new ReflectionMethod($service, 'normalizeEvent');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);

        $method->invoke($service, [
            'user_id' => 1,
            'event_type_id' => 2,
            'due_on' => '',
        ]);
    }

    private function eventRow(array $overrides = []): array
    {
        return [
            'id' => 1,
            'user_id' => 7,
            'title' => '',
            'event_type_name' => 'Gefahrgutschulung',
            'due_on' => '2999-01-01',
            'completed_at' => null,
            'default_reminder_days' => 14,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'reminder_channels' => 'admin,email',
            ...$overrides,
        ];
    }
}
