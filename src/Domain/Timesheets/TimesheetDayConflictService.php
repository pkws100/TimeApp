<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class TimesheetDayConflictService
{
    private const ABSENCE_TYPES = ['sick', 'vacation', 'holiday', 'absent'];

    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function assertNoConflictForWork(int $userId, string $workDate, ?int $excludeTimesheetId = null): void
    {
        $conflict = $this->firstActiveBooking($userId, $workDate, self::ABSENCE_TYPES, $excludeTimesheetId);

        if ($conflict !== null) {
            throw new InvalidArgumentException('Am gewaehlten Datum besteht bereits eine ganztaegige Abwesenheit. Arbeitsbuchungen sind an diesem Tag gesperrt.');
        }
    }

    /** Call under the accounting write lock, inside the transaction that writes the booking. */
    public function assertNoWorkOverlap(
        int $userId,
        string $workDate,
        ?string $startTime,
        ?string $endTime,
        ?int $excludeTimesheetId = null,
        ?DateTimeImmutable $now = null
    ): void {
        if ($startTime === null || $startTime === '') {
            return;
        }

        $zone = new DateTimeZone('Europe/Berlin');
        $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
        [$start, $end] = PauseAssessmentCalculator::bounds($workDate, $startTime, $endTime, $now);
        $open = $endTime === null || $endTime === '';
        if (!$open && $end === $start) {
            return;
        }

        $bindings = [
            'user_id' => $userId,
            'excluded_id' => $excludeTimesheetId ?? 0,
            'previous_date' => $now->setTimestamp($start)->modify('-1 day')->format('Y-m-d'),
            'candidate_start' => $now->setTimestamp($start)->format('Y-m-d H:i:s'),
            'live_reference_date' => LiveWorkSession::referenceDate($workDate, $now),
        ];
        $endingBefore = '';
        if (!$open) {
            $bindings['last_date'] = $now->setTimestamp($end)->format('Y-m-d');
            $bindings['candidate_end'] = $now->setTimestamp($end)->format('Y-m-d H:i:s');
            $endingBefore = ' AND t.work_date <= :last_date AND ' . TimesheetOverlap::startSql('t') . ' < :candidate_end';
        }

        $overlap = $this->connection->fetchOne(
            'SELECT t.id FROM timesheets t
             WHERE t.user_id = :user_id AND t.id <> :excluded_id
               AND t.entry_type = "work" AND COALESCE(t.is_deleted, 0) = 0 AND t.start_time IS NOT NULL
               AND ((t.end_time IS NULL AND ' . LiveWorkSession::isCurrentSql('t.user_id', 't.work_date') . ')
                    OR (t.end_time <> t.start_time AND t.work_date >= :previous_date
                        AND ' . TimesheetOverlap::endSql('t') . ' > :candidate_start))'
                . $endingBefore . ' LIMIT 1',
            $bindings
        );

        if ($overlap !== null) {
            throw new WorkSessionConflict(
                'overlapping_work_session',
                'Die Arbeitszeiten überschneiden sich mit einer anderen Buchung. Bitte prüfen und korrigieren Sie den Zeitraum.'
            );
        }
    }

    public function assertNoConflictForFullDayAbsence(int $userId, string $workDate, ?int $excludeTimesheetId = null): void
    {
        $conflict = $this->firstActiveBooking($userId, $workDate, ['work', ...self::ABSENCE_TYPES], $excludeTimesheetId);

        if ($conflict !== null) {
            $type = (string) ($conflict['entry_type'] ?? '');
            $message = $type === 'work'
                ? 'Am gewaehlten Datum besteht bereits eine Arbeitsbuchung. Ganztaegige Abwesenheiten sind dann nicht zulaessig.'
                : 'Am gewaehlten Datum besteht bereits eine ganztaegige Abwesenheit.';

            throw new InvalidArgumentException($message);
        }
    }

    /**
     * @param list<string> $dates
     */
    public function assertNoConflictsForVacationRequest(int $userId, array $dates, ?int $requestId = null): void
    {
        foreach ($dates as $date) {
            $this->assertNoConflictForFullDayAbsence($userId, $date, $this->timesheetIdForVacationRequest($requestId, $date));
        }
    }

    /**
     * @param list<string> $entryTypes
     */
    private function firstActiveBooking(int $userId, string $workDate, array $entryTypes, ?int $excludeTimesheetId): ?array
    {
        if ($userId <= 0 || $workDate === '' || !$this->connection->tableExists('timesheets')) {
            return null;
        }

        $placeholders = [];
        $bindings = [
            'user_id' => $userId,
            'work_date' => $workDate,
        ];

        foreach (array_values($entryTypes) as $index => $entryType) {
            $key = 'entry_type_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $entryType;
        }

        $excludeSql = '';

        if ($excludeTimesheetId !== null && $excludeTimesheetId > 0) {
            $excludeSql = ' AND id <> :exclude_id';
            $bindings['exclude_id'] = $excludeTimesheetId;
        }

        return $this->connection->fetchOne(
            'SELECT id, entry_type, work_date
             FROM timesheets
             WHERE user_id = :user_id
               AND work_date = :work_date
               AND entry_type IN (' . implode(', ', $placeholders) . ')
               AND COALESCE(is_deleted, 0) = 0' . $excludeSql . '
             ORDER BY id ASC
             LIMIT 1',
            $bindings
        );
    }

    private function timesheetIdForVacationRequest(?int $requestId, string $workDate): ?int
    {
        if ($requestId === null || $requestId <= 0 || !$this->connection->columnExists('timesheets', 'vacation_request_id')) {
            return null;
        }

        $row = $this->connection->fetchOne(
            'SELECT id
             FROM timesheets
             WHERE vacation_request_id = :request_id
               AND work_date = :work_date
               AND COALESCE(is_deleted, 0) = 0
             LIMIT 1',
            ['request_id' => $requestId, 'work_date' => $workDate]
        );

        return $row === null ? null : (int) ($row['id'] ?? 0);
    }
}
