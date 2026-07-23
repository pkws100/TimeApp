<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectService;
use App\Domain\Users\PermissionMatrix;
use App\Http\Controllers\ProjectController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class ProjectControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testEveryProjectApiActionRejectsMissingCurrentUser(): void
    {
        $connection = new DatabaseConnection([]);
        $controller = new ProjectController(
            new ProjectService($connection),
            new AuthService($connection, new PermissionMatrix([], [])),
            new ProjectAccessService($connection)
        );

        self::assertSame(401, $controller->index($this->request('GET'))->status());
        self::assertSame(401, $controller->show($this->request('GET'), ['id' => '1'])->status());
        self::assertSame(401, $controller->store($this->request('POST'))->status());
        self::assertSame(401, $controller->update($this->request('PUT'), ['id' => '1'])->status());
        self::assertSame(401, $controller->archive($this->request('DELETE'), ['id' => '1'])->status());
    }

    private function request(string $method): Request
    {
        return new Request($method, '/api/v1/projects', [], [], [], [], []);
    }
}
