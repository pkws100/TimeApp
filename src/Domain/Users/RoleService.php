<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class RoleService
{
    public function __construct(
        private DatabaseConnection $connection,
        private PermissionMatrix $permissionMatrix
    ) {
    }

    public function list(string $scope = 'active'): array
    {
        if ($this->connection->tableExists('roles') && $this->connection->tableExists('permissions')) {
            return $this->connection->fetchAll(
                'SELECT
                    roles.id,
                    roles.slug,
                    roles.name,
                    roles.description,
                    roles.is_system_role,
                    roles.is_deleted,
                    GROUP_CONCAT(DISTINCT permissions.code ORDER BY permissions.code SEPARATOR ", ") AS permission_codes
                 FROM roles
                 LEFT JOIN role_permissions ON role_permissions.role_id = roles.id
                 LEFT JOIN permissions ON permissions.id = role_permissions.permission_id
                 WHERE ' . $this->scopeWhereClause($scope) . '
                 GROUP BY roles.id
                 ORDER BY roles.is_deleted ASC, roles.name'
            );
        }

        return array_map(
            static function (array $role, int $index): array {
                return [
                    'id' => $index + 1,
                    'slug' => $role['slug'] ?? '',
                    'name' => $role['label'] ?? ($role['slug'] ?? ''),
                    'description' => '',
                    'is_system_role' => 1,
                    'is_deleted' => 0,
                    'permission_codes' => implode(', ', $role['permissions'] ?? []),
                ];
            },
            $this->permissionMatrix->roles(),
            array_keys($this->permissionMatrix->roles())
        );
    }

    public function find(int $id): ?array
    {
        if (!$this->connection->tableExists('roles')) {
            return null;
        }

        $role = $this->connection->fetchOne(
            'SELECT id, slug, name, description, is_system_role, is_deleted, deleted_at FROM roles WHERE id = :id LIMIT 1',
            ['id' => $id]
        );

        if ($role === null) {
            return null;
        }

        $permissionIds = $this->connection->fetchAll(
            'SELECT permission_id FROM role_permissions WHERE role_id = :role_id ORDER BY permission_id',
            ['role_id' => $id]
        );
        $role['permission_ids'] = array_map(static fn (array $row): int => (int) $row['permission_id'], $permissionIds);

        return $role;
    }

    public function availablePermissions(): array
    {
        if ($this->connection->tableExists('permissions')) {
            return $this->connection->fetchAll(
                'SELECT id, code, label, scope FROM permissions ORDER BY scope, code'
            );
        }

        return array_map(
            static fn (string $code): array => ['id' => 0, 'code' => $code, 'label' => $code, 'scope' => 'fallback'],
            $this->permissionMatrix->allPermissions()
        );
    }

    public function create(array $payload): array
    {
        $record = $this->normalize($payload);

        if (!$this->connection->tableExists('roles')) {
            $record['id'] = random_int(1000, 9999);
            $record['permission_ids'] = $this->normalizePermissionIds($payload['permission_ids'] ?? []);
            $record['is_system_role'] = 0;

            return $record;
        }

        return $this->connection->transaction(function () use ($record, $payload) {
            $this->connection->execute(
                'INSERT INTO roles (
                    slug, name, description, is_system_role, is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
                ) VALUES (
                    :slug, :name, :description, 0, 0, NULL, NULL, NOW(), NOW()
                )',
                $record
            );

            $roleId = $this->connection->lastInsertId();
            $this->syncPermissions($roleId, $this->normalizePermissionIds($payload['permission_ids'] ?? []));

            return $this->find($roleId) ?? [];
        });
    }

    public function update(int $id, array $payload): ?array
    {
        $record = $this->normalize($payload);

        if (!$this->connection->tableExists('roles')) {
            $record['id'] = $id;
            $record['permission_ids'] = $this->normalizePermissionIds($payload['permission_ids'] ?? []);

            return $record;
        }

        return $this->connection->transaction(function () use ($id, $record, $payload) {
            $this->connection->execute(
                'UPDATE roles SET slug = :slug, name = :name, description = :description, updated_at = NOW() WHERE id = :id',
                [...$record, 'id' => $id]
            );

            $this->syncPermissions($id, $this->normalizePermissionIds($payload['permission_ids'] ?? []));

            return $this->find($id);
        });
    }

    public function archive(int $id, ?int $deletedByUserId = null): bool
    {
        $role = $this->find($id);

        if ($role === null) {
            return false;
        }

        if ((int) ($role['is_system_role'] ?? 0) === 1) {
            throw new RuntimeException('Systemrollen koennen nicht archiviert werden.');
        }

        return $this->connection->execute(
            'UPDATE roles SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    private function syncPermissions(int $roleId, array $permissionIds): void
    {
        if (!$this->connection->tableExists('role_permissions')) {
            return;
        }

        $this->connection->execute('DELETE FROM role_permissions WHERE role_id = :role_id', ['role_id' => $roleId]);

        foreach ($permissionIds as $permissionId) {
            $this->connection->execute(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
                ['role_id' => $roleId, 'permission_id' => $permissionId]
            );
        }
    }

    private function normalize(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $slug = trim((string) ($payload['slug'] ?? ''));

        return [
            'slug' => $slug !== '' ? $slug : $this->slugify($name),
            'name' => $name,
            'description' => $this->nullableString($payload['description'] ?? null),
        ];
    }

    private function normalizePermissionIds(mixed $permissionIds): array
    {
        $permissionIds = is_array($permissionIds) ? $permissionIds : [$permissionIds];
        $normalized = array_values(array_unique(array_filter(array_map(static fn (mixed $value): int => (int) $value, $permissionIds))));
        sort($normalized);

        return $normalized;
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

        return trim($value, '-') ?: 'rolle';
    }

    private function scopeWhereClause(string $scope): string
    {
        return match ($scope) {
            'archived' => 'roles.is_deleted = 1',
            'all' => '1 = 1',
            default => 'roles.is_deleted = 0',
        };
    }
}
