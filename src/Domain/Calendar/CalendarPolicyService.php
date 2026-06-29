<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class CalendarPolicyService implements CalendarPolicyProvider
{
    /** @var array<string, string> */
    private const REGIONS = [
        '' => 'Keine automatische Feiertagsregel',
        'BW' => 'Baden-Wuerttemberg',
        'BY' => 'Bayern',
        'BE' => 'Berlin',
        'BB' => 'Brandenburg',
        'HB' => 'Bremen',
        'HH' => 'Hamburg',
        'HE' => 'Hessen',
        'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Niedersachsen',
        'NW' => 'Nordrhein-Westfalen',
        'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland',
        'SN' => 'Sachsen',
        'ST' => 'Sachsen-Anhalt',
        'SH' => 'Schleswig-Holstein',
        'TH' => 'Thueringen',
    ];

    public function __construct(private DatabaseConnection $connection)
    {
    }

    /**
     * @return array<string, string>
     */
    public function regions(): array
    {
        return self::REGIONS;
    }

    public function currentRegion(): string
    {
        if (!$this->connection->tableExists('company_settings')
            || !$this->connection->columnExists('company_settings', 'holiday_region')) {
            return '';
        }

        $region = strtoupper(trim((string) ($this->connection->fetchColumn(
            'SELECT holiday_region FROM company_settings WHERE id = 1 LIMIT 1'
        ) ?? '')));

        return array_key_exists($region, self::REGIONS) ? $region : '';
    }

    public function saveRegion(string $region): void
    {
        $region = strtoupper(trim($region));

        if (!array_key_exists($region, self::REGIONS)) {
            throw new InvalidArgumentException('Bitte ein gueltiges Bundesland auswaehlen.');
        }

        if (!$this->connection->tableExists('company_settings')
            || !$this->connection->columnExists('company_settings', 'holiday_region')) {
            throw new RuntimeException('Die Kalender-Settings sind noch nicht migriert.');
        }

        $this->ensureCompanySettingsRow();
        $this->connection->execute(
            'UPDATE company_settings SET holiday_region = :region, updated_at = NOW() WHERE id = 1',
            ['region' => $region === '' ? null : $region]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function closuresForYear(int $year, string $scope = 'active'): array
    {
        if (!$this->connection->tableExists('company_closures')) {
            return [];
        }

        $scopeClause = match ($scope) {
            'all' => '1 = 1',
            'archived' => 'is_deleted = 1',
            default => 'is_deleted = 0',
        };

        return array_map(
            fn (array $row): array => $this->normalizeClosureRow($row),
            $this->connection->fetchAll(
                'SELECT id, title, date_from, date_to, year, notes, created_at, updated_at, is_deleted, deleted_at
                 FROM company_closures
                 WHERE year = :year AND ' . $scopeClause . '
                 ORDER BY date_from ASC, date_to ASC, id ASC',
                ['year' => $year]
            )
        );
    }

    public function createClosure(array $payload): array
    {
        if (!$this->connection->tableExists('company_closures')) {
            throw new RuntimeException('Die Betriebsurlaub-Tabelle ist noch nicht migriert.');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $dateFrom = $this->normalizeDate((string) ($payload['date_from'] ?? ''));
        $dateTo = $this->normalizeDate((string) ($payload['date_to'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException('Bitte einen Titel fuer den Betriebsurlaub angeben.');
        }

        if ($dateTo < $dateFrom) {
            throw new InvalidArgumentException('Das Enddatum darf nicht vor dem Startdatum liegen.');
        }

        $notes = trim((string) ($payload['notes'] ?? ''));
        $this->connection->execute(
            'INSERT INTO company_closures (title, date_from, date_to, year, notes, created_at, updated_at, is_deleted, deleted_at, deleted_by_user_id)
             VALUES (:title, :date_from, :date_to, :year, :notes, NOW(), NOW(), 0, NULL, NULL)',
            [
                'title' => $title,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'year' => (int) $dateFrom->format('Y'),
                'notes' => $notes !== '' ? $notes : null,
            ]
        );

        return [
            'id' => $this->connection->lastInsertId(),
            'title' => $title,
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
            'year' => (int) $dateFrom->format('Y'),
            'notes' => $notes !== '' ? $notes : null,
            'is_deleted' => 0,
        ];
    }

    public function archiveClosure(int $id, ?int $userId = null): bool
    {
        if ($id <= 0 || !$this->connection->tableExists('company_closures')) {
            return false;
        }

        return $this->connection->execute(
            'UPDATE company_closures
             SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW()
             WHERE id = :id',
            [
                'id' => $id,
                'deleted_by_user_id' => $userId,
            ]
        );
    }

    public function requiresTimeTracking(string $date): bool
    {
        $policy = $this->dayPolicy($date);

        return (bool) ($policy['time_tracking_required'] ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    public function dayPolicy(string $date): array
    {
        $normalized = $this->normalizeDate($date);
        $publicHoliday = $this->publicHolidayForDate($normalized);
        $closures = $this->closuresForDate($normalized);
        $isClosure = $closures !== [];
        $isHoliday = $publicHoliday !== null;

        return [
            'date' => $normalized->format('Y-m-d'),
            'holiday_region' => $this->currentRegion(),
            'is_public_holiday' => $isHoliday,
            'holiday_name' => $publicHoliday['name'] ?? null,
            'is_company_closure' => $isClosure,
            'closure_titles' => array_map(static fn (array $closure): string => (string) $closure['title'], $closures),
            'closures' => $closures,
            'time_tracking_required' => !$isHoliday && !$isClosure,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    public function publicHolidayForDate(DateTimeImmutable|string $date, ?string $region = null): ?array
    {
        $date = $date instanceof DateTimeImmutable ? $date : $this->normalizeDate($date);
        $region = $region === null ? $this->currentRegion() : strtoupper(trim($region));

        if ($region === '' || !array_key_exists($region, self::REGIONS)) {
            return null;
        }

        return $this->publicHolidays((int) $date->format('Y'), $region)[$date->format('Y-m-d')] ?? null;
    }

    /**
     * @return array<string, array{name: string, region: string}>
     */
    public function publicHolidays(int $year, string $region): array
    {
        $region = strtoupper(trim($region));

        if ($region === '' || !array_key_exists($region, self::REGIONS)) {
            return [];
        }

        $easter = $this->easterSunday($year);
        $holidays = [
            $year . '-01-01' => 'Neujahr',
            $easter->modify('-2 days')->format('Y-m-d') => 'Karfreitag',
            $easter->modify('+1 day')->format('Y-m-d') => 'Ostermontag',
            $year . '-05-01' => 'Tag der Arbeit',
            $easter->modify('+39 days')->format('Y-m-d') => 'Christi Himmelfahrt',
            $easter->modify('+50 days')->format('Y-m-d') => 'Pfingstmontag',
            $year . '-10-03' => 'Tag der Deutschen Einheit',
            $year . '-12-25' => '1. Weihnachtstag',
            $year . '-12-26' => '2. Weihnachtstag',
        ];

        if (in_array($region, ['BW', 'BY', 'ST'], true)) {
            $holidays[$year . '-01-06'] = 'Heilige Drei Koenige';
        }

        if (in_array($region, ['BE', 'MV'], true)) {
            $holidays[$year . '-03-08'] = 'Internationaler Frauentag';
        }

        if (in_array($region, ['BW', 'BY', 'HE', 'NW', 'RP', 'SL'], true)) {
            $holidays[$easter->modify('+60 days')->format('Y-m-d')] = 'Fronleichnam';
        }

        if ($region === 'SL') {
            $holidays[$year . '-08-15'] = 'Mariae Himmelfahrt';
        }

        if ($region === 'TH') {
            $holidays[$year . '-09-20'] = 'Weltkindertag';
        }

        if (in_array($region, ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH'], true)) {
            $holidays[$year . '-10-31'] = 'Reformationstag';
        }

        if (in_array($region, ['BW', 'BY', 'NW', 'RP', 'SL'], true)) {
            $holidays[$year . '-11-01'] = 'Allerheiligen';
        }

        if ($region === 'SN') {
            $holidays[$this->repentanceDay($year)->format('Y-m-d')] = 'Buss- und Bettag';
        }

        ksort($holidays);

        return array_map(
            static fn (string $name): array => ['name' => $name, 'region' => $region],
            $holidays
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function closuresForDate(DateTimeImmutable $date): array
    {
        if (!$this->connection->tableExists('company_closures')) {
            return [];
        }

        return array_map(
            fn (array $row): array => $this->normalizeClosureRow($row),
	            $this->connection->fetchAll(
	                'SELECT id, title, date_from, date_to, year, notes, created_at, updated_at, is_deleted, deleted_at
	                 FROM company_closures
	                 WHERE is_deleted = 0
	                   AND date_from <= :date_from
	                   AND date_to >= :date_to
	                 ORDER BY date_from ASC, id ASC',
	                [
	                    'date_from' => $date->format('Y-m-d'),
	                    'date_to' => $date->format('Y-m-d'),
	                ]
	            )
	        );
	    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeClosureRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'date_from' => (string) ($row['date_from'] ?? ''),
            'date_to' => (string) ($row['date_to'] ?? ''),
            'year' => (int) ($row['year'] ?? 0),
            'notes' => trim((string) ($row['notes'] ?? '')) !== '' ? (string) $row['notes'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'is_deleted' => (int) ($row['is_deleted'] ?? 0),
            'deleted_at' => $row['deleted_at'] ?? null,
        ];
    }

    private function normalizeDate(string $date): DateTimeImmutable
    {
        $date = trim($date);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Bitte ein gueltiges Datum angeben.');
        }

        $normalized = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$normalized instanceof DateTimeImmutable
            || $normalized->format('Y-m-d') !== $date
            || !($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            throw new InvalidArgumentException('Bitte ein gueltiges Datum angeben.');
        }

        return $normalized;
    }

    private function ensureCompanySettingsRow(): void
    {
        $this->connection->execute(
            'INSERT IGNORE INTO company_settings (id, company_name, country, smtp_port, smtp_encryption, smtp_last_test_status, geo_capture_enabled, geo_requires_acknowledgement, created_at, updated_at)
             VALUES (1, "", "Deutschland", 587, "tls", "untested", 0, 0, NOW(), NOW())'
        );
    }

    private function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    private function repentanceDay(int $year): DateTimeImmutable
    {
        $christmas = new DateTimeImmutable($year . '-12-25');
        $fourthAdvent = $christmas->modify('last sunday');

        return $fourthAdvent->modify('-32 days');
    }
}
