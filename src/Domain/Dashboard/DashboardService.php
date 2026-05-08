<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Users\StorageUsageService;
use App\Infrastructure\Database\DatabaseConnection;

final class DashboardService
{
    public function __construct(
        private DatabaseConnection $connection,
        private StorageUsageService $storageUsageService,
        private AttendanceService $attendanceService
    ) {
    }

    public function overview(): array
    {
        $storage = $this->storageUsageService->usage();
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $hasDatabase = $this->connection->isAvailable();
        $hasTimesheets = $this->connection->tableExists('timesheets');
        $hasUsers = $this->connection->tableExists('users');

        if ($hasTimesheets && $hasUsers) {
            $attendance = $this->attendanceService->todaySummary($today);
            $allocations = [];

            foreach ($attendance['present'] as $person) {
                $location = (string) ($person['location'] ?? 'Nicht zugeordnet');

                if (!isset($allocations[$location])) {
                    $allocations[$location] = [
                        'project' => $location,
                        'people' => 0,
                    ];
                }

                $allocations[$location]['people']++;
            }

            $periods = $this->periodOverviews();
            $hasLiveData = (int) $attendance['present_count'] > 0
                || array_sum($attendance['status_counts']) > 0
                || array_sum(array_map(static fn (array $period): int => (int) ($period['entries'] ?? 0), $periods)) > 0;

            return [
                'status' => 'database',
                'message' => $hasLiveData
                    ? 'Live-Daten aus Zeiterfassung und Anwesenheit sind aktiv.'
                    : 'Es liegen aktuell noch keine belastbaren Buchungen fuer das Dashboard vor.',
                'today' => $today,
                'metrics' => [
                    'anwesend' => (int) $attendance['present_count'],
                    'abwesend' => array_sum($attendance['status_counts']),
                    'storage' => $storage['human'],
                    'db_status' => $hasDatabase ? 'verbunden' : 'nicht verbunden',
                ],
                'allocations' => array_values($allocations),
                'absences' => array_map(
                    static fn (array $status): array => [
                        'name' => (string) ($status['user_name'] ?? ''),
                        'type' => (string) ($status['entry_type'] ?? ''),
                    ],
                    $attendance['statuses']
                ),
                'contacts' => [],
                'periods' => $periods,
            ];
        }

        return [
            'status' => 'empty',
            'message' => $hasDatabase
                ? 'Das Dashboard wartet noch auf belastbare Tabellen- und Buchungsdaten.'
                : 'Die Datenbank ist aktuell nicht verbunden. Es koennen noch keine Live-Daten angezeigt werden.',
            'today' => $today,
            'metrics' => [
                'anwesend' => 0,
                'abwesend' => 0,
                'storage' => $storage['human'],
                'db_status' => $hasDatabase ? 'wartet auf Migration' : 'nicht verbunden',
            ],
            'allocations' => [],
            'absences' => [],
            'contacts' => [],
            'periods' => $this->periodOverviews(),
        ];
    }

    public function charts(string $period = 'month'): array
    {
        $period = $this->normalizePeriod($period);

        if ($this->connection->tableExists('timesheets') && $this->connection->tableExists('users')) {
            $rows = $this->chartRows($period);
            $charts = $this->buildChartsFromRows($rows, $period);
            $charts['status'] = $rows === [] ? 'empty' : 'database';
            $charts['message'] = $rows === []
                ? 'Fuer den gewaehlten Zeitraum liegen noch keine belastbaren Buchungen vor.'
                : 'Live-Daten fuer die Dashboard-Grafik wurden geladen.';

            return $charts;
        }

        return $this->fallbackCharts($period);
    }

