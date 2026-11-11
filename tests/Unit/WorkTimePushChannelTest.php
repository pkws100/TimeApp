<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Push\PushSender;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\WorkTime\WorkTimePushChannel;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class WorkTimePushChannelTest extends TestCase
{
    public function testUnconfiguredProviderIsReportedWithoutAnyNetworkAttempt(): void
    {
        $sender = new class implements PushSender {
            public int $calls = 0;
            public function isConfigured(): bool { return false; }
            public function send(array $subscriptionRow, array $message, ?string $dedupeKey = null, ?string $workDate = null): array
            {
                ++$this->calls;
                throw new \RuntimeException('An unconfigured sender must never be called.');
            }
        };
        $channel = new WorkTimePushChannel(new PushSubscriptionService(new DatabaseConnection([])), $sender);
        $result = $channel(1, ['body' => 'Test'], 'event-key');
        self::assertFalse($result['ok']);
        self::assertTrue($result['not_configured']);
        self::assertSame(0, $sender->calls);
    }
}
