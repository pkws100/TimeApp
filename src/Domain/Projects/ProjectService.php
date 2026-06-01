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
            $trackedSelect = '0 AS tracked_net_minutes';
            $trackedJoin = '';
            $signatureColumns = $this->signatureColumnsSelect();

            if ($this->connection->tableExists('timesheets')) {
                $trackedSelect = 'COALESCE(tracked.tracked_net_minutes, 0) AS tracked_net_minutes';
                $trackedJoin = 'LEFT JOIN (
                    SELECT project_id, COALESCE(SUM(net_minutes), 0) AS tracked_net_minutes
                    FROM timesheets
                    WHERE project_id IS NOT NULL
                      AND entry_type = "work"
                      AND COALESCE(is_deleted, 0) = 0
                    GROUP BY project_id
                ) AS tracked ON tracked.project_id = projects.id';
            }

            return $this->connection->fetchAll(
                'SELECT projects.id, projects.project_number, projects.name, projects.customer_name, ' . $signatureColumns . ', projects.status, projects.address_line_1, projects.postal_code, projects.city, projects.starts_on, projects.ends_on, projects.is_deleted, projects.deleted_at, ' . $trackedSelect . '
                 FROM projects
                 ' . $trackedJoin . '
                 WHERE ' . $this->scopeWhereClause($scope) . '
                 ORDER BY projects.is_deleted ASC, projects.created_at DESC'
            );
        }

        return [
            [
                'id' => 1,
                'project_number' => '2026-001',
                'name' => 'Neubau Kita Nord',
                'customer_name' => 'Stadtwerke Nord',
                'customer_signature_required' => 0,
                'customer_signature_name' => null,
                'status' => 'active',
                'city' => 'Hamburg',
                'is_deleted' => 0,
                'tracked_net_minutes' => 0,
            ],
            [
                'id' => 2,
                'project_number' => '2026-002',
                'name' => 'Sanierung Rathaus',
                'customer_name' => 'Gemeinde Mitte',
                'customer_signature_required' => 0,
                'customer_signature_name' => null,
                'status' => 'planning',
                'city' => 'Lueneburg',
                'is_deleted' => 0,
                'tracked_net_minutes' => 0,
            ],
        ];
    }

    public function summarizeTrackedNetMinutes(array $timesheets): array
    {
        $minutesByProject = [];

        foreach ($timesheets as $timesheet) {
            $projectId = (int) ($timesheet['project_id'] ?? 0);

            if ($projectId <= 0) {
                continue;
            }

            if ((string) ($timesheet['entry_type'] ?? '') !== 'work') {
                continue;
            }

            if ((int) ($timesheet['is_deleted'] ?? 0) === 1) {
                continue;
            }

            $minutesByProject[$projectId] = ($minutesByProject[$projectId] ?? 0) + max(0, (int) ($timesheet['net_minutes'] ?? 0));
        }

        return $minutesByProject;
    }

    public function membershipUserIds(int $projectId): array
    {
        if ($projectId <= 0 || !$this->connection->tableExists('project_memberships')) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT user_id
             FROM project_memberships
             WHERE project_id = :project_id
               AND (assigned_from IS NULL OR assigned_from <= CURDATE())
               AND (assigned_until IS NULL OR assigned_until >= CURDATE())
             ORDER BY user_id',
            ['project_id' => $projectId]
        );

        return array_map(static fn (array $row): int => (int) ($row['user_id'] ?? 0), $rows);
    }

    public function syncMemberships(int $projectId, mixed $userIds): void
    {
        if ($projectId <= 0 || !$this->connection->tableExists('project_memberships')) {
            return;
        }

        $userIds = $this->validActiveUserIds($this->normalizeUserIds($userIds));
        $existingUserIds = $this->allMembershipUserIds($projectId);

        $this->connection->transaction(function () use ($projectId, $userIds, $existingUserIds): void {
            foreach ($existingUserIds as $existingUserId) {
                if (in_array($existingUserId, $userIds, true)) {
                    continue;
                }

                $this->connection->execute(
                    'DELETE FROM project_memberships WHERE project_id = :project_id AND user_id = :user_id',
                    [
                        'project_id' => $projectId,
                        'user_id' => $existingUserId,
                    ]
                );
            }

            foreach ($userIds as $userId) {
                if (in_array($userId, $existingUserIds, true)) {
                    $this->connection->execute(
                        'UPDATE project_memberships
                         SET assigned_from = NULL, assigned_until = NULL
                         WHERE project_id = :project_id AND user_id = :user_id',
                        [
                            'project_id' => $projectId,
                            'user_id' => $userId,
                        ]
                    );
                    continue;
                }

                $this->connection->execute(
                    'INSERT INTO project_memberships (project_id, user_id, assignment_role, assigned_from, assigned_until)
                     VALUES (:project_id, :user_id, NULL, NULL, NULL)',
                    [
                        'project_id' => $projectId,
                        'user_id' => $userId,
                    ]
                );
            }
        });
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
            'SELECT id, project_number, name, customer_name, ' . $this->signatureColumnsSelect() . ', status, address_line_1, postal_code, city, starts_on, ends_on, is_deleted, deleted_at
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
            $hasSignatureRequired = $this->connection->columnExists('projects', 'customer_signature_required');
            $hasSignatureName = $this->connection->columnExists('projects', 'customer_signature_name');
            $columns = [
                'project_number',
                'name',
                'customer_name',
                'status',
                'address_line_1',
                'postal_code',
                'city',
                'starts_on',
                'ends_on',
                'is_deleted',
                'deleted_at',
                'deleted_by_user_id',
                'created_at',
                'updated_at',
            ];
            $values = [
                ':project_number',
                ':name',
                ':customer_name',
                ':status',
                ':address_line_1',
                ':postal_code',
                ':city',
                ':starts_on',
                ':ends_on',
                '0',
                'NULL',
                'NULL',
                'NOW()',
                'NOW()',
            ];
            $bindings = $record;

            if ($hasSignatureRequired) {
                array_splice($columns, 3, 0, 'customer_signature_required');
                array_splice($values, 3, 0, ':customer_signature_required');
            } else {
                unset($bindings['customer_signature_required']);
            }

            if ($hasSignatureName) {
                $offset = $hasSignatureRequired ? 4 : 3;
                array_splice($columns, $offset, 0, 'customer_signature_name');
                array_splice($values, $offset, 0, ':customer_signature_name');
            } else {
                unset($bindings['customer_signature_name']);
            }

            $this->connection->execute(
                'INSERT INTO projects (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')',
                $bindings
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

        $set = [
            'project_number = :project_number',
            'name = :name',
            'customer_name = :customer_name',
            'status = :status',
            'address_line_1 = :address_line_1',
            'postal_code = :postal_code',
            'city = :city',
            'starts_on = :starts_on',
            'ends_on = :ends_on',
            'updated_at = NOW()',
        ];
        $bindings = $record;

        if ($this->connection->columnExists('projects', 'customer_signature_required')) {
            array_splice($set, 3, 0, 'customer_signature_required = :customer_signature_required');
        } else {
            unset($bindings['customer_signature_required']);
        }

        if ($this->connection->columnExists('projects', 'customer_signature_name')) {
            $offset = $this->connection->columnExists('projects', 'customer_signature_required') ? 4 : 3;
            array_splice($set, $offset, 0, 'customer_signature_name = :customer_signature_name');
        } else {
            unset($bindings['customer_signature_name']);
        }

        $this->connection->execute(
            'UPDATE projects SET
                ' . implode(",\n                ", $set) . '
             WHERE id = :id',
            [...$bindings, 'id' => $id]
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

    public function restore(int $id, ?int $restoredByUserId = null): bool
    {
        unset($restoredByUserId);

        if (!$this->connection->tableExists('projects')) {
            return true;
        }

        return $this->connection->execute(
            'UPDATE projects SET is_deleted = 0, deleted_at = NULL, deleted_by_user_id = NULL, updated_at = NOW() WHERE id = :id',
            ['id' => $id]
        );
    }

    private function normalize(array $payload): array
    {
        return [
            'project_number' => trim((string) ($payload['project_number'] ?? '')),
            'name' => trim((string) ($payload['name'] ?? '')),
            'customer_name' => $this->nullableString($payload['customer_name'] ?? null),
            'customer_signature_required' => $this->truthy($payload['customer_signature_required'] ?? false) ? 1 : 0,
            'customer_signature_name' => $this->nullableString($payload['customer_signature_name'] ?? null),
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

    private function normalizeUserIds(mixed $userIds): array
    {
        $userIds = is_array($userIds) ? $userIds : [$userIds];
        $normalized = array_values(array_unique(array_filter(array_map(static fn (mixed $value): int => (int) $value, $userIds))));
        sort($normalized);

        return $normalized;
    }

    private function allMembershipUserIds(int $projectId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT user_id FROM project_memberships WHERE project_id = :project_id ORDER BY user_id',
            ['project_id' => $projectId]
        );

        return array_map(static fn (array $row): int => (int) ($row['user_id'] ?? 0), $rows);
    }

    private function validActiveUserIds(array $userIds): array
    {
        if ($userIds === [] || !$this->connection->tableExists('users')) {
            return [];
        }

        $bindings = [];
        $placeholders = [];

        foreach ($userIds as $index => $userId) {
            $key = 'user_id_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $userId;
        }

        $rows = $this->connection->fetchAll(
            'SELECT id
             FROM users
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND employment_status = :employment_status
               AND COALESCE(is_deleted, 0) = 0
             ORDER BY id',
            [...$bindings, 'employment_status' => 'active']
        );

        return array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes'], true);
    }

    private function signatureColumnsSelect(): string
    {
        $required = $this->connection->columnExists('projects', 'customer_signature_required')
            ? 'COALESCE(projects.customer_signature_required, 0) AS customer_signature_required'
            : '0 AS customer_signature_required';
        $name = $this->connection->columnExists('projects', 'customer_signature_name')
            ? 'projects.customer_signature_name'
            : 'NULL';

        return $required . ', ' . $name . ' AS customer_signature_name';
    }

    private function scopeWhereClause(string $scope): string
    {
        return match ($scope) {
            'archived' => 'projects.is_deleted = 1',
            'all' => '1 = 1',
            default => 'projects.is_deleted = 0',
        };
    }
}
