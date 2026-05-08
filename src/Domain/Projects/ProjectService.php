<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Infrastructure\Database\DatabaseConnection;

final class ProjectService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function list(string $scope = 'active'): array
    {
        if ($this->connection->tableExists('projects')) {
            return $this->connection->fetchAll(
                'SELECT id, project_number, name, customer_name, status, address_line_1, postal_code, city, starts_on, ends_on, is_deleted, deleted_at
                 FROM projects
                 WHERE ' . $this->scopeWhereClause($scope) . '
                 ORDER BY is_deleted ASC, created_at DESC'
            );
        }

        return [
            [
                'id' => 1,
                'project_number' => '2026-001',
                'name' => 'Neubau Kita Nord',
                'customer_name' => 'Stadtwerke Nord',
                'status' => 'active',
                'city' => 'Hamburg',
                'is_deleted' => 0,
            ],
            [
                'id' => 2,
                'project_number' => '2026-002',
                'name' => 'Sanierung Rathaus',
                'customer_name' => 'Gemeinde Mitte',
                'status' => 'planning',
                'city' => 'Lueneburg',
                'is_deleted' => 0,
            ],
        ];
    }

    public function find(int $id): ?array
    {
        if (!$this->connection->tableExists('projects')) {
            foreach ($this->list('all') as $project) {
                if ((int) $project['id'] === $id) {
                    return $project;
                }
            }

            return null;
        }

        return $this->connection->fetchOne(
            'SELECT id, project_number, name, customer_name, status, address_line_1, postal_code, city, starts_on, ends_on, is_deleted, deleted_at
             FROM projects
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    public function create(array $payload): array
    {
        $record = $this->normalize($payload);

        if ($this->connection->tableExists('projects')) {
            $this->connection->execute(
                'INSERT INTO projects (
                    project_number, name, customer_name, status, address_line_1, postal_code, city, starts_on, ends_on, is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
                ) VALUES (
                    :project_number, :name, :customer_name, :status, :address_line_1, :postal_code, :city, :starts_on, :ends_on, 0, NULL, NULL, NOW(), NOW()
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

        if (!$this->connection->tableExists('projects')) {
            $record['id'] = $id;
            $record['is_deleted'] = 0;

            return $record;
        }

        $this->connection->execute(
            'UPDATE projects SET
                project_number = :project_number,
                name = :name,
                customer_name = :customer_name,
                status = :status,
                address_line_1 = :address_line_1,
                postal_code = :postal_code,
                city = :city,
                starts_on = :starts_on,
                ends_on = :ends_on,
                updated_at = NOW()
             WHERE id = :id',
            [...$record, 'id' => $id]
        );

        return $this->find($id);
    }

    public function archive(int $id, ?int $deletedByUserId = null): bool
    {
        if (!$this->connection->tableExists('projects')) {
            return true;
        }

        return $this->connection->execute(
            'UPDATE projects SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    private function normalize(array $payload): array
    {
        return [
            'project_number' => trim((string) ($payload['project_number'] ?? '')),
            'name' => trim((string) ($payload['name'] ?? '')),
            'customer_name' => $this->nullableString($payload['customer_name'] ?? null),
            'status' => trim((string) ($payload['status'] ?? 'planning')),
            'address_line_1' => $this->nullableString($payload['address_line_1'] ?? null),
            'postal_code' => $this->nullableString($payload['postal_code'] ?? null),
            'city' => $this->nullableString($payload['city'] ?? null),
            'starts_on' => $this->nullableString($payload['starts_on'] ?? null),
            'ends_on' => $this->nullableString($payload['ends_on'] ?? null),
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
