<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\TimesheetPauseService;
use App\Infrastructure\Database\DatabaseConnection;

/** Read-only comparison of persisted work under the current and proposed policy. */
final class WorkTimeAssignmentImpact
{
    public function __construct(
        private DatabaseConnection $connection,
        private WorkTimePolicyService $policies,
        private ?WorkTimePreviewService $preview = null,
        private ?TimesheetPauseService $pauses = null
    ) {
    }

    public function inspect(int $userId, string $from, ?string $until, ?array $proposed, bool $includeRows): array
    {
        $until ??= '9999-12-31';
        $params = ['user_id' => $userId, 'date_from' => $from, 'date_to' => $until];
        $entries = $this->connection->fetchAll(
            'SELECT t.*, t.row_start AS work_time_source_revision FROM timesheets t
             WHERE t.user_id = :user_id AND t.work_date BETWEEN :date_from AND :date_to
               AND t.entry_type = "work" AND COALESCE(t.is_deleted, 0) = 0
             ORDER BY t.work_date, t.id',
            $params
        );
        $closures = $this->connection->fetchAll(
            'SELECT id, closure_number, status, source_type, source_id, user_id, project_id, period_start, period_end FROM accounting_closures
             WHERE status IN ("final", "correction") AND (user_id IS NULL OR user_id = :user_id)
               AND period_start <= :date_to AND period_end >= :date_from ORDER BY id',
            $params
        );
        $projectIds = array_column($entries, 'project_id');
        $closures = array_values(array_filter($closures, static fn (array $closure): bool =>
            $closure['project_id'] === null || in_array($closure['project_id'], $projectIds, true)
        ));
        $hash = hash_init('sha256');
        hash_update($hash, $this->json($closures));
        $rows = [];
        $dates = [];
        $lockedDates = [];
        $deltas = [];
        $preview = $this->preview ?? new WorkTimePreviewService($this->connection, $this->policies);
        $fields = [
            'actual_work_minutes', 'inside_window_minutes', 'outside_before_minutes', 'outside_after_minutes', 'outside_gap_minutes',
            'outside_pending_minutes', 'outside_approved_minutes', 'outside_rejected_minutes', 'outside_disputed_minutes',
            'accountable_work_minutes', 'calculation_status', 'documented_break_minutes', 'unplaced_break_minutes', 'clarification_reason',
        ];
        foreach ($entries as $entry) {
            $date = $entry['work_date'];
            $args = ['id' => $entry['id']];
            $breaks = $this->connection->fetchAll('SELECT break_started_at, break_ended_at FROM timesheet_breaks WHERE timesheet_id = :id ORDER BY break_started_at, id', $args);
            $deviations = $this->connection->fetchAll('SELECT * FROM work_time_deviations WHERE timesheet_id = :id AND superseded_at IS NULL ORDER BY id', $args);
            $currentPolicy = $this->policies->assignedPolicyFor($userId, $date);
            $durationBreaks = $this->pauses?->unplacedDurations((int) $entry['id']) ?? [];
            $pauseModel = $this->pauses?->snapshot((int) $entry['id']);
            $source = ['breaks' => $breaks, 'deviations' => $deviations, 'duration_breaks' => $durationBreaks, 'pause_model' => $pauseModel];
            hash_update($hash, $this->json([$entry, $source, $currentPolicy]));
            if (!$includeRows) {
                continue;
            }
            $dates[$date] = true;
            $closed = array_values(array_filter($closures, static fn (array $closure): bool =>
                ($closure['project_id'] === null || $closure['project_id'] === $entry['project_id'])
                && $closure['period_start'] <= $date && $closure['period_end'] >= $date
            ));
            if ($closed !== []) {
                $lockedDates[$date] = true;
            }
            $old = $preview->forTimesheet($entry, $currentPolicy, $source);
            $next = $preview->forTimesheet($entry, $proposed, $source);
            $before = array_intersect_key($old['calculation'], array_flip($fields));
            $after = array_intersect_key($next['calculation'], array_flip($fields));
            $delta = isset($before['accountable_work_minutes'], $after['accountable_work_minutes'])
                ? $after['accountable_work_minutes'] - $before['accountable_work_minutes'] : null;
            $deltas[] = $delta;
            if (count($rows) < 100) {
                $rows[] = [
                    'id' => (int) $entry['id'], 'work_date' => $date, 'recorded_net_minutes' => (int) $entry['net_minutes'],
                    'recorded_break_minutes' => (int) $entry['break_minutes'], 'documented_breaks' => $breaks,
                    'duration_break_minutes' => array_sum(array_column($durationBreaks, 'duration_minutes')),
                    'before_version' => $old['policy']['policy_version_id'] ?? null,
                    'after_version' => $next['policy']['policy_version_id'] ?? null,
                    'before' => $before, 'after' => $after, 'delta_minutes' => $delta,
                    'closures' => array_column($closed, 'closure_number'),
                ];
            }
        }
        return [
            'source_hash' => hash_final($hash), 'preview_only' => true, 'booking_count' => count($entries),
            'workday_count' => count($dates), 'closed_workday_count' => count($lockedDates),
            'closing_count' => count($closures), 'rows' => $rows,
            'accountable_delta_minutes' => in_array(null, $deltas, true) ? null : array_sum($deltas),
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
