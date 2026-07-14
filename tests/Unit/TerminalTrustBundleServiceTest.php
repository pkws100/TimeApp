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
        file_put_contents($this->directory . '/bundle.json', json_encode([
            'payload' => json_decode((string) file_get_contents(base_path('tests/fixtures/terminal-trust/canonical-payload.json')), true, 512, JSON_THROW_ON_ERROR),
            'signature_algorithm' => 'ECDSA-P256-SHA256',
            'signature' => trim((string) file_get_contents(base_path('tests/fixtures/terminal-trust/signature.b64'))),
        ], JSON_THROW_ON_ERROR));

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
}
