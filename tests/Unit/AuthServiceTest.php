<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Users\PermissionMatrix;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $sessionPath = sys_get_temp_dir() . '/baustelle-test-sessions';

            if (!is_dir($sessionPath)) {
                mkdir($sessionPath, 0775, true);
            }

            session_save_path($sessionPath);
            session_start();
        }

        $_SESSION = [];
    }

    public function testDemoLoginIsRejectedWithoutDatabaseUser(): void
    {
        $service = new AuthService(
            new DatabaseConnection([]),
            new PermissionMatrix(
                ['administrator' => ['label' => 'Administrator', 'permissions' => ['*']]],
                ['dashboard.view', 'timesheets.create', 'timesheets.view_own']
            )
        );

        $result = $service->login('admin@example.invalid', 'secret123!');

        self::assertFalse($result['ok']);
        self::assertSame('Ungueltige Zugangsdaten.', $result['message']);
        self::assertNull($service->currentUser());
    }

    public function testSessionPayloadMarksBootstrapAsRequiredWhenNoAdministratorExists(): void
    {
        $service = new AuthService(
            new DatabaseConnection([]),
            new PermissionMatrix(
                ['administrator' => ['label' => 'Administrator', 'permissions' => ['*']]],
                ['dashboard.view', 'timesheets.create', 'timesheets.view_own']
            )
        );

        $payload = $service->sessionPayload();

        self::assertFalse($payload['authenticated']);
        self::assertTrue($payload['bootstrap_required']);
    }
}
