<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Infrastructure\Database\DatabaseConnection;

final class ProjectAccessService
{
    private const GLOBAL_PERMISSIONS = [
        '*',
        'projects.manage',
        'files.manage',
        'timesheets.manage',
    ];

    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function hasGlobalAccess(array $user): bool
    {
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];

        foreach (self::GLOBAL_PERMISSIONS as $permission) {
            if (in_array($permission, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    public function canManage(array $user): bool
    {
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];

        return in_array('*', $permissions, true) || in_array('projects.manage', $permissions, true);
    }

    public function canAccess(array $user, int $projectId): bool
    {
        if (!$this->isActiveUser($user) || $projectId <= 0 || !$this->projectExists($projectId)) {
            return false;
        }

        if ($this->hasGlobalAccess($user)) {
            return true;
        }

        if (!$this->activeProjectExists($projectId)) {
            return false;
        }

        return in_array($projectId, $this->activeProjectIdsForUser($user), true);
    }

    /** @return list<int> */
    public function activeProjectIdsForUser(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);

        if (
            !$this->isActiveUser($user)
            || $userId <= 0
            || !$this->connection->tableExists('projects')
            || !$this->connection->tableExists('project_memberships')
        ) {
            return [];
        }

        if ($this->hasGlobalAccess($user)) {
            $rows = $this->connection->fetchAll(
                'SELECT id
                 FROM projects
                 WHERE COALESCE(is_deleted, 0) = 0
                   AND status <> "archived"
                 ORDER BY id'
            );
        } else {
            $rows = $this->connection->fetchAll(
                'SELECT DISTINCT projects.id
                 FROM projects
                 INNER JOIN project_memberships ON project_memberships.project_id = projects.id
                 WHERE project_memberships.user_id = :user_id
                   AND COALESCE(projects.is_deleted, 0) = 0
                   AND projects.status <> "archived"
                   AND (project_memberships.assigned_from IS NULL OR project_memberships.assigned_from <= CURDATE())
                   AND (project_memberships.assigned_until IS NULL OR project_memberships.assigned_until >= CURDATE())
                 ORDER BY projects.id',
                ['user_id' => $userId]
            );
        }

        return array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows
        )));
    }

    private function activeProjectExists(int $projectId): bool
    {
        if (!$this->connection->tableExists('projects')) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM projects
             WHERE id = :project_id
               AND COALESCE(is_deleted, 0) = 0
               AND status <> "archived"',
            ['project_id' => $projectId]
        ) ?? 0) > 0;
    }

    private function projectExists(int $projectId): bool
    {
        if (!$this->connection->tableExists('projects')) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM projects WHERE id = :project_id',
            ['project_id' => $projectId]
        ) ?? 0) > 0;
    }

    private function isActiveUser(array $user): bool
    {
        return (int) ($user['id'] ?? 0) > 0
            && (int) ($user['is_deleted'] ?? 0) === 0
            && (string) ($user['employment_status'] ?? 'active') === 'active';
    }
}