    public function buildChartsFromRows(array $rows, string $period): array
    {
        $buckets = $this->chartBuckets($period);
        $presentSets = [];
        $absenceSets = [];
        $hours = array_fill_keys(array_keys($buckets), 0.0);
        $entryTypeCounts = ['work' => 0, 'sick' => 0, 'vacation' => 0, 'holiday' => 0, 'absent' => 0];
        $projectAllocations = [];

        foreach ($rows as $row) {
            $bucketKey = $this->bucketKey((string) ($row['work_date'] ?? ''), $period);

            if (!isset($buckets[$bucketKey])) {
                continue;
            }

            $userId = (int) ($row['user_id'] ?? 0);
            $entryType = (string) ($row['entry_type'] ?? '');

            if ($entryType === 'work') {
                $presentSets[$bucketKey][$userId] = true;
                $hours[$bucketKey] += ((int) ($row['net_minutes'] ?? 0)) / 60;

                $projectName = trim((string) ($row['project_name'] ?? 'Nicht zugeordnet'));
                $projectName = $projectName !== '' ? $projectName : 'Nicht zugeordnet';
                $projectAllocations[$projectName][$userId] = true;
            } else {
                $absenceSets[$bucketKey][$userId] = true;
            }

            if (array_key_exists($entryType, $entryTypeCounts)) {
                $entryTypeCounts[$entryType]++;
            }
        }

        $labels = array_values($buckets);
        $headcountPresent = [];
        $headcountAbsence = [];
        $hourValues = [];

        foreach (array_keys($buckets) as $bucketKey) {
            $headcountPresent[] = isset($presentSets[$bucketKey]) ? count($presentSets[$bucketKey]) : 0;
            $headcountAbsence[] = isset($absenceSets[$bucketKey]) ? count($absenceSets[$bucketKey]) : 0;
            $hourValues[] = round($hours[$bucketKey], 2);
        }

        arsort($projectAllocations);
        $topProjects = array_slice($projectAllocations, 0, 6, true);

        return [
            'period' => $period,
            'headcount' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Anwesenheit',
                        'data' => $headcountPresent,
                    ],
                    [
                        'label' => 'Abwesenheit',
                        'data' => $headcountAbsence,
                    ],
                ],
            ],
            'hours' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Nettoarbeitsstunden',
                        'data' => $hourValues,
                    ],
                ],
            ],
            'entry_types' => [
                'labels' => ['Arbeit', 'Krank', 'Urlaub', 'Feiertag', 'Fehlt'],
                'datasets' => [
                    [
                        'label' => 'Eintraege',
                        'data' => [
                            $entryTypeCounts['work'],
                            $entryTypeCounts['sick'],
                            $entryTypeCounts['vacation'],
                            $entryTypeCounts['holiday'],
                            $entryTypeCounts['absent'],
                        ],
                    ],
                ],
            ],
            'project_allocations' => [
                'labels' => array_keys($topProjects),
                'datasets' => [
                    [
                        'label' => 'Personen auf Projekten',
                        'data' => array_map(static fn (array $users): int => count($users), array_values($topProjects)),
                    ],
                ],
            ],
        ];
    }

    private function periodOverviews(): array
    {
        return [
            'day' => $this->periodOverview('day', 'Tag'),
            'week' => $this->periodOverview('week', 'Woche'),
            'month' => $this->periodOverview('month', 'Monat'),
            'year' => $this->periodOverview('year', 'Jahr'),
        ];
    }

    private function periodOverview(string $period, string $label): array
    {
        if (!$this->connection->tableExists('timesheets') || !$this->connection->tableExists('users')) {
            return ['label' => $label, 'entries' => 0, 'hours' => 0.0];
        }

        $range = $this->periodRange($period);
        $summary = $this->connection->fetchOne(
            'SELECT
                COUNT(*) AS entries,
                COALESCE(SUM(CASE WHEN timesheets.entry_type = "work" THEN timesheets.net_minutes ELSE 0 END), 0) AS net_minutes
             FROM timesheets
             INNER JOIN users ON users.id = timesheets.user_id
             WHERE timesheets.work_date BETWEEN :start AND :end
               AND COALESCE(timesheets.is_deleted, 0) = 0
               AND COALESCE(users.is_deleted, 0) = 0',
            [
                'start' => $range['start']->format('Y-m-d'),
                'end' => $range['end']->format('Y-m-d'),
            ]
        );

        $entries = (int) ($summary['entries'] ?? 0);
        $hours = round(((int) ($summary['net_minutes'] ?? 0)) / 60, 2);

        return ['label' => $label, 'entries' => $entries, 'hours' => $hours];
    }

    private function chartRows(string $period): array
    {
        $range = $this->periodRange($period);

        return $this->connection->fetchAll(
            'SELECT
                timesheets.work_date,
                timesheets.user_id,
                timesheets.entry_type,
                timesheets.net_minutes,
                projects.name AS project_name
             FROM timesheets
             INNER JOIN users ON users.id = timesheets.user_id
             LEFT JOIN projects ON projects.id = timesheets.project_id
             WHERE timesheets.work_date BETWEEN :start AND :end
               AND COALESCE(timesheets.is_deleted, 0) = 0
               AND COALESCE(users.is_deleted, 0) = 0',
            [
                'start' => $range['start']->format('Y-m-d'),
                'end' => $range['end']->format('Y-m-d'),
            ]
        );
    }

    private function fallbackCharts(string $period): array
    {
        return [
            'period' => $period,
            'status' => 'empty',
            'message' => 'Die Dashboard-Grafik wartet noch auf belastbare Daten aus der Zeiterfassung.',
            'headcount' => [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Anwesenheit',
                        'data' => [],
                    ],
                    [
                        'label' => 'Abwesenheit',
                        'data' => [],
                    ],
                ],
            ],
            'hours' => [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Nettoarbeitsstunden',
                        'data' => [],
                    ],
                ],
            ],
            'entry_types' => [
                'labels' => ['Arbeit', 'Krank', 'Urlaub', 'Feiertag', 'Fehlt'],
                'datasets' => [
                    [
                        'label' => 'Eintraege',
                        'data' => [0, 0, 0, 0, 0],
                    ],
                ],
            ],
            'project_allocations' => [
                'labels' => [],
                'datasets' => [
                    [
                        'label' => 'Personen auf Projekten',
                        'data' => [],
                    ],
                ],
            ],
        ];
    }

    private function chartBuckets(string $period): array
    {
        $range = $this->periodRange($period);
        $buckets = [];

        if ($period === 'year') {
            $cursor = $range['start'];

            while ($cursor <= $range['end']) {
                $buckets[$cursor->format('Y-m')] = $cursor->format('M');
                $cursor = $cursor->modify('+1 month');
            }

            return $buckets;
        }

        $cursor = $range['start'];

        while ($cursor <= $range['end']) {
            $buckets[$cursor->format('Y-m-d')] = $cursor->format('d.m.');
            $cursor = $cursor->modify('+1 day');
        }

        return $buckets;
    }

    private function bucketKey(string $date, string $period): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date) ?: new \DateTimeImmutable($date);

        return $period === 'year' ? $parsed->format('Y-m') : $parsed->format('Y-m-d');
    }

    private function periodRange(string $period): array
    {
        $today = new \DateTimeImmutable('today');

        return match ($period) {
            'day' => ['start' => $today, 'end' => $today],
            'week' => ['start' => $today->modify('monday this week'), 'end' => $today->modify('sunday this week')],
            'year' => [
                'start' => new \DateTimeImmutable($today->format('Y-01-01')),
                'end' => new \DateTimeImmutable($today->format('Y-12-31')),
            ],
            default => [
                'start' => new \DateTimeImmutable($today->format('Y-m-01')),
                'end' => new \DateTimeImmutable($today->format('Y-m-t')),
            ],
        };
    }

    private function normalizePeriod(string $period): string
    {
        return in_array($period, ['day', 'week', 'month', 'year'], true) ? $period : 'month';
    }
}
