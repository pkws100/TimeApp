<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use DateTimeImmutable;
use DateTimeZone;

final class WorkTimeAccessService
{
    public function __construct(private WorkTimePolicyService $policies)
    {
    }

    public function isAdministrator(array $actor): bool
    {
        return in_array('administrator', $actor['roles'] ?? [], true)
            || in_array('*', $actor['permissions'] ?? [], true);
    }

    public function canManageUser(array $actor, int $userId, ?string $date = null): bool
    {
        if ($this->isAdministrator($actor)) {
            return true;
        }

        $date ??= (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        foreach ($this->policies->listAssignments($userId) as $assignment) {
            if ((string) $assignment['effective_from'] <= $date
                && (empty($assignment['effective_to']) || (string) $assignment['effective_to'] >= $date)
                && in_array((int) ($actor['id'] ?? 0), array_map('intval', $assignment['supervisor_user_ids'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }
}
