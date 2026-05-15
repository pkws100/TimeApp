<?php

declare(strict_types=1);

namespace App\Domain\Push;

use App\Infrastructure\Database\DatabaseConnection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

final class PushNotificationService
{
    public function __construct(
        private DatabaseConnection $connection,
        private PushSettingsService $settingsService,
        private PushSubscriptionService $subscriptionService
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->settingsService->vapidConfigured() && class_exists(WebPush::class);
    }

    public function send(array $subscriptionRow, array $message, ?string $dedupeKey = null, ?string $workDate = null): array
    {
        $userId = (int) ($subscriptionRow['user_id'] ?? 0);
        $subscriptionId = (int) ($subscriptionRow['id'] ?? 0);
        $title = (string) ($message['title'] ?? 'Zeiterfassung');
        $body = (string) ($message['body'] ?? '');
        $notificationType = (string) ($message['type'] ?? 'push');
        $payload = [
            'title' => $title,
            'body' => $body,
            'url' => (string) ($message['url'] ?? '/app/zeiten'),
            'tag' => (string) ($message['tag'] ?? $notificationType),
        ];
        $dedupeKey ??= $notificationType . ':' . $userId . ':' . date('YmdHis');

        if (!$this->isConfigured()) {
            $this->log($userId, $subscriptionId, $notificationType, $workDate, $dedupeKey, $title, $body, $payload, 'error', 'VAPID ist nicht vollstaendig konfiguriert.');

            return ['ok' => false, 'message' => 'VAPID ist nicht vollstaendig konfiguriert.'];
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $this->settingsService->vapidSubject(),
                    'publicKey' => $this->settingsService->vapidPublicKey(),
                    'privateKey' => $this->settingsService->vapidPrivateKey(),
                ],
            ]);
            $subscription = Subscription::create([
                'endpoint' => (string) ($subscriptionRow['endpoint'] ?? ''),
                'publicKey' => (string) ($subscriptionRow['public_key'] ?? ''),
                'authToken' => (string) ($subscriptionRow['auth_token'] ?? ''),
                'contentEncoding' => (string) ($subscriptionRow['content_encoding'] ?? 'aes128gcm'),
            ]);
            $report = $webPush->sendOneNotification(
                $subscription,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );

            if ($report->isSuccess()) {
                $this->subscriptionService->markSuccess($subscriptionId);
                $this->log($userId, $subscriptionId, $notificationType, $workDate, $dedupeKey, $title, $body, $payload, 'sent');

                return ['ok' => true, 'message' => 'Push-Benachrichtigung wurde gesendet.'];
            }

            $reason = $report->getReason() ?: 'Push-Versand fehlgeschlagen.';
            $this->subscriptionService->markFailure($subscriptionId, $reason, $report->isSubscriptionExpired());
            $this->log($userId, $subscriptionId, $notificationType, $workDate, $dedupeKey, $title, $body, $payload, 'error', $reason);

            return ['ok' => false, 'message' => $reason];
        } catch (Throwable $throwable) {
            $messageText = $throwable->getMessage();
            $this->subscriptionService->markFailure($subscriptionId, $messageText);
            $this->log($userId, $subscriptionId, $notificationType, $workDate, $dedupeKey, $title, $body, $payload, 'error', $messageText);

            return ['ok' => false, 'message' => $messageText];
        }
    }

    public function logExists(string $dedupeKey): bool
    {
        if (!$this->connection->tableExists('push_notification_log')) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM push_notification_log WHERE dedupe_key = :dedupe_key AND status IN ("sent", "dry_run")',
            ['dedupe_key' => $dedupeKey]
        ) ?? 0) > 0;
    }

    public function logDryRun(int $userId, string $workDate, string $dedupeKey, string $title, string $body): void
    {
        $this->log($userId, null, 'missing_booking_reminder', $workDate, $dedupeKey, $title, $body, [
            'title' => $title,
            'body' => $body,
            'url' => '/app/zeiten',
            'tag' => 'missing-booking-' . $workDate,
        ], 'dry_run');
    }

    private function log(
        int $userId,
        ?int $subscriptionId,
        string $notificationType,
        ?string $workDate,
        string $dedupeKey,
        string $title,
        string $body,
        array $payload,
        string $status,
        ?string $errorMessage = null
    ): void {
        if (!$this->connection->tableExists('push_notification_log')) {
            return;
        }

        $this->connection->execute(
            'INSERT INTO push_notification_log (
                user_id, subscription_id, notification_type, work_date, dedupe_key, title, body,
                payload_json, status, error_message, sent_at, created_at
            ) VALUES (
                :user_id, :subscription_id, :notification_type, :work_date, :dedupe_key, :title, :body,
                :payload_json, :status, :error_message, CASE WHEN :sent = 1 THEN NOW() ELSE NULL END, NOW()
            )
            ON DUPLICATE KEY UPDATE
                subscription_id = VALUES(subscription_id),
                status = CASE WHEN push_notification_log.status = "sent" THEN "sent" ELSE VALUES(status) END,
                error_message = CASE WHEN push_notification_log.status = "sent" THEN push_notification_log.error_message ELSE VALUES(error_message) END,
                sent_at = CASE WHEN sent_at IS NOT NULL THEN sent_at ELSE VALUES(sent_at) END',
            [
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'notification_type' => $notificationType,
                'work_date' => $workDate,
                'dedupe_key' => $dedupeKey,
                'title' => mb_substr($title, 0, 180),
                'body' => $body,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => $status,
                'error_message' => $errorMessage,
                'sent' => in_array($status, ['sent', 'dry_run'], true) ? 1 : 0,
            ]
        );
    }
}
