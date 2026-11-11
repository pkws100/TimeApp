<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/** Read-only summary for the user form; it never activates an assignment. */
final class WorkTimeUserSummaryService
{
    public function __construct(private DatabaseConnection $connection, private WorkTimePolicyService $policies)
    {
    }

    public function forUser(array $actor, int $userId, ?DateTimeImmutable $asOf = null): ?array
    {
        $permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
        if ($userId <= 0 || (!in_array('*', $permissions, true) && !in_array('work_time_policies.view', $permissions, true))) {
            return null;
        }
        $isAdmin = (new WorkTimeAccessService($this->policies))->isAdministrator($actor);
        $asOf = ($asOf ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('Europe/Berlin'));
        $today = $asOf->format('Y-m-d');
        $current = $future = [];
        foreach ($this->policies->listAssignments($userId) as $assignment) {
            if (!$isAdmin && !in_array((int) ($actor['id'] ?? 0), $assignment['supervisor_user_ids'], true)) {
                continue;
            }
            if ($assignment['effective_to'] !== null && $assignment['effective_to'] < $today) {
                continue;
            }
            if ($assignment['effective_from'] <= $today) {
                $current[] = $assignment;
            } else {
                $future[] = $assignment;
            }
        }
        if (!$isAdmin && $current === [] && $future === []) {
            return null;
        }
        usort($future, static fn (array $a, array $b): int => [$a['effective_from'], $a['id']] <=> [$b['effective_from'], $b['id']]);
        $assignments = $current;
        if ($future !== []) {
            $assignments[] = $future[0];
        }
        foreach ($assignments as &$assignment) {
            $version = $this->connection->fetchOne('SELECT policy_json FROM work_time_policy_versions WHERE id = :id', ['id' => $assignment['policy_version_id']]);
            $policy = array_replace(json_decode((string) ($version['policy_json'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR), $assignment['overrides']);
            $assignment['windows'] = $policy['windows'] ?? [];
            $assignment['status'] = $assignment['effective_from'] > $today ? 'planned' : 'current';
            unset($assignment['overrides'], $assignment['overrides_json'], $assignment['supervisor_user_ids']);
        }
        unset($assignment);

        return [
            'enabled' => $this->policies->enabled(),
            'can_manage' => ($isAdmin || $current !== [])
                && (in_array('*', $permissions, true) || in_array('work_time_policies.manage', $permissions, true)),
            'schema_available' => $this->connection->tableExists('employee_work_time_assignments'),
            'assignments' => $assignments,
            'current_count' => count($current),
            'additional_future_count' => max(0, count($future) - 1),
            'as_of' => $today,
            'url' => '/admin/work-time?user_id=' . $userId,
        ];
    }
}
