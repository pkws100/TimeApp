<?php

declare(strict_types=1);

namespace App\Domain\TimeAccounts;

use App\Domain\Calendar\CalendarPolicyService;
use App\Infrastructure\Database\DatabaseConnection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;

final class TimeAccountService
{
    private const DEFAULT_SORT = 'name';
    private const DEFAULT_DIRECTION = 'asc';
    private const DEFAULT_PAGE = 1;
    private const DEFAULT_PER_PAGE = 50;
    private const PER_PAGE_OPTIONS = [25, 50, 75, 100];

    public function __construct(
        private DatabaseConnection $connection,
        private CalendarPolicyService $calendarPolicyService
    ) {
    }

    public function monthlyAccount(int $userId, int $year, int $month): array
    {
        $user = $this->user($userId);

        if ($user === null) {
            return $this->emptyMonthlyAccount($userId, $year, $month);
        }

        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');
        $stats = $this->timesheetStats($userId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $calendar = $this->calendarStats($user, $start, $end);
        $targetMinutes = $this->targetMinutes($user, $calendar);
        $actualMinutes = (int) ($stats['work_minutes'] ?? 0);
        $saldoMinutes = $actualMinutes - $targetMinutes;
        $vacation = $this->vacationYear($userId, $year, $user);

        return [
            'user_id' => $userId,
            'user' => $this->userLabel($user),
            'year' => $year,
            'month' => $month,
            'month_label' => $start->format('Y-m'),
            'target_minutes' => $targetMinutes,
            'actual_minutes' => $actualMinutes,
            'saldo_minutes' => $saldoMinutes,
            'target_label' => $this->durationLabel($targetMinutes),
            'actual_label' => $this->durationLabel($actualMinutes),
            'saldo_label' => $this->signedDurationLabel($saldoMinutes),
            'vacation_days' => (float) ($stats['vacation_days'] ?? 0),
            'sick_days' => (float) ($stats['sick_days'] ?? 0),
            'absent_days' => (float) ($stats['absent_days'] ?? 0),
            'holiday_days' => (float) $calendar['public_holiday_days'],
            'company_closure_days' => (float) $calendar['company_closure_days'],
            'workday_count' => (int) $calendar['workday_count'],
            'vacation' => $vacation,
        ];
    }

    public function vacationYear(int $userId, int $year, ?array $user = null): array
    {
        $user ??= $this->user($userId);
        $entitlement = (float) ($user['vacation_days_year'] ?? 0);
        $carryover = (float) ($user['vacation_carryover_days'] ?? 0);
        $taken = $this->takenVacationDays($userId, $year);
        $pending = $this->pendingVacationDays($userId, $year, $user ?? []);
        $total = $entitlement + $carryover;
        $remaining = $total - $taken;
        $available = $remaining - $pending;

        return [
            'year' => $year,
            'entitlement_days' => $entitlement,
            'carryover_days' => $carryover,
            'total_days' => $total,
            'approved_taken_days' => $taken,
            'pending_days' => $pending,
            'remaining_days' => $remaining,
            'available_days' => $available,
        ];
    }

    public function adminOverview(int $year, int $month, array $filters = []): array
    {
        $filters = $this->normalizeAdminFilters($filters);
        $users = $this->activeUsers();
        $filterUserId = (int) ($filters['user_id'] ?? 0);

        if ($filterUserId > 0) {
            $users = array_values(array_filter($users, static fn (array $user): bool => (int) ($user['id'] ?? 0) === $filterUserId));
        }

        if ($filters['q'] !== '') {
            $needle = mb_strtolower((string) $filters['q']);
            $users = array_values(array_filter($users, function (array $user) use ($needle): bool {
                return str_contains(mb_strtolower($this->userSearchLabel($user)), $needle);
            }));
        }

        $sort = (string) $filters['sort'];
        $direction = (string) $filters['direction'];
        $needsCalculatedRows = $sort !== 'name' || $filters['saldo_filter'] !== '' || $filters['vacation_filter'] !== '';

        if (!$needsCalculatedRows) {
            usort($users, function (array $left, array $right) use ($direction): int {
                $result = strcmp($this->userLabel($left), $this->userLabel($right));

                return $direction === 'desc' ? -$result : $result;
            });

            $pagination = $this->paginationForCount(count($users), $filters);
            $pageUsers = array_slice($users, ($pagination['page'] - 1) * $pagination['per_page'], $pagination['per_page']);

            return [
                'year' => $year,
                'month' => $month,
                'rows' => array_map(
                    fn (array $user): array => $this->monthlyAccount((int) $user['id'], $year, $month),
                    $pageUsers
                ),
                'pagination' => $pagination,
                'filters' => [...$filters, 'user_id' => $filterUserId > 0 ? $filterUserId : null],
            ];
        }

        $rows = array_map(
            fn (array $user): array => $this->monthlyAccount((int) $user['id'], $year, $month),
            $users
        );

        if ($filters['saldo_filter'] !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                $saldo = (int) ($row['saldo_minutes'] ?? 0);

                return match ($filters['saldo_filter']) {
                    'negative' => $saldo < 0,
                    'positive' => $saldo > 0,
                    'zero' => $saldo === 0,
                    default => true,
                };
            }));
        }

        if ($filters['vacation_filter'] !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                $vacation = $row['vacation'] ?? [];
                $remaining = (float) ($vacation['remaining_days'] ?? 0);
                $available = (float) ($vacation['available_days'] ?? 0);
                $pending = (float) ($vacation['pending_days'] ?? 0);

                return match ($filters['vacation_filter']) {
                    'negative_remaining' => $remaining < 0,
                    'negative_available' => $available < 0,
                    'pending' => $pending > 0,
                    default => true,
                };
            }));
        }

        usort($rows, function (array $left, array $right) use ($sort, $direction): int {
            $result = match ($sort) {
                'target' => ((int) ($left['target_minutes'] ?? 0) <=> (int) ($right['target_minutes'] ?? 0)),
                'actual' => ((int) ($left['actual_minutes'] ?? 0) <=> (int) ($right['actual_minutes'] ?? 0)),
                'saldo' => ((int) ($left['saldo_minutes'] ?? 0) <=> (int) ($right['saldo_minutes'] ?? 0)),
                'taken_vacation' => ((float) ($left['vacation']['approved_taken_days'] ?? 0) <=> (float) ($right['vacation']['approved_taken_days'] ?? 0)),
                'pending_vacation' => ((float) ($left['vacation']['pending_days'] ?? 0) <=> (float) ($right['vacation']['pending_days'] ?? 0)),
                'resturlaub' => ((float) ($left['vacation']['remaining_days'] ?? 0) <=> (float) ($right['vacation']['remaining_days'] ?? 0)),
                'available_vacation' => ((float) ($left['vacation']['available_days'] ?? 0) <=> (float) ($right['vacation']['available_days'] ?? 0)),
                'sick' => ((float) ($left['sick_days'] ?? 0) <=> (float) ($right['sick_days'] ?? 0)),
                'absent' => ((float) ($left['absent_days'] ?? 0) <=> (float) ($right['absent_days'] ?? 0)),
                'holiday' => ((float) ($left['holiday_days'] ?? 0) <=> (float) ($right['holiday_days'] ?? 0)),
                'company_closure' => ((float) ($left['company_closure_days'] ?? 0) <=> (float) ($right['company_closure_days'] ?? 0)),
                default => strcmp((string) $left['user'], (string) $right['user']),
            };

            if ($result === 0 && $sort !== 'name') {
                return strcmp((string) $left['user'], (string) $right['user']);
            }

            return $direction === 'desc' ? -$result : $result;
        });

        $pagination = $this->paginationForCount(count($rows), $filters);
        $offset = ($pagination['page'] - 1) * $pagination['per_page'];

        return [
            'year' => $year,
            'month' => $month,
            'rows' => array_slice($rows, $offset, $pagination['per_page']),
            'pagination' => $pagination,
            'filters' => [...$filters, 'user_id' => $filterUserId > 0 ? $filterUserId : null],
        ];
    }

    public function adminExportRows(int $year, int $month, array $filters = []): array
    {
        $filters = $this->normalizeAdminFilters($filters);
        $rows = $this->adminRows($year, $month, $filters);

        return array_map(static function (array $row): array {
            $vacation = $row['vacation'] ?? [];

            return [
                'Mitarbeiter' => (string) ($row['user'] ?? ''),
                'Jahr' => (int) ($row['year'] ?? 0),
                'Monat' => (int) ($row['month'] ?? 0),
                'Soll' => (string) ($row['target_label'] ?? '00:00'),
                'Ist' => (string) ($row['actual_label'] ?? '00:00'),
                'Saldo' => (string) ($row['saldo_label'] ?? '+00:00'),
                'Urlaub genommen' => (float) ($vacation['approved_taken_days'] ?? 0),
                'Urlaub offen' => (float) ($vacation['pending_days'] ?? 0),
                'Resturlaub' => (float) ($vacation['remaining_days'] ?? 0),
                'Verfuegbar' => (float) ($vacation['available_days'] ?? 0),
                'Krank' => (float) ($row['sick_days'] ?? 0),
                'Fehltage' => (float) ($row['absent_days'] ?? 0),
                'Feiertage' => (float) ($row['holiday_days'] ?? 0),
                'Betriebsurlaub' => (float) ($row['company_closure_days'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * @return array<string, string>
     */
    public function adminSortOptions(): array
    {
        return [
            'name' => 'Mitarbeiter',
            'target' => 'Soll',
            'actual' => 'Ist',
            'saldo' => 'Saldo',
            'taken_vacation' => 'Urlaub genommen',
            'pending_vacation' => 'Urlaub offen',
            'resturlaub' => 'Resturlaub',
            'available_vacation' => 'Verfuegbar',
            'sick' => 'Krank',
            'absent' => 'Fehltage',
            'holiday' => 'Feiertage',
            'company_closure' => 'Betriebsurlaub',
        ];
    }

    /**
     * @return list<int>
     */
    public function adminPerPageOptions(): array
    {
        return self::PER_PAGE_OPTIONS;
    }

    public function durationLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public function signedDurationLabel(int $minutes): string
    {
        $prefix = $minutes >= 0 ? '+' : '-';
        $absolute = abs($minutes);

        return $prefix . sprintf('%02d:%02d', intdiv($absolute, 60), $absolute % 60);
    }

    private function emptyMonthlyAccount(int $userId, int $year, int $month): array
    {
        return [
            'user_id' => $userId,
            'user' => 'Unbekannter User',
            'year' => $year,
            'month' => $month,
            'month_label' => sprintf('%04d-%02d', $year, $month),
            'target_minutes' => 0,
            'actual_minutes' => 0,
            'saldo_minutes' => 0,
            'target_label' => '00:00',
            'actual_label' => '00:00',
            'saldo_label' => '+00:00',
            'vacation_days' => 0.0,
            'sick_days' => 0.0,
            'absent_days' => 0.0,
            'holiday_days' => 0.0,
            'company_closure_days' => 0.0,
            'workday_count' => 0,
            'vacation' => $this->vacationYear($userId, $year, []),
        ];
    }

    private function normalizeAdminFilters(array $input): array
    {
        $sort = trim((string) ($input['sort'] ?? self::DEFAULT_SORT));
        $sort = array_key_exists($sort, $this->adminSortOptions()) ? $sort : self::DEFAULT_SORT;
        $direction = strtolower(trim((string) ($input['direction'] ?? self::DEFAULT_DIRECTION)));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : self::DEFAULT_DIRECTION;
        $page = max(self::DEFAULT_PAGE, (int) ($input['page'] ?? self::DEFAULT_PAGE));
        $perPage = (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
        $saldoFilter = trim((string) ($input['saldo_filter'] ?? ''));
        $saldoFilter = in_array($saldoFilter, ['negative', 'positive', 'zero'], true) ? $saldoFilter : '';
        $vacationFilter = trim((string) ($input['vacation_filter'] ?? ''));
        $vacationFilter = in_array($vacationFilter, ['negative_remaining', 'negative_available', 'pending'], true) ? $vacationFilter : '';

        return [
            'user_id' => max(0, (int) ($input['user_id'] ?? 0)),
            'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 120),
            'saldo_filter' => $saldoFilter,
            'vacation_filter' => $vacationFilter,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function adminRows(int $year, int $month, array $filters): array
    {
        $users = $this->activeUsers();
        $filterUserId = (int) ($filters['user_id'] ?? 0);

        if ($filterUserId > 0) {
            $users = array_values(array_filter($users, static fn (array $user): bool => (int) ($user['id'] ?? 0) === $filterUserId));
        }

        if ($filters['q'] !== '') {
            $needle = mb_strtolower((string) $filters['q']);
            $users = array_values(array_filter($users, function (array $user) use ($needle): bool {
                return str_contains(mb_strtolower($this->userSearchLabel($user)), $needle);
            }));
        }

        $rows = array_map(
            fn (array $user): array => $this->monthlyAccount((int) $user['id'], $year, $month),
            $users
        );

        if ($filters['saldo_filter'] !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                $saldo = (int) ($row['saldo_minutes'] ?? 0);

                return match ($filters['saldo_filter']) {
                    'negative' => $saldo < 0,
                    'positive' => $saldo > 0,
                    'zero' => $saldo === 0,
                    default => true,
                };
            }));
        }

        if ($filters['vacation_filter'] !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                $vacation = $row['vacation'] ?? [];
                $remaining = (float) ($vacation['remaining_days'] ?? 0);
                $available = (float) ($vacation['available_days'] ?? 0);
                $pending = (float) ($vacation['pending_days'] ?? 0);

                return match ($filters['vacation_filter']) {
                    'negative_remaining' => $remaining < 0,
                    'negative_available' => $available < 0,
                    'pending' => $pending > 0,
                    default => true,
                };
            }));
        }

        $sort = (string) $filters['sort'];
        $direction = (string) $filters['direction'];

        usort($rows, function (array $left, array $right) use ($sort, $direction): int {
            $result = match ($sort) {
                'target' => ((int) ($left['target_minutes'] ?? 0) <=> (int) ($right['target_minutes'] ?? 0)),
                'actual' => ((int) ($left['actual_minutes'] ?? 0) <=> (int) ($right['actual_minutes'] ?? 0)),
                'saldo' => ((int) ($left['saldo_minutes'] ?? 0) <=> (int) ($right['saldo_minutes'] ?? 0)),
                'taken_vacation' => ((float) ($left['vacation']['approved_taken_days'] ?? 0) <=> (float) ($right['vacation']['approved_taken_days'] ?? 0)),
                'pending_vacation' => ((float) ($left['vacation']['pending_days'] ?? 0) <=> (float) ($right['vacation']['pending_days'] ?? 0)),
                'resturlaub' => ((float) ($left['vacation']['remaining_days'] ?? 0) <=> (float) ($right['vacation']['remaining_days'] ?? 0)),
                'available_vacation' => ((float) ($left['vacation']['available_days'] ?? 0) <=> (float) ($right['vacation']['available_days'] ?? 0)),
                'sick' => ((float) ($left['sick_days'] ?? 0) <=> (float) ($right['sick_days'] ?? 0)),
                'absent' => ((float) ($left['absent_days'] ?? 0) <=> (float) ($right['absent_days'] ?? 0)),
                'holiday' => ((float) ($left['holiday_days'] ?? 0) <=> (float) ($right['holiday_days'] ?? 0)),
                'company_closure' => ((float) ($left['company_closure_days'] ?? 0) <=> (float) ($right['company_closure_days'] ?? 0)),
                default => strcmp((string) $left['user'], (string) $right['user']),
            };

            if ($result === 0 && $sort !== 'name') {
                return strcmp((string) $left['user'], (string) $right['user']);
            }

            return $direction === 'desc' ? -$result : $result;
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{total: int, page: int, per_page: int, total_pages: int}
     */
    private function paginationForCount(int $total, array $filters): array
    {
        $perPage = (int) $filters['per_page'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min((int) $filters['page'], $totalPages);

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    private function targetMinutes(array $user, array $calendar): int
    {
        $mode = (string) ($user['target_hours_mode'] ?? 'month');
        $weekHours = (float) ($user['target_hours_week'] ?? 0);

        if ($mode === 'week' && $weekHours > 0) {
            $scheduledDays = max(1, count($this->workdays($user)));
            $minutesPerDay = ($weekHours * 60) / $scheduledDays;

            return (int) round($minutesPerDay * (int) $calendar['effective_workday_count']);
        }

        return (int) round(((float) ($user['target_hours_month'] ?? 0)) * 60);
    }

    private function calendarStats(array $user, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $workdays = $this->workdays($user);
        $stats = [
            'workday_count' => 0,
            'effective_workday_count' => 0,
            'public_holiday_days' => 0,
            'company_closure_days' => 0,
        ];

        foreach ($this->dateRange($start, $end) as $date) {
            if (!in_array((int) $date->format('N'), $workdays, true)) {
                continue;
            }

            ++$stats['workday_count'];
            $policy = $this->calendarPolicyService->dayPolicy($date->format('Y-m-d'));
            $isHoliday = (bool) ($policy['is_public_holiday'] ?? false);
            $isClosure = (bool) ($policy['is_company_closure'] ?? false);

            if ($isHoliday) {
                ++$stats['public_holiday_days'];
            }

            if ($isClosure) {
                ++$stats['company_closure_days'];
            }

            if (!$isHoliday && !$isClosure) {
                ++$stats['effective_workday_count'];
            }
        }

        return $stats;
    }

    private function timesheetStats(int $userId, string $dateFrom, string $dateTo): array
    {
        $stats = [
            'work_minutes' => 0,
            'vacation_days' => 0.0,
            'sick_days' => 0.0,
            'absent_days' => 0.0,
        ];

        if (!$this->connection->tableExists('timesheets')) {
            return $stats;
        }

        $rows = $this->connection->fetchAll(
            'SELECT entry_type, COUNT(DISTINCT work_date) AS day_count, SUM(COALESCE(net_minutes, 0)) AS minutes
             FROM timesheets
             WHERE user_id = :user_id
               AND work_date >= :date_from
               AND work_date <= :date_to
               AND COALESCE(is_deleted, 0) = 0
             GROUP BY entry_type',
            [
                'user_id' => $userId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]
        );

        foreach ($rows as $row) {
            $type = (string) ($row['entry_type'] ?? '');

            if ($type === 'work') {
                $stats['work_minutes'] = (int) ($row['minutes'] ?? 0);
            } elseif ($type === 'vacation') {
                $stats['vacation_days'] = (float) ($row['day_count'] ?? 0);
            } elseif ($type === 'sick') {
                $stats['sick_days'] = (float) ($row['day_count'] ?? 0);
            } elseif ($type === 'absent') {
                $stats['absent_days'] = (float) ($row['day_count'] ?? 0);
            }
        }

        return $stats;
    }

    private function takenVacationDays(int $userId, int $year): float
    {
        if (!$this->connection->tableExists('timesheets')) {
            return 0.0;
        }

        return (float) ((int) ($this->connection->fetchColumn(
            'SELECT COUNT(DISTINCT work_date)
             FROM timesheets
             WHERE user_id = :user_id
               AND entry_type = "vacation"
               AND work_date >= :date_from
               AND work_date <= :date_to
               AND COALESCE(is_deleted, 0) = 0',
            [
                'user_id' => $userId,
                'date_from' => sprintf('%04d-01-01', $year),
                'date_to' => sprintf('%04d-12-31', $year),
            ]
        ) ?? 0));
    }

    private function pendingVacationDays(int $userId, int $year, array $user): float
    {
        if (!$this->connection->tableExists('vacation_requests')) {
            return 0.0;
        }

        $yearStart = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $yearEnd = new DateTimeImmutable(sprintf('%04d-12-31', $year));
        $rows = $this->connection->fetchAll(
            'SELECT date_from, date_to
             FROM vacation_requests
             WHERE user_id = :user_id
               AND status = "pending"
               AND date_from <= :date_to
               AND date_to >= :date_from
               AND COALESCE(is_deleted, 0) = 0',
            [
                'user_id' => $userId,
                'date_from' => $yearStart->format('Y-m-d'),
                'date_to' => $yearEnd->format('Y-m-d'),
            ]
        );
        $days = 0;

        foreach ($rows as $row) {
            $start = new DateTimeImmutable(max((string) $row['date_from'], $yearStart->format('Y-m-d')));
            $end = new DateTimeImmutable(min((string) $row['date_to'], $yearEnd->format('Y-m-d')));
            $days += (int) $this->calendarStats($user, $start, $end)['effective_workday_count'];
        }

        return (float) $days;
    }

    private function user(int $userId): ?array
    {
        if ($userId <= 0 || !$this->connection->tableExists('users')) {
            return null;
        }

        $columns = [
            'id',
            'first_name',
            'last_name',
            'email',
            'target_hours_month',
            $this->columnOrLiteral('target_hours_mode', '"month"', 'target_hours_mode'),
            $this->columnOrLiteral('target_hours_week', 'NULL', 'target_hours_week'),
            $this->columnOrLiteral('workdays_mask', '"1,2,3,4,5"', 'workdays_mask'),
            $this->columnOrLiteral('vacation_days_year', '0', 'vacation_days_year'),
            $this->columnOrLiteral('vacation_carryover_days', '0', 'vacation_carryover_days'),
        ];

        return $this->connection->fetchOne(
            'SELECT ' . implode(', ', $columns) . ' FROM users WHERE id = :id LIMIT 1',
            ['id' => $userId]
        );
    }

    private function activeUsers(): array
    {
        if (!$this->connection->tableExists('users')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT id, first_name, last_name, email
             FROM users
             WHERE COALESCE(is_deleted, 0) = 0
               AND employment_status = "active"
             ORDER BY last_name ASC, first_name ASC, id ASC'
        );
    }

    private function columnOrLiteral(string $column, string $literal, string $alias): string
    {
        return $this->connection->columnExists('users', $column)
            ? $column . ' AS ' . $alias
            : $literal . ' AS ' . $alias;
    }

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

    /**
     * @return iterable<DateTimeImmutable>
     */
    private function dateRange(DateTimeImmutable $start, DateTimeImmutable $end): iterable
    {
        return new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
    }

    private function userLabel(array $user): string
    {
        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));

        return $name !== '' ? $name : (string) ($user['email'] ?? ('User #' . (int) ($user['id'] ?? 0)));
    }

    private function userSearchLabel(array $user): string
    {
        return trim($this->userLabel($user) . ' ' . (string) ($user['email'] ?? ''));
    }
}
