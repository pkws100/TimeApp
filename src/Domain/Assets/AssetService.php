<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Infrastructure\Database\DatabaseConnection;

final class AssetService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function list(string $scope = 'active'): array
    {
        if ($this->connection->tableExists('assets')) {
            return $this->connection->fetchAll(
                'SELECT id, asset_type, name, identifier, status, notes, is_deleted, deleted_at
                 FROM assets
                 WHERE ' . $this->scopeWhereClause($scope) . '
                 ORDER BY is_deleted ASC, asset_type, name'
            );
        }

        return [
            ['id' => 1, 'asset_type' => 'vehicle', 'name' => 'Crafter 3.5t', 'identifier' => 'HH-ZE-501', 'status' => 'available', 'is_deleted' => 0],
            ['id' => 2, 'asset_type' => 'equipment', 'name' => 'Minibagger 1.8t', 'identifier' => 'EQ-018', 'status' => 'assigned', 'is_deleted' => 0],
        ];
    }

    public function find(int $id): ?array
    {
        if (!$this->connection->tableExists('assets')) {
            foreach ($this->list('all') as $asset) {
                if ((int) $asset['id'] === $id) {
                    return $asset;
                }
            }

            return null;
        }

        return $this->connection->fetchOne(
            'SELECT id, asset_type, name, identifier, status, notes, is_deleted, deleted_at
             FROM assets
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    public function create(array $payload): array
    {
        $record = $this->normalize($payload);

        if ($this->connection->tableExists('assets')) {
            $this->connection->execute(
                'INSERT INTO assets (
                    asset_type, name, identifier, status, notes, is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
                ) VALUES (
                    :asset_type, :name, :identifier, :status, :notes, 0, NULL, NULL, NOW(), NOW()
                )',
                $record
            );

            return $this->find($this->connection->lastInsertId()) ?? $record;
        }

        $record['id'] = random_int(1000, 9999);
        $record['is_deleted'] = 0;

        return $record;
    }

    public function update(int $id, array $payload): ?array
    {
        $record = $this->normalize($payload);

        if (!$this->connection->tableExists('assets')) {
            $record['id'] = $id;
            $record['is_deleted'] = 0;

            return $record;
        }

        $this->connection->execute(
            'UPDATE assets SET
                asset_type = :asset_type,
                name = :name,
                identifier = :identifier,
                status = :status,
                notes = :notes,
                updated_at = NOW()
             WHERE id = :id',
            [...$record, 'id' => $id]
        );

        return $this->find($id);
    }

    public function archive(int $id, ?int $deletedByUserId = null): bool
    {
        if (!$this->connection->tableExists('assets')) {
            return true;
        }

        return $this->connection->execute(
            'UPDATE assets SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    private function normalize(array $payload): array
    {
        return [
            'asset_type' => trim((string) ($payload['asset_type'] ?? 'equipment')),
            'name' => trim((string) ($payload['name'] ?? '')),
            'identifier' => trim((string) ($payload['identifier'] ?? '')),
            'status' => trim((string) ($payload['status'] ?? 'available')),
            'notes' => $this->nullableString($payload['notes'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function scopeWhereClause(string $scope): string
    {
        return match ($scope) {
            'archived' => 'is_deleted = 1',
            'all' => '1 = 1',
            default => 'is_deleted = 0',
        };
    }
}
