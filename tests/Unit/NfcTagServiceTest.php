<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Terminals\NfcTagService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class NfcTagServiceTest extends TestCase
{
    public function testNormalizesUidSeparatorsAndCase(): void
    {
        $service = new NfcTagService(new DatabaseConnection([]), 'test-key');

        self::assertSame('04:A1:B2:C3:D4', $service->normalizeUid('04-a1 b2:c3d4'));
    }

    public function testHashesNormalizedUidWithConfiguredKey(): void
    {
        $service = new NfcTagService(new DatabaseConnection([]), 'test-key');

        self::assertSame(
            hash_hmac('sha256', '04:A1:B2:C3:D4', 'test-key'),
            $service->hashUid('04:a1:b2:c3:d4')
        );
    }

    public function testMasksUidWithoutExposingFullValue(): void
    {
        $service = new NfcTagService(new DatabaseConnection([]), 'test-key');

        self::assertSame('04:A1:...:D4', $service->maskUid('04:A1:B2:C3:D4'));
        self::assertSame('04:...', $service->maskUid('04:A1'));
    }

    public function testHashingRequiresConfiguredSecret(): void
    {
        $service = new NfcTagService(new DatabaseConnection([]), '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SETTINGS_ENCRYPTION_KEY');

        $service->hashUid('04:A1:B2:C3:D4');
    }
}
