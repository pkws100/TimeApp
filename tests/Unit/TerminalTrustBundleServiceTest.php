<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Terminals\TerminalTrustBundleService;
use PHPUnit\Framework\TestCase;

final class TerminalTrustBundleServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/terminal-trust-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testValidSignedBundleHasMetadata(): void
    {
        file_put_contents($this->directory . '/public.pem', (string) file_get_contents(base_path('tests/fixtures/terminal-trust/public.pem')));
        file_put_contents($this->directory . '/bundle.json', json_encode($this->signedBundle([$this->validCaCertificate()]), JSON_THROW_ON_ERROR));

        $service = new TerminalTrustBundleService($this->directory . '/bundle.json', $this->directory . '/public.pem');

        self::assertSame(7, $service->metadata()['latest_version']);
        self::assertNotNull($service->publicBundle());
    }

    public function testMissingOrTamperedBundleIsNotPublished(): void
    {
        $service = new TerminalTrustBundleService($this->directory . '/missing.json', $this->directory . '/missing.pem');
        self::assertNull($service->publicBundle());

        file_put_contents($this->directory . '/public.pem', (string) file_get_contents(base_path('tests/fixtures/terminal-trust/public.pem')));
        file_put_contents($this->directory . '/bundle.json', json_encode(['payload' => ['format_version' => 1, 'bundle_version' => 1, 'certificates' => ['x']], 'signature_algorithm' => 'ECDSA-P256-SHA256', 'signature' => 'tampered'], JSON_THROW_ON_ERROR));
        self::assertNull((new TerminalTrustBundleService($this->directory . '/bundle.json', $this->directory . '/public.pem'))->publicBundle());
    }

    public function testSignedBundleWithInvalidCertificateIsNotPublished(): void
    {
        file_put_contents($this->directory . '/public.pem', (string) file_get_contents(base_path('tests/fixtures/terminal-trust/public.pem')));
        file_put_contents($this->directory . '/bundle.json', json_encode($this->signedBundle(['not a certificate']), JSON_THROW_ON_ERROR));

        self::assertNull((new TerminalTrustBundleService($this->directory . '/bundle.json', $this->directory . '/public.pem'))->publicBundle());
    }

    public function testCertificateMustBeCaAndCertificateCountIsBounded(): void
    {
        file_put_contents($this->directory . '/public.pem', (string) file_get_contents(base_path('tests/fixtures/terminal-trust/public.pem')));
        file_put_contents($this->directory . '/bundle.json', json_encode($this->signedBundle([$this->nonCaCertificate()]), JSON_THROW_ON_ERROR));
        self::assertNull((new TerminalTrustBundleService($this->directory . '/bundle.json', $this->directory . '/public.pem'))->publicBundle());

        file_put_contents($this->directory . '/bundle.json', json_encode($this->signedBundle(array_fill(0, 9, $this->validCaCertificate())), JSON_THROW_ON_ERROR));
        self::assertNull((new TerminalTrustBundleService($this->directory . '/bundle.json', $this->directory . '/public.pem'))->publicBundle());
    }

    public function testRsaVerificationKeyIsRejected(): void
    {
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($rsa);
        $details = openssl_pkey_get_details($rsa);
        file_put_contents($this->directory . '/public.pem', $details['key']);
        file_put_contents($this->directory . '/bundle.json', json_encode($this->signedBundle([$this->validCaCertificate()]), JSON_THROW_ON_ERROR));

        self::assertNull((new TerminalTrustBundleService($this->directory . '/bundle.json', $this->directory . '/public.pem'))->publicBundle());
    }

    /** @param list<string> $certificates */
    private function signedBundle(array $certificates): array
    {
        $payload = [
            'format_version' => 1,
            'bundle_version' => 7,
            'created_at' => '2026-07-14T12:00:00Z',
            'warning_after' => '2030-01-01T00:00:00Z',
            'replace_before' => '2031-01-01T00:00:00Z',
            'certificates' => $certificates,
        ];
        $signature = '';
        self::assertTrue(openssl_sign($this->signedPayload($payload), $signature, (string) file_get_contents(base_path('tests/fixtures/terminal-trust/test-private.pem')), OPENSSL_ALGO_SHA256));

        return ['payload' => $payload, 'signature_algorithm' => 'ECDSA-P256-SHA256', 'signature' => base64_encode($signature)];
    }

    private function signedPayload(array $payload): string
    {
        $out = "PKWS-TERMINAL-TRUST-V1\n";
        foreach (['format_version', 'bundle_version', 'created_at', 'warning_after', 'replace_before'] as $name) {
            $value = (string) $payload[$name];
            $out .= $name . ':' . strlen($value) . "\n" . $value . "\n";
        }
        $out .= 'certificate_count:' . strlen((string) count($payload['certificates'])) . "\n" . count($payload['certificates']) . "\n";
        foreach ($payload['certificates'] as $certificate) {
            $value = trim(str_replace(["\r\n", "\r"], "\n", $certificate));
            $out .= 'certificate:' . strlen($value) . "\n" . $value . "\n";
        }
        return $out;
    }

    private function validCaCertificate(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'PKWS Test CA'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256', 'x509_extensions' => 'v3_ca']);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        return $pem;
    }

    private function nonCaCertificate(): string
    {
        $config = $this->directory . '/openssl-leaf.cnf';
        file_put_contents($config, "[ req ]\ndistinguished_name = req_dn\n[ req_dn ]\n[ v3_leaf ]\nbasicConstraints = critical,CA:FALSE\n");
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'PKWS Test Leaf'], $key, ['digest_alg' => 'sha256', 'config' => $config]);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'v3_leaf']);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        return $pem;
    }
}
