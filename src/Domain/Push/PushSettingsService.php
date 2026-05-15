<?php

declare(strict_types=1);

namespace App\Domain\Push;

use App\Infrastructure\Database\DatabaseConnection;

final class PushSettingsService
{
    private const DEFAULT_NOTICE = 'Sie haben heute noch keine Arbeitszeit gebucht. Bitte erfassen Sie Ihren Tagesstatus.';

    public function __construct(
        private DatabaseConnection $connection,
        private array $pushConfig = []
    ) {
    }

    public function current(): array
    {
        $settings = [
            'id' => 1,
            'enabled' => false,
            'reminder_enabled' => true,
            'reminder_time' => '09:00',
            'reminder_weekdays' => [1, 2, 3, 4, 5],
            'notice_text' => self::DEFAULT_NOTICE,
            'vapid_configured' => $this->vapidConfigured(),
            'vapid_public_key' => $this->vapidPublicKey(),
            'vapid_subject' => $this->vapidSubject(),
        ];

        if (!$this->connection->tableExists('push_settings')) {
            return $settings;
        }

        $row = $this->connection->fetchOne('SELECT * FROM push_settings WHERE id = 1 LIMIT 1') ?? [];

        if ($row === []) {
            return $settings;
        }

        return [
            ...$settings,
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'reminder_enabled' => (int) ($row['reminder_enabled'] ?? 1) === 1,
            'reminder_time' => $this->normalizeTime((string) ($row['reminder_time'] ?? '09:00')),
            'reminder_weekdays' => $this->normalizeWeekdays((string) ($row['reminder_weekdays'] ?? '1,2,3,4,5')),
            'notice_text' => trim((string) ($row['notice_text'] ?? '')) !== '' ? (string) $row['notice_text'] : self::DEFAULT_NOTICE,
        ];
    }

    public function save(array $input): void
    {
        if (!$this->connection->tableExists('push_settings')) {
            return;
        }

        $enabled = (string) ($input['enabled'] ?? '0') === '1' ? 1 : 0;
        $reminderEnabled = (string) ($input['reminder_enabled'] ?? '0') === '1' ? 1 : 0;
        $time = $this->normalizeTime((string) ($input['reminder_time'] ?? '09:00'));
        $weekdays = $this->weekdayListFromInput($input['reminder_weekdays'] ?? []);
        $notice = trim((string) ($input['notice_text'] ?? ''));

        if ($notice === '') {
            $notice = self::DEFAULT_NOTICE;
        }

        $this->connection->execute(
            'INSERT INTO push_settings (
                id, enabled, reminder_enabled, reminder_time, reminder_weekdays, notice_text, created_at, updated_at
            ) VALUES (
                1, :enabled, :reminder_enabled, :reminder_time, :reminder_weekdays, :notice_text, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                reminder_enabled = VALUES(reminder_enabled),
                reminder_time = VALUES(reminder_time),
                reminder_weekdays = VALUES(reminder_weekdays),
                notice_text = VALUES(notice_text),
                updated_at = NOW()',
            [
                'enabled' => $enabled,
                'reminder_enabled' => $reminderEnabled,
                'reminder_time' => $time,
                'reminder_weekdays' => implode(',', $weekdays),
                'notice_text' => $notice,
            ]
        );
    }

    public function vapidConfigured(): bool
    {
        return $this->vapidPublicKey() !== '' && $this->vapidPrivateKey() !== '' && $this->vapidSubject() !== '';
    }

    public function vapidPublicKey(): string
    {
        return trim((string) ($this->pushConfig['vapid']['public_key'] ?? ''));
    }

    public function vapidPrivateKey(): string
    {
        return trim((string) ($this->pushConfig['vapid']['private_key'] ?? ''));
    }

    public function vapidSubject(): string
    {
        return trim((string) ($this->pushConfig['vapid']['subject'] ?? 'mailto:admin@example.invalid'));
    }

    private function normalizeTime(string $time): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '09:00';
    }

    /**
     * @return array<int, int>
     */
    private function normalizeWeekdays(string $weekdays): array
    {
        $items = array_values(array_unique(array_filter(array_map(
            static fn (string $day): int => (int) trim($day),
            explode(',', $weekdays)
        ), static fn (int $day): bool => $day >= 1 && $day <= 7)));

        sort($items);

        return $items !== [] ? $items : [1, 2, 3, 4, 5];
    }

    /**
     * @param mixed $input
     * @return array<int, int>
     */
    private function weekdayListFromInput(mixed $input): array
    {
        if (!is_array($input)) {
            return $this->normalizeWeekdays((string) $input);
        }

        $items = array_values(array_unique(array_filter(array_map(
            static fn (mixed $day): int => (int) $day,
            $input
        ), static fn (int $day): bool => $day >= 1 && $day <= 7)));

        sort($items);

        return $items !== [] ? $items : [1, 2, 3, 4, 5];
    }
}
