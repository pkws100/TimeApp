<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Personnel\Compliance\PersonnelProductionHttpBoundary;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class PersonnelProductionHttpBoundaryTest extends TestCase
{
    public function testProductionIngressRequiresTheTrustedApacheHttpsSignalAndExactOrigin(): void
    {
        $origin = 'https://time.example.test';
        $request = static fn (array $server): Request => new Request('GET', '/admin/personnel-documents', [], [], [], [], $server);
        $host = ['HTTP_HOST' => 'time.example.test'];

        self::assertTrue(PersonnelProductionHttpBoundary::matchesOrigin($request($host + ['HTTPS' => 'on']), $origin));
        self::assertFalse(PersonnelProductionHttpBoundary::matchesOrigin($request($host + [
            'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'time.example.test', 'HTTP_HTTPS' => 'on',
        ]), $origin));
        self::assertFalse(PersonnelProductionHttpBoundary::matchesOrigin($request($host + ['HTTPS' => 'off']), $origin));
        self::assertFalse(PersonnelProductionHttpBoundary::matchesOrigin($request(['HTTP_HOST' => 'other.example.test', 'HTTPS' => 'on']), $origin));
        self::assertFalse(PersonnelProductionHttpBoundary::matchesOrigin($request($host + ['HTTPS' => 'on']), $origin . '/admin'));
        self::assertFalse(PersonnelProductionHttpBoundary::matchesOrigin($request($host + ['HTTPS' => 'on']), 'http://time.example.test'));
        self::assertNull(PersonnelProductionHttpBoundary::discover($request($host + ['HTTPS' => 'on']), new DatabaseConnection([]), []));
    }
}
