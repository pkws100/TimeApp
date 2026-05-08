<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Users\PermissionMatrix;
use App\Http\Controllers\AdminAuthController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class AdminAuthControllerTest extends TestCase
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

    public function testShowDisplaysBootstrapHintWhenNoAdministratorExists(): void
    {
        $controller = new AdminAuthController(
            new AuthService(
                new DatabaseConnection([]),
                new PermissionMatrix(
                    ['administrator' => ['label' => 'Administrator', 'permissions' => ['*']]],
                    ['dashboard.view']
                )
            )
        );

        $request = new Request('GET', '/admin/login', [], [], [], [], []);
        $response = $controller->show($request);

        ob_start();
        $response->send();
        $content = ob_get_clean() ?: '';

        self::assertStringContainsString('bin/bootstrap-admin.php', $content);
        self::assertStringContainsString('System ist noch nicht initialisiert', $content);
    }
}
