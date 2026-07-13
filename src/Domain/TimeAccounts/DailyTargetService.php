<?php

declare(strict_types=1);

namespace App\Domain\TimeAccounts;

use App\Domain\Calendar\CalendarPolicyService;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

final class DailyTargetService
{
    public function __construct(private CalendarPolicyService $calendarPolicyService)
    {
    }

    /** @var array<string, array<string, array{date: string, contract_minutes: int, holiday_reduction_minutes: int, company_closure_reduction_minutes: int, effective_minutes: int, is_workday: bool}>> */
    private array $monthBreakdownCache = [];

    /**
     * @return array<string, array{date: string, contract_minutes: int, holiday_reduction_minutes: int, company_closure_reduction_minutes: int, effective_minutes: int, is_workday: bool}>
     */
    public function breakdown(array $user, string $dateFrom, string $dateTo): array
    {
        $start = new DateTimeImmutable($dateFrom);
        $end = new DateTimeImmutable($dateTo);

        if ($end < $start) {
            return [];
        }

        $days = [];

        foreach ($this->monthStarts($start, $end) as $monthStart) {
            $monthEnd = $monthStart->modify('last day of this month');
            $days += $this->monthBreakdown($user, $monthStart, $monthEnd);
        }

        return array_filter(
            $days,
            static fn (array $day): bool => $day['date'] >= $start->format('Y-m-d') && $day['date'] <= $end->format('Y-m-d')
        );
    }

    public function effectiveTargetForDate(array $user, string $date): int
    {
        $breakdown = $this->breakdown($user, $date, $date);
        $day = $breakdown[$date] ?? null;

        return $day === null ? 0 : (int) $day['effective_minutes'];
    }

    public function stats(array $user, string $dateFrom, string $dateTo): array
    {
        $days = $this->breakdown($user, $dateFrom, $dateTo);
        $stats = [
            'contract_target_minutes' => 0,
            'holiday_reduction_minutes' => 0,
            'company_closure_reduction_minutes' => 0,
            'effective_target_minutes' => 0,
            'workday_count' => 0,
            'effective_workday_count' => 0,
            'public_holiday_days' => 0,
            'company_closure_days' => 0,
        ];

        foreach ($days as $day) {
            if (!$day['is_workday']) {
                continue;
            }

            ++$stats['workday_count'];
            $stats['contract_target_minutes'] += (int) $day['contract_minutes'];
            $stats['holiday_reduction_minutes'] += (int) $day['holiday_reduction_minutes'];
            $stats['company_closure_reduction_minutes'] += (int) $day['company_closure_reduction_minutes'];
            $stats['effective_target_minutes'] += (int) $day['effective_minutes'];

            if ((int) $day['holiday_reduction_minutes'] > 0) {
                ++$stats['public_holiday_days'];
            }

            if ((int) $day['company_closure_reduction_minutes'] > 0) {
                ++$stats['company_closure_days'];
            }

            if ((int) $day['effective_minutes'] > 0) {
                ++$stats['effective_workday_count'];
            }
        }

        return $stats;
    }

    /**
     * @return array<string, array{date: string, contract_minutes: int, holiday_reduction_minutes: int, company_closure_reduction_minutes: int, effective_minutes: int, is_workday: bool}>
     */
    private function monthBreakdown(array $user, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): array
    {
        $cacheKey = $this->monthCacheKey($user, $monthStart);

        if (isset($this->monthBreakdownCache[$cacheKey])) {
            return $this->monthBreakdownCache[$cacheKey];
        }

        $workdays = $this->workdays($user);
        $workDates = [];

        foreach ($this->dateRange($monthStart, $monthEnd) as $date) {
            if (in_array((int) $date->format('N'), $workdays, true)) {
                $workDates[] = $date->format('Y-m-d');
            }
        }

        $contractByDate = $this->contractMinutesByDate($user, $workDates);
        $days = [];

        foreach ($this->dateRange($monthStart, $monthEnd) as $date) {
            $dateString = $date->format('Y-m-d');
            $isWorkday = in_array($dateString, $workDates, true);
            $contractMinutes = $isWorkday ? (int) ($contractByDate[$dateString] ?? 0) : 0;
            $policy = $this->calendarPolicyService->dayPolicy($dateString);
            $isHoliday = (bool) ($policy['is_public_holiday'] ?? false);
            $isClosure = (bool) ($policy['is_company_closure'] ?? false);
            $holidayReduction = $isWorkday && $isHoliday ? $contractMinutes : 0;
            $closureReduction = $isWorkday && !$isHoliday && $isClosure ? $contractMinutes : 0;

            $days[$dateString] = [
                'date' => $dateString,
                'contract_minutes' => $contractMinutes,
                'holiday_reduction_minutes' => $holidayReduction,
                'company_closure_reduction_minutes' => $closureReduction,
                'effective_minutes' => max(0, $contractMinutes - $holidayReduction - $closureReduction),
                'is_workday' => $isWorkday,
            ];
        }

        return $this->monthBreakdownCache[$cacheKey] = $days;
    }

    private function monthCacheKey(array $user, DateTimeImmutable $monthStart): string
    {
        return implode('|', [
            (string) ($user['id'] ?? ''),
            (string) ($user['target_hours_mode'] ?? 'month'),
            (string) ($user['target_hours_week'] ?? ''),
            (string) ($user['target_hours_month'] ?? ''),
            (string) ($user['workdays_mask'] ?? '1,2,3,4,5'),
            $monthStart->format('Y-m'),
        ]);
    }

    /**
     * @param list<string> $workDates
     * @return array<string, int>
     */
    private function contractMinutesByDate(array $user, array $workDates): array
    {
        if ($workDates === []) {
            return [];
        }

        $mode = (string) ($user['target_hours_mode'] ?? 'month');
        $workdays = $this->workdays($user);

        if ($mode === 'week' && (float) ($user['target_hours_week'] ?? 0) > 0) {
            $weeklyMinutes = (int) round((float) $user['target_hours_week'] * 60);
            $base = intdiv($weeklyMinutes, max(1, count($workdays)));
            $remainder = $weeklyMinutes % max(1, count($workdays));
            $byWeekday = [];

            foreach ($workdays as $index => $weekday) {
                $byWeekday[$weekday] = $base + ($index < $remainder ? 1 : 0);
            }

            $result = [];

            foreach ($workDates as $date) {
                $result[$date] = (int) ($byWeekday[(int) (new DateTimeImmutable($date))->format('N')] ?? 0);
            }

            return $result;
        }

        $monthMinutes = (int) round((float) ($user['target_hours_month'] ?? 0) * 60);
        $base = intdiv($monthMinutes, count($workDates));
        $remainder = $monthMinutes % count($workDates);
        $result = [];

        foreach (array_values($workDates) as $index => $date) {
            $result[$date] = $base + ($index < $remainder ? 1 : 0);
        }

        return $result;
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function monthStarts(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $months = [];
        $cursor = $start->modify('first day of this month');
        $last = $end->modify('first day of this month');

        while ($cursor <= $last) {
            $months[] = $cursor;
            $cursor = $cursor->modify('first day of next month');
        }

        return $months;
    }

    /**
     * @return iterable<DateTimeImmutable>
     */
    private function dateRange(DateTimeImmutable $start, DateTimeImmutable $end): iterable
    {
        return new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
    }

    /**
     * @return list<int>
     */
    private function workdays(array $user): array
    {
        $values = preg_split('/[,\s;|]+/', trim((string) ($user['workdays_mask'] ?? '1,2,3,4,5'))) ?: [];
        $days = [];

        foreach ($values as $value) {
            $day = (int) $value;

            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }
}
