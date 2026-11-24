<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Push\PublicPushEndpoint;
use PHPUnit\Framework\TestCase;

final class PublicPushEndpointTest extends TestCase
{
    public function testRejectsNonHttpsAndUnqualifiedDestinationsWithoutLookup(): void
    {
        foreach (['http://push.example.test/device', 'https://push.example.test:8443/device',
            'https://user@push.example.test/device', 'https://127.0.0.1/device', 'https://localhost/device'] as $endpoint) {
            self::assertNull(PublicPushEndpoint::transport($endpoint, static function (): never {
                self::fail('Invalid endpoint should be rejected before DNS lookup.');
            }));
        }
    }

    public function testPinsOnlyPublicAddressesAndDisablesRedirectsAndProxies(): void
    {
        $endpoint = 'https://PUSH.example.test/device';
        self::assertNull(PublicPushEndpoint::transport($endpoint, static fn (): array => [
            ['ip' => '10.0.0.9'], ['ipv6' => '::1'], ['ip' => '169.254.169.254'], ['ipv6' => '::ffff:127.0.0.1'],
            ['ipv6' => 'fec0::1'], ['ipv6' => '64:ff9b:1::7f00:1'], ['ipv6' => '3fff::1'], ['ip' => '192.88.99.1'],
        ]));
        $config = PublicPushEndpoint::transport($endpoint, static function (string $host): array {
            self::assertSame('push.example.test', $host);
            return [['ip' => '10.0.0.9'], ['ip' => '8.8.8.8'], ['ipv6' => '2606:4700:4700::1111']];
        });
        self::assertNotNull($config);
        self::assertFalse($config['allow_redirects']);
        self::assertSame('', $config['proxy']);
        self::assertSame(['push.example.test:443:8.8.8.8,[2606:4700:4700::1111]'], $config['curl'][CURLOPT_RESOLVE]);
    }
}
