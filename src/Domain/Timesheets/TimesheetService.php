<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use InvalidArgumentException;

final class TimesheetService
{
    private ?TimesheetPauseService $pauses = null;

    public function __construct(
        private DatabaseConnection $connection,
        private TimesheetCalculator $calculator
    ) {
    }

    public function setPauseService(TimesheetPauseService $service): void
    {
        $this->pauses = $service;
    }

    public function list(): array
    {
        if ($this->connection->tableExists('timesheets')) {
            $absenceReasonSelect = $this->connection->columnExists('timesheets', 'absence_reason_code')
                ? 'absence_reason_code'
                : 'NULL AS absence_reason_code';

            $rows = $this->connection->fetchAll(
                'SELECT id, user_id, project_id, work_date, start_time, end_time, break_minutes, net_minutes, entry_type, ' . $absenceReasonSelect . ', note, is_deleted FROM timesheets ORDER BY work_date DESC, start_time DESC LIMIT 50'
            );
            $assessments = $this->pauses?->assessmentsForEntries($rows) ?? [];

            return array_map(static function (array $row) use ($assessments): array {
                unset($row['is_deleted']);
                if (isset($assessments[$row['id']])) {
                    $row['break_assessment'] = $assessments[$row['id']];
                }
                return $row;
            }, $rows);
        }

        return [
            [
                'id' => 1,
                'user_id' => 1,
                'project_id' => 1,
                'work_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'start_time' => '07:00',
                'end_time' => '16:00',
                'break_minutes' => 45,
                'net_minutes' => 495,
                'entry_type' => 'work',
                'note' => 'Schalung und Materialannahme',
            ],
        ];
    }

    public function calculate(array $payload, ?int $userId = null): array
    {
        $date = (string) ($payload['work_date'] ?? (new DateTimeImmutable('today'))->format('Y-m-d'));
        $start = $payload['start_time'] ?? null;
        $end = $payload['end_time'] ?? null;
        $type = (string) ($payload['entry_type'] ?? 'work');
        $duration = $payload['manual_break_minutes'] ?? 0;
        $policy = $userId !== null && $type === 'work' ? $this->pauses?->configuredPolicy($userId, $date) : null;

        if ($policy === null) {
            return $this->calculator->calculate($date, $start, $end, (int) $duration, $type);
        }
        if (!is_string($start) || !is_string($end) || $start === '' || $end === '') {
            throw new InvalidArgumentException('Start- und Endzeit sind fuer Arbeitseintraege erforderlich.');
        }

        $minutes = filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]]);
        if ($minutes === false) {
            throw new InvalidArgumentException('Die dokumentierte Pausendauer muss in ganzen Minuten angegeben werden.');
        }
        $breaks = $minutes > 0 ? [['duration_minutes' => $minutes]] : [];
        $calculated = $this->calculator->calculateDocumented($date, $start, $end, $breaks);
        $assessment = (new PauseAssessmentCalculator())->assess([[
            'work_date' => $date, 'start_time' => $start, 'end_time' => $end, 'breaks' => $breaks,
        ]], $policy);

        return [
            'work_date' => $date, 'entry_type' => $type, ...$calculated,
            'net_hours' => round($calculated['net_minutes'] / 60, 2),
            'break_assessment' => $assessment,
        ];
    }

    public function reportRows(string $period): array
    {
        $rows = $this->list();
        $includeAssessment = (bool) array_filter($rows, static fn (array $row): bool => isset($row['break_assessment']));

        return array_map(
            static fn (array $row): array => [
                'Datum' => $row['work_date'],
                'Mitarbeiter' => 'User #' . $row['user_id'],
                'Projekt' => 'Projekt #' . $row['project_id'],
                'Start' => $row['start_time'],
                'Ende' => $row['end_time'],
                'Gespeicherte Pause (Min)' => $row['break_minutes'],
                'Netto (Min)' => $row['net_minutes'],
                'Typ' => self::semanticEntryType($row),
                'Bemerkung' => $row['note'],
                'Periode' => $period,
                ...(!$includeAssessment ? [] : [
                    'Dokumentierte Tagespause (Min)' => $row['break_assessment']['documented_break_minutes'] ?? '',
                    'Pflichtpause am Arbeitstag (Min)' => $row['break_assessment']['required_break_minutes'] ?? '',
                    'Fehlende Pflichtpause am Arbeitstag (Min)' => $row['break_assessment']['missing_break_minutes'] ?? '',
                    'Legacy-Pausenabzug ohne Nachweis (Min)' => $row['break_assessment']['legacy_deduction_minutes'] ?? '',
                ]),
            ],
            $rows
        );
    }

    private static function semanticEntryType(array $row): string
    {
        $entryType = (string) ($row['entry_type'] ?? '');

        if ($entryType === 'vacation' && (string) ($row['absence_reason_code'] ?? '') === 'unpaid_leave') {
            return 'absent';
        }

        return $entryType;
    }
}
