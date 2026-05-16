<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../seeds/InitialReferenceSeeder.php';

final class ReferenceSeederTest extends TestCase
{
    public function testSeederContainsEveryConfiguredPermission(): void
    {
        $config = require __DIR__ . '/../../config/permissions.php';
        $configuredPermissions = $config['available'];
        $seedPermissions = array_map(
            static fn (array $permission): string => $permission['code'],
            \InitialReferenceSeeder::permissions()
        );

        self::assertSameSize(array_unique($seedPermissions), $seedPermissions);
        self::assertEqualsCanonicalizing($configuredPermissions, $seedPermissions);
    }

    public function testSeederRoleGrantsMatchConfiguredRoleGrants(): void
    {
        $config = require __DIR__ . '/../../config/permissions.php';
        $availablePermissions = $config['available'];
        $seedRolePermissions = \InitialReferenceSeeder::rolePermissions();

        foreach ($config['roles'] as $slug => $role) {
            self::assertArrayHasKey($slug, $seedRolePermissions);

            $configuredPermissions = $role['permissions'] === ['*']
                ? $availablePermissions
                : $role['permissions'];

            self::assertEqualsCanonicalizing($configuredPermissions, $seedRolePermissions[$slug]);
        }
    }

    public function testPushPermissionsAreGrantedToExpectedRoles(): void
    {
        $seedRolePermissions = \InitialReferenceSeeder::rolePermissions();

        self::assertContains('push.manage', $seedRolePermissions['administrator']);
        self::assertContains('push.receive', $seedRolePermissions['administrator']);
        self::assertContains('push.manage', $seedRolePermissions['geschaeftsfuehrung']);
        self::assertContains('push.receive', $seedRolePermissions['geschaeftsfuehrung']);

        foreach (['bauleiter', 'kolonnenfuehrer', 'mitarbeiter', 'disposition'] as $roleSlug) {
            self::assertContains('push.receive', $seedRolePermissions[$roleSlug]);
            self::assertNotContains('push.manage', $seedRolePermissions[$roleSlug]);
        }
    }
}
