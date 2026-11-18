<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Domain\WorkTime\WorkTimeLocalTime;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class TimesheetPauseIntervals
{
    private ?bool $available = null;

    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function forTimesheet(int $timesheetId): array
    {
        return array_map(static fn (array $row): array => [
            'break_started_at' => $row['break_started_at'], 'break_ended_at' => $row['break_ended_at'],
        ], $this->rows([$timesheetId]));
    }

    public function rows(array $timesheetIds, bool $forUpdate = false): array
    {
        if ($timesheetIds === [] || !($this->available ??= $this->connection->tableExists('timesheet_breaks'))) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($timesheetIds), '?'));
        $rows = $this->connection->fetchAll('SELECT * FROM timesheet_breaks WHERE timesheet_id IN (' . $placeholders . ')
            ORDER BY break_started_at, id' . ($forUpdate ? ' FOR UPDATE' : ''), $timesheetIds);
        $folds = [];
        foreach ($rows as &$row) {
            if (isset($row['break_started_unix'])) {
                $folds[(int) $row['timesheet_id']] = true;
            }
            $row = self::restoreRow($row);
        }
        unset($row);

        if ($folds !== []) {
            // Across the time change wall-clock order is not chronological.
            $sort = [];
            foreach ($rows as $row) {
                if (!isset($folds[(int) $row['timesheet_id']])) {
                    continue;
                }
                $sort[$row['id']] = (new DateTimeImmutable($row['break_started_at'], new DateTimeZone('Europe/Berlin')))->getTimestamp();
            }
            usort($rows, static fn (array $a, array $b): int => [$a['timesheet_id'], $sort[$a['id']] ?? $a['break_started_at'], $a['id']]
                <=> [$b['timesheet_id'], $sort[$b['id']] ?? $b['break_started_at'], $b['id']]);
        }

        return $rows;
    }

    public function currentForTimesheet(int $timesheetId): ?array
    {
        $row = $this->connection->fetchOne('SELECT * FROM timesheet_breaks WHERE timesheet_id = :id
            AND break_ended_at IS NULL ORDER BY id DESC LIMIT 1', ['id' => $timesheetId]);
        if ($row === null) {
            return null;
        }

        $row = self::restoreRow($row);
        return ['id' => $row['id'], 'break_started_at' => $row['break_started_at'], 'break_ended_at' => null];
    }

    public function append(int $timesheetId, int $userId, string $start, ?string $end, string $source, ?string $note): void
    {
        $fields = [
            'timesheet_id' => $timesheetId, 'user_id' => $userId, 'source' => $source, 'note' => $note,
            ...$this->timestampFields('break_started', $start), ...$this->timestampFields('break_ended', $end),
        ];
        $columns = array_keys($fields);
        $this->connection->execute('INSERT INTO timesheet_breaks (' . implode(', ', $columns) . ')
            VALUES (:' . implode(', :', $columns) . ')', $fields);
    }

    public function close(int $id, string $end): void
    {
        $fields = $this->timestampFields('break_ended', $end);
        $updates = array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($fields));
        $this->connection->execute('UPDATE timesheet_breaks SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = :id',
            [...$fields, 'id' => $id]);
    }

    public static function forDisplay(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return WorkTimeLocalTime::fromInput($value)->format(DATE_ATOM);
        } catch (InvalidArgumentException) {
            // A legacy wall-clock stamp may be ambiguous. Preserve it without inventing an offset.
            return $value;
        }
    }

    private static function restore(?string $local, int|string|null $unix): ?string
    {
        return $unix === null ? $local : (new DateTimeImmutable('@' . $unix))->setTimezone(new DateTimeZone('Europe/Berlin'))->format(DATE_ATOM);
    }

    private static function restoreRow(array $row): array
    {
        $row['break_started_at'] = self::restore($row['break_started_at'], $row['break_started_unix'] ?? null);
        $row['break_ended_at'] = self::restore($row['break_ended_at'], $row['break_ended_unix'] ?? null);
        unset($row['break_started_unix'], $row['break_ended_unix']);
        return $row;
    }

    private function timestampFields(string $prefix, ?string $value): array
    {
        if ($value === null || strlen($value) === 19) {
            return [$prefix . '_at' => $value];
        }

        $instant = WorkTimeLocalTime::fromInput($value);
        $column = $prefix . '_unix';
        if (!$this->connection->columnExists('timesheet_breaks', $column)) {
            throw new InvalidArgumentException('Bitte zuerst die Migration für Pausen in der Zeitumstellung anwenden.');
        }

        return [$prefix . '_at' => $instant->format('Y-m-d H:i:s'), $column => $instant->getTimestamp()];
    }
}
