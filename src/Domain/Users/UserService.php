<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class UserService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function list(string $scope = 'active'): array
    {
        if ($this->connection->tableExists('users')) {
            return $this->connection->fetchAll(
                'SELECT
                    users.id,
                    users.employee_number,
                    users.first_name,
                    users.last_name,
                    users.email,
                    users.phone,
                    users.employment_status,
                    users.emergency_contact_name,
                    users.emergency_contact_phone,
                    users.target_hours_month,
                    users.is_deleted,
                    GROUP_CONCAT(DISTINCT roles.name ORDER BY roles.name SEPARATOR ", ") AS role_names
                 FROM users
                 LEFT JOIN user_roles ON user_roles.user_id = users.id
                 LEFT JOIN roles ON roles.id = user_roles.role_id
                 WHERE ' . $this->scopeWhereClause('users', $scope) . '
                 GROUP BY users.id
                 ORDER BY users.is_deleted ASC, users.last_name, users.first_name'
            );
        }

        return [
            [
                'id' => 1,
                'first_name' => 'Jana',
                'last_name' => 'Kluge',
                'email' => 'jana.kluge@example.invalid',
                'phone' => '+49 151 10000001',
                'employment_status' => 'active',
                'emergency_contact_name' => 'Mario Kluge',
                'emergency_contact_phone' => '+49 151 20000001',
                'role_names' => 'Bauleiter',
                'is_deleted' => 0,
            ],
        ];
    }

    public function find(int $id): ?array
    {
        if (!$this->connection->tableExists('users')) {
            foreach ($this->list('all') as $user) {
                if ((int) $user['id'] === $id) {
                    $user['role_ids'] = [];

                    return $user;
                }
            }

            return null;
        }

        $user = $this->connection->fetchOne(
            'SELECT id, employee_number, first_name, last_name, email, phone, employment_status, emergency_contact_name, emergency_contact_phone, target_hours_month, is_deleted, deleted_at
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $id]
        );

        if ($user === null) {
            return null;
        }

        $roleIds = $this->connection->fetchAll(
            'SELECT role_id FROM user_roles WHERE user_id = :user_id ORDER BY role_id',
            ['user_id' => $id]
        );
        $user['role_ids'] = array_map(static fn (array $row): int => (int) $row['role_id'], $roleIds);

        return $user;
    }

    public function create(array $payload): array
    {
        $record = $this->normalize($payload);

        if ($record['password'] === null) {
            throw new InvalidArgumentException('Beim Anlegen eines Users ist ein Passwort erforderlich.');
        }

        if (!$this->connection->tableExists('users')) {
            $record['id'] = random_int(1000, 9999);
            $record['role_ids'] = $this->normalizeRoleIds($payload['role_ids'] ?? []);

            return $record;
        }

        return $this->connection->transaction(function () use ($record, $payload) {
            $this->connection->execute(
                'INSERT INTO users (
                    employee_number, first_name, last_name, email, phone, password_hash, employment_status, emergency_contact_name, emergency_contact_phone, target_hours_month, is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
                ) VALUES (
                    :employee_number, :first_name, :last_name, :email, :phone, :password_hash, :employment_status, :emergency_contact_name, :emergency_contact_phone, :target_hours_month, 0, NULL, NULL, NOW(), NOW()
                )',
                [
                    'employee_number' => $record['employee_number'],
                    'first_name' => $record['first_name'],
                    'last_name' => $record['last_name'],
                    'email' => $record['email'],
                    'phone' => $record['phone'],
                    'password_hash' => password_hash($record['password'], PASSWORD_DEFAULT),
                    'employment_status' => $record['employment_status'],
                    'emergency_contact_name' => $record['emergency_contact_name'],
                    'emergency_contact_phone' => $record['emergency_contact_phone'],
                    'target_hours_month' => $record['target_hours_month'],
                ]
            );

            $userId = $this->connection->lastInsertId();
            $this->syncRoles($userId, $this->normalizeRoleIds($payload['role_ids'] ?? []));

            return $this->find($userId) ?? [];
        });
    }

    public function update(int $id, array $payload): ?array
    {
        $record = $this->normalize($payload);

        if (!$this->connection->tableExists('users')) {
            $record['id'] = $id;
            $record['role_ids'] = $this->normalizeRoleIds($payload['role_ids'] ?? []);

            return $record;
        }

        return $this->connection->transaction(function () use ($id, $record, $payload) {
            $bindings = [
                'id' => $id,
                'employee_number' => $record['employee_number'],
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'email' => $record['email'],
                'phone' => $record['phone'],
                'employment_status' => $record['employment_status'],
                'emergency_contact_name' => $record['emergency_contact_name'],
                'emergency_contact_phone' => $record['emergency_contact_phone'],
                'target_hours_month' => $record['target_hours_month'],
            ];

            $passwordSql = '';

            if ($record['password'] !== null) {
                $passwordSql = ', password_hash = :password_hash';
                $bindings['password_hash'] = password_hash($record['password'], PASSWORD_DEFAULT);
            }

            $this->connection->execute(
                'UPDATE users SET
                    employee_number = :employee_number,
                    first_name = :first_name,
                    last_name = :last_name,
                    email = :email,
                    phone = :phone,
                    employment_status = :employment_status,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_phone = :emergency_contact_phone,
                    target_hours_month = :target_hours_month' . $passwordSql . ',
                    updated_at = NOW()
                 WHERE id = :id',
                $bindings
            );

            $this->syncRoles($id, $this->normalizeRoleIds($payload['role_ids'] ?? []));

            return $this->find($id);
        });
    }

    public function archive(int $id, ?int $deletedByUserId = null): bool
    {
        if (!$this->connection->tableExists('users')) {
            return true;
        }

        return $this->connection->execute(
            'UPDATE users SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'deleted_by_user_id' => $deletedByUserId]
        );
    }

    private function syncRoles(int $userId, array $roleIds): void
    {
        if (!$this->connection->tableExists('user_roles')) {
            return;
        }

        $this->connection->execute('DELETE FROM user_roles WHERE user_id = :user_id', ['user_id' => $userId]);

        foreach ($roleIds as $roleId) {
            $this->connection->execute(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
                ['user_id' => $userId, 'role_id' => $roleId]
            );
        }
    }

    private function normalize(array $payload): array
    {
        $password = trim((string) ($payload['password'] ?? ''));

        return [
            'employee_number' => $this->nullableString($payload['employee_number'] ?? null),
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
            'email' => trim((string) ($payload['email'] ?? '')),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'password' => $password === '' ? null : $password,
            'employment_status' => trim((string) ($payload['employment_status'] ?? 'active')),
            'emergency_contact_name' => $this->nullableString($payload['emergency_contact_name'] ?? null),
            'emergency_contact_phone' => $this->nullableString($payload['emergency_contact_phone'] ?? null),
            'target_hours_month' => (float) ($payload['target_hours_month'] ?? 0),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function normalizeRoleIds(mixed $roleIds): array
    {
        $roleIds = is_array($roleIds) ? $roleIds : [$roleIds];
        $normalized = array_values(array_unique(array_filter(array_map(static fn (mixed $value): int => (int) $value, $roleIds))));
        sort($normalized);

        return $normalized;
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
