<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class TimesheetWriteGuard
{
    private const ACCOUNTING_WRITE_LOCK = 'accounting-timesheet-write';

    public function __construct(private DatabaseConnection $connection)
    {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withAccountingWriteLock(callable $callback): mixed
    {
        $locked = (int) ($this->connection->fetchColumn('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => self::ACCOUNTING_WRITE_LOCK]) ?? 0);

        if ($locked !== 1) {
            throw new InvalidArgumentException('Die Abrechnung verarbeitet gerade Buchungen. Bitte erneut versuchen.');
        }

        try {
            return $callback();
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => self::ACCOUNTING_WRITE_LOCK]);
        }
    }

    public function assertAccountingPeriodOpen(int $userId, ?int $projectId, string $workDate): void
    {
        if ($userId <= 0
            || $workDate === ''
            || !$this->connection->tableExists('accounting_closures')) {
            return;
        }

        $lock = $this->connection->fetchOne(
            'SELECT closure_number, note
             FROM accounting_closures
             WHERE status IN ("final", "correction")
               AND period_start <= :work_date_start
               AND period_end >= :work_date_end
               AND (user_id IS NULL OR user_id = :user_id)
               AND (project_id IS NULL OR project_id = :project_id)
             ORDER BY id DESC
             LIMIT 1',
            [
                'work_date_start' => $workDate,
                'work_date_end' => $workDate,
                'user_id' => $userId,
                'project_id' => $projectId,
            ]
        );

        if ($lock !== null && array_key_exists('COUNT(*)', $lock) && (int) $lock['COUNT(*)'] <= 0) {
            $lock = null;
        }

        if ($lock !== null && str_starts_with((string) ($lock['closure_number'] ?? ''), 'ZK-')) {
            throw new InvalidArgumentException('Der gewaehlte Zeitraum ist wegen des Zeitkonto-Stichtags festgeschrieben. Korrekturen erfolgen ueber das Zeitkonto-Journal.');
        }

        if ($lock !== null) {
            throw new InvalidArgumentException('Der gewaehlte Zeitraum ist bereits festgeschrieben. Normale Aenderungen sind gesperrt.');
        }
    }

    /**
     * @param list<string> $workDates
     */
    public function assertAccountingPeriodsOpen(int $userId, array $workDates): void
    {
        foreach ($workDates as $workDate) {
            $this->assertAccountingPeriodOpen($userId, null, $workDate);
        }
    }
}
