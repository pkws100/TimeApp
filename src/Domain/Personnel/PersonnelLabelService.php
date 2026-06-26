<?php

declare(strict_types=1);

namespace App\Domain\Personnel;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class PersonnelLabelService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function list(string $scope = 'active'): array
    {
        if (!$this->connection->tableExists('personnel_labels')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT
                personnel_labels.*,
                COUNT(DISTINCT user_labels.user_id) AS user_count
             FROM personnel_labels
             LEFT JOIN user_labels ON user_labels.label_id = personnel_labels.id
             WHERE ' . $this->scopeWhereClause('personnel_labels', $scope) . '
             GROUP BY personnel_labels.id
             ORDER BY personnel_labels.is_deleted ASC, personnel_labels.name ASC'
        );
    }

    public function find(int $id): ?array
    {
        if (!$this->connection->tableExists('personnel_labels') || $id <= 0) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM personnel_labels WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public function create(array $payload): array
    {
        $record = $this->normalize($payload);

        if (!$this->connection->tableExists('personnel_labels')) {
            return $record + ['id' => random_int(1000, 9999), 'is_deleted' => 0, 'user_count' => 0];
        }

        $this->connection->execute(
            'INSERT INTO personnel_labels (
                slug, name, color, icon, description, is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
            ) VALUES (
                :slug, :name, :color, :icon, :description, 0, NULL, NULL, NOW(), NOW()
            )',
            $record
        );

        return $this->find($this->connection->lastInsertId()) ?? [];
    }

    public function update(int $id, array $payload): ?array
    {
        $record = $this->normalize($payload);

        if (!$this->connection->tableExists('personnel_labels') || $id <= 0) {
            return null;
        }

        $this->connection->execute(
            'UPDATE personnel_labels
             SET slug = :slug, name = :name, color = :color, icon = :icon, description = :description, updated_at = NOW()
             WHERE id = :id',
            $record + ['id' => $id]
        );

        return $this->find($id);
    }

    public function archive(int $id, ?int $deletedByUserId = null): bool
    {
        if (!$this->connection->tableExists('personnel_labels') || $id <= 0) {
            return false;
        }

        return $this->connection->execute(
            'UPDATE personnel_labels
             SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW()
             WHERE id = :id',
            ['id' => $id, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    public function restore(int $id): bool
    {
        if (!$this->connection->tableExists('personnel_labels') || $id <= 0) {
            return false;
        }

        return $this->connection->execute(
            'UPDATE personnel_labels SET is_deleted = 0, deleted_at = NULL, deleted_by_user_id = NULL, updated_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    public function labelsForUser(int $userId): array
    {
        if (!$this->connection->tableExists('user_labels') || !$this->connection->tableExists('personnel_labels') || $userId <= 0) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT personnel_labels.*
             FROM user_labels
             INNER JOIN personnel_labels ON personnel_labels.id = user_labels.label_id
             WHERE user_labels.user_id = :user_id
               AND COALESCE(personnel_labels.is_deleted, 0) = 0
             ORDER BY personnel_labels.name ASC',
            ['user_id' => $userId]
        );
    }

    public function labelsForUsersGrouped(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map(static fn (mixed $value): int => (int) $value, $userIds))));

        if ($userIds === [] || !$this->connection->tableExists('user_labels') || !$this->connection->tableExists('personnel_labels')) {
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
            'SELECT user_labels.user_id, personnel_labels.*
             FROM user_labels
             INNER JOIN personnel_labels ON personnel_labels.id = user_labels.label_id
             WHERE user_labels.user_id IN (' . implode(', ', $placeholders) . ')
               AND COALESCE(personnel_labels.is_deleted, 0) = 0
             ORDER BY personnel_labels.name ASC',
            $bindings
        );
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['user_id']][] = $row;
        }

        return $grouped;
    }

    public function syncUserLabels(int $userId, mixed $labelIds, ?int $assignedByUserId = null): void
    {
        if (!$this->connection->tableExists('user_labels') || $userId <= 0) {
            return;
        }

        $labelIds = $this->activeLabelIds($this->normalizeIds($labelIds));

        $this->connection->transaction(function () use ($userId, $labelIds, $assignedByUserId): void {
            $this->connection->execute('DELETE FROM user_labels WHERE user_id = :user_id', ['user_id' => $userId]);

            foreach ($labelIds as $labelId) {
                $this->connection->execute(
                    'INSERT INTO user_labels (user_id, label_id, assigned_by_user_id, created_at)
                     VALUES (:user_id, :label_id, :assigned_by_user_id, NOW())',
                    [
                        'user_id' => $userId,
                        'label_id' => $labelId,
                        'assigned_by_user_id' => $assignedByUserId,
                    ]
                );
            }
        });
    }

    public function statistics(): array
    {
        $labels = $this->list('active');

        return [
            'labels' => array_map(static fn (array $label): string => (string) ($label['name'] ?? ''), $labels),
            'datasets' => [
                [
                    'label' => 'Mitarbeiter je Label',
                    'data' => array_map(static fn (array $label): int => (int) ($label['user_count'] ?? 0), $labels),
                    'backgroundColor' => array_map(static fn (array $label): string => (string) ($label['color'] ?? '#2563eb'), $labels),
                ],
            ],
        ];
    }

    private function normalize(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Bitte einen Label-Namen angeben.');
        }

        $slug = trim((string) ($payload['slug'] ?? ''));
        $color = trim((string) ($payload['color'] ?? '#2563eb'));

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#2563eb';
        }

        return [
            'slug' => $slug !== '' ? $this->slugify($slug) : $this->slugify($name),
            'name' => mb_substr($name, 0, 120),
            'color' => $color,
            'icon' => mb_substr($this->slugify((string) ($payload['icon'] ?? 'award')), 0, 60) ?: 'award',
            'description' => $this->nullableString($payload['description'] ?? null),
        ];
    }

    private function normalizeIds(mixed $ids): array
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $normalized = array_values(array_unique(array_filter(array_map(static fn (mixed $value): int => (int) $value, $ids))));
        sort($normalized);

        return $normalized;
    }

    private function activeLabelIds(array $labelIds): array
    {
        if ($labelIds === [] || !$this->connection->tableExists('personnel_labels')) {
            return [];
        }

        $bindings = [];
        $placeholders = [];

        foreach ($labelIds as $index => $labelId) {
            $key = 'label_id_' . $index;
            $bindings[$key] = $labelId;
            $placeholders[] = ':' . $key;
        }

        $rows = $this->connection->fetchAll(
            'SELECT id
             FROM personnel_labels
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND COALESCE(is_deleted, 0) = 0
             ORDER BY id ASC',
            $bindings
        );

        return array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-') ?: 'label';
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
