<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;

final class TimesheetService
{
    public function __construct(
        private DatabaseConnection $connection,
        private TimesheetCalculator $calculator
    ) {
    }

    public function list(): array
    {
        if ($this->connection->tableExists('timesheets')) {
            return $this->connection->fetchAll(
                'SELECT id, user_id, project_id, work_date, start_time, end_time, break_minutes, net_minutes, entry_type, note FROM timesheets ORDER BY work_date DESC, start_time DESC LIMIT 50'
            );
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

    public function calculate(array $payload): array
    {
        return $this->calculator->calculate(
            (string) ($payload['work_date'] ?? (new \DateTimeImmutable('today'))->format('Y-m-d')),
            $payload['start_time'] ?? null,
            $payload['end_time'] ?? null,
            (int) ($payload['manual_break_minutes'] ?? 0),
            (string) ($payload['entry_type'] ?? 'work')
        );
    }

    public function reportRows(string $period): array
    {
        $rows = $this->list();

        return array_map(
            static fn (array $row): array => [
                'Datum' => $row['work_date'],
                'Mitarbeiter' => 'User #' . $row['user_id'],
                'Projekt' => 'Projekt #' . $row['project_id'],
                'Start' => $row['start_time'],
                'Ende' => $row['end_time'],
                'Pause (Min)' => $row['break_minutes'],
                'Netto (Min)' => $row['net_minutes'],
                'Typ' => $row['entry_type'],
                'Bemerkung' => $row['note'],
                'Periode' => $period,
            ],
            $rows
        );
    }
}

