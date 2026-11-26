<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;

final class ProjectAccessService
{
    private const GLOBAL_PERMISSIONS = [
        '*',
        'projects.manage',
        'files.manage',
        'timesheets.manage',
    ];

    private const GLOBAL_FILE_PERMISSIONS = [
        '*',
        'projects.manage',
        'files.manage',
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

    public function canAccess(array $user, int $projectId, bool $allowArchived = false): bool
    {
        if (!$this->isActiveUser($user) || $projectId <= 0) {
            return false;
        }

        if ($this->hasGlobalAccess($user)) {
            return $allowArchived ? $this->projectExists($projectId) : $this->activeProjectExists($projectId);
        }

        return $this->activeProjectExists($projectId) && $this->hasActiveMembership($user, $projectId);
    }

    public function canAccessFiles(array $user, int $projectId, bool $allowArchived = false): bool
    {
        if (!$this->isActiveUser($user) || $projectId <= 0) {
            return false;
        }

        if ($this->hasAnyPermission($user, self::GLOBAL_FILE_PERMISSIONS)) {
            return $allowArchived ? $this->projectExists($projectId) : $this->activeProjectExists($projectId);
        }

        return $this->activeProjectExists($projectId) && $this->hasActiveMembership($user, $projectId);
    }

    public function requireProjectEntryWriteAccess(array $user, int $projectId, bool $allowArchived = false): void
    {
        if (!$this->canWriteProjectEntries($user, $projectId, $allowArchived)) {
            throw new ProjectAccessDeniedException(
                'Keine Schreibberechtigung fuer dieses Projekt.',
                $this->canAccess($user, $projectId, $allowArchived) ? ProjectAccessDeniedException::WRITE_DENIED : null
            );
        }
    }

    private function canWriteProjectEntries(array $user, int $projectId, bool $allowArchived): bool
    {
        if (!$this->isActiveUser($user) || $projectId <= 0) {
            return false;
        }

        if ($this->canManage($user)) {
            return $allowArchived ? $this->projectExists($projectId) : $this->activeProjectExists($projectId);
        }

        return $this->hasAnyPermission($user, ['timesheets.create'])
            && $this->activeProjectExists($projectId)
            && $this->hasActiveMembership($user, $projectId);
    }

    private function hasActiveMembership(array $user, int $projectId): bool
    {
        if (!$this->connection->tableExists('project_memberships')) {
            return false;
        }

        $today = $this->today();

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM project_memberships
             WHERE project_id = :project_id
               AND user_id = :user_id
               AND (assigned_from IS NULL OR assigned_from <= :assigned_from_date)
               AND (assigned_until IS NULL OR assigned_until >= :assigned_until_date)',
            [
                'project_id' => $projectId,
                'user_id' => (int) ($user['id'] ?? 0),
                'assigned_from_date' => $today,
                'assigned_until_date' => $today,
            ]
        ) ?? 0) > 0;
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
            $today = $this->today();
            $rows = $this->connection->fetchAll(
                'SELECT DISTINCT projects.id
                 FROM projects
                 INNER JOIN project_memberships ON project_memberships.project_id = projects.id
                 WHERE project_memberships.user_id = :user_id
                   AND COALESCE(projects.is_deleted, 0) = 0
                   AND projects.status <> "archived"
                   AND (project_memberships.assigned_from IS NULL OR project_memberships.assigned_from <= :assigned_from_date)
                   AND (project_memberships.assigned_until IS NULL OR project_memberships.assigned_until >= :assigned_until_date)
                 ORDER BY projects.id',
                ['user_id' => $userId, 'assigned_from_date' => $today, 'assigned_until_date' => $today]
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

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }

    private function hasAnyPermission(array $user, array $requiredPermissions): bool
    {
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];

        return array_intersect($requiredPermissions, $permissions) !== [];
    }
}
