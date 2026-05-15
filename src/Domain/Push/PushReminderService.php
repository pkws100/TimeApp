<?php

declare(strict_types=1);

namespace App\Domain\Push;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;

final class PushReminderService
{
    public function __construct(
        private DatabaseConnection $connection,
        private PushSettingsService $settingsService,
        private PushSubscriptionService $subscriptionService,
        private PushNotificationService $notificationService,
        private string $timezone
    ) {
    }

    public function sendDueReminders(?DateTimeImmutable $now = null, bool $dryRun = false): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($this->timezone));
        $settings = $this->settingsService->current();
        $summary = [
            'checked_at' => $now->format(DATE_ATOM),
            'dry_run' => $dryRun,
            'candidates' => 0,
            'sent' => 0,
            'skipped' => 0,
            'errors' => 0,
            'messages' => [],
        ];

        if (!$settings['enabled'] || !$settings['reminder_enabled']) {
            $summary['messages'][] = 'Push-Erinnerungen sind deaktiviert.';

            return $summary;
        }

        if (!$dryRun && !$this->notificationService->isConfigured()) {
            $summary['messages'][] = 'VAPID ist nicht vollstaendig konfiguriert.';

            return $summary;
        }

        if (!$this->isDue($now, $settings)) {
            $summary['messages'][] = 'Reminder-Zeitfenster ist noch nicht erreicht oder der Wochentag ist deaktiviert.';

            return $summary;
        }

        foreach ($this->candidateUsers($now->format('Y-m-d')) as $user) {
            $summary['candidates']++;
            $userId = (int) $user['id'];
            $workDate = $now->format('Y-m-d');
            $dedupeKey = 'missing-booking:' . $workDate . ':' . $userId;

            if ($this->notificationService->logExists($dedupeKey)) {
                $summary['skipped']++;
                continue;
            }

            $title = 'Buchung fehlt';
            $body = (string) $settings['notice_text'];
            $subscriptions = $this->subscriptionService->activeSubscriptionsForUser($userId);

            if ($subscriptions === []) {
                $summary['skipped']++;
                continue;
            }

            if ($dryRun) {
                $summary['sent']++;
                continue;
            }

            $sentForUser = false;

            foreach ($subscriptions as $subscription) {
                $result = $this->notificationService->send($subscription, [
                    'type' => 'missing_booking_reminder',
                    'title' => $title,
                    'body' => $body,
                    'url' => '/app/zeiten',
                    'tag' => 'missing-booking-' . $workDate,
                ], $dedupeKey, $workDate);

                if ($result['ok'] ?? false) {
                    $sentForUser = true;
                }
            }

            if ($sentForUser) {
                $summary['sent']++;
            } else {
                $summary['errors']++;
            }
        }

        return $summary;
    }

    private function isDue(DateTimeImmutable $now, array $settings): bool
    {
        $weekday = (int) $now->format('N');

        if (!in_array($weekday, $settings['reminder_weekdays'] ?? [], true)) {
            return false;
        }

        $reminderTime = (string) ($settings['reminder_time'] ?? '09:00');

        return $now->format('H:i') >= $reminderTime;
    }

    private function candidateUsers(string $workDate): array
    {
        if (!$this->connection->tableExists('users')
            || !$this->connection->tableExists('timesheets')
            || !$this->connection->tableExists('push_subscriptions')
            || !$this->connection->tableExists('permissions')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT DISTINCT users.id, users.first_name, users.last_name, users.email
             FROM users
             INNER JOIN user_roles ON user_roles.user_id = users.id
             INNER JOIN role_permissions ON role_permissions.role_id = user_roles.role_id
             INNER JOIN permissions ON permissions.id = role_permissions.permission_id
             INNER JOIN push_subscriptions ON push_subscriptions.user_id = users.id AND push_subscriptions.is_enabled = 1
             WHERE permissions.code = "push.receive"
               AND COALESCE(users.is_deleted, 0) = 0
               AND users.employment_status = "active"
               AND NOT EXISTS (
                   SELECT 1
                   FROM timesheets
                   WHERE timesheets.user_id = users.id
                     AND timesheets.work_date = :work_date
                     AND COALESCE(timesheets.is_deleted, 0) = 0
               )
             ORDER BY users.last_name ASC, users.first_name ASC',
            ['work_date' => $workDate]
        );
    }
}
