<?php

declare(strict_types=1);

namespace App\Domain\Personnel;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

final class PersonnelEventService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function eventTypes(string $scope = 'active'): array
    {
        if (!$this->connection->tableExists('personnel_event_types')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT
                personnel_event_types.*,
                COUNT(DISTINCT user_events.id) AS event_count
             FROM personnel_event_types
             LEFT JOIN user_events ON user_events.event_type_id = personnel_event_types.id
             WHERE ' . $this->scopeWhereClause('personnel_event_types', $scope) . '
             GROUP BY personnel_event_types.id
             ORDER BY personnel_event_types.is_deleted ASC, personnel_event_types.name ASC'
        );
    }

    public function findEventType(int $id): ?array
    {
        if (!$this->connection->tableExists('personnel_event_types') || $id <= 0) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM personnel_event_types WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function createEventType(array $payload): array
    {
        $record = $this->normalizeEventType($payload);

        if (!$this->connection->tableExists('personnel_event_types')) {
            return $record + ['id' => random_int(1000, 9999), 'is_deleted' => 0, 'event_count' => 0];
        }

        $this->connection->execute(
            'INSERT INTO personnel_event_types (
                slug, name, color, icon, default_reminder_days, description, is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
            ) VALUES (
                :slug, :name, :color, :icon, :default_reminder_days, :description, 0, NULL, NULL, NOW(), NOW()
            )',
            $record
        );

        return $this->findEventType($this->connection->lastInsertId()) ?? [];
    }

    public function updateEventType(int $id, array $payload): ?array
    {
        $record = $this->normalizeEventType($payload);

        if (!$this->connection->tableExists('personnel_event_types') || $id <= 0) {
            return null;
        }

        $this->connection->execute(
            'UPDATE personnel_event_types
             SET slug = :slug,
                 name = :name,
                 color = :color,
                 icon = :icon,
                 default_reminder_days = :default_reminder_days,
                 description = :description,
                 updated_at = NOW()
             WHERE id = :id',
            $record + ['id' => $id]
        );

        return $this->findEventType($id);
    }

    public function archiveEventType(int $id, ?int $deletedByUserId = null): bool
    {
        if (!$this->connection->tableExists('personnel_event_types') || $id <= 0) {
            return false;
        }

        return $this->connection->execute(
            'UPDATE personnel_event_types
             SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW()
             WHERE id = :id',
            ['id' => $id, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    public function events(array $filters = []): array
    {
        if (!$this->hasEventTables()) {
            return [];
        }

        $scope = (string) ($filters['scope'] ?? 'active');
        $clauses = [$this->scopeWhereClause('user_events', $scope)];
        $bindings = [];

        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $clauses[] = 'user_events.user_id = :user_id';
            $bindings['user_id'] = $userId;
        }

        $typeId = (int) ($filters['event_type_id'] ?? 0);
        if ($typeId > 0) {
            $clauses[] = 'user_events.event_type_id = :event_type_id';
            $bindings['event_type_id'] = $typeId;
        }

        $dateFrom = $this->dateOrNull($filters['date_from'] ?? null);
        $dateTo = $this->dateOrNull($filters['date_to'] ?? null);

        if ($dateFrom !== null) {
            $clauses[] = 'user_events.due_on >= :date_from';
            $bindings['date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $clauses[] = 'user_events.due_on <= :date_to';
            $bindings['date_to'] = $dateTo;
        }

        $rows = $this->connection->fetchAll(
            'SELECT
                user_events.*,
                users.first_name,
                users.last_name,
                users.email,
                users.employee_number,
                personnel_event_types.name AS event_type_name,
                personnel_event_types.color AS event_type_color,
                personnel_event_types.icon AS event_type_icon,
                personnel_event_types.default_reminder_days,
                GROUP_CONCAT(DISTINCT user_event_reminders.channel ORDER BY user_event_reminders.channel SEPARATOR ",") AS reminder_channels
             FROM user_events
             INNER JOIN users ON users.id = user_events.user_id
             INNER JOIN personnel_event_types ON personnel_event_types.id = user_events.event_type_id
             LEFT JOIN user_event_reminders ON user_event_reminders.user_event_id = user_events.id AND user_event_reminders.is_enabled = 1
             WHERE ' . implode(' AND ', $clauses) . '
             GROUP BY user_events.id
             ORDER BY user_events.due_on ASC, users.last_name ASC, users.first_name ASC, user_events.id ASC',
            $bindings
        );
        $status = trim((string) ($filters['status'] ?? ''));
        $rows = array_map(fn (array $row): array => $this->normalizeEventRow($row), $rows);

        if ($status !== '' && $status !== 'all') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['status'] ?? '') === $status));
        }

        return $rows;
    }

    public function findEvent(int $id): ?array
    {
        if (!$this->hasEventTables() || $id <= 0) {
            return null;
        }

        $rows = $this->events(['scope' => 'all']);

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $row['reminders'] = $this->remindersForEvent($id);

                return $row;
            }
        }

        return null;
    }

    public function createEvent(array $payload): array
    {
        $record = $this->normalizeEvent($payload);

        if (!$this->hasEventTables()) {
            return $record + ['id' => random_int(1000, 9999), 'is_deleted' => 0];
        }

        return $this->connection->transaction(function () use ($record, $payload): array {
            $this->connection->execute(
                'INSERT INTO user_events (
                    user_id, event_type_id, title, due_on, valid_from, valid_until, note,
                    completed_at, completed_by_user_id, is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
                ) VALUES (
                    :user_id, :event_type_id, :title, :due_on, :valid_from, :valid_until, :note,
                    NULL, NULL, 0, NULL, NULL, NOW(), NOW()
                )',
                $record
            );

            $eventId = $this->connection->lastInsertId();
            $this->syncReminders($eventId, $payload);

            return $this->findEvent($eventId) ?? [];
        });
    }

    public function updateEvent(int $id, array $payload): ?array
    {
        $record = $this->normalizeEvent($payload);

        if (!$this->hasEventTables() || $id <= 0) {
            return null;
        }

        return $this->connection->transaction(function () use ($id, $record, $payload): ?array {
            $completed = $this->normalizeBoolean($payload['completed'] ?? false);
            $completedBy = isset($payload['completed_by_user_id']) ? (int) $payload['completed_by_user_id'] : null;
            $bindings = $record + [
                'id' => $id,
                'completed_by_user_id' => $completedBy,
                'completed_for_at' => $completed ? 1 : 0,
                'completed_for_user' => $completed ? 1 : 0,
            ];

            $this->connection->execute(
                'UPDATE user_events
                 SET user_id = :user_id,
                     event_type_id = :event_type_id,
                     title = :title,
                     due_on = :due_on,
                     valid_from = :valid_from,
                     valid_until = :valid_until,
                     note = :note,
                     completed_at = CASE WHEN :completed_for_at = 1 THEN COALESCE(completed_at, NOW()) ELSE NULL END,
                     completed_by_user_id = CASE WHEN :completed_for_user = 1 THEN :completed_by_user_id ELSE NULL END,
                     updated_at = NOW()
                 WHERE id = :id',
                $bindings
            );
            $this->syncReminders($id, $payload);

            return $this->findEvent($id);
        });
    }

    public function archiveEvent(int $id, ?int $deletedByUserId = null): bool
    {
        if (!$this->connection->tableExists('user_events') || $id <= 0) {
            return false;
        }

        return $this->connection->execute(
            'UPDATE user_events
             SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW()
             WHERE id = :id',
            ['id' => $id, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    public function upcomingForUser(int $userId, int $limit = 6): array
    {
        if ($userId <= 0) {
            return [];
        }

        return array_slice($this->openEventsForUsers([$userId])[$userId] ?? [], 0, $limit);
    }

    public function upcomingForUsersGrouped(array $userIds): array
    {
        return array_map(static fn (array $events): array => array_slice($events, 0, 3), $this->openEventsForUsers($userIds));
    }

    public function calendarEvents(string $dateFrom, string $dateTo): array
    {
        if (!$this->hasEventTables()) {
            return [];
        }

        $dueRows = $this->connection->fetchAll(
            $this->baseEventSelect() . '
             WHERE COALESCE(user_events.is_deleted, 0) = 0
               AND user_events.due_on BETWEEN :due_from AND :due_to
               AND COALESCE(users.is_deleted, 0) = 0
               AND users.employment_status = "active"
             GROUP BY user_events.id
             ORDER BY user_events.due_on ASC, user_events.id ASC',
            [
                'due_from' => $dateFrom,
                'due_to' => $dateTo,
            ]
        );
        $markers = [];

        foreach ($dueRows as $row) {
            $event = $this->normalizeEventRow($row);
            $event['marker_date'] = (string) ($event['due_on'] ?? '');
            $event['marker_type'] = 'due';
            $event['marker_label'] = 'Stichtag';
            $markers[] = $event;
        }

        if ($this->connection->tableExists('user_event_reminders')) {
            $reminderRows = $this->connection->fetchAll(
                'SELECT
                    user_event_reminders.id AS reminder_id,
                    user_event_reminders.channel,
                    user_event_reminders.recipient_type,
                    user_event_reminders.role_slug,
                    user_event_reminders.days_before,
                    DATE_SUB(user_events.due_on, INTERVAL user_event_reminders.days_before DAY) AS marker_date,
                    user_events.*,
                    users.first_name,
                    users.last_name,
                    users.email,
                    users.employee_number,
                    personnel_event_types.name AS event_type_name,
                    personnel_event_types.color AS event_type_color,
                    personnel_event_types.icon AS event_type_icon,
                    personnel_event_types.default_reminder_days,
                    GROUP_CONCAT(DISTINCT user_event_reminders.channel ORDER BY user_event_reminders.channel SEPARATOR ",") AS reminder_channels
                 FROM user_event_reminders
                 INNER JOIN user_events ON user_events.id = user_event_reminders.user_event_id
                 INNER JOIN users ON users.id = user_events.user_id
                 INNER JOIN personnel_event_types ON personnel_event_types.id = user_events.event_type_id
                 WHERE user_event_reminders.is_enabled = 1
                   AND COALESCE(user_events.is_deleted, 0) = 0
                   AND user_events.completed_at IS NULL
                   AND COALESCE(users.is_deleted, 0) = 0
                   AND users.employment_status = "active"
                   AND DATE_SUB(user_events.due_on, INTERVAL user_event_reminders.days_before DAY) BETWEEN :reminder_from AND :reminder_to
                 GROUP BY user_event_reminders.id
                 ORDER BY marker_date ASC, user_events.due_on ASC',
                [
                    'reminder_from' => $dateFrom,
                    'reminder_to' => $dateTo,
                ]
            );

            foreach ($reminderRows as $row) {
                $event = $this->normalizeEventRow($row);
                $event['marker_date'] = (string) ($row['marker_date'] ?? $event['due_on'] ?? '');
                $event['marker_type'] = 'reminder';
                $event['marker_label'] = 'Reminder';
                $markers[] = $event;
            }
        }

        usort($markers, static function (array $left, array $right): int {
            return strcmp((string) ($left['marker_date'] ?? ''), (string) ($right['marker_date'] ?? ''))
                ?: strcmp((string) ($left['due_on'] ?? ''), (string) ($right['due_on'] ?? ''));
        });

        return $markers;
    }

    public function overview(): array
    {
        $events = $this->events(['scope' => 'active']);
        $statusCounts = ['ok' => 0, 'due_soon' => 0, 'overdue' => 0, 'completed' => 0];

        foreach ($events as $event) {
            $status = (string) ($event['status'] ?? 'ok');
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        return [
            'total_events' => count($events),
            'overdue_events' => $statusCounts['overdue'],
            'due_soon_events' => $statusCounts['due_soon'],
            'completed_events' => $statusCounts['completed'],
            'upcoming' => array_slice(array_values(array_filter(
                $events,
                static fn (array $event): bool => in_array((string) ($event['status'] ?? ''), ['ok', 'due_soon'], true)
            )), 0, 8),
            'overdue' => array_slice(array_values(array_filter(
                $events,
                static fn (array $event): bool => (string) ($event['status'] ?? '') === 'overdue'
            )), 0, 8),
            'status_chart' => [
                'labels' => ['OK', 'Bald faellig', 'Ueberfaellig', 'Erledigt'],
                'datasets' => [
                    [
                        'label' => 'Events',
                        'data' => array_values($statusCounts),
                        'backgroundColor' => ['#16a34a', '#d97706', '#dc2626', '#64748b'],
                    ],
                ],
            ],
        ];
    }

    public function dueReminderCandidates(?DateTimeImmutable $now = null): array
    {
        if (!$this->hasEventTables() || !$this->connection->tableExists('user_event_reminders')) {
            return [];
        }

        $today = ($now ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $rows = $this->connection->fetchAll(
            'SELECT
                user_event_reminders.id AS reminder_id,
                user_event_reminders.channel,
                user_event_reminders.recipient_type,
                user_event_reminders.role_slug,
                user_event_reminders.days_before,
                user_events.id AS user_event_id,
                user_events.user_id,
                user_events.title,
                user_events.due_on,
                user_events.valid_from,
                user_events.valid_until,
                user_events.note,
                users.first_name,
                users.last_name,
                users.email,
                personnel_event_types.name AS event_type_name,
                personnel_event_types.color AS event_type_color,
                personnel_event_types.icon AS event_type_icon,
                DATE_SUB(user_events.due_on, INTERVAL user_event_reminders.days_before DAY) AS reminder_date
             FROM user_event_reminders
             INNER JOIN user_events ON user_events.id = user_event_reminders.user_event_id
             INNER JOIN users ON users.id = user_events.user_id
             INNER JOIN personnel_event_types ON personnel_event_types.id = user_events.event_type_id
             WHERE user_event_reminders.is_enabled = 1
               AND COALESCE(user_events.is_deleted, 0) = 0
               AND user_events.completed_at IS NULL
               AND COALESCE(users.is_deleted, 0) = 0
               AND users.employment_status = "active"
               AND DATE_SUB(user_events.due_on, INTERVAL user_event_reminders.days_before DAY) <= :today
             ORDER BY user_events.due_on ASC, user_event_reminders.channel ASC',
            ['today' => $today]
        );

        return array_map(fn (array $row): array => $this->normalizeEventRow($row), $rows);
    }

    public function adminRoleUsers(string $roleSlug): array
    {
        if (!$this->connection->tableExists('users')
            || !$this->connection->tableExists('roles')
            || !$this->connection->tableExists('user_roles')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT DISTINCT users.id, users.first_name, users.last_name, users.email
             FROM users
             INNER JOIN user_roles ON user_roles.user_id = users.id
             INNER JOIN roles ON roles.id = user_roles.role_id
             WHERE roles.slug = :role_slug
               AND COALESCE(users.is_deleted, 0) = 0
               AND users.employment_status = "active"
             ORDER BY users.last_name ASC, users.first_name ASC',
            ['role_slug' => $roleSlug]
        );
    }

    public function notificationLogExists(string $dedupeKey): bool
    {
        if (!$this->connection->tableExists('personnel_event_notification_log')) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM personnel_event_notification_log WHERE dedupe_key = :dedupe_key AND status IN ("sent", "shown")',
            ['dedupe_key' => $dedupeKey]
        ) ?? 0) > 0;
    }

    public function logNotification(array $candidate, ?int $recipientUserId, ?string $recipientRoleSlug, string $dedupeKey, string $title, string $body, string $status, ?string $errorMessage = null): void
    {
        if (!$this->connection->tableExists('personnel_event_notification_log')) {
            return;
        }

        $this->connection->execute(
            'INSERT INTO personnel_event_notification_log (
                user_event_id, reminder_id, recipient_user_id, recipient_role_slug, channel, reminder_date,
                dedupe_key, title, body, status, error_message, sent_at, created_at
            ) VALUES (
                :user_event_id, :reminder_id, :recipient_user_id, :recipient_role_slug, :channel, :reminder_date,
                :dedupe_key, :title, :body, :status, :error_message, CASE WHEN :sent = 1 THEN NOW() ELSE NULL END, NOW()
            )
            ON DUPLICATE KEY UPDATE
                status = CASE WHEN personnel_event_notification_log.status IN ("sent", "shown") THEN personnel_event_notification_log.status ELSE VALUES(status) END,
                error_message = CASE WHEN personnel_event_notification_log.status IN ("sent", "shown") THEN personnel_event_notification_log.error_message ELSE VALUES(error_message) END,
                sent_at = CASE WHEN sent_at IS NOT NULL THEN sent_at ELSE VALUES(sent_at) END',
            [
                'user_event_id' => (int) ($candidate['user_event_id'] ?? $candidate['id'] ?? 0),
                'reminder_id' => (int) ($candidate['reminder_id'] ?? 0) ?: null,
                'recipient_user_id' => $recipientUserId,
                'recipient_role_slug' => $recipientRoleSlug,
                'channel' => (string) ($candidate['channel'] ?? 'admin'),
                'reminder_date' => (string) ($candidate['reminder_date'] ?? date('Y-m-d')),
                'dedupe_key' => $dedupeKey,
                'title' => mb_substr($title, 0, 180),
                'body' => $body,
                'status' => $status,
                'error_message' => $errorMessage,
                'sent' => in_array($status, ['sent', 'shown'], true) ? 1 : 0,
            ]
        );
    }

    public function remindersForEvent(int $eventId): array
    {
        if (!$this->connection->tableExists('user_event_reminders') || $eventId <= 0) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT * FROM user_event_reminders WHERE user_event_id = :user_event_id ORDER BY channel ASC, recipient_type ASC, role_slug ASC',
            ['user_event_id' => $eventId]
        );
    }

    public function normalizeEventRow(array $row): array
    {
        $dueOn = (string) ($row['due_on'] ?? '');
        $defaultReminderDays = isset($row['default_reminder_days']) ? (int) $row['default_reminder_days'] : null;
        $daysBefore = isset($row['days_before']) ? (int) $row['days_before'] : $defaultReminderDays;
        $status = $this->statusFor($dueOn, $row['completed_at'] ?? null, $daysBefore);
        $displayTitle = trim((string) ($row['title'] ?? ''));

        if ($displayTitle === '') {
            $displayTitle = (string) ($row['event_type_name'] ?? 'Termin');
        }

        $row['display_title'] = $displayTitle;
        $row['user_name'] = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        $row['status'] = $status['status'];
        $row['status_label'] = $status['label'];
        $row['days_until_due'] = $status['days_until_due'];
        $row['is_overdue'] = $status['status'] === 'overdue';
        $row['is_due_soon'] = $status['status'] === 'due_soon';
        $row['reminder_channels_list'] = array_values(array_filter(array_map('trim', explode(',', (string) ($row['reminder_channels'] ?? '')))));

        return $row;
    }

    private function syncReminders(int $eventId, array $payload): void
    {
        if (!$this->connection->tableExists('user_event_reminders')) {
            return;
        }

        $daysBefore = $this->reminderDaysFromPayload($payload);
        $channels = is_array($payload['reminder_channels'] ?? null) ? $payload['reminder_channels'] : [];
        $channels = array_values(array_unique(array_intersect(array_map('strval', $channels), ['admin', 'push', 'email'])));

        $this->connection->execute('DELETE FROM user_event_reminders WHERE user_event_id = :user_event_id', ['user_event_id' => $eventId]);

        if ($channels === []) {
            return;
        }

        foreach ($channels as $channel) {
            if ($channel === 'admin') {
                foreach (['administrator', 'geschaeftsfuehrung'] as $roleSlug) {
                    $this->insertReminder($eventId, 'admin', 'admin_role', $roleSlug, $daysBefore);
                }
                continue;
            }

            $this->insertReminder($eventId, $channel, 'user', null, $daysBefore);
        }
    }

    private function insertReminder(int $eventId, string $channel, string $recipientType, ?string $roleSlug, int $daysBefore): void
    {
        $this->connection->execute(
            'INSERT INTO user_event_reminders (
                user_event_id, channel, recipient_type, role_slug, days_before, is_enabled, created_at, updated_at
            ) VALUES (
                :user_event_id, :channel, :recipient_type, :role_slug, :days_before, 1, NOW(), NOW()
            )',
            [
                'user_event_id' => $eventId,
                'channel' => $channel,
                'recipient_type' => $recipientType,
                'role_slug' => $roleSlug,
                'days_before' => $daysBefore,
            ]
        );
    }

    private function reminderDaysFromPayload(array $payload): int
    {
        $submitted = trim((string) ($payload['reminder_days'] ?? ''));

        if ($submitted !== '') {
            return max(0, (int) $submitted);
        }

        $eventType = $this->findEventType((int) ($payload['event_type_id'] ?? 0));

        if ($eventType !== null && $eventType['default_reminder_days'] !== null && trim((string) $eventType['default_reminder_days']) !== '') {
            return max(0, (int) $eventType['default_reminder_days']);
        }

        return 14;
    }

    private function normalizeEventType(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Bitte einen Event-Typ-Namen angeben.');
        }

        $slug = trim((string) ($payload['slug'] ?? ''));
        $color = trim((string) ($payload['color'] ?? '#7c3aed'));

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#7c3aed';
        }

        $defaultReminderDays = trim((string) ($payload['default_reminder_days'] ?? ''));

        return [
            'slug' => $slug !== '' ? $this->slugify($slug) : $this->slugify($name),
            'name' => mb_substr($name, 0, 150),
            'color' => $color,
            'icon' => mb_substr($this->slugify((string) ($payload['icon'] ?? 'calendar-check')), 0, 60) ?: 'calendar-check',
            'default_reminder_days' => $defaultReminderDays === '' ? null : max(0, (int) $defaultReminderDays),
            'description' => $this->nullableString($payload['description'] ?? null),
        ];
    }

    private function normalizeEvent(array $payload): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $eventTypeId = (int) ($payload['event_type_id'] ?? 0);
        $dueOn = $this->dateOrNull($payload['due_on'] ?? null);

        if ($userId <= 0 || $eventTypeId <= 0 || $dueOn === null) {
            throw new InvalidArgumentException('Bitte Mitarbeiter, Event-Typ und Faelligkeit auswaehlen.');
        }

        return [
            'user_id' => $userId,
            'event_type_id' => $eventTypeId,
            'title' => $this->nullableString($payload['title'] ?? null),
            'due_on' => $dueOn,
            'valid_from' => $this->dateOrNull($payload['valid_from'] ?? null),
            'valid_until' => $this->dateOrNull($payload['valid_until'] ?? null),
            'note' => $this->nullableString($payload['note'] ?? null),
        ];
    }

    private function statusFor(string $dueOn, mixed $completedAt, ?int $reminderDays): array
    {
        if (trim((string) $completedAt) !== '') {
            return ['status' => 'completed', 'label' => 'Erledigt', 'days_until_due' => null];
        }

        try {
            $today = new DateTimeImmutable('today');
            $dueDate = new DateTimeImmutable($dueOn);
        } catch (Exception) {
            return ['status' => 'ok', 'label' => 'OK', 'days_until_due' => null];
        }

        $daysUntil = (int) $today->diff($dueDate)->format('%r%a');

        if ($daysUntil < 0) {
            return ['status' => 'overdue', 'label' => 'Ueberfaellig', 'days_until_due' => $daysUntil];
        }

        if ($daysUntil <= max(0, $reminderDays ?? 14)) {
            return ['status' => 'due_soon', 'label' => 'Bald faellig', 'days_until_due' => $daysUntil];
        }

        return ['status' => 'ok', 'label' => 'OK', 'days_until_due' => $daysUntil];
    }

    private function hasEventTables(): bool
    {
        return $this->connection->tableExists('user_events')
            && $this->connection->tableExists('personnel_event_types')
            && $this->connection->tableExists('users');
    }

    private function openEventsForUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map(static fn (mixed $value): int => (int) $value, $userIds))));

        if ($userIds === [] || !$this->hasEventTables()) {
            return [];
        }

        $bindings = [];
        $placeholders = [];

        foreach ($userIds as $index => $userId) {
            $key = 'user_id_' . $index;
            $bindings[$key] = $userId;
            $placeholders[] = ':' . $key;
        }

        $rows = $this->connection->fetchAll(
            $this->baseEventSelect() . '
             WHERE user_events.user_id IN (' . implode(', ', $placeholders) . ')
               AND COALESCE(user_events.is_deleted, 0) = 0
               AND user_events.completed_at IS NULL
               AND COALESCE(users.is_deleted, 0) = 0
               AND users.employment_status = "active"
             GROUP BY user_events.id
             ORDER BY user_events.due_on ASC, user_events.id ASC',
            $bindings
        );
        $grouped = [];

        foreach (array_map(fn (array $row): array => $this->normalizeEventRow($row), $rows) as $event) {
            $grouped[(int) ($event['user_id'] ?? 0)][] = $event;
        }

        return $grouped;
    }

    private function baseEventSelect(): string
    {
        return 'SELECT
                user_events.*,
                users.first_name,
                users.last_name,
                users.email,
                users.employee_number,
                personnel_event_types.name AS event_type_name,
                personnel_event_types.color AS event_type_color,
                personnel_event_types.icon AS event_type_icon,
                personnel_event_types.default_reminder_days,
                GROUP_CONCAT(DISTINCT user_event_reminders.channel ORDER BY user_event_reminders.channel SEPARATOR ",") AS reminder_channels
             FROM user_events
             INNER JOIN users ON users.id = user_events.user_id
             INNER JOIN personnel_event_types ON personnel_event_types.id = user_events.event_type_id
             LEFT JOIN user_event_reminders ON user_event_reminders.user_event_id = user_events.id AND user_event_reminders.is_enabled = 1';
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-') ?: 'event';
    }

    private function scopeWhereClause(string $table, string $scope): string
    {
        return match ($scope) {
            'archived' => $table . '.is_deleted = 1',
            'all' => '1 = 1',
            default => $table . '.is_deleted = 0',
        };
    }
}
