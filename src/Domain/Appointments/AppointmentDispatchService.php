<?php

declare(strict_types=1);

namespace App\Domain\Appointments;

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Push\PushSender;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AppointmentDispatchService
{
    private const MESSAGES = [
        'inactive' => 'Das Mitarbeiterkonto ist nicht aktiv.',
        'no_permission' => 'Das Recht für Push-Benachrichtigungen fehlt.',
        'no_device' => 'Es ist kein aktives Push-Gerät registriert.',
        'push_disabled' => 'Push ist global deaktiviert.',
        'push_unconfigured' => 'Push ist serverseitig nicht vollständig eingerichtet.',
        'appointment_changed' => 'Der Termin ist in diesem Stand nicht mehr für den Versand verfügbar.',
        'partially_accepted' => 'Der Push-Dienst hat die Nachricht nur für einen Teil der Geräte angenommen.',
        'failed' => 'Die Nachricht wurde für kein Gerät angenommen.',
        'uncertain' => 'Die Annahme der Nachricht durch den Push-Dienst ist unklar.',
    ];

    private const DEVICE_RESULTS = [
        'accepted_subscription_count' => 0,
        'failed_subscription_count' => 0,
        'uncertain_subscription_count' => 0,
    ];

    private const ACTIVE_RECIPIENT = 'COALESCE(u.is_deleted, 0) = 0 AND u.employment_status = "active"';
    private const PUSH_PERMISSION = 'EXISTS (
        SELECT 1 FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id AND COALESCE(r.is_deleted, 0) = 0
        INNER JOIN role_permissions rp ON rp.role_id = r.id
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE ur.user_id = u.id AND p.code IN ("push.receive", "*")
    )';

    private readonly DateTimeZone $timezone;

    public function __construct(
        private readonly DatabaseConnection $connection,
        private readonly ProjectAccessService $projectAccess,
        private readonly PushSettingsService $pushSettings,
        private readonly PushSubscriptionService $subscriptions,
        private readonly PushSender $sender,
        string $timezone = 'Europe/Berlin'
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function recipientPreview(array $actor, int $appointmentId): array
    {
        $this->requireRight($actor);

        return $this->connection->transaction(function () use ($actor, $appointmentId): array {
            $appointment = $this->appointment($actor, $appointmentId, ' LOCK IN SHARE MODE');
            $this->assertSendable($appointment, (int) $appointment['version']);
            $pushIssue = $this->pushIssue();

            return [
                'appointment_version' => (int) $appointment['version'],
                'push_available' => $pushIssue === null,
                'status' => $pushIssue ?? 'ready',
                'error_message' => self::MESSAGES[$pushIssue] ?? null,
                'recipients' => $this->recipients($appointmentId, $pushIssue),
            ];
        });
    }

    public function dispatch(array $actor, int $appointmentId, int $version, string $requestKey): array
    {
        $actorId = $this->requireRight($actor);
        if ($version < 1 || $version > 4294967295 || preg_match('/\A[a-f0-9]{32}\z/D', $requestKey) !== 1) {
            throw new InvalidArgumentException('Der Versandauftrag ist ungültig. Bitte öffnen Sie die Empfängervorschau erneut.');
        }

        // Commit the dispatch and all recipient rows before contacting any provider.
        [$dispatchId, $message, $devices] = $this->connection->transaction(function () use ($actor, $actorId, $appointmentId, $version, $requestKey): array {
            $appointment = $this->appointment($actor, $appointmentId, ' FOR UPDATE', allowArchivedProject: true);
            $previous = $this->connection->fetchOne(
                'SELECT id, appointment_version, project_id, project_scope_known FROM appointment_dispatches
                 WHERE appointment_id = :appointment AND sent_by_user_id = :actor AND request_key = :request_key',
                ['appointment' => $appointmentId, 'actor' => $actorId, 'request_key' => $requestKey]
            );
            if ($previous !== null) {
                if (!$this->canReadSnapshot($actor, $previous)) {
                    throw $this->notFound();
                }
                if ((int) $previous['appointment_version'] !== $version) {
                    throw new AppointmentConflictException('Diese Versandkennung gehört zu einer anderen Terminversion.');
                }

                return [(int) $previous['id'], null, []];
            }

            if (!$this->canAccessProject($actor, $appointment)) {
                throw $this->notFound();
            }
            $this->assertSendable($appointment, $version);
            $recipients = $this->recipients($appointmentId, $this->pushIssue());
            if ($recipients === []) {
                throw new InvalidArgumentException('Dem Termin sind keine Mitarbeiter zugeordnet.');
            }
            $message = $this->message($appointment);
            $dispatchId = $this->insertDispatch($appointment, $actorId, $requestKey, $message);
            $devices = [];
            foreach ($recipients as $recipient) {
                $userId = $recipient['user_id'];
                if ($recipient['status'] === 'ready') {
                    $devices[$userId] = [];
                    foreach ($this->subscriptions->activeSubscriptionsForUser($userId) as $device) {
                        if ($device['permission_status'] === 'granted') {
                            $devices[$userId][] = ['id' => (int) $device['id'], 'endpoint' => $device['endpoint']];
                        }
                    }
                    $recipient['active_subscription_count'] = count($devices[$userId]);
                    $recipient['status'] = $devices[$userId] === [] ? 'no_device' : 'pending';
                }
                $this->insertRecipient($dispatchId, $recipient);
            }

            return [$dispatchId, $message, $devices];
        });

        if ($message !== null) {
            foreach ($devices as $userId => $recipientDevices) {
                if ($recipientDevices !== []) {
                    $this->deliver($actor, $appointmentId, $version, $dispatchId, $userId, $message, $recipientDevices);
                }
            }
            $this->write(
                'UPDATE appointment_dispatches SET completed_at_utc = UTC_TIMESTAMP() WHERE id = :id',
                ['id' => $dispatchId]
            );
        }

        return $this->connection->transaction(function () use ($actor, $appointmentId, $dispatchId): array {
            $this->appointment($actor, $appointmentId, allowArchivedProject: true);

            return $this->dispatchRecords($actor, $appointmentId, $dispatchId, 1)[0] ?? throw $this->notFound();
        });
    }

    /** @return list<array> */
    public function history(array $actor, int $appointmentId, int $limit = 10, ?int $beforeId = null): array
    {
        $this->requireRight($actor);

        return $this->connection->transaction(function () use ($actor, $appointmentId, $limit, $beforeId): array {
            $this->appointment($actor, $appointmentId, allowArchivedProject: true);

            return $this->dispatchRecords($actor, $appointmentId, null, max(1, min(50, $limit)), $beforeId);
        });
    }

    private function requireRight(array $actor): int
    {
        $id = (int) ($actor['id'] ?? 0);
        $permissions = $actor['permissions'] ?? [];
        if ($id < 1 || (int) ($actor['is_deleted'] ?? 0) !== 0 || ($actor['employment_status'] ?? 'active') !== 'active'
            || !is_array($permissions) || (!in_array('*', $permissions, true)
                && (!in_array('appointments.view', $permissions, true) || !in_array('appointments.dispatch', $permissions, true)))) {
            throw new AppointmentAccessDeniedException('Für den Terminversand fehlt die Berechtigung.');
        }

        return $id;
    }

    private function appointment(array $actor, int $id, string $lock = '', bool $allowArchivedProject = false): array
    {
        $row = $this->connection->fetchOne(
            'SELECT id, title, starts_at_utc, project_id, location, status, archived_at, version
             FROM appointments WHERE id = :id' . $lock,
            ['id' => $id]
        );
        if ($row === null || !$this->canAccessProject($actor, $row, $allowArchivedProject)) {
            throw $this->notFound();
        }

        return $row;
    }

    private function canAccessProject(array $actor, array $appointment, bool $allowArchivedProject = false): bool
    {
        return $appointment['project_id'] === null || $this->projectAccess->canAccess($actor, (int) $appointment['project_id'], $allowArchivedProject);
    }

    private function canReadSnapshot(array $actor, array $snapshot): bool
    {
        return $this->projectAccess->hasGlobalAccess($actor)
            || ((bool) $snapshot['project_scope_known'] && $this->canAccessProject($actor, $snapshot));
    }

    private function assertSendable(array $appointment, int $version): void
    {
        if ($appointment['archived_at'] !== null) {
            throw new AppointmentConflictException('Archivierte Termine können nicht versendet werden.');
        }
        if ((int) $appointment['version'] !== $version) {
            throw new AppointmentConflictException('Der Termin wurde zwischenzeitlich geändert. Bitte öffnen Sie die Empfängervorschau erneut.');
        }
    }

    private function pushIssue(): ?string
    {
        if (!$this->pushSettings->current()['enabled']) {
            return 'push_disabled';
        }

        return $this->sender->isConfigured() ? null : 'push_unconfigured';
    }

    private function recipients(int $appointmentId, ?string $pushIssue): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT u.id AS user_id, CONCAT_WS(' ', u.first_name, u.last_name) AS display_name, "
                . self::ACTIVE_RECIPIENT . ' AS is_active, ' . self::PUSH_PERMISSION . ' AS has_push_permission,
                (SELECT COUNT(*) FROM push_subscriptions ps
                 WHERE ps.user_id = u.id AND ps.is_enabled = 1 AND ps.permission_status = "granted") AS active_subscription_count
             FROM appointment_participants ap INNER JOIN users u ON u.id = ap.user_id
             WHERE ap.appointment_id = :appointment ORDER BY u.last_name, u.first_name, u.id',
            ['appointment' => $appointmentId]
        );

        return array_map(static function (array $row) use ($pushIssue): array {
            $deviceCount = (int) $row['active_subscription_count'];
            $hasPushPermission = (bool) $row['has_push_permission'];
            $status = match (true) {
                !(bool) $row['is_active'] => 'inactive',
                !$hasPushPermission => 'no_permission',
                $deviceCount === 0 => 'no_device',
                default => $pushIssue ?? 'ready',
            };

            return [
                'user_id' => (int) $row['user_id'],
                'display_name' => $row['display_name'],
                'has_push_permission' => $hasPushPermission,
                'active_subscription_count' => $deviceCount,
                'status' => $status,
                'error_message' => self::MESSAGES[$status] ?? null,
            ];
        }, $rows);
    }

    private function message(array $appointment): array
    {
        $starts = (new DateTimeImmutable($appointment['starts_at_utc'], new DateTimeZone('UTC')))->setTimezone($this->timezone);
        $day = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'][(int) $starts->format('N') - 1];
        $body = [$day . ', ' . $starts->format('d.m.Y, H:i') . ' Uhr'];
        if ($appointment['project_id'] !== null) {
            $number = $this->connection->fetchColumn('SELECT project_number FROM projects WHERE id = :id', ['id' => $appointment['project_id']]);
            if (is_string($number) && $number !== '') {
                $body[] = 'Projekt ' . $this->shortText($number, 60);
            }
        }
        if ($appointment['location'] !== null) {
            $body[] = 'Treffpunkt: ' . $this->shortText($appointment['location'], 160);
        }
        $prefix = $appointment['status'] === 'cancelled' ? 'Termin abgesagt – ' : 'Termin – ';

        return [
            'title' => $this->shortText($prefix . $appointment['title'], 180),
            'body' => implode("\n", $body),
            'url' => '/app/termine?id=' . (int) $appointment['id'],
        ];
    }

    private function shortText(string $text, int $limit): string
    {
        return mb_substr(trim((string) preg_replace('/[\p{C}\p{Z}\s]+/u', ' ', $text)), 0, $limit);
    }

    private function insertDispatch(array $appointment, int $actorId, string $requestKey, array $message): int
    {
        $this->write(
            'INSERT INTO appointment_dispatches
                (appointment_id, appointment_version, project_id, project_scope_known, sent_by_user_id, request_key,
                 channel, title, body, target_url, created_at_utc)
             VALUES (:appointment, :version, :project, 1, :actor, :request_key, "push", :title, :body, :url, UTC_TIMESTAMP())',
            ['appointment' => $appointment['id'], 'version' => $appointment['version'], 'project' => $appointment['project_id'],
                'actor' => $actorId, 'request_key' => $requestKey, ...$message]
        );

        return $this->connection->lastInsertId();
    }

    private function insertRecipient(int $dispatchId, array $recipient): void
    {
        $this->write(
            'INSERT INTO appointment_dispatch_recipients
                (dispatch_id, user_id, recipient_name, has_push_permission, active_subscription_count, status, error_message)
             VALUES (:dispatch, :user, :name, :permission, :devices, :status, :error)',
            ['dispatch' => $dispatchId, 'user' => $recipient['user_id'], 'name' => $recipient['display_name'],
                'permission' => (int) $recipient['has_push_permission'], 'devices' => $recipient['active_subscription_count'],
                'status' => $recipient['status'], 'error' => self::MESSAGES[$recipient['status']] ?? null]
        );
    }

    private function deliver(array $actor, int $appointmentId, int $version, int $dispatchId, int $userId, array $message, array $devices): void
    {
        $counts = self::DEVICE_RESULTS;
        $stop = null;
        $payload = ['type' => 'appointment_dispatch', ...$message, 'tag' => 'appointment-dispatch-' . $dispatchId, 'redact_provider_error' => true];
        foreach ($devices as $device) {
            $stop = $this->deliveryIssue($actor, $appointmentId, $version, $userId);
            if ($stop !== null) {
                break;
            }
            // Registration can transfer an endpoint to a different account. Use current credentials for the original owner only.
            $current = $this->subscriptions->activeSubscriptionForUserEndpoint($userId, $device['endpoint']);
            if ($current === null || (int) $current['id'] !== $device['id'] || $current['permission_status'] !== 'granted') {
                ++$counts['failed_subscription_count'];
                continue;
            }
            ++$counts['uncertain_subscription_count'];
            $this->storeResult($dispatchId, $userId, 'sending', $counts);
            try {
                $result = $this->sender->send($current, $payload, 'appointment-dispatch:' . $dispatchId . ':subscription:' . $current['id']);
            } catch (Throwable) {
                $result = [];
            }
            if (($result['ok'] ?? false) === true) {
                --$counts['uncertain_subscription_count'];
                ++$counts['accepted_subscription_count'];
            } elseif (($result['definitive_not_accepted'] ?? false) === true) {
                --$counts['uncertain_subscription_count'];
                ++$counts['failed_subscription_count'];
            }
            $this->storeResult($dispatchId, $userId, 'sending', $counts);
        }

        $accepted = $counts['accepted_subscription_count'];
        $status = match (true) {
            $accepted === count($devices) => 'accepted',
            $accepted > 0 => 'partially_accepted',
            $counts['uncertain_subscription_count'] > 0 => 'uncertain',
            default => $stop ?? 'failed',
        };
        $this->storeResult($dispatchId, $userId, $status, $counts, $stop);
    }

    private function deliveryIssue(array $actor, int $appointmentId, int $version, int $userId): ?string
    {
        $pushIssue = $this->pushIssue();
        $row = $this->connection->fetchOne(
            'SELECT a.project_id, ' . self::ACTIVE_RECIPIENT . ' AS is_active, ' . self::PUSH_PERMISSION . ' AS has_push_permission
             FROM appointments a INNER JOIN appointment_participants ap ON ap.appointment_id = a.id
             INNER JOIN users u ON u.id = ap.user_id
             WHERE a.id = :appointment AND a.version = :version AND a.archived_at IS NULL AND ap.user_id = :user',
            ['appointment' => $appointmentId, 'version' => $version, 'user' => $userId]
        );

        return match (true) {
            $row === null || !$this->canAccessProject($actor, $row) => 'appointment_changed',
            !(bool) $row['is_active'] => 'inactive',
            !(bool) $row['has_push_permission'] => 'no_permission',
            default => $pushIssue,
        };
    }

    private function storeResult(int $dispatchId, int $userId, string $status, array $counts, ?string $stop = null): void
    {
        $this->write(
            'UPDATE appointment_dispatch_recipients
             SET status = :status, accepted_subscription_count = :accepted_subscription_count,
                 failed_subscription_count = :failed_subscription_count, uncertain_subscription_count = :uncertain_subscription_count,
                 error_message = :error
             WHERE dispatch_id = :dispatch AND user_id = :user',
            ['dispatch' => $dispatchId, 'user' => $userId, 'status' => $status, 'error' => self::MESSAGES[$stop ?? $status] ?? null, ...$counts]
        );
    }

    private function dispatchRecords(array $actor, int $appointmentId, ?int $dispatchId, int $limit, ?int $beforeId = null): array
    {
        $params = ['appointment' => $appointmentId];
        $filter = '';
        if ($dispatchId !== null) {
            $filter = ' AND d.id = :dispatch';
            $params['dispatch'] = $dispatchId;
        }
        if ($beforeId !== null) {
            $filter .= ' AND d.id < :before';
            $params['before'] = $beforeId;
        }
        if (!$this->projectAccess->hasGlobalAccess($actor)) {
            $activeProjectIds = $this->projectAccess->activeProjectIdsForUser($actor);
            $scope = 'd.project_id IS NULL';
            if ($activeProjectIds !== []) {
                $scope .= ' OR d.project_id IN (' . implode(', ', $activeProjectIds) . ')';
            }
            $filter .= ' AND d.project_scope_known = 1 AND (' . $scope . ')';
        }
        $rows = $this->connection->fetchAll(
            "SELECT d.id, d.appointment_id, d.appointment_version, d.sent_by_user_id, d.request_key, d.channel,
                    d.title, d.body, d.target_url, d.created_at_utc, d.completed_at_utc,
                    CONCAT_WS(' ', u.first_name, u.last_name) AS sender_name
             FROM appointment_dispatches d INNER JOIN users u ON u.id = d.sent_by_user_id
             WHERE d.appointment_id = :appointment" . $filter . ' ORDER BY d.id DESC LIMIT ' . $limit,
            $params
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $recipients = [];
        foreach ($this->connection->fetchAll(
            'SELECT dispatch_id, user_id, recipient_name AS display_name, has_push_permission, active_subscription_count,
                    accepted_subscription_count, failed_subscription_count, uncertain_subscription_count, status, error_message
             FROM appointment_dispatch_recipients WHERE dispatch_id IN (' . implode(', ', $ids) . ') ORDER BY id'
        ) as $recipient) {
            $id = (int) $recipient['dispatch_id'];
            unset($recipient['dispatch_id']);
            foreach (['user_id', 'active_subscription_count', ...array_keys(self::DEVICE_RESULTS)] as $key) {
                $recipient[$key] = (int) $recipient[$key];
            }
            $recipient['has_push_permission'] = (bool) $recipient['has_push_permission'];
            $recipients[$id][] = $recipient;
        }

        return array_map(static function (array $row) use ($recipients): array {
            foreach (['id', 'appointment_id', 'appointment_version', 'sent_by_user_id'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['recipients'] = $recipients[$row['id']] ?? [];
            $row['recipient_count'] = count($row['recipients']);
            $row += ['accepted_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'uncertain_count' => 0, ...self::DEVICE_RESULTS];
            foreach ($row['recipients'] as $recipient) {
                $bucket = match ($recipient['status']) {
                    'accepted', 'partially_accepted' => 'accepted_count',
                    'pending', 'sending', 'uncertain' => 'uncertain_count',
                    'failed' => 'failed_count',
                    default => 'skipped_count',
                };
                ++$row[$bucket];
                foreach (array_keys(self::DEVICE_RESULTS) as $key) {
                    $row[$key] += $recipient[$key];
                }
            }

            return $row;
        }, $rows);
    }

    private function write(string $sql, array $params): void
    {
        if (!$this->connection->execute($sql, $params)) {
            throw new RuntimeException('Der Terminversand konnte nicht protokolliert werden.');
        }
    }

    private function notFound(): AppointmentNotFoundException
    {
        return new AppointmentNotFoundException('Der angeforderte Termin ist nicht verfügbar.');
    }
}
