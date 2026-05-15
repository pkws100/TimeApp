<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Push\PushSettingsService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class PushSettingsServiceTest extends TestCase
{
    public function testDefaultsAreSafeWhenTablesAreMissing(): void
    {
        $service = new PushSettingsService(new DatabaseConnection([]), []);

        $settings = $service->current();

        self::assertFalse($settings['enabled']);
        self::assertTrue($settings['reminder_enabled']);
        self::assertSame('09:00', $settings['reminder_time']);
        self::assertSame([1, 2, 3, 4, 5], $settings['reminder_weekdays']);
        self::assertFalse($settings['vapid_configured']);
        self::assertSame('', $settings['vapid_public_key']);
    }

    public function testVapidStatusComesFromRuntimeConfig(): void
    {
        $service = new PushSettingsService(new DatabaseConnection([]), [
            'vapid' => [
                'public_key' => 'public',
                'private_key' => 'private',
                'subject' => 'mailto:test@example.invalid',
            ],
        ]);

        self::assertTrue($service->vapidConfigured());
        self::assertSame('public', $service->vapidPublicKey());
        self::assertSame('private', $service->vapidPrivateKey());
        self::assertSame('mailto:test@example.invalid', $service->vapidSubject());
    }
}
