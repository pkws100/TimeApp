<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Push\PushSender;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ProjectDispatchService
{
    public function __construct(
        private DatabaseConnection $connection,
        private ProjectAccessService $projectAccessService,
        private PushSettingsService $pushSettingsService,
        private PushSubscriptionService $pushSubscriptionService,
        private PushSender $pushSender
    ) {
    }

    public function recipientPreview(array $actor, int $projectId): array
    {
        $this->assertManageAccess($actor, $projectId);

        if ($this->activeProject($projectId) === null) {
            return [];
        }

        return array_map(static function (array $recipient): array {
            $hasPermission = (int) ($recipient['has_push_permission'] ?? 0) === 1;
            $subscriptionCount = (int) ($recipient['active_subscription_count'] ?? 0);
            $skipReason = null;

            if (!$hasPermission) {
                $skipReason = 'Push-Recht fehlt';
            } elseif ($subscriptionCount === 0) {
                $skipReason = 'Kein aktives Push-Geraet';
            }

            return [
                'user_id' => (int) ($recipient['id'] ?? 0),
                'display_name' => trim((string) ($recipient['first_name'] ?? '') . ' ' . (string) ($recipient['last_name'] ?? '')),
                'email' => (string) ($recipient['email'] ?? ''),
                'has_push_permission' => $hasPermission,
                'active_subscription_count' => $subscriptionCount,
                'skip_reason' => $skipReason,
            ];
        }, $this->recipientRows($projectId));
    }

    public function dispatch(array $actor, int $projectId): array
    {
        $this->assertManageAccess($actor, $projectId);
        $project = $this->activeProject($projectId);

        if ($project === null) {
            throw new InvalidArgumentException('Das Projekt kann nicht versendet werden.');
        }

        foreach (['project_dispatches', 'project_dispatch_recipients'] as $table) {
            if (!$this->connection->tableExists($table)) {
                throw new RuntimeException('Der Projektversand ist noch nicht migriert.');
            }
        }

        $projectLabel = trim((string) ($project['project_number'] ?? '') . ' – ' . (string) ($project['name'] ?? ''));
        $projectLabel = mb_substr($projectLabel !== '' ? $projectLabel : 'Projekt #' . $projectId, 0, 140);
        $targetUrl = '/app/projektwahl?project=' . $projectId;
        $actorId = (int) ($actor['id'] ?? 0);
        $recipients = $this->recipientRows($projectId);

        if ($recipients === []) {
            throw new InvalidArgumentException('Dem Projekt sind keine aktiven Empfaenger zugeordnet.');
        }

        $dispatch = $this->createDispatch($projectId, $actorId, $projectLabel, $targetUrl);
        $dispatchId = (int) $dispatch['id'];
        $settings = $this->pushSettingsService->current();
        $pushAvailable = (bool) ($settings['enabled'] ?? false) && $this->pushSender->isConfigured();
        $pushUnavailableMessage = !(bool) ($settings['enabled'] ?? false)
            ? 'Push ist global deaktiviert.'
            : 'VAPID ist nicht vollstaendig konfiguriert.';
        $counts = [
            'recipient_count' => count($recipients),
            'delivered_count' => 0,
            'skipped_count' => 0,
            'error_count' => 0,
        ];
        $successfulDeviceCount = 0;
        $failedDeviceCount = 0;

        foreach ($recipients as $recipient) {
            $result = $this->dispatchToRecipient(
                $dispatchId,
                $recipient,
                (string) $dispatch['title'],
                (string) $dispatch['body'],
                $targetUrl,
                $pushAvailable,
                $pushUnavailableMessage
            );
            $counts[$result['status'] === 'sent' ? 'delivered_count' : $result['status'] . '_count']++;
            $successfulDeviceCount += (int) ($result['successful_subscription_count'] ?? 0);
            $failedDeviceCount += (int) ($result['failed_subscription_count'] ?? 0);
            $this->storeRecipientResult($dispatchId, (int) $recipient['id'], $result);
        }

        $this->connection->execute(
            'UPDATE project_dispatches
             SET recipient_count = :recipient_count,
                 delivered_count = :delivered_count,
                 skipped_count = :skipped_count,
                 error_count = :error_count
             WHERE id = :id',
            [...$counts, 'id' => $dispatchId]
        );

        return [
            ...$dispatch,
            ...$counts,
            'successful_device_count' => $successfulDeviceCount,
            'failed_device_count' => $failedDeviceCount,
            'push_available' => $pushAvailable,
            'message' => $pushAvailable
                ? 'Der Auftrag wurde verarbeitet.'
                : $pushUnavailableMessage . ' Der Versandversuch wurde protokolliert.',
        ];
    }

    public function history(array $actor, int $projectId, int $limit = 10): array
    {
        $this->assertManageAccess($actor, $projectId);

        if (!$this->connection->tableExists('project_dispatches')) {
            return [];
        }

        $limit = max(1, min(50, $limit));

        return $this->connection->fetchAll(
            'SELECT
                project_dispatches.*,
                users.first_name AS sender_first_name,
                users.last_name AS sender_last_name,
                COALESCE((
                    SELECT SUM(project_dispatch_recipients.successful_subscription_count)
                    FROM project_dispatch_recipients
                    WHERE project_dispatch_recipients.dispatch_id = project_dispatches.id
                ), 0) AS successful_device_count,
                COALESCE((
                    SELECT SUM(project_dispatch_recipients.failed_subscription_count)
                    FROM project_dispatch_recipients
                    WHERE project_dispatch_recipients.dispatch_id = project_dispatches.id
                ), 0) AS failed_device_count
             FROM project_dispatches
             LEFT JOIN users ON users.id = project_dispatches.sent_by_user_id
             WHERE project_dispatches.project_id = :project_id
             ORDER BY project_dispatches.created_at DESC, project_dispatches.id DESC
             LIMIT ' . $limit,
            ['project_id' => $projectId]
        );
    }

    private function createDispatch(int $projectId, int $actorId, string $projectLabel, string $targetUrl): array
    {
        return $this->connection->transaction(function () use ($projectId, $actorId, $projectLabel, $targetUrl): array {
            $project = $this->connection->fetchOne(
                'SELECT id
                 FROM projects
                 WHERE id = :project_id
                   AND COALESCE(is_deleted, 0) = 0
                   AND status <> "archived"
                 FOR UPDATE',
                ['project_id' => $projectId]
            );

            if ($project === null) {
                throw new InvalidArgumentException('Das Projekt kann nicht versendet werden.');
            }

            $isUpdate = (int) ($this->connection->fetchColumn(
                'SELECT COUNT(*) FROM project_dispatches WHERE project_id = :dispatch_project_id',
                ['dispatch_project_id' => $projectId]
            ) ?? 0) > 0;
            $kind = $isUpdate ? 'update' : 'new';
            $title = $isUpdate ? 'Auftrag aktualisiert' : 'Neuer Auftrag';
            $body = $projectLabel . "\n" . ($isUpdate
                ? 'Die Arbeitsanweisung oder die Auftragsunterlagen wurden aktualisiert.'
                : 'Ein neuer Arbeitsauftrag wurde fuer Sie bereitgestellt.');

            $this->connection->execute(
                'INSERT INTO project_dispatches (
                    project_id, dispatch_kind, title, body, target_url, sent_by_user_id,
                    recipient_count, delivered_count, skipped_count, error_count, created_at
                 ) VALUES (
                    :project_id, :dispatch_kind, :title, :body, :target_url, :sent_by_user_id,
                    0, 0, 0, 0, NOW()
                 )',
                [
                    'project_id' => $projectId,
                    'dispatch_kind' => $kind,
                    'title' => $title,
                    'body' => mb_substr($body, 0, 500),
                    'target_url' => $targetUrl,
                    'sent_by_user_id' => $actorId > 0 ? $actorId : null,
                ]
            );

            return [
                'id' => $this->connection->lastInsertId(),
                'project_id' => $projectId,
                'dispatch_kind' => $kind,
                'title' => $title,
                'body' => mb_substr($body, 0, 500),
                'target_url' => $targetUrl,
            ];
        });
    }

    private function dispatchToRecipient(
        int $dispatchId,
        array $recipient,
        string $title,
        string $body,
        string $targetUrl,
        bool $pushAvailable,
        string $pushUnavailableMessage
    ): array {
        $userId = (int) ($recipient['id'] ?? 0);

        if ((int) ($recipient['has_push_permission'] ?? 0) !== 1) {
            return $this->recipientResult('skipped', 0, 0, 0, 'Push-Recht fehlt.');
        }

        $subscriptions = $this->deduplicateSubscriptions(
            $this->pushSubscriptionService->activeSubscriptionsForUser($userId)
        );
        $activeCount = count($subscriptions);

        if ($activeCount === 0) {
            return $this->recipientResult('skipped', 0, 0, 0, 'Kein aktives Push-Geraet.');
        }

        if (!$pushAvailable) {
            return $this->recipientResult('error', $activeCount, 0, 0, $pushUnavailableMessage);
        }

        $successful = 0;
        $failed = 0;
        $errors = [];

        foreach ($subscriptions as $subscription) {
            $subscriptionId = (int) ($subscription['id'] ?? 0);
            $dedupeKey = 'project-dispatch:' . $dispatchId . ':user:' . $userId . ':subscription:' . $subscriptionId;

            try {
                $result = $this->pushSender->send($subscription, [
                    'type' => 'project_dispatch',
                    'title' => $title,
                    'body' => $body,
                    'url' => $targetUrl,
                    'tag' => 'project-dispatch-' . $dispatchId,
                ], $dedupeKey);
            } catch (Throwable $throwable) {
                $result = ['ok' => false, 'message' => $throwable->getMessage()];
                error_log('Project dispatch subscription failed: ' . $throwable->getMessage());
            }

            if (($result['ok'] ?? false) === true) {
                $successful++;
            } else {
                $failed++;
                $message = trim((string) ($result['message'] ?? 'Push-Versand fehlgeschlagen.'));

                if ($message !== '') {
                    $errors[] = $message;
                }
            }
        }

        if ($successful > 0) {
            $message = $failed > 0
                ? $successful . ' Geraet(e) erreicht, ' . $failed . ' fehlgeschlagen.'
                : $successful . ' Geraet(e) erfolgreich erreicht.';

            return $this->recipientResult('sent', $activeCount, $successful, $failed, $message);
        }

        return $this->recipientResult(
            'error',
            $activeCount,
            0,
            $failed,
            implode(' | ', array_values(array_unique($errors))) ?: 'Kein Push-Geraet konnte erreicht werden.'
        );
    }

    private function recipientRows(int $projectId): array
    {
        if (
            !$this->connection->tableExists('project_memberships')
            || !$this->connection->tableExists('users')
            || !$this->connection->tableExists('roles')
            || !$this->connection->tableExists('user_roles')
            || !$this->connection->tableExists('role_permissions')
            || !$this->connection->tableExists('permissions')
        ) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT
                users.id,
                users.first_name,
                users.last_name,
                users.email,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM user_roles
                    INNER JOIN roles ON roles.id = user_roles.role_id
                    INNER JOIN role_permissions ON role_permissions.role_id = roles.id
                    INNER JOIN permissions ON permissions.id = role_permissions.permission_id
                    WHERE user_roles.user_id = users.id
                      AND COALESCE(roles.is_deleted, 0) = 0
                      AND permissions.code IN ("push.receive", "*")
                ) THEN 1 ELSE 0 END AS has_push_permission,
                (
                    SELECT COUNT(*)
                    FROM push_subscriptions
                    WHERE push_subscriptions.user_id = users.id
                      AND push_subscriptions.is_enabled = 1
                ) AS active_subscription_count
             FROM project_memberships
             INNER JOIN users ON users.id = project_memberships.user_id
             WHERE project_memberships.project_id = :project_id
               AND users.employment_status = "active"
               AND COALESCE(users.is_deleted, 0) = 0
               AND (project_memberships.assigned_from IS NULL OR project_memberships.assigned_from <= CURDATE())
               AND (project_memberships.assigned_until IS NULL OR project_memberships.assigned_until >= CURDATE())
             ORDER BY users.last_name ASC, users.first_name ASC, users.id ASC',
            ['project_id' => $projectId]
        );
    }

    private function activeProject(int $projectId): ?array
    {
        if (!$this->connection->tableExists('projects')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT id, project_number, name
             FROM projects
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0
               AND status <> "archived"
             LIMIT 1',
            ['id' => $projectId]
        );
    }

    private function storeRecipientResult(int $dispatchId, int $userId, array $result): void
    {
        $this->connection->execute(
            'INSERT INTO project_dispatch_recipients (
                dispatch_id, user_id, status, active_subscription_count,
                successful_subscription_count, failed_subscription_count,
                result_message, created_at, updated_at
             ) VALUES (
                :dispatch_id, :user_id, :status, :active_subscription_count,
                :successful_subscription_count, :failed_subscription_count,
                :result_message, NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                active_subscription_count = VALUES(active_subscription_count),
                successful_subscription_count = VALUES(successful_subscription_count),
                failed_subscription_count = VALUES(failed_subscription_count),
                result_message = VALUES(result_message),
                updated_at = NOW()',
            [
                'dispatch_id' => $dispatchId,
                'user_id' => $userId,
                ...$result,
            ]
        );
    }

    private function deduplicateSubscriptions(array $subscriptions): array
    {
        $unique = [];
        $seenIds = [];
        $seenEndpoints = [];

        foreach ($subscriptions as $subscription) {
            $id = (int) ($subscription['id'] ?? 0);
            $endpointHash = trim((string) ($subscription['endpoint_hash'] ?? ''));

            if (
                ($id <= 0 && $endpointHash === '')
                || ($id > 0 && isset($seenIds[$id]))
                || ($endpointHash !== '' && isset($seenEndpoints[$endpointHash]))
            ) {
                continue;
            }

            if ($id > 0) {
                $seenIds[$id] = true;
            }

            if ($endpointHash !== '') {
                $seenEndpoints[$endpointHash] = true;
            }

            $unique[] = $subscription;
        }

        return $unique;
    }

    private function recipientResult(
        string $status,
        int $active,
        int $successful,
        int $failed,
        string $message
    ): array {
        return [
            'status' => $status,
            'active_subscription_count' => $active,
            'successful_subscription_count' => $successful,
            'failed_subscription_count' => $failed,
            'result_message' => mb_substr($message, 0, 2000),
        ];
    }

    private function assertManageAccess(array $actor, int $projectId): void
    {
        if (!$this->projectAccessService->canManage($actor) || !$this->projectAccessService->canAccess($actor, $projectId)) {
            throw new RuntimeException('Keine Berechtigung fuer den Projektversand.');
        }
    }
}
