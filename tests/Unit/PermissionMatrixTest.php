<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Users\PermissionMatrix;
use PHPUnit\Framework\TestCase;

final class PermissionMatrixTest extends TestCase
{
    public function testWildcardRoleExpandsToAllPermissions(): void
    {
        $matrix = new PermissionMatrix(
            [
                'administrator' => ['label' => 'Administrator', 'permissions' => ['*']],
                'mitarbeiter' => ['label' => 'Mitarbeiter', 'permissions' => ['timesheets.create']],
            ],
            ['dashboard.view', 'timesheets.create', 'reports.export']
        );

        self::assertSame(
            ['dashboard.view', 'timesheets.create', 'reports.export'],
            $matrix->permissionsForRoles(['administrator'])
        );
    }
}

