<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use LogicException;

/** Legacy booking writers cannot reinterpret an attested actual pause. */
final class TimesheetPauseEvidenceGuard
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    /**
     * Call before writing, inside the accounting lock and the write transaction.
     * Audit presence also protects evidence whose intervals were already lost by
     * an older version; disabling WorkTime must never disable this protection.
     */
    public function assertChangeAllowed(int $timesheetId, array $proposed, bool $changesBreakIntervals = false): void
    {
        if ($timesheetId <= 0 || !$this->hasEvidence($timesheetId)) {
            return;
        }
        if (!$this->connection->pdo()?->inTransaction()
            || (int) $this->connection->fetchColumn('SELECT IS_USED_LOCK("accounting-timesheet-write") = CONNECTION_ID()') !== 1) {
            throw new LogicException('Pause evidence validation requires the accounting lock and transaction.');
        }
        $before = $this->connection->fetchOne('SELECT * FROM timesheets WHERE id = :id FOR UPDATE', ['id' => $timesheetId]);
        if ($before === null) {
            throw new InvalidArgumentException('Die Buchung mit Pausennachweis wurde nicht gefunden. Bitte neu laden.');
        }
        $changed = $changesBreakIntervals;
        foreach (['user_id', 'work_date', 'start_time', 'end_time', 'gross_minutes', 'break_minutes', 'net_minutes', 'entry_type', 'source'] as $field) {
            if (!array_key_exists($field, $proposed)) {
                continue;
            }
            if ($this->value($field, $before[$field] ?? null) !== $this->value($field, $proposed[$field])) {
                $changed = true;
            }
        }
        if ($changed) {
            throw new InvalidArgumentException('Diese Buchung besitzt einen geschuetzten Pausennachweis. Pausenintervalle, Stempelzeiten, Dauer und Herkunft duerfen ueber diesen Buchungsweg nicht geaendert werden. Bitte den Nachweis im Admin pruefen; eine Korrektur benoetigt einen ausdruecklichen revisionsfaehigen Korrekturweg.');
        }
    }

    private function hasEvidence(int $timesheetId): bool
    {
        if ($this->connection->tableExists('work_time_pause_corrections')
            && $this->connection->fetchOne('SELECT id FROM work_time_pause_corrections WHERE timesheet_id = :id LIMIT 1', ['id' => $timesheetId]) !== null) {
            return true;
        }
        return $this->connection->tableExists('timesheet_breaks')
            && $this->connection->columnExists('timesheet_breaks', 'source')
            && $this->connection->fetchOne('SELECT id FROM timesheet_breaks WHERE timesheet_id = :id AND source = "admin_evidence" LIMIT 1', ['id' => $timesheetId]) !== null;
    }

    private function value(string $field, mixed $value): int|string|null
    {
        if (in_array($field, ['user_id', 'gross_minutes', 'break_minutes', 'net_minutes'], true)) {
            return (int) $value;
        }
        if (in_array($field, ['start_time', 'end_time'], true)) {
            if ($value === null || $value === '') {
                return null;
            }
            return strlen((string) $value) === 5 ? $value . ':00' : (string) $value;
        }
        return $value === null ? null : (string) $value;
    }
}
