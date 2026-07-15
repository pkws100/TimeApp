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
        private CalendarPolicyService $calendarPolicyService,
        private ?DailyTargetService $dailyTargetService = null,
        private ?AccountJournalService $journalService = null,
        private ?EmployeeAccountCutoverService $cutoverService = null
    ) {
        $this->dailyTargetService ??= new DailyTargetService($calendarPolicyService);
        $this->journalService ??= new AccountJournalService($connection);
        $this->cutoverService ??= new EmployeeAccountCutoverService($connection, $this->journalService);
    }

    public function monthlyAccount(int $userId, int $year, int $month, ?string $asOfDate = null, bool $includeEntries = false): array
    {
        $user = $this->user($userId);

        if ($user === null) {
            return $this->emptyMonthlyAccount($userId, $year, $month);
        }

        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');
        $today = $this->normalizeAsOfDate($asOfDate);
        $standDate = $this->standDateForPeriod($start, $end, $today);
        $cutover = $this->cutoverService?->activeCutover($userId);
        $cutoverDate = $cutover !== null ? (string) $cutover['effective_from'] : null;

        if ($cutover !== null && $end->format('Y-m-d') < $cutoverDate) {
            return $this->monthlyAccountBeforeCutover($user, $userId, $year, $month, $start, $end, $cutover);
        }

        $calculationStart = $cutoverDate !== null && $cutoverDate > $start->format('Y-m-d')
            ? new DateTimeImmutable($cutoverDate)
            : $start;
        $targetEnd = $standDate !== null && $standDate >= $calculationStart->format('Y-m-d') ? new DateTimeImmutable($standDate) : null;
        $stats = $targetEnd === null
            ? $this->emptyTimesheetStats()
            : $this->timesheetStats($userId, $calculationStart->format('Y-m-d'), $targetEnd->format('Y-m-d'));
        $monthTargetStats = $this->dailyTargetService?->stats($user, $start->format('Y-m-d'), $end->format('Y-m-d')) ?? $this->calendarTargetFallback($user, $start, $end);
        $targetStats = $targetEnd === null
            ? $this->emptyTargetStats()
            : ($this->dailyTargetService?->stats($user, $calculationStart->format('Y-m-d'), $targetEnd->format('Y-m-d')) ?? $this->calendarTargetFallback($user, $calculationStart, $targetEnd));
        $targetMinutes = (int) $targetStats['effective_target_minutes'];
        $actualMinutes = (int) ($stats['work_minutes'] ?? 0);
        $creditedMinutes = (int) ($stats['credited_minutes'] ?? 0);
        $cutoverId = $cutover !== null ? (int) $cutover['id'] : null;
        $manualAdjustmentMinutes = $targetEnd === null ? 0 : $this->journalMinutes($userId, $calculationStart->format('Y-m-d'), $targetEnd->format('Y-m-d'), false, $cutoverId);
        $periodDelta = $actualMinutes + $creditedMinutes - $targetMinutes + $manualAdjustmentMinutes;
        $openingBalance = null;
        $closingBalance = null;
        $cutoverStatus = $cutover === null ? 'missing' : 'final';

        if ($cutover !== null) {
            $periodStartForBalance = $calculationStart->format('Y-m-d');
            $beforePeriod = (new DateTimeImmutable($periodStartForBalance))->modify('-1 day')->format('Y-m-d');
            $openingBalance = $this->balanceUntil($user, $cutover, $beforePeriod);
            $closingBalance = $targetEnd === null ? $openingBalance : $openingBalance + $periodDelta;
        }

        $saldoMinutes = $periodDelta;
        $vacation = $this->vacationYearForCutover($userId, $year, $user, $cutover);

        return [
            'user_id' => $userId,
            'user' => $this->userLabel($user),
            'year' => $year,
            'month' => $month,
            'month_label' => $start->format('Y-m'),
            'cutover_status' => $cutoverStatus,
            'cutover_id' => $cutover !== null ? (int) $cutover['id'] : null,
            'cutover_date' => $cutoverDate,
            'as_of_date' => $standDate,
            'account_message' => $cutover === null ? 'Zeitkonto noch nicht eingerichtet' : null,
            'opening_balance_at_period_start_minutes' => $openingBalance,
            'target_minutes' => $targetMinutes,
            'contract_target_minutes' => (int) $targetStats['contract_target_minutes'],
            'month_contract_target_minutes' => (int) $monthTargetStats['contract_target_minutes'],
            'month_effective_target_minutes' => (int) $monthTargetStats['effective_target_minutes'],
            'holiday_reduction_minutes' => (int) $targetStats['holiday_reduction_minutes'],
            'company_closure_reduction_minutes' => (int) $targetStats['company_closure_reduction_minutes'],
            'effective_target_minutes' => $targetMinutes,
            'actual_minutes' => $actualMinutes,
            'actual_work_minutes' => $actualMinutes,
            'credited_absence_minutes' => $creditedMinutes,
            'manual_adjustment_minutes' => $manualAdjustmentMinutes,
            'period_delta_minutes' => $periodDelta,
            'closing_balance_minutes' => $closingBalance,
            'saldo_minutes' => $saldoMinutes,
            'target_label' => $this->durationLabel($targetMinutes),
            'month_target_label' => $this->durationLabel((int) $monthTargetStats['effective_target_minutes']),
            'actual_label' => $this->durationLabel($actualMinutes),
            'credited_absence_label' => $this->durationLabel($creditedMinutes),
            'manual_adjustment_label' => $this->signedDurationLabel($manualAdjustmentMinutes),
            'period_delta_label' => $this->signedDurationLabel($periodDelta),
            'opening_balance_at_period_start_label' => $openingBalance === null ? null : $this->signedDurationLabel($openingBalance),
            'closing_balance_label' => $closingBalance === null ? null : $this->signedDurationLabel($closingBalance),
            'saldo_label' => $this->signedDurationLabel($saldoMinutes),
            'vacation_days' => (float) ($stats['vacation_days'] ?? 0),
            'sick_days' => (float) ($stats['sick_days'] ?? 0),
            'absent_days' => (float) ($stats['absent_days'] ?? 0),
            'holiday_days' => (float) $targetStats['public_holiday_days'],
            'company_closure_days' => (float) $targetStats['company_closure_days'],
            'workday_count' => (int) $targetStats['workday_count'],
            'vacation' => $vacation,
            'time_entries' => $includeEntries ? ($this->journalService?->timeEntriesForUser($userId, null, $standDate, $cutoverId, 50) ?? []) : [],
            'vacation_entries' => $includeEntries ? ($this->journalService?->vacationEntriesForUser($userId, $year, $cutoverId, 50) ?? []) : [],
        ];
    }

    public function vacationYear(int $userId, int $year, ?array $user = null): array
    {
        $user ??= $this->user($userId);
        $cutover = $this->cutoverService?->activeCutover($userId);

        return $this->vacationYearForCutover($userId, $year, $user ?? [], $cutover);
    }

    private function vacationYearForCutover(int $userId, int $year, array $user, ?array $cutover): array
    {
        $cutoverDate = $cutover !== null ? (string) $cutover['effective_from'] : null;
        $cutoverId = $cutover !== null ? (int) $cutover['id'] : null;
        $isCutoverYear = $cutover !== null && (int) ($cutover['leave_year'] ?? 0) === $year;
        $hasJournal = !$isCutoverYear && $cutoverId !== null && $this->hasVacationJournalEntries($userId, $year, $cutoverId);

        if ($isCutoverYear) {
            $entitlement = (float) ($cutover['annual_leave_entitlement_days'] ?? 0);
            $carryover = (float) ($cutover['leave_carryover_days'] ?? 0);
            $openingBalance = (float) ($cutover['opening_remaining_leave_days'] ?? 0);
            $openingAdjustment = $openingBalance - $entitlement - $carryover;
            $manualAdjustments = $cutoverId !== null ? $this->vacationJournalManualSum($userId, $year, $cutoverId) : 0.0;
            $total = $openingBalance + $manualAdjustments;
            $source = 'cutover_snapshot';
        } else {
            $journalTotal = $cutoverId !== null ? $this->vacationJournalSum($userId, $year, $cutoverId) : 0.0;
            $entitlement = $hasJournal ? $this->vacationJournalTypeSum($userId, $year, 'annual_entitlement', $cutoverId) : (float) ($user['vacation_days_year'] ?? 0);
            $carryover = $hasJournal ? $this->vacationJournalTypeSum($userId, $year, 'carryover', $cutoverId) : (float) ($user['vacation_carryover_days'] ?? 0);
            $openingAdjustment = $hasJournal ? $this->vacationJournalTypeSum($userId, $year, 'opening_adjustment', $cutoverId) : 0.0;
            $manualAdjustments = $hasJournal ? $this->vacationJournalManualSum($userId, $year, $cutoverId) : 0.0;
            $total = $hasJournal ? $journalTotal : $entitlement + $carryover;
            $openingBalance = $hasJournal ? $entitlement + $carryover + $openingAdjustment : $total;
            $source = $hasJournal ? 'journal' : 'user_defaults';
        }

        $taken = $this->takenVacationDays($userId, $year, $cutoverDate);
        $takenPast = $this->takenVacationDays($userId, $year, $cutoverDate, null, $this->normalizeAsOfDate(null));
        $futureApproved = $this->futureApprovedVacationDays($userId, $year, $cutoverDate, $this->normalizeAsOfDate(null));
        $pending = $this->pendingVacationDays($userId, $year, $user);
        $remaining = $total - $taken;
        $available = $remaining - $pending;

        return [
            'year' => $year,
            'entitlement_days' => $entitlement,
            'carryover_days' => $carryover,
            'opening_adjustment_days' => $openingAdjustment,
            'manual_adjustment_days' => $manualAdjustments,
            'total_days' => $total,
            'opening_balance_days' => $openingBalance,
            'approved_taken_days' => $taken,
            'approved_taken_past_days' => $takenPast,
            'future_approved_days' => $futureApproved,
            'pending_days' => $pending,
            'remaining_days' => $remaining,
            'available_days' => $available,
            'source' => $source,
        ];
    }

    public function adminVacationOverview(int $year, int $userId = 0): array
    {
        $year = max(2000, min(2100, $year));
        $users = $this->activeUsers();

        if ($userId > 0) {
            $users = array_values(array_filter(
                $users,
                static fn (array $user): bool => (int) ($user['id'] ?? 0) === $userId
            ));
        }

        return [
            'year' => $year,
            'rows' => array_map(fn (array $user): array => $this->adminVacationRow($user, $year), $users),
        ];
    }

    public function journalEntries(int $userId, ?int $year = null, int $limit = 50, int $page = 1): array
    {
        $cutover = $this->cutoverService?->activeCutover($userId);
        $cutoverId = $cutover !== null ? (int) $cutover['id'] : null;
        $year ??= (int) date('Y');
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        if ($cutoverId === null) {
            return $this->emptyJournalEntries($page, $limit);
        }

        return $this->journalEntriesForCutover($userId, $year, $limit, $page, $cutover, false);
    }

    public function adminJournalEntries(int $userId, ?int $year = null, int $limit = 50, int $page = 1, ?int $cutoverId = null): array
    {
        if ($cutoverId === null) {
            return $this->journalEntries($userId, $year, $limit, $page);
        }

        $cutover = $this->cutoverService?->find($cutoverId);
        if ($cutover === null
            || (int) ($cutover['user_id'] ?? 0) !== $userId
            || !in_array((string) ($cutover['status'] ?? ''), ['final', 'reversed'], true)) {
            throw new \InvalidArgumentException('Die Stichtagsgeneration wurde fuer diesen Mitarbeiter nicht gefunden.');
        }

        $active = $this->cutoverService?->activeCutover($userId);
        $readOnly = $active === null || (int) ($active['id'] ?? 0) !== $cutoverId;

        return $this->journalEntriesForCutover(
            $userId,
            $year ?? (int) ($cutover['leave_year'] ?? date('Y')),
            max(1, min(100, $limit)),
            max(1, $page),
            $cutover,
            $readOnly
        );
    }

    private function journalEntriesForCutover(int $userId, int $year, int $limit, int $page, array $cutover, bool $readOnly): array
    {
        $cutoverId = (int) ($cutover['id'] ?? 0);
        $offset = ($page - 1) * $limit;
        $timeEntries = $this->journalService?->timeEntriesForUser($userId, null, null, $cutoverId, $limit + 1, $offset) ?? [];
        $vacationEntries = $this->journalService?->vacationEntriesForUser($userId, $year, $cutoverId, $limit + 1, $offset) ?? [];

        return [
            'cutover_id' => $cutoverId,
            'cutover_status' => (string) ($cutover['status'] ?? ''),
            'read_only' => $readOnly,
            'time_entries' => array_slice($timeEntries, 0, $limit),
            'vacation_entries' => array_slice($vacationEntries, 0, $limit),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'time_has_more' => count($timeEntries) > $limit,
                'vacation_has_more' => count($vacationEntries) > $limit,
            ],
        ];
    }

    private function emptyJournalEntries(int $page, int $limit): array
    {
        return [
            'cutover_id' => null,
            'cutover_status' => null,
            'read_only' => true,
            'time_entries' => [],
            'vacation_entries' => [],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'time_has_more' => false,
                'vacation_has_more' => false,
            ],
        ];
    }

    public function adminOverview(int $year, int $month, array $filters = []): array
    {
        $filters = $this->normalizeAdminFilters($filters);
        $users = $this->activeUsers();
        $filterUserId = (int) ($filters['user_id'] ?? 0);
        $includeEntries = $filterUserId > 0;

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
                'rows' => array_map(fn (array $user): array => $this->monthlyAccount((int) $user['id'], $year, $month, null, $includeEntries), $pageUsers),
                'pagination' => $pagination,
                'filters' => [...$filters, 'user_id' => $filterUserId > 0 ? $filterUserId : null],
            ];
        }

        $rows = array_map(
            fn (array $user): array => $this->monthlyAccount((int) $user['id'], $year, $month, null, $includeEntries),
            $users
        );

        if ($filters['saldo_filter'] !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($filters): bool {
                $saldo = $row['closing_balance_minutes'] ?? $row['saldo_minutes'] ?? 0;
                $saldo = (int) $saldo;

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
                'saldo' => ((int) (($left['closing_balance_minutes'] ?? null) ?? ($left['saldo_minutes'] ?? 0)) <=> (int) (($right['closing_balance_minutes'] ?? null) ?? ($right['saldo_minutes'] ?? 0))),
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
                'Stichtag' => (string) ($row['cutover_date'] ?? ''),
                'Standdatum' => (string) ($row['as_of_date'] ?? ''),
                'Monatsanfangsbestand' => (string) ($row['opening_balance_at_period_start_label'] ?? ''),
                'Monats-Soll gesamt' => (string) ($row['month_target_label'] ?? '00:00'),
                'Soll bis Standdatum' => (string) ($row['target_label'] ?? '00:00'),
                'Feiertagsreduzierung (Min)' => (int) ($row['holiday_reduction_minutes'] ?? 0),
                'Betriebsschliessungsreduzierung (Min)' => (int) ($row['company_closure_reduction_minutes'] ?? 0),
                'Effektives Soll (Min)' => (int) ($row['effective_target_minutes'] ?? 0),
                'Tatsaechliche Arbeitszeit' => (string) ($row['actual_label'] ?? '00:00'),
                'Abwesenheitsgutschriften' => (string) ($row['credited_absence_label'] ?? '00:00'),
                'Manuelle Korrekturen' => (string) ($row['manual_adjustment_label'] ?? '+00:00'),
                'Monatsveraenderung' => (string) ($row['period_delta_label'] ?? ($row['saldo_label'] ?? '+00:00')),
                'Monatsendbestand' => (string) ($row['closing_balance_label'] ?? ''),
                'Urlaub genommen' => (float) ($vacation['approved_taken_days'] ?? 0),
                'Urlaub offen' => (float) ($vacation['pending_days'] ?? 0),
                'Jahresurlaub' => (float) ($vacation['entitlement_days'] ?? 0),
                'Uebertrag' => (float) ($vacation['carryover_days'] ?? 0),
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
            'cutover_status' => 'missing',
            'cutover_date' => null,
            'as_of_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'account_message' => 'Zeitkonto noch nicht eingerichtet',
            'opening_balance_at_period_start_minutes' => null,
            'target_minutes' => 0,
            'contract_target_minutes' => 0,
            'month_contract_target_minutes' => 0,
            'month_effective_target_minutes' => 0,
            'holiday_reduction_minutes' => 0,
            'company_closure_reduction_minutes' => 0,
            'effective_target_minutes' => 0,
            'actual_minutes' => 0,
            'actual_work_minutes' => 0,
            'credited_absence_minutes' => 0,
            'manual_adjustment_minutes' => 0,
            'period_delta_minutes' => 0,
            'closing_balance_minutes' => null,
            'saldo_minutes' => 0,
            'target_label' => '00:00',
            'month_target_label' => '00:00',
            'actual_label' => '00:00',
            'credited_absence_label' => '00:00',
            'manual_adjustment_label' => '+00:00',
            'period_delta_label' => '+00:00',
            'opening_balance_at_period_start_label' => null,
            'closing_balance_label' => null,
            'saldo_label' => '+00:00',
            'vacation_days' => 0.0,
            'sick_days' => 0.0,
            'absent_days' => 0.0,
            'holiday_days' => 0.0,
            'company_closure_days' => 0.0,
            'workday_count' => 0,
            'vacation' => $this->vacationYear($userId, $year, []),
            'time_entries' => [],
            'vacation_entries' => [],
        ];
    }

    private function monthlyAccountBeforeCutover(array $user, int $userId, int $year, int $month, DateTimeImmutable $start, DateTimeImmutable $end, array $cutover): array
    {
        $stats = $this->timesheetStats($userId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $monthTargetStats = $this->dailyTargetService?->stats($user, $start->format('Y-m-d'), $end->format('Y-m-d')) ?? $this->calendarTargetFallback($user, $start, $end);
        $vacation = $this->vacationYearForCutover($userId, $year, $user, $cutover);

        return [
            'user_id' => $userId,
            'user' => $this->userLabel($user),
            'year' => $year,
            'month' => $month,
            'month_label' => $start->format('Y-m'),
            'cutover_status' => 'not_active_in_period',
            'cutover_id' => (int) $cutover['id'],
            'cutover_date' => (string) $cutover['effective_from'],
            'as_of_date' => $end->format('Y-m-d'),
            'account_message' => 'Zeitkonto war in diesem Zeitraum noch nicht aktiv.',
            'opening_balance_at_period_start_minutes' => null,
            'target_minutes' => 0,
            'contract_target_minutes' => 0,
            'month_contract_target_minutes' => (int) $monthTargetStats['contract_target_minutes'],
            'month_effective_target_minutes' => (int) $monthTargetStats['effective_target_minutes'],
            'holiday_reduction_minutes' => 0,
            'company_closure_reduction_minutes' => 0,
            'effective_target_minutes' => 0,
            'actual_minutes' => (int) ($stats['work_minutes'] ?? 0),
            'actual_work_minutes' => (int) ($stats['work_minutes'] ?? 0),
            'credited_absence_minutes' => (int) ($stats['credited_minutes'] ?? 0),
            'manual_adjustment_minutes' => 0,
            'period_delta_minutes' => 0,
            'closing_balance_minutes' => null,
            'saldo_minutes' => 0,
            'target_label' => '00:00',
            'month_target_label' => $this->durationLabel((int) $monthTargetStats['effective_target_minutes']),
            'actual_label' => $this->durationLabel((int) ($stats['work_minutes'] ?? 0)),
            'credited_absence_label' => $this->durationLabel((int) ($stats['credited_minutes'] ?? 0)),
            'manual_adjustment_label' => '+00:00',
            'period_delta_label' => '+00:00',
            'opening_balance_at_period_start_label' => null,
            'closing_balance_label' => null,
            'saldo_label' => '+00:00',
            'vacation_days' => (float) ($stats['vacation_days'] ?? 0),
            'sick_days' => (float) ($stats['sick_days'] ?? 0),
            'absent_days' => (float) ($stats['absent_days'] ?? 0),
            'holiday_days' => (float) $monthTargetStats['public_holiday_days'],
            'company_closure_days' => (float) $monthTargetStats['company_closure_days'],
            'workday_count' => (int) $monthTargetStats['workday_count'],
            'vacation' => $vacation,
            'time_entries' => [],
            'vacation_entries' => [],
        ];
    }

    private function balanceUntil(array $user, array $cutover, string $dateTo): int
    {
        $cutoverDate = (string) $cutover['effective_from'];
        $opening = (int) ($cutover['opening_time_balance_minutes'] ?? 0);

        if ($dateTo < $cutoverDate) {
            return $opening;
        }

        $stats = $this->timesheetStats((int) $cutover['user_id'], $cutoverDate, $dateTo);
        $targetStats = $this->dailyTargetService?->stats($user, $cutoverDate, $dateTo) ?? $this->emptyTargetStats();
        $journal = $this->journalMinutes((int) $cutover['user_id'], $cutoverDate, $dateTo, false, (int) $cutover['id']);

        return $opening
            + (int) $stats['work_minutes']
            + (int) $stats['credited_minutes']
            - (int) $targetStats['effective_target_minutes']
            + $journal;
    }

    private function journalMinutes(int $userId, ?string $dateFrom, ?string $dateTo, bool $includeOpening, ?int $cutoverId = null): int
    {
        if ($userId <= 0 || !$this->connection->tableExists('time_account_entries')) {
            return 0;
        }

        $clauses = ['user_id = :user_id'];
        $bindings = ['user_id' => $userId];

        if ($dateFrom !== null) {
            $clauses[] = 'effective_date >= :date_from';
            $bindings['date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $clauses[] = 'effective_date <= :date_to';
            $bindings['date_to'] = $dateTo;
        }

        if (!$includeOpening) {
            $clauses[] = 'entry_type <> "opening_balance"';
        }

        if ($cutoverId !== null && $this->connection->columnExists('time_account_entries', 'cutover_id')) {
            $clauses[] = 'cutover_id = :cutover_id';
            $bindings['cutover_id'] = $cutoverId;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COALESCE(SUM(minutes), 0) FROM time_account_entries WHERE ' . implode(' AND ', $clauses),
            $bindings
        ) ?? 0);
    }

    private function normalizeAsOfDate(?string $asOfDate): string
    {
        $value = trim((string) ($asOfDate ?? ''));
        $date = $value === '' ? new DateTimeImmutable('today') : DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && ($value === '' || ($date->format('Y-m-d') === $value && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))))) {
            return $date->format('Y-m-d');
        }

        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function standDateForPeriod(DateTimeImmutable $start, DateTimeImmutable $end, string $asOfDate): ?string
    {
        if ($asOfDate < $start->format('Y-m-d')) {
            return null;
        }

        if ($asOfDate > $end->format('Y-m-d')) {
            return $end->format('Y-m-d');
        }

        return $asOfDate;
    }

    private function emptyTimesheetStats(): array
    {
        return [
            'work_minutes' => 0,
            'credited_minutes' => 0,
            'vacation_days' => 0.0,
            'sick_days' => 0.0,
            'absent_days' => 0.0,
        ];
    }

    private function emptyTargetStats(): array
    {
        return [
            'contract_target_minutes' => 0,
            'holiday_reduction_minutes' => 0,
            'company_closure_reduction_minutes' => 0,
            'effective_target_minutes' => 0,
            'workday_count' => 0,
            'effective_workday_count' => 0,
            'public_holiday_days' => 0,
            'company_closure_days' => 0,
        ];
    }

    private function calendarTargetFallback(array $user, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $calendar = $this->calendarStats($user, $start, $end);
        $target = $this->targetMinutes($user, $calendar);

        return [
            'contract_target_minutes' => $target,
            'holiday_reduction_minutes' => 0,
            'company_closure_reduction_minutes' => 0,
            'effective_target_minutes' => $target,
            'workday_count' => (int) $calendar['workday_count'],
            'effective_workday_count' => (int) $calendar['effective_workday_count'],
            'public_holiday_days' => (int) $calendar['public_holiday_days'],
            'company_closure_days' => (int) $calendar['company_closure_days'],
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
                $saldo = (int) (($row['closing_balance_minutes'] ?? null) ?? ($row['saldo_minutes'] ?? 0));

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
                'saldo' => ((int) (($left['closing_balance_minutes'] ?? null) ?? ($left['saldo_minutes'] ?? 0)) <=> (int) (($right['closing_balance_minutes'] ?? null) ?? ($right['saldo_minutes'] ?? 0))),
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
            'credited_minutes' => 0,
            'vacation_days' => 0.0,
            'sick_days' => 0.0,
            'absent_days' => 0.0,
        ];

        if (!$this->connection->tableExists('timesheets')) {
            return $stats;
        }

        $creditedSelect = $this->connection->columnExists('timesheets', 'credited_minutes')
            ? 'SUM(COALESCE(credited_minutes, 0)) AS credited_minutes'
            : '0 AS credited_minutes';
        $vacationDaySelect = $this->connection->columnExists('timesheets', 'absence_reason_code')
            ? 'COUNT(DISTINCT CASE WHEN absence_reason_code = "vacation_paid" OR absence_reason_code IS NULL THEN work_date END) AS paid_vacation_days,
               COUNT(DISTINCT CASE WHEN absence_reason_code = "unpaid_leave" THEN work_date END) AS unpaid_leave_days'
            : 'COUNT(DISTINCT work_date) AS paid_vacation_days, 0 AS unpaid_leave_days';
        $rows = $this->connection->fetchAll(
            'SELECT entry_type, COUNT(DISTINCT work_date) AS day_count, ' . $vacationDaySelect . ', SUM(COALESCE(net_minutes, 0)) AS minutes, ' . $creditedSelect . '
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
            $dayCount = (float) ($row['day_count'] ?? 0);

            if ($type === 'work') {
                $stats['work_minutes'] += (int) ($row['minutes'] ?? 0);
            } elseif ($type === 'vacation') {
                $stats['vacation_days'] += (float) ($row['paid_vacation_days'] ?? 0);
                $stats['absent_days'] += (float) ($row['unpaid_leave_days'] ?? 0);
            } elseif ($type === 'sick') {
                $stats['sick_days'] += $dayCount;
            } elseif ($type === 'absent') {
                $stats['absent_days'] += $dayCount;
            }

            $stats['credited_minutes'] += (int) ($row['credited_minutes'] ?? 0);
        }

        return $stats;
    }

    private function takenVacationDays(int $userId, int $year, ?string $cutoverDate = null, ?string $dateFromOverride = null, ?string $dateToOverride = null): float
    {
        if (!$this->connection->tableExists('timesheets')) {
            return 0.0;
        }

        $dateFrom = $dateFromOverride ?? sprintf('%04d-01-01', $year);
        $dateTo = $dateToOverride ?? sprintf('%04d-12-31', $year);

        if ($cutoverDate !== null && $cutoverDate > $dateFrom) {
            $dateFrom = $cutoverDate;
        }

        if ($dateTo < $dateFrom) {
            return 0.0;
        }

        $paidVacationClause = $this->connection->columnExists('timesheets', 'absence_reason_code')
            ? ' AND (absence_reason_code = "vacation_paid" OR absence_reason_code IS NULL)'
            : '';

        return (float) ((int) ($this->connection->fetchColumn(
            'SELECT COUNT(DISTINCT work_date)
             FROM timesheets
             WHERE user_id = :user_id
               AND entry_type = "vacation"
               AND work_date >= :date_from
               AND work_date <= :date_to
               AND COALESCE(is_deleted, 0) = 0' . $paidVacationClause,
            [
                'user_id' => $userId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]
        ) ?? 0));
    }

    private function futureApprovedVacationDays(int $userId, int $year, ?string $cutoverDate, string $asOfDate): float
    {
        $tomorrow = (new DateTimeImmutable($asOfDate))->modify('+1 day')->format('Y-m-d');
        $yearStart = sprintf('%04d-01-01', $year);
        $dateFrom = max($tomorrow, $yearStart);

        return $this->takenVacationDays($userId, $year, $cutoverDate, $dateFrom, sprintf('%04d-12-31', $year));
    }

    private function vacationJournalSum(int $userId, int $year, int $cutoverId): float
    {
        if (!$this->connection->tableExists('vacation_account_entries')) {
            return 0.0;
        }

        $clauses = ['user_id = :user_id', 'leave_year = :leave_year'];
        $bindings = ['user_id' => $userId, 'leave_year' => $year];

        if ($this->connection->columnExists('vacation_account_entries', 'cutover_id')) {
            $clauses[] = 'cutover_id = :cutover_id';
            $bindings['cutover_id'] = $cutoverId;
        }

        return (float) ($this->connection->fetchColumn(
            'SELECT COALESCE(SUM(days), 0) FROM vacation_account_entries WHERE ' . implode(' AND ', $clauses),
            $bindings
        ) ?? 0);
    }

    private function hasVacationJournalEntries(int $userId, int $year, int $cutoverId): bool
    {
        if (!$this->connection->tableExists('vacation_account_entries')) {
            return false;
        }

        $clauses = ['user_id = :user_id', 'leave_year = :leave_year'];
        $bindings = ['user_id' => $userId, 'leave_year' => $year];

        if ($this->connection->columnExists('vacation_account_entries', 'cutover_id')) {
            $clauses[] = 'cutover_id = :cutover_id';
            $bindings['cutover_id'] = $cutoverId;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM vacation_account_entries WHERE ' . implode(' AND ', $clauses),
            $bindings
        ) ?? 0) > 0;
    }

    private function vacationJournalTypeSum(int $userId, int $year, string $entryType, int $cutoverId): float
    {
        if (!$this->connection->tableExists('vacation_account_entries')) {
            return 0.0;
        }

        $clauses = ['user_id = :user_id', 'leave_year = :leave_year', 'entry_type = :entry_type'];
        $bindings = ['user_id' => $userId, 'leave_year' => $year, 'entry_type' => $entryType];

        if ($this->connection->columnExists('vacation_account_entries', 'cutover_id')) {
            $clauses[] = 'cutover_id = :cutover_id';
            $bindings['cutover_id'] = $cutoverId;
        }

        return (float) ($this->connection->fetchColumn(
            'SELECT COALESCE(SUM(days), 0)
             FROM vacation_account_entries
             WHERE ' . implode(' AND ', $clauses),
            $bindings
        ) ?? 0);
    }

    private function vacationJournalManualSum(int $userId, int $year, int $cutoverId): float
    {
        if (!$this->connection->tableExists('vacation_account_entries')) {
            return 0.0;
        }

        $clauses = ['user_id = :user_id', 'leave_year = :leave_year', 'entry_type IN ("manual_adjustment", "expiry", "reversal")'];
        $bindings = ['user_id' => $userId, 'leave_year' => $year];

        if ($this->connection->columnExists('vacation_account_entries', 'cutover_id')) {
            $clauses[] = 'cutover_id = :cutover_id';
            $bindings['cutover_id'] = $cutoverId;
        }

        return (float) ($this->connection->fetchColumn(
            'SELECT COALESCE(SUM(days), 0)
             FROM vacation_account_entries
             WHERE ' . implode(' AND ', $clauses),
            $bindings
        ) ?? 0);
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
            'SELECT id, employee_number, first_name, last_name, email,
                    workdays_mask, vacation_days_year, vacation_carryover_days
             FROM users
             WHERE COALESCE(is_deleted, 0) = 0
               AND employment_status = "active"
             ORDER BY last_name ASC, first_name ASC, id ASC'
        );
    }

    private function adminVacationRow(array $user, int $year): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $cutover = $this->cutoverService?->activeCutover($userId);
        $row = [
            'user_id' => $userId,
            'user' => $this->userLabel($user),
            'employee_number' => (string) ($user['employee_number'] ?? ''),
            'year' => $year,
            'account_status' => 'missing',
            'cutover_id' => null,
            'cutover_date' => null,
            'leave_year' => null,
            'source' => null,
            'vacation' => null,
        ];

        if ($cutover === null) {
            return $row;
        }

        $leaveYear = (int) ($cutover['leave_year'] ?? 0);
        $row['cutover_id'] = (int) ($cutover['id'] ?? 0);
        $row['cutover_date'] = (string) ($cutover['effective_from'] ?? '');
        $row['leave_year'] = $leaveYear;

        if ($year < $leaveYear) {
            $row['account_status'] = 'not_active_in_year';

            return $row;
        }

        $vacation = $this->vacationYearForCutover($userId, $year, $user, $cutover);
        $row['account_status'] = 'active';
        $row['source'] = (string) ($vacation['source'] ?? '');
        $row['vacation'] = $vacation;

        return $row;
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
