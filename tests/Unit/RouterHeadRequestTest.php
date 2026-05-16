<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterHeadRequestTest extends TestCase
{
    public function testHeadRequestsUseGetRoutes(): void
    {
        $router = new Router();
        $router->get('/admin/login', static fn (): Response => Response::html('Login OK'));

        $response = $router->dispatch(new Request('HEAD', '/admin/login', [], [], [], [], []));

        self::assertSame(200, $response->status());
    }

    public function testHeadResponsesCanBeSentWithoutBody(): void
    {
        $response = Response::html('Login OK');

        ob_start();
        $response->send(true);
        $body = ob_get_clean() ?: '';

        self::assertSame('', $body);
    }
}
