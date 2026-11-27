<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/** Builds report evidence once; finalized documents only read its stored snapshot. */
final class AccountingEmployeeSummaryService
{
    public function __construct(
        private DatabaseConnection $connection,
        private TimeAccountService $accounts,
        private DailyTargetService $targets,
        private WorkTimePolicyService $policies,
        private WorkTimePreviewService $preview
    ) {
    }

    public function forPeriod(array $selection, array $items, string $periodStart, string $periodEnd): array
    {
        $details = [];
        foreach ($items as $item) {
            $policy = $this->policies->assignedPolicyFor((int) $item['user_id'], (string) $item['work_date']);
            $details[(string) $item['timesheet_id']] = [
                'absence_reason_code' => $item['absence_reason_code'] ?? null,
                'credited_minutes' => (int) ($item['credited_minutes'] ?? 0),
                'work_time_policy' => $policy === null ? null : [
                    ...array_intersect_key($policy, array_flip(['template_name', 'revision', 'timezone', 'windows', 'checkout_mode', 'effective_from', 'effective_to'])),
                    'enabled' => $this->policies->enabled(),
                ],
            ];
        }
        // Project reports are shared with project recipients and contain no personnel account data.
        if (($selection['project_id'] ?? null) !== null || ($selection['type'] ?? '') === 'project') {
            return ['version' => 1, 'employee_summaries' => [], 'item_details' => $details, 'employee_summary_status' => 'not_applicable'];
        }

        $userIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['user_id'], $items)));
        if (($selection['user_id'] ?? null) !== null) {
            $userIds[] = (int) $selection['user_id'];
        } elseif (($selection['type'] ?? 'month') === 'month') {
            // A missing booking must not remove an active employee from the payroll overview.
            $requiredUsers = $this->connection->fetchAll('SELECT id FROM users WHERE employment_status = "active" AND time_tracking_required = 1 AND COALESCE(is_deleted, 0) = 0');
            $userIds = [...$userIds, ...array_map(static fn (array $user): int => (int) $user['id'], $requiredUsers)];
        }
        $summaries = [];
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        foreach (array_unique($userIds) as $userId) {
            $user = $this->connection->fetchOne('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
            if ($user === null) { continue; }
            for ($year = (int) substr($periodStart, 0, 4); $year <= (int) substr($periodEnd, 0, 4); ++$year) {
                $from = max($periodStart, sprintf('%04d-01-01', $year));
                $to = min($periodEnd, sprintf('%04d-12-31', $year));
                $asOf = min($to, $today);
                $days = $asOf < $from ? [] : $this->targets->breakdown($user, $from, $asOf);
                $vacation = $this->accounts->vacationYearAsOf($userId, $year, $asOf, $user);
                $paid = $unpaid = $sick = $credits = [];
                foreach ($items as $item) {
                    $date = (string) $item['work_date'];
                    if ((int) $item['user_id'] !== $userId || $date < $from || $date > $asOf || (int) ($days[$date]['effective_minutes'] ?? 0) <= 0) { continue; }
                    $type = (string) $item['entry_type'];
                    $reason = $item['absence_reason_code'] ?? null;
                    if ($type === 'vacation' && ($reason === null || $reason === 'vacation_paid')) { $paid[$date] = true; }
                    if (($type === 'vacation' || $type === 'absent') && $reason === 'unpaid_leave') { $unpaid[$date] = true; }
                    if ($type === 'sick') { $sick[$date] = true; }
                    if ($type !== 'work') {
                        // Historical duplicate absence rows must not multiply the daily credit.
                        $credits[$date] = max($credits[$date] ?? 0, (int) ($item['credited_minutes'] ?? 0));
                    }
                }
                $window = $asOf < $from ? [] : $this->preview->forUserPeriod($userId, $from, $asOf);
                $inside = $window['inside_window_minutes'] ?? null;
                $outside = isset($window['outside_before_minutes'], $window['outside_after_minutes'], $window['outside_gap_minutes'])
                    ? $window['outside_before_minutes'] + $window['outside_after_minutes'] + $window['outside_gap_minutes'] : null;
                if (empty($window['enabled']) || ($window['calculation_status'] ?? 'no_policy') === 'no_policy') {
                    $inside = $outside = null;
                }
                $summaries[] = [
                    'user_id' => $userId, 'employee_number' => (string) ($user['employee_number'] ?? ''),
                    'employee_name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
                    'period_start' => $from, 'period_end' => $to, 'as_of_date' => $asOf, 'leave_year' => $year,
                    'vacation_taken_days' => count($paid), 'vacation_unpaid_days' => count($unpaid), 'sick_days' => count($sick),
                    'credited_absence_minutes' => array_sum($credits),
                    'vacation_remaining_days' => $vacation['remaining_at_date_days'],
                    'vacation_booked_remaining_days' => $vacation['remaining_days'],
                    'vacation_taken_year_days' => $vacation['recorded_taken_year_days'],
                    'vacation_consumption_from_date' => $vacation['consumption_from_date'],
                    'vacation_taken_account_days' => $vacation['approved_taken_past_days'],
                    'vacation_future_days' => $vacation['future_approved_days'],
                    'vacation_entitlement_days' => $vacation['entitlement_days'], 'vacation_total_days' => $vacation['total_days'],
                    'vacation_is_provisional' => $vacation['is_provisional'],
                    'target_minutes' => array_sum(array_column($days, 'effective_minutes')),
                    'required_days' => count(array_filter($days, static fn (array $day): bool => (int) $day['effective_minutes'] > 0)),
                    'core_time_periods' => $this->policies->configuredPeriods($userId, $from, $to),
                    'inside_window_minutes' => $inside, 'outside_window_minutes' => $outside,
                    'window_is_provisional' => true,
                ];
            }
        }
        usort($summaries, static fn (array $a, array $b): int => [$a['employee_name'], $a['user_id'], $a['leave_year']] <=> [$b['employee_name'], $b['user_id'], $b['leave_year']]);
        return ['version' => 1, 'employee_summaries' => $summaries, 'item_details' => $details, 'employee_summary_status' => 'snapshot'];
    }
}
