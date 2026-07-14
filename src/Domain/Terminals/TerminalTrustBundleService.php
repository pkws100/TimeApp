<?php

declare(strict_types=1);

namespace App\Domain\Terminals;

/**
 * Serves only an already signed public trust bundle. Signing deliberately
 * remains an offline operation in tools/terminal-trust-bundle.
 */
final class TerminalTrustBundleService
{
    public function __construct(private string $bundlePath)
    {
    }

    public function publicBundle(): ?array
    {
        if (!is_file($this->bundlePath) || !is_readable($this->bundlePath)) {
            return null;
        }

        $raw = file_get_contents($this->bundlePath);
        if (!is_string($raw) || strlen($raw) > 32768) {
            return null;
        }

        $bundle = json_decode($raw, true);
        if (!is_array($bundle) || !is_array($bundle['payload'] ?? null)
            || !is_string($bundle['signature'] ?? null)
            || ($bundle['signature_algorithm'] ?? null) !== 'ECDSA-P256-SHA256') {
            return null;
        }

        $payload = $bundle['payload'];
        if (($payload['format_version'] ?? null) !== 1 || (int) ($payload['bundle_version'] ?? 0) < 1
            || !is_array($payload['certificates'] ?? null) || $payload['certificates'] === []) {
            return null;
        }

        return $bundle;
    }
}
