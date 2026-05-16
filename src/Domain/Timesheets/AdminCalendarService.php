<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;

final class AdminCalendarService
{
    private ?array $activeUsers = null;

    public function __construct(
        private DatabaseConnection $connection,
        private AdminBookingService $bookingService
    ) {
    }

    public function month(string $monthInput = ''): array
    {
        $monthStart = $this->normalizeMonth($monthInput);
        $monthEnd = $monthStart->modify('last day of this month');
        $gridStart = $monthStart->modify('monday this week');
        $gridEnd = $monthEnd->modify('sunday this week');
        $bookings = $this->bookingService->list([
            'date_from' => $gridStart->format('Y-m-d'),
            'date_to' => $gridEnd->format('Y-m-d'),
            'project_id' => '',
            'user_id' => '',
            'entry_type' => '',
            'scope' => 'all',
        ]);
        $bookingsByDate = $this->groupBookingsByDate($bookings);
        $days = [];

        for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $dayBookings = $bookingsByDate[$date] ?? [];
            $summary = $this->summarizeDay($date, $dayBookings);
            $summary['is_current_month'] = $cursor->format('Y-m') === $monthStart->format('Y-m');
            $summary['weekday'] = (int) $cursor->format('N');
            $summary['day_number'] = (int) $cursor->format('j');
            $days[] = $summary;
        }

