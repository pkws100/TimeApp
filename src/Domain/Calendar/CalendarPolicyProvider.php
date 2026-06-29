<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

interface CalendarPolicyProvider
{
    public function requiresTimeTracking(string $date): bool;

    /**
     * @return array<string, mixed>
     */
    public function dayPolicy(string $date): array;
}
