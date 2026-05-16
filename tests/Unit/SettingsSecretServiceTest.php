<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Settings\SettingsSecretService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsSecretServiceTest extends TestCase
{
    public function testEncryptsAndDecryptsSecret(): void
    {
        $service = new SettingsSecretService('unit-test-key');

        $encrypted = $service->encrypt('smtp-secret');

        self::assertIsString($encrypted);
        self::assertStringStartsWith('enc:v1:', $encrypted);
        self::assertStringNotContainsString('smtp-secret', $encrypted);
        self::assertSame('smtp-secret', $service->decrypt($encrypted));
    }

    public function testLegacyPlaintextIsReturnedForBackwardCompatibility(): void
    {
        $service = new SettingsSecretService('');

        self::assertSame('legacy-secret', $service->decrypt('legacy-secret'));
    }

    public function testMissingKeyIsHandledForEncryption(): void
    {
        $service = new SettingsSecretService('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SETTINGS_ENCRYPTION_KEY');

        $service->encrypt('smtp-secret');
    }

    public function testMissingKeyIsHandledForEncryptedValue(): void
    {
        $encrypted = (new SettingsSecretService('unit-test-key'))->encrypt('smtp-secret');
        $service = new SettingsSecretService('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SETTINGS_ENCRYPTION_KEY');

        $service->decrypt($encrypted);
    }
}
