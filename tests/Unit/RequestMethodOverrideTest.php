<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestMethodOverrideTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_FILES = [];
    }

    public function testPostMethodCanBeOverriddenToDelete(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/projects/4';
        $_POST['_method'] = 'DELETE';

        $request = Request::capture();

        self::assertSame('DELETE', $request->method());
        self::assertSame('/admin/projects/4', $request->path());
    }
}

