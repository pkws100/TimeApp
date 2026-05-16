<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Users\UserService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UserServiceTest extends TestCase
{
    public function testNormalizeDefaultsTimeTrackingRequirementToTrue(): void
    {
        $record = $this->normalize([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada@example.test',
        ]);

        self::assertTrue($record['time_tracking_required']);
    }

    public function testNormalizeAcceptsDisabledTimeTrackingRequirement(): void
    {
        $record = $this->normalize([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada@example.test',
            'time_tracking_required' => '0',
        ]);

        self::assertFalse($record['time_tracking_required']);
    }

    public function testNormalizeAcceptsCheckedTimeTrackingRequirement(): void
    {
        $record = $this->normalize([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada@example.test',
            'time_tracking_required' => '1',
        ]);

        self::assertTrue($record['time_tracking_required']);
    }

    private function normalize(array $payload): array
    {
        $service = new UserService(new DatabaseConnection([]));
        $method = new ReflectionMethod($service, 'normalize');
        $method->setAccessible(true);

        return $method->invoke($service, $payload);
    }
}
