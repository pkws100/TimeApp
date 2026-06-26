<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Personnel\PersonnelLabelService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PersonnelLabelServiceTest extends TestCase
{
    public function testNormalizeBuildsSafeSlugColorAndIcon(): void
    {
        $service = new PersonnelLabelService(new DatabaseConnection([]));
        $method = new ReflectionMethod($service, 'normalize');
        $method->setAccessible(true);

        $record = $method->invoke($service, [
            'name' => 'LKW Fahrer',
            'color' => 'not-a-color',
            'icon' => 'Truck Icon',
        ]);

        self::assertSame('lkw-fahrer', $record['slug']);
        self::assertSame('#2563eb', $record['color']);
        self::assertSame('truck-icon', $record['icon']);
    }

    public function testNormalizeRequiresName(): void
    {
        $service = new PersonnelLabelService(new DatabaseConnection([]));
        $method = new ReflectionMethod($service, 'normalize');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);

        $method->invoke($service, ['name' => '']);
    }
}