        return [
            'month' => $monthStart->format('Y-m'),
            'label' => $this->monthLabel($monthStart),
            'previous_month' => $monthStart->modify('-1 month')->format('Y-m'),
            'next_month' => $monthStart->modify('+1 month')->format('Y-m'),
            'today' => (new DateTimeImmutable())->format('Y-m-d'),
            'days' => $days,
            'totals' => $this->monthTotals($days),
        ];
    }

    public function day(string $dateInput = ''): array
    {
        $date = $this->normalizeDate($dateInput)->format('Y-m-d');
        $bookings = $this->bookingService->list([
            'date_from' => $date,
            'date_to' => $date,
            'project_id' => '',
            'user_id' => '',
            'entry_type' => '',
            'scope' => 'all',
        ]);

        return [
            'date' => $date,
            'label' => $this->dateLabel(new DateTimeImmutable($date)),
            'summary' => $this->summarizeDay($date, $bookings),
            'bookings' => $bookings,
            'assets' => $this->assetsForDay($date, $bookings),
        ];
    }

    public function normalizeMonth(string $monthInput = ''): DateTimeImmutable
    {
        $monthInput = trim($monthInput);

        if (!preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
            return new DateTimeImmutable('first day of this month');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $monthInput . '-01');
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && $date->format('Y-m') === $monthInput && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            return $date;
        }

        return new DateTimeImmutable('first day of this month');
    }

    public function normalizeDate(string $dateInput = ''): DateTimeImmutable
    {
        $dateInput = trim($dateInput);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput)) {
            return new DateTimeImmutable('today');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $dateInput && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            return $date;
        }

        return new DateTimeImmutable('today');
    }

    public function summarizeDay(string $date, array $bookings): array
    {
        $activeBookings = array_values(array_filter(
            $bookings,
            static fn (array $booking): bool => (int) ($booking['is_deleted'] ?? 0) === 0
        ));
        $activeWorkBookings = array_values(array_filter(
            $activeBookings,
            static fn (array $booking): bool => (string) ($booking['entry_type'] ?? 'work') === 'work'
        ));
        $activeAbsenceBookings = array_values(array_filter(
            $activeBookings,
            static fn (array $booking): bool => in_array((string) ($booking['entry_type'] ?? 'work'), ['sick', 'vacation', 'holiday', 'absent'], true)
        ));
        $absenceCounts = $this->absenceCounts($activeAbsenceBookings);
        $bookedUserIds = array_values(array_unique(array_filter(array_map(
            static fn (array $booking): ?int => isset($booking['user_id']) ? (int) $booking['user_id'] : null,
            $activeBookings
        ))));
        $missingCount = $this->missingCount($date, $bookedUserIds);
        $hasProblem = false;

        foreach ($bookings as $booking) {
            $entryType = (string) ($booking['entry_type'] ?? 'work');

            if ((int) ($booking['is_deleted'] ?? 0) === 1) {
                $hasProblem = true;
                break;
            }

            if ($entryType === 'work' && (trim((string) ($booking['start_time'] ?? '')) === '' || trim((string) ($booking['end_time'] ?? '')) === '')) {
                $hasProblem = true;
                break;
            }

            if ((bool) ($booking['needs_project_assignment'] ?? false)) {
                $hasProblem = true;
                break;
            }
        }

        $status = 'empty';
        $statusLabel = 'Keine Buchung';

        if ($hasProblem) {
            $status = 'issue';
            $statusLabel = 'Pruefen';
        } elseif ($missingCount > 0) {
            $status = 'missing';
            $statusLabel = 'Fehlend';
        } elseif ($activeAbsenceBookings !== [] && $activeWorkBookings === []) {
            $status = 'absence';
            $statusLabel = 'Abwesenheit';
        } elseif ($activeWorkBookings !== []) {
            $status = 'ok';
            $statusLabel = 'Sauber';
        }

        return [
            'date' => $date,
            'status' => $status,
            'status_label' => $statusLabel,
            'booking_count' => count($bookings),
            'active_booking_count' => count($activeBookings),
            'work_booking_count' => count($activeWorkBookings),
            'absence_count' => count($activeAbsenceBookings),
            'missing_count' => $missingCount,
            'sick_count' => $absenceCounts['sick'],
            'vacation_count' => $absenceCounts['vacation'],
            'holiday_count' => $absenceCounts['holiday'],
            'stored_absent_count' => $absenceCounts['absent'],
            'employee_count' => count(array_unique(array_filter(array_map(
                static fn (array $booking): ?int => isset($booking['user_id']) ? (int) $booking['user_id'] : null,
                $activeBookings
            )))),
            'net_minutes' => array_sum(array_map(
                static fn (array $booking): int => (int) ($booking['net_minutes'] ?? 0),
                $activeBookings
            )),
            'issue_count' => $hasProblem ? $this->issueCount($bookings) : 0,
        ];
    }

    private function assetsForDay(string $date, array $bookings): array
    {
        if ($bookings === [] || !$this->connection->tableExists('asset_assignments') || !$this->connection->tableExists('assets')) {
            return [];
        }

        $dayStart = $date . ' 00:00:00';
        $dayEnd = $date . ' 23:59:59';
        $projectIds = array_values(array_unique(array_filter(array_map(
            static fn (array $booking): ?int => isset($booking['project_id']) ? (int) $booking['project_id'] : null,
            $bookings
        ))));
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn (array $booking): ?int => isset($booking['user_id']) ? (int) $booking['user_id'] : null,
            $bookings
        ))));
        $clauses = [];
        $bindings = [
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
        ];

        foreach ($projectIds as $index => $projectId) {
            $key = 'project_id_' . $index;
            $bindings[$key] = $projectId;
            $clauses[] = 'asset_assignments.project_id = :' . $key;
        }

        foreach ($userIds as $index => $userId) {
            $key = 'user_id_' . $index;
            $bindings[$key] = $userId;
            $clauses[] = 'asset_assignments.user_id = :' . $key;
        }

        if ($clauses === []) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT
                assets.id,
                assets.asset_type,
                assets.name,
                assets.identifier,
                assets.status,
                asset_assignments.project_id,
                asset_assignments.user_id,
                asset_assignments.assigned_from,
                asset_assignments.assigned_until,
                asset_assignments.notes
             FROM asset_assignments
             INNER JOIN assets ON assets.id = asset_assignments.asset_id
             WHERE COALESCE(assets.is_deleted, 0) = 0
               AND asset_assignments.assigned_from <= :day_end
               AND (asset_assignments.assigned_until IS NULL OR asset_assignments.assigned_until >= :day_start)
               AND (' . implode(' OR ', $clauses) . ')
             ORDER BY assets.asset_type ASC, assets.name ASC',
            $bindings
        );
    }

    private function groupBookingsByDate(array $bookings): array
    {
        $grouped = [];

        foreach ($bookings as $booking) {
            $date = (string) ($booking['work_date'] ?? '');

            if ($date === '') {
                continue;
            }

            $grouped[$date][] = $booking;
        }

        return $grouped;
    }

    private function issueCount(array $bookings): int
    {
        $count = 0;

        foreach ($bookings as $booking) {
            $entryType = (string) ($booking['entry_type'] ?? 'work');
            $isIssue = (int) ($booking['is_deleted'] ?? 0) === 1
                || ($entryType === 'work' && (trim((string) ($booking['start_time'] ?? '')) === '' || trim((string) ($booking['end_time'] ?? '')) === ''))
                || (bool) ($booking['needs_project_assignment'] ?? false);

            if ($isIssue) {
                $count++;
            }
        }

        return $count;
    }

    private function monthTotals(array $days): array
    {
        $currentMonthDays = array_values(array_filter(
            $days,
            static fn (array $day): bool => (bool) ($day['is_current_month'] ?? false)
        ));

        return [
            'ok_days' => count(array_filter($currentMonthDays, static fn (array $day): bool => $day['status'] === 'ok')),
            'issue_days' => count(array_filter($currentMonthDays, static fn (array $day): bool => $day['status'] === 'issue')),
            'absence_days' => count(array_filter($currentMonthDays, static fn (array $day): bool => (int) ($day['absence_count'] ?? 0) > 0)),
            'missing_days' => count(array_filter($currentMonthDays, static fn (array $day): bool => (int) ($day['missing_count'] ?? 0) > 0)),
            'empty_days' => count(array_filter($currentMonthDays, static fn (array $day): bool => $day['status'] === 'empty')),
            'net_minutes' => array_sum(array_map(static fn (array $day): int => (int) ($day['net_minutes'] ?? 0), $currentMonthDays)),
        ];
    }

    private function absenceCounts(array $bookings): array
    {
        $counts = [
            'sick' => 0,
            'vacation' => 0,
            'holiday' => 0,
            'absent' => 0,
        ];

        foreach ($bookings as $booking) {
            $entryType = (string) ($booking['entry_type'] ?? '');

            if (array_key_exists($entryType, $counts)) {
                $counts[$entryType]++;
            }
        }

        return $counts;
    }

    private function missingCount(string $date, array $bookedUserIds): int
    {
        if (!$this->shouldDeriveMissing($date)) {
            return 0;
        }

        return count(array_diff($this->activeUserIds($date), $bookedUserIds));
    }

    private function activeUserIds(string $date): array
    {
        if ($this->activeUsers === null) {
            if (!$this->connection->tableExists('users')) {
                $this->activeUsers = [];
            } else {
                $timeTrackingSelect = $this->connection->columnExists('users', 'time_tracking_required')
                    ? 'COALESCE(time_tracking_required, 1)'
                    : '1';
                $this->activeUsers = $this->connection->fetchAll(
                    'SELECT id, created_at, ' . $timeTrackingSelect . ' AS time_tracking_required
                     FROM users
                     WHERE COALESCE(is_deleted, 0) = 0
                       AND employment_status = "active"
                     ORDER BY id ASC'
                );
            }
        }

        try {
            $dayEnd = new DateTimeImmutable($date . ' 23:59:59');
        } catch (\Exception) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static function (array $row) use ($dayEnd): int {
                if ((int) ($row['time_tracking_required'] ?? 1) !== 1) {
                    return 0;
                }

                $createdAt = trim((string) ($row['created_at'] ?? ''));

                if ($createdAt !== '') {
                    try {
                        if (new DateTimeImmutable($createdAt) > $dayEnd) {
                            return 0;
                        }
                    } catch (\Exception) {
                        return 0;
                    }
                }

                return (int) ($row['id'] ?? 0);
            },
            $this->activeUsers
        ))));
    }

    private function shouldDeriveMissing(string $dateInput): bool
    {
        try {
            $date = new DateTimeImmutable($dateInput);
        } catch (\Exception) {
            return false;
        }

        $today = new DateTimeImmutable('today');

        return (int) $date->format('N') <= 5 && $date <= $today;
    }

    private function monthLabel(DateTimeImmutable $date): string
    {
        $months = [
            1 => 'Januar',
            2 => 'Februar',
            3 => 'Maerz',
            4 => 'April',
            5 => 'Mai',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'August',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Dezember',
        ];

        return $months[(int) $date->format('n')] . ' ' . $date->format('Y');
    }

    private function dateLabel(DateTimeImmutable $date): string
    {
        $weekdays = [
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
            7 => 'Sonntag',
        ];

        return $weekdays[(int) $date->format('N')] . ', ' . $date->format('d.m.Y');
    }
}
