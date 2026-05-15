<?php

declare(strict_types=1);

namespace App\Domain\Push;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class PushSubscriptionService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function storeForUser(array $user, array $payload, string $userAgent = ''): array
    {
        if (!$this->connection->tableExists('push_subscriptions')) {
            throw new InvalidArgumentException('Push ist noch nicht migriert.');
        }

        $endpoint = trim((string) ($payload['endpoint'] ?? ''));
        $keys = is_array($payload['keys'] ?? null) ? $payload['keys'] : [];
        $publicKey = trim((string) ($keys['p256dh'] ?? ''));
        $authToken = trim((string) ($keys['auth'] ?? ''));

        if ($endpoint === '' || $publicKey === '' || $authToken === '') {
            throw new InvalidArgumentException('Die Push-Anmeldung ist unvollstaendig.');
        }

        $endpointHash = hash('sha256', $endpoint);
        $contentEncoding = trim((string) ($payload['contentEncoding'] ?? $payload['content_encoding'] ?? 'aes128gcm'));
        $deviceLabel = $this->deviceLabel((string) ($payload['device_label'] ?? ''), $userAgent);

        $this->connection->execute(
            'INSERT INTO push_subscriptions (
                user_id, endpoint_hash, endpoint, public_key, auth_token, content_encoding, device_label,
                user_agent, is_enabled, permission_status, last_seen_at, created_at, updated_at
            ) VALUES (
                :user_id, :endpoint_hash, :endpoint, :public_key, :auth_token, :content_encoding, :device_label,
                :user_agent, 1, :permission_status, NOW(), NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                endpoint = VALUES(endpoint),
                public_key = VALUES(public_key),
                auth_token = VALUES(auth_token),
                content_encoding = VALUES(content_encoding),
                device_label = VALUES(device_label),
                user_agent = VALUES(user_agent),
                is_enabled = 1,
                permission_status = VALUES(permission_status),
                last_seen_at = NOW(),
                updated_at = NOW()',
            [
                'user_id' => (int) ($user['id'] ?? 0),
                'endpoint_hash' => $endpointHash,
                'endpoint' => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => $contentEncoding !== '' ? $contentEncoding : 'aes128gcm',
                'device_label' => $deviceLabel,
                'user_agent' => $userAgent,
                'permission_status' => (string) ($payload['permission_status'] ?? 'granted'),
            ]
        );

        return $this->findByEndpointHash($endpointHash) ?? [];
    }

    public function devicesForUser(int $userId): array
    {
        if (!$this->connection->tableExists('push_subscriptions')) {
            return [];
        }

        return array_map([$this, 'publicDevice'], $this->connection->fetchAll(
            'SELECT *
             FROM push_subscriptions
             WHERE user_id = :user_id
             ORDER BY is_enabled DESC, last_seen_at DESC, id DESC',
            ['user_id' => $userId]
        ));
    }

    public function activeSubscriptionsForUser(int $userId): array
    {
        if (!$this->connection->tableExists('push_subscriptions')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT *
             FROM push_subscriptions
             WHERE user_id = :user_id
               AND is_enabled = 1
             ORDER BY last_seen_at DESC, id DESC',
            ['user_id' => $userId]
        );
    }

    public function adminList(): array
    {
        if (!$this->connection->tableExists('push_subscriptions')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT
                push_subscriptions.*,
                users.first_name,
                users.last_name,
                users.email
             FROM push_subscriptions
             INNER JOIN users ON users.id = push_subscriptions.user_id
             ORDER BY push_subscriptions.is_enabled DESC, push_subscriptions.updated_at DESC, push_subscriptions.id DESC'
        );
    }

    public function setEnabled(int $id, bool $enabled, ?int $userId = null): bool
    {
        if (!$this->connection->tableExists('push_subscriptions') || $id <= 0) {
            return false;
        }

        $sql = 'UPDATE push_subscriptions SET is_enabled = :enabled, updated_at = NOW() WHERE id = :id';
        $params = [
            'enabled' => $enabled ? 1 : 0,
            'id' => $id,
        ];

        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = $userId;
        }

        return $this->connection->execute($sql, $params);
    }

    public function markSuccess(int $id): void
    {
        $this->connection->execute(
            'UPDATE push_subscriptions
             SET last_success_at = NOW(), last_error_at = NULL, last_error_message = NULL, failed_attempts = 0, updated_at = NOW()
             WHERE id = :id',
            ['id' => $id]
        );
    }

    public function markFailure(int $id, string $message, bool $disable = false): void
    {
        $this->connection->execute(
            'UPDATE push_subscriptions
             SET last_error_at = NOW(),
                 last_error_message = :message,
                 failed_attempts = failed_attempts + 1,
                 is_enabled = CASE WHEN :disable = 1 THEN 0 ELSE is_enabled END,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'message' => mb_substr($message, 0, 1000),
                'disable' => $disable ? 1 : 0,
            ]
        );
    }

    private function findByEndpointHash(string $endpointHash): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM push_subscriptions WHERE endpoint_hash = :endpoint_hash LIMIT 1',
            ['endpoint_hash' => $endpointHash]
        );
    }

    private function publicDevice(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'device_label' => (string) ($row['device_label'] ?? 'Dieses Geraet'),
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
            'permission_status' => (string) ($row['permission_status'] ?? 'granted'),
            'last_seen_at' => $row['last_seen_at'] ?? null,
            'last_success_at' => $row['last_success_at'] ?? null,
            'last_error_at' => $row['last_error_at'] ?? null,
            'last_error_message' => $row['last_error_message'] ?? null,
        ];
    }

    private function deviceLabel(string $label, string $userAgent): string
    {
        $label = trim($label);

        if ($label !== '') {
            return mb_substr($label, 0, 150);
        }

        if (stripos($userAgent, 'iphone') !== false || stripos($userAgent, 'ipad') !== false) {
            return 'iOS PWA';
        }

        if (stripos($userAgent, 'android') !== false) {
            return 'Android Browser';
        }

        return 'Browser-Geraet';
    }
}
