<?php

declare(strict_types=1);

namespace App\Domain\Push;

use Closure;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;

final class PublicPushEndpoint
{
    public static function transport(string $endpoint, ?Closure $lookup = null): ?array
    {
        $url = parse_url($endpoint);
        $host = $url['host'] ?? '';
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || ($url['scheme'] ?? '') !== 'https'
            || ($url['port'] ?? 443) !== 443 || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || preg_match('/\.[a-z][a-z0-9-]*\z/i', $host) !== 1) {
            return null;
        }

        $host = strtolower($host);
        $lookup ??= static fn (string $domain): array|false => @dns_get_record($domain . '.', DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($lookup($host) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                continue;
            }
            $ip = inet_ntop(inet_pton($ip));
            $ipv6 = str_contains($ip, ':');
            if (($ipv6 && (preg_match('/\A[23]/', $ip) !== 1 || preg_match('/\A(?:2002:|2001:0*:|3fff:)/', $ip) === 1))
                || str_starts_with($ip, '192.88.99.')) {
                continue;
            }
            $addresses[] = $ipv6 ? '[' . $ip . ']' : $ip;
        }
        if ($addresses === []) {
            return null;
        }

        // Use these exact addresses while keeping the original TLS hostname. Proxies and redirects could bypass the pin.
        return [
            'handler' => HandlerStack::create(new CurlMultiHandler()),
            'allow_redirects' => false,
            'proxy' => '',
            'connect_timeout' => 5,
            'curl' => [CURLOPT_RESOLVE => [$host . ':443:' . implode(',', array_unique($addresses))]],
        ];
    }
}
