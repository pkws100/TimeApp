<?php

declare(strict_types=1);

namespace App\Domain\Terminals;

/**
 * Serves only an already signed public trust bundle. Signing deliberately
 * remains an offline operation in tools/terminal-trust-bundle.
 */
final class TerminalTrustBundleService
{
    private const MAX_BUNDLE_BYTES = 32768;

    public function __construct(private string $bundlePath, private string $publicKeyPath)
    {
    }

    public function publicBundle(): ?array
    {
        if (!is_file($this->bundlePath) || !is_readable($this->bundlePath)) {
            return null;
        }

        $raw = file_get_contents($this->bundlePath);
        if (!is_string($raw) || strlen($raw) > self::MAX_BUNDLE_BYTES) {
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

        if (!$this->verifySignature($bundle)) {
            return null;
        }

        return $bundle;
    }

    public function metadata(): ?array
    {
        $bundle = $this->publicBundle();
        if ($bundle === null) {
            return null;
        }

        $payload = $bundle['payload'];

        return [
            'latest_version' => (int) $payload['bundle_version'],
            'download_url' => '/api/v1/terminal/trust-bundle',
            'warning_after' => (string) ($payload['warning_after'] ?? ''),
            'replace_before' => (string) ($payload['replace_before'] ?? ''),
        ];
    }

    private function verifySignature(array $bundle): bool
    {
        if ($this->publicKeyPath === '' || !is_readable($this->publicKeyPath)) {
            return false;
        }

        $publicKey = file_get_contents($this->publicKeyPath);
        $key = is_string($publicKey) ? openssl_pkey_get_public($publicKey) : false;
        if ($key === false) {
            return false;
        }

        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || (($details['ec']['curve_name'] ?? null) !== 'prime256v1')) {
            return false;
        }

        $signature = base64_decode((string) ($bundle['signature'] ?? ''), true);
        if ($signature === false) {
            return false;
        }

        return openssl_verify($this->signedPayload($bundle['payload']), $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function signedPayload(array $payload): string
    {
        $out = "PKWS-TERMINAL-TRUST-V1\n";
        foreach ([
            'format_version' => (string) ((int) ($payload['format_version'] ?? 0)),
            'bundle_version' => (string) ((int) ($payload['bundle_version'] ?? 0)),
            'created_at' => (string) ($payload['created_at'] ?? ''),
            'warning_after' => (string) ($payload['warning_after'] ?? ''),
            'replace_before' => (string) ($payload['replace_before'] ?? ''),
            'certificate_count' => (string) count($payload['certificates'] ?? []),
        ] as $name => $value) {
            $out .= $name . ':' . strlen($value) . "\n" . $value . "\n";
        }
        foreach ($payload['certificates'] as $certificate) {
            $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $certificate));
            $out .= 'certificate:' . strlen($value) . "\n" . $value . "\n";
        }

        return $out;
    }
}
