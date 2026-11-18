<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Domain\WorkTime\WorkTimeLocalTime;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class TimesheetPauseService
{
    private const DAILY_REST_SECONDS = 11 * 3600;

    private PauseAssessmentCalculator $calculator;
    private WorkdayStateCalculator $workday;
    private TimesheetPauseIntervals $intervals;

    public function __construct(private DatabaseConnection $connection, private PausePolicyService $policies)
    {
        $this->calculator = new PauseAssessmentCalculator();
        $this->workday = new WorkdayStateCalculator();
        $this->intervals = new TimesheetPauseIntervals($connection);
    }

    public function available(): bool
    {
        return $this->connection->tableExists('timesheet_pause_policy_snapshots')
            && $this->connection->tableExists('timesheet_pause_durations');
    }

    public function configuredPolicy(int $userId, string $day): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $dayPolicy = $this->connection->fetchColumn('SELECT p.policy_json FROM timesheet_pause_policy_snapshots p
            JOIN timesheets t ON t.id = p.timesheet_id WHERE t.user_id = :user_id AND t.work_date = :work_date
            AND t.entry_type = "work" AND COALESCE(t.is_deleted, 0) = 0
            ORDER BY t.id LIMIT 1', ['user_id' => $userId, 'work_date' => $day]);
        return is_string($dayPolicy) ? json_decode($dayPolicy, true, flags: JSON_THROW_ON_ERROR) : $this->policies->policyFor($userId, $day);
    }

    public function snapshot(int $timesheetId): ?array
    {
        return $this->snapshots([$timesheetId])[$timesheetId] ?? null;
    }

    public function attachSnapshot(int $timesheetId, array $policy): void
    {
        $this->connection->execute('INSERT INTO timesheet_pause_policy_snapshots (timesheet_id, policy_json) VALUES (:id, :policy)', [
            'id' => $timesheetId, 'policy' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function breaks(int $timesheetId): array
    {
        return $this->groupedBreaks([$timesheetId])[$timesheetId] ?? [];
    }

    public function groupedBreaks(array $timesheetIds): array
    {
        $ids = $this->ids($timesheetIds);
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->intervals->rows($ids) as $row) {
            $record = [
                'id' => (int) $row['id'], 'source' => $row['source'], 'note' => $row['note'],
                'break_started_at' => TimesheetPauseIntervals::forDisplay($row['break_started_at']),
                'break_ended_at' => TimesheetPauseIntervals::forDisplay($row['break_ended_at']),
            ];
            $record['duration_minutes'] = $record['break_ended_at'] === null ? null : $this->workday->breakDurationMinutes($record);
            $byId[$row['timesheet_id']][] = $record;
        }
        if ($this->connection->tableExists('timesheet_pause_durations')) {
            foreach ($this->connection->fetchAll('SELECT timesheet_id, id, duration_minutes, source, note FROM timesheet_pause_durations
                WHERE placed_at IS NULL AND timesheet_id IN (' . $this->placeholders($ids) . ') ORDER BY id', $ids) as $row) {
                $byId[$row['timesheet_id']][] = [
                    'id' => -(int) $row['id'], 'source' => $row['source'], 'note' => $row['note'],
                    'break_started_at' => null, 'break_ended_at' => null, 'duration_minutes' => (int) $row['duration_minutes'],
                ];
            }
        }
        return $byId;
    }

    public function openCarryover(int $userId, string $today): ?array
    {
        if (!$this->connection->tableExists('timesheet_pause_policy_snapshots')) {
            return null;
        }
        $open = $this->connection->fetchAll('SELECT t.*, p.name AS project_name, s.timesheet_id AS pause_policy_entry
            FROM timesheets t LEFT JOIN timesheet_pause_policy_snapshots s ON s.timesheet_id = t.id
            LEFT JOIN projects p ON p.id = t.project_id
            WHERE t.user_id = :id AND t.entry_type = "work" AND t.is_deleted = 0 AND t.start_time IS NOT NULL AND t.end_time IS NULL
            ORDER BY t.id LIMIT 2', ['id' => $userId]);
        if (count($open) !== 1 || $open[0]['pause_policy_entry'] === null || $open[0]['work_date'] >= $today) {
            return null;
        }
        $entry = $open[0];
        unset($entry['pause_policy_entry']);
        $entry['breaks'] = $this->breaks((int) $entry['id']);
        $entry['current_break'] = $this->workday->currentBreak($entry['breaks']);
        $entry['break_assessment'] = $this->assessmentForDay($userId, $entry['work_date']);
        return $entry;
    }

    public function hasOpenModelBooking(int $userId): bool
    {
        return $this->connection->tableExists('timesheet_pause_policy_snapshots')
            && (int) $this->connection->fetchColumn('SELECT t.id FROM timesheet_pause_policy_snapshots s
                JOIN timesheets t ON t.id = s.timesheet_id WHERE t.user_id = :id AND t.entry_type = "work"
                AND t.start_time IS NOT NULL AND t.end_time IS NULL AND t.is_deleted = 0 LIMIT 1', ['id' => $userId]) > 0;
    }

    public function assessmentForDay(int $userId, string $date): array
    {
        return $this->assessmentsForPeriod($userId, $date, $date, true)[$date]
            ?? $this->assessment([], $this->configuredPolicy($userId, $date));
    }

    public function firstModelDayBefore(int $userId, string $date): ?string
    {
        $first = $this->connection->tableExists('timesheet_pause_policy_snapshots')
            ? $this->connection->fetchColumn('SELECT MIN(t.work_date) FROM timesheets t
                JOIN timesheet_pause_policy_snapshots p ON p.timesheet_id = t.id
                WHERE t.user_id = :user_id AND t.work_date < :date AND t.entry_type = "work" AND t.is_deleted = 0',
                ['user_id' => $userId, 'date' => $date]) : null;
        return is_string($first) ? $first : null;
    }

    /** Return the same day assessment for each selected, active work booking. */
    public function assessmentsForEntries(array $entries): array
    {
        $byUser = [];
        foreach ($entries as $entry) {
            if ($entry['entry_type'] === 'work' && empty($entry['is_deleted'])) {
                $byUser[$entry['user_id']][$entry['work_date']][] = (int) $entry['id'];
            }
        }

        $result = [];
        foreach ($byUser as $userId => $days) {
            $dates = array_keys($days);
            foreach ($this->assessmentsForPeriod((int) $userId, min($dates), max($dates), true) as $date => $assessment) {
                foreach ($days[$date] ?? [] as $id) {
                    $result[$id] = $assessment;
                }
            }
        }
        return $result;
    }

    /** By default only days with a booking activated under a pause model affect account checks. */
    public function assessmentsForPeriod(int $userId, string $from, string $to, bool $includeLegacy = false): array
    {
        if ($from > $to || !$this->available()) {
            return [];
        }
        $entries = $this->connection->fetchAll('SELECT id, work_date, start_time, end_time, break_minutes FROM timesheets
            WHERE user_id = :id AND work_date BETWEEN :from_date AND :to_date AND entry_type = "work" AND is_deleted = 0
            ORDER BY work_date, start_time, id', ['id' => $userId,
            'from_date' => (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'),
            'to_date' => (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'),
        ]);
        $ids = array_column($entries, 'id');
        $snapshots = $this->snapshots($ids);
        if (!$includeLegacy && $snapshots === []) {
            return [];
        }
        $breaks = $this->groupedBreaks($ids);
        $periods = [];
        $periodIndex = -1;
        $previousDate = $previousEnd = null;
        $now = new DateTimeImmutable();
        foreach ($entries as $entry) {
            $date = $entry['work_date'];
            $id = $entry['id'];
            $entry['breaks'] = $breaks[$id] ?? [];
            if (!isset($snapshots[$id])) {
                $entry['legacy_deduction_minutes'] = max(0, (int) $entry['break_minutes'] - $this->workday->completedBreakMinutes($entry['breaks']));
            }
            $start = $end = null;
            if (!empty($entry['start_time'])) {
                try {
                    [$start, $end] = PauseAssessmentCalculator::bounds($date, $entry['start_time'], $entry['end_time'], $now);
                } catch (InvalidArgumentException) {
                    // The assessment marks an invalid timestamp as unresolved.
                }
            }
            $hasBounds = $previousEnd !== null && $start !== null;
            $hasDailyRest = $hasBounds && $start - $previousEnd >= self::DAILY_REST_SECONDS;
            $continuesOvernight = $hasBounds && $previousDate !== null
                && $date === (new DateTimeImmutable($previousDate))->modify('+1 day')->format('Y-m-d');
            if ($hasDailyRest || ($date !== $previousDate && !$continuesOvernight)) {
                $periodIndex++;
                $previousEnd = null;
            }
            $periods[$periodIndex][] = $entry;
            $previousDate = $date;
            if ($end !== null) {
                $previousEnd = max($previousEnd ?? $end, $end);
            }
        }

        $out = [];
        foreach ($periods as $period) {
            $dates = array_values(array_unique(array_column($period, 'work_date')));
            $policy = null;
            foreach ($period as $entry) {
                if (isset($snapshots[$entry['id']])) {
                    $policy = $this->stricterPolicy($policy, $snapshots[$entry['id']]);
                }
            }
            if (!$includeLegacy && $policy === null) {
                continue;
            }
            $assessment = $this->assessment($period, $policy) + ['linked_work_dates' => $dates];
            foreach ($dates as $date) {
                if ($date >= $from && $date <= $to) {
                    $out[$date] = isset($out[$date]) ? $this->combineDayPeriods($out[$date], $assessment) : $assessment;
                }
            }
        }
        return $out;
    }

    private function combineDayPeriods(array $previous, array $current): array
    {
        $day = $current['legacy'] ? $previous : $current;
        foreach (['documented_break_minutes', 'qualifying_break_minutes', 'required_break_minutes',
            'missing_break_minutes', 'unplaced_break_minutes', 'legacy_deduction_minutes'] as $field) {
            $day[$field] = $previous[$field] + $current[$field];
        }
        $day['actual_work_minutes'] = isset($previous['actual_work_minutes'], $current['actual_work_minutes'])
            ? $previous['actual_work_minutes'] + $current['actual_work_minutes'] : null;
        $day['longest_uninterrupted_work_minutes'] = isset($previous['longest_uninterrupted_work_minutes'], $current['longest_uninterrupted_work_minutes'])
            ? max($previous['longest_uninterrupted_work_minutes'], $current['longest_uninterrupted_work_minutes']) : null;
        $day['continuous_work_limit_exceeded'] = $previous['continuous_work_limit_exceeded'] || $current['continuous_work_limit_exceeded'];
        $day['is_running'] = $previous['is_running'] || $current['is_running'];
        $statuses = [$previous['calculation_status'], $current['calculation_status']];
        $day['calculation_status'] = in_array('needs_clarification', $statuses, true) ? 'needs_clarification'
            : ($day['is_running'] ? 'pending' : 'resolved');
        $day['linked_work_dates'] = array_values(array_unique([...$previous['linked_work_dates'], ...$current['linked_work_dates']]));
        return $day;
    }

    private function stricterPolicy(?array $previous, array $current): array
    {
        if ($previous === null) {
            return $current;
        }
        foreach (['minimum_over_six_minutes', 'minimum_over_nine_minutes', 'additional_minutes'] as $field) {
            $current[$field] = max((int) ($previous[$field] ?? 0), (int) ($current[$field] ?? 0));
        }
        return $current;
    }

    private function assessment(array $entries, ?array $policy): array
    {
        return [
            ...$this->calculator->assess($entries, $policy ?? []),
            'legacy' => $policy === null,
            'capture_mode' => $policy['capture_mode'] ?? 'manual',
            'version_id' => $policy['version_id'] ?? null,
            'revision' => $policy['revision'] ?? null,
            'template_name' => $policy['template_name'] ?? null,
            'planned_windows' => $policy['planned_windows'] ?? [],
        ];
    }

    public function addDuration(int $timesheetId, int $actor, int $duration, string $source, ?string $note): void
    {
        if (!$this->connection->tableExists('timesheet_pause_durations')) {
            throw new InvalidArgumentException('Pausennachtraege sind noch nicht migriert.');
        }
        $this->connection->execute('INSERT INTO timesheet_pause_durations (timesheet_id, recorded_by_user_id, duration_minutes, source, note)
            VALUES (:entry, :actor, :duration, :source, :note)', ['entry' => $timesheetId, 'actor' => $actor, 'duration' => $duration, 'source' => $source, 'note' => $note]);
    }

    public function unplacedDurations(int $timesheetId): array
    {
        return $this->connection->tableExists('timesheet_pause_durations')
            ? $this->connection->fetchAll('SELECT id, duration_minutes, source, recorded_by_user_id, created_at FROM timesheet_pause_durations
                WHERE timesheet_id = :id AND placed_at IS NULL ORDER BY id', ['id' => $timesheetId]) : [];
    }

    public function markDurationsPlaced(int $timesheetId): void
    {
        $this->connection->execute('UPDATE timesheet_pause_durations SET placed_at = NOW() WHERE timesheet_id = :id AND placed_at IS NULL', ['id' => $timesheetId]);
    }

    public function addInterval(int $timesheetId, int $userId, string $start, ?string $end, string $source, ?string $note): void
    {
        $this->intervals->append($timesheetId, $userId, $start, $end, $source, $note);
    }

    public function validateEvidence(array $entry, array $breaks): void
    {
        $now = new DateTimeImmutable();
        [$begin, $end] = PauseAssessmentCalculator::bounds($entry['work_date'], $entry['start_time'], $entry['end_time'] ?? null, $now);
        $end = min($end, $now->getTimestamp());
        $seconds = 0;
        $intervals = [];
        foreach ($breaks as $break) {
            if (empty($break['break_started_at'])) {
                $seconds += (int) ($break['duration_minutes'] ?? 0) * 60;
                continue;
            }
            $from = WorkTimeLocalTime::fromInput($break['break_started_at'])->getTimestamp();
            $to = empty($break['break_ended_at']) ? $end : WorkTimeLocalTime::fromInput($break['break_ended_at'])->getTimestamp();
            if ($from < $begin || $to > $end || $from > $to || ($from === $to && !empty($break['break_ended_at']))) {
                throw new InvalidArgumentException('Die Pause muss im tatsaechlichen Arbeitseinsatz liegen.');
            }
            $intervals[] = [$from, $to];
            $seconds += $to - $from;
        }
        sort($intervals);
        $previousEnd = $begin;
        foreach ($intervals as [$from, $to]) {
            if ($from < $previousEnd) {
                throw new InvalidArgumentException('Die Pause ueberschneidet sich mit einem vorhandenen Pausennachweis.');
            }
            $previousEnd = $to;
        }
        if ($seconds > max(0, $end - $begin)) {
            throw new InvalidArgumentException('Die erfassten Pausen sind laenger als der Arbeitseinsatz.');
        }
    }

    private function snapshots(array $timesheetIds): array
    {
        $ids = $this->ids($timesheetIds);
        if ($ids === [] || !$this->connection->tableExists('timesheet_pause_policy_snapshots')) {
            return [];
        }
        $out = [];
        foreach ($this->connection->fetchAll('SELECT timesheet_id, policy_json FROM timesheet_pause_policy_snapshots
            WHERE timesheet_id IN (' . $this->placeholders($ids) . ')', $ids) as $row) {
            $out[$row['timesheet_id']] = json_decode($row['policy_json'], true, flags: JSON_THROW_ON_ERROR);
        }
        return $out;
    }

    private function ids(array $timesheetIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $timesheetIds), static fn (int $id): bool => $id > 0)));
    }

    private function placeholders(array $ids): string
    {
        return implode(', ', array_fill(0, count($ids), '?'));
    }
}
