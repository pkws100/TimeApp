<?php

declare(strict_types=1);

namespace App\Domain\Users;

use DateTimeImmutable;
use DateTimeInterface;

final class UserWorkdayPolicy
{
    public const DEFAULT_MASK = '1,2,3,4,5';

    /** @var list<int> */
    public const DEFAULT_WORKDAYS = [1, 2, 3, 4, 5];

    /**
     * @return list<int>
     */
    public function scheduledWorkdays(array $user): array
    {
        if (!array_key_exists('workdays_mask', $user) || $user['workdays_mask'] === null) {
            return self::DEFAULT_WORKDAYS;
        }

        $mask = trim((string) $user['workdays_mask']);

        if ($mask === '') {
            return self::DEFAULT_WORKDAYS;
        }

        $values = preg_split('/[,\s;|]+/', $mask, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $workdays = [];

        foreach ($values as $value) {
            if (preg_match('/^[1-7]$/', $value) !== 1) {
                continue;
            }

            $workdays[(int) $value] = true;
        }

        $days = array_keys($workdays);
        sort($days);

        return $days;
    }

    public function isScheduledWorkday(array $user, DateTimeInterface|string $date): bool
    {
        if (is_string($date)) {
            $date = $this->parseDate($date);
        }

        if (!$date instanceof DateTimeInterface) {
            return false;
        }

        return in_array((int) $date->format('N'), $this->scheduledWorkdays($user), true);
    }

    private function parseDate(string $date): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$parsed instanceof DateTimeImmutable
            || $parsed->format('Y-m-d') !== $date
            || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }
}
