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
    /* Fixed insertion order is the protocol canonicalization used by firmware 1.1. */
    return json_encode([
        'format_version' => (int) $payload['format_version'],
        'bundle_version' => (int) $payload['bundle_version'],
        'created_at' => (string) $payload['created_at'],
        'warning_after' => (string) $payload['warning_after'],
        'replace_before' => (string) $payload['replace_before'],
        'certificates' => array_values($payload['certificates']),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
    if (!openssl_sign(canonical($payload), $signature, $private, OPENSSL_ALGO_SHA256)) {
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
    $valid = openssl_verify(canonical($bundle['payload']), base64_decode((string) $bundle['signature'], true) ?: '', $public, OPENSSL_ALGO_SHA256) === 1;
    echo $valid ? "valid\n" : "invalid\n";
    exit($valid ? 0 : 1);
}

usage();
