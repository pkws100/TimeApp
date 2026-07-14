#!/usr/bin/env php
<?php

declare(strict_types=1);

/* Offline-only signer/verifier for public terminal trust bundles. */
function usage(): never
{
    fwrite(STDERR, "Usage:\n  terminal-trust-bundle.php sign --private-key /secure/key.pem --version N --cert root.pem [--cert next.pem] [--warning-after ISO] [--replace-before ISO] [--output bundle.json]\n  terminal-trust-bundle.php verify --public-key public.pem --bundle bundle.json\n");
    exit(64);
}

function args(array $argv): array
{
    $result = ['cert' => []];
    for ($i = 2; $i < count($argv); $i++) {
        if (!str_starts_with($argv[$i], '--') || !isset($argv[$i + 1])) {
            usage();
        }
        $key = substr($argv[$i], 2);
        $value = $argv[++$i];
        if ($key === 'cert') {
            $result['cert'][] = $value;
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
}

function canonical(array $payload): string
{
    $out = "PKWS-TERMINAL-TRUST-V1\n";
    $append = static function (string $name, string $value) use (&$out): void {
        $out .= $name . ':' . strlen($value) . "\n" . $value . "\n";
    };
    $append('format_version', (string) (int) $payload['format_version']);
    $append('bundle_version', (string) (int) $payload['bundle_version']);
    $append('created_at', (string) $payload['created_at']);
    $append('warning_after', (string) $payload['warning_after']);
    $append('replace_before', (string) $payload['replace_before']);
    $append('certificate_count', (string) count($payload['certificates']));
    foreach ($payload['certificates'] as $certificate) {
        $append('certificate', trim(str_replace(["\r\n", "\r"], "\n", (string) $certificate)));
    }

    return $out;
}

function requireP256Key(string $pem, bool $private): OpenSSLAsymmetricKey
{
    $key = $private ? openssl_pkey_get_private($pem) : openssl_pkey_get_public($pem);
    if (!$key instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('EC P-256 key could not be read.');
    }
    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
        || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
        throw new RuntimeException('Only EC prime256v1 / P-256 keys are permitted.');
    }

    return $key;
}

$command = $argv[1] ?? '';
$options = args($argv);

if ($command === 'sign') {
    $keyPath = $options['private-key'] ?? (getenv('TERMINAL_TRUST_BUNDLE_PRIVATE_KEY') ?: '');
    $version = (int) ($options['version'] ?? 0);
    if ($keyPath === '' || $version < 1 || $options['cert'] === []) {
        usage();
    }
    $private = file_get_contents($keyPath);
    if (!is_string($private) || !str_contains($private, 'PRIVATE KEY')) {
        throw new RuntimeException('Private key could not be read.');
    }
    $privateKey = requireP256Key($private, true);
    $certificates = [];
    foreach ($options['cert'] as $certPath) {
        $certificate = trim(str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($certPath)));
        if (!str_contains($certificate, '-----BEGIN CERTIFICATE-----')) {
            throw new RuntimeException('Invalid CA certificate: ' . $certPath);
        }
        $certificates[] = $certificate;
    }
    $payload = [
        'format_version' => 1,
        'bundle_version' => $version,
        'created_at' => $options['created-at'] ?? gmdate('Y-m-d\\TH:i:s\\Z'),
        'warning_after' => $options['warning-after'] ?? '',
        'replace_before' => $options['replace-before'] ?? '',
        'certificates' => $certificates,
    ];
    $signature = '';
    if (!openssl_sign(canonical($payload), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('ECDSA P-256 signing failed.');
    }
    $bundle = ['payload' => $payload, 'signature_algorithm' => 'ECDSA-P256-SHA256', 'signature' => base64_encode($signature)];
    $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (isset($options['output'])) {
        if (file_put_contents($options['output'], $json) === false) {
            throw new RuntimeException('Could not write output bundle.');
        }
    } else {
        echo $json;
    }
    exit(0);
}

if ($command === 'verify') {
    $public = file_get_contents((string) ($options['public-key'] ?? ''));
    $bundle = json_decode((string) file_get_contents((string) ($options['bundle'] ?? '')), true, 512, JSON_THROW_ON_ERROR);
    if (!is_string($public) || !is_array($bundle['payload'] ?? null) || ($bundle['signature_algorithm'] ?? '') !== 'ECDSA-P256-SHA256') {
        throw new RuntimeException('Invalid public key or bundle format.');
    }
    $publicKey = requireP256Key($public, false);
    $valid = openssl_verify(canonical($bundle['payload']), base64_decode((string) $bundle['signature'], true) ?: '', $publicKey, OPENSSL_ALGO_SHA256) === 1;
    echo $valid ? "valid\n" : "invalid\n";
    exit($valid ? 0 : 1);
}

usage();
