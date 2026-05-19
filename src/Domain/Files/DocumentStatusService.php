<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use RuntimeException;

final class DocumentStatusService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(string $scope = 'active'): array
    {
        if (!$this->connection->tableExists('document_status_profiles')) {
            return [];
        }

        $where = match ($scope) {
            'all' => '1 = 1',
            'archived' => 'is_deleted = 1',
            default => 'is_deleted = 0',
        };

        return array_map(
            fn (array $row): array => $this->normalizeRow($row),
            $this->connection->fetchAll(
                'SELECT id, label, slug, color, sort_order, is_default, is_deleted, deleted_at
                 FROM document_status_profiles
                 WHERE ' . $where . '
                 ORDER BY is_deleted ASC, sort_order ASC, label ASC'
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeList(): array
    {
        return $this->list('active');
    }

    public function defaultStatus(): ?array
    {
        if (!$this->connection->tableExists('document_status_profiles')) {
            return null;
        }

        $row = $this->connection->fetchOne(
            'SELECT id, label, slug, color, sort_order, is_default, is_deleted, deleted_at
             FROM document_status_profiles
             WHERE is_deleted = 0
             ORDER BY is_default DESC, sort_order ASC, id ASC
             LIMIT 1'
        );

        return $row === null ? null : $this->normalizeRow($row);
    }

    public function save(array $payload, ?int $id = null): array
    {
        if (!$this->connection->tableExists('document_status_profiles')) {
            throw new RuntimeException('Die Dokumentstatus-Tabelle ist noch nicht migriert.');
        }

        $label = trim((string) ($payload['label'] ?? ''));
        $color = trim((string) ($payload['color'] ?? '#64748b'));
        $sortOrder = (int) ($payload['sort_order'] ?? 100);
        $isDefault = (string) ($payload['is_default'] ?? '0') === '1';

        if ($label === '') {
            throw new InvalidArgumentException('Bitte einen Statusnamen angeben.');
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new InvalidArgumentException('Bitte eine gueltige Statusfarbe im Format #RRGGBB angeben.');
        }

        $slug = $this->uniqueSlug($this->slug($label), $id);

        if ($id !== null && $id > 0) {
            $existing = $this->find($id, 'all');

            if ($existing === null) {
                throw new InvalidArgumentException('Der Dokumentstatus wurde nicht gefunden.');
            }

            $this->connection->execute(
                'UPDATE document_status_profiles
                 SET label = :label, slug = :slug, color = :color, sort_order = :sort_order, updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => $id,
                    'label' => $label,
                    'slug' => $slug,
                    'color' => $color,
                    'sort_order' => $sortOrder,
                ]
            );
        } else {
            $this->connection->execute(
                'INSERT INTO document_status_profiles (label, slug, color, sort_order, is_default, created_at, updated_at, is_deleted, deleted_at, deleted_by_user_id)
                 VALUES (:label, :slug, :color, :sort_order, 0, NOW(), NOW(), 0, NULL, NULL)',
                [
                    'label' => $label,
                    'slug' => $slug,
                    'color' => $color,
                    'sort_order' => $sortOrder,
                ]
            );
            $id = $this->connection->lastInsertId();
        }

        if ($isDefault || $this->defaultStatus() === null) {
            $this->markDefault((int) $id);
        }

        $saved = $this->find((int) $id, 'all');

        if ($saved === null) {
            throw new RuntimeException('Der Dokumentstatus konnte nicht gespeichert werden.');
        }

        return $saved;
    }

    public function archive(int $id, ?int $userId = null): bool
    {
        $status = $this->find($id, 'all');

        if ($status === null) {
            return false;
        }

        if ((bool) ($status['is_default'] ?? false)) {
            $activeAlternatives = array_values(array_filter(
                $this->activeList(),
                static fn (array $row): bool => (int) ($row['id'] ?? 0) !== $id
            ));

            if ($activeAlternatives === []) {
                throw new InvalidArgumentException('Der letzte aktive Defaultstatus kann nicht archiviert werden.');
            }

            $this->markDefault((int) $activeAlternatives[0]['id']);
        }

        return $this->connection->execute(
            'UPDATE document_status_profiles
             SET is_deleted = 1, is_default = 0, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'deleted_by_user_id' => $userId,
            ]
        );
    }

    public function find(int $id, string $scope = 'active'): ?array
    {
        if ($id <= 0 || !$this->connection->tableExists('document_status_profiles')) {
            return null;
        }

        $where = match ($scope) {
            'all' => '1 = 1',
            'archived' => 'is_deleted = 1',
            default => 'is_deleted = 0',
        };

        $row = $this->connection->fetchOne(
            'SELECT id, label, slug, color, sort_order, is_default, is_deleted, deleted_at
             FROM document_status_profiles
             WHERE id = :id AND ' . $where . '
             LIMIT 1',
            ['id' => $id]
        );

        return $row === null ? null : $this->normalizeRow($row);
    }

    private function markDefault(int $id): void
    {
        if ($this->find($id, 'active') === null) {
            throw new InvalidArgumentException('Nur aktive Status koennen Default sein.');
        }

        $this->connection->execute('UPDATE document_status_profiles SET is_default = 0 WHERE is_default = 1');
        $this->connection->execute(
            'UPDATE document_status_profiles SET is_default = 1, updated_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    private function uniqueSlug(string $baseSlug, ?int $ignoreId = null): string
    {
        $slug = $baseSlug !== '' ? $baseSlug : 'status';
        $candidate = $slug;
        $counter = 2;

        while ($this->slugExists($candidate, $ignoreId)) {
            $candidate = $slug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $params = ['slug' => $slug];
        $sql = 'SELECT COUNT(*) FROM document_status_profiles WHERE slug = :slug';

        if ($ignoreId !== null && $ignoreId > 0) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        return (int) ($this->connection->fetchColumn($sql, $params) ?? 0) > 0;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = strtr($value, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'color' => (string) ($row['color'] ?? '#64748b'),
            'sort_order' => (int) ($row['sort_order'] ?? 100),
            'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            'is_deleted' => (int) ($row['is_deleted'] ?? 0),
            'deleted_at' => $row['deleted_at'] ?? null,
        ];
    }
}
