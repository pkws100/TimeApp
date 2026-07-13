<?php

declare(strict_types=1);

namespace App\Domain\TimeAccounts;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class AccountJournalService
{
    private const TIME_TYPES_REQUIRING_REASON = ['manual_adjustment', 'payout', 'time_off_compensation', 'reversal'];
    private const VACATION_TYPES_REQUIRING_REASON = ['manual_adjustment', 'expiry', 'reversal'];

    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function addTimeEntry(
        int $userId,
        string $effectiveDate,
        int $minutes,
        string $entryType,
        ?string $sourceType,
        ?int $sourceId,
        string $description,
        ?int $createdByUserId,
        ?int $approvedByUserId = null,
        ?int $reversalOfId = null
    ): int {
        if (!$this->connection->tableExists('time_account_entries')) {
            throw new InvalidArgumentException('Das Zeitkonto-Journal ist noch nicht migriert.');
        }

        $description = trim($description);

        if ($description === '' && in_array($entryType, self::TIME_TYPES_REQUIRING_REASON, true)) {
            throw new InvalidArgumentException('Bitte eine Begruendung fuer die Zeitkonto-Buchung angeben.');
        }

        $this->connection->execute(
            'INSERT INTO time_account_entries (
                user_id, effective_date, minutes, entry_type, source_type, source_id, description, created_by_user_id, approved_by_user_id, reversal_of_id, created_at
             ) VALUES (
                :user_id, :effective_date, :minutes, :entry_type, :source_type, :source_id, :description, :created_by_user_id, :approved_by_user_id, :reversal_of_id, NOW()
             )',
            [
                'user_id' => $userId,
                'effective_date' => $effectiveDate,
                'minutes' => $minutes,
                'entry_type' => $entryType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'created_by_user_id' => $createdByUserId,
                'approved_by_user_id' => $approvedByUserId,
                'reversal_of_id' => $reversalOfId,
            ]
        );

        return $this->connection->lastInsertId();
    }

    public function addVacationEntry(
        int $userId,
        int $leaveYear,
        string $effectiveDate,
        float $days,
        string $entryType,
        ?string $sourceType,
        ?int $sourceId,
        string $description,
        ?int $createdByUserId,
        ?int $approvedByUserId = null,
        ?int $reversalOfId = null
    ): int {
        if (!$this->connection->tableExists('vacation_account_entries')) {
            throw new InvalidArgumentException('Das Urlaubskonto-Journal ist noch nicht migriert.');
        }

        $description = trim($description);

        if ($description === '' && in_array($entryType, self::VACATION_TYPES_REQUIRING_REASON, true)) {
            throw new InvalidArgumentException('Bitte eine Begruendung fuer die Urlaubskonto-Buchung angeben.');
        }

        $this->connection->execute(
            'INSERT INTO vacation_account_entries (
                user_id, leave_year, effective_date, days, entry_type, source_type, source_id, description, created_by_user_id, approved_by_user_id, reversal_of_id, created_at
             ) VALUES (
                :user_id, :leave_year, :effective_date, :days, :entry_type, :source_type, :source_id, :description, :created_by_user_id, :approved_by_user_id, :reversal_of_id, NOW()
             )',
            [
                'user_id' => $userId,
                'leave_year' => $leaveYear,
                'effective_date' => $effectiveDate,
                'days' => number_format($days, 2, '.', ''),
                'entry_type' => $entryType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'created_by_user_id' => $createdByUserId,
                'approved_by_user_id' => $approvedByUserId,
                'reversal_of_id' => $reversalOfId,
            ]
        );

        return $this->connection->lastInsertId();
    }

    public function reverseTimeEntry(int $entryId, int $adminUserId, string $reason, ?string $effectiveDate = null, bool $allowCutoverOwned = false): int
    {
        $entry = $this->timeEntry($entryId);

        if ($entry === null) {
            throw new InvalidArgumentException('Die Zeitkonto-Buchung wurde nicht gefunden.');
        }

        if (!$allowCutoverOwned && (string) ($entry['source_type'] ?? '') === 'employee_account_cutover') {
            throw new InvalidArgumentException('Eroeffnungsbuchungen eines Stichtags koennen nur ueber die Stichtags-Revidierung ausgeglichen werden.');
        }

        $this->assertNotAlreadyReversed('time_account_entries', $entryId);

        return $this->addTimeEntry(
            (int) $entry['user_id'],
            $effectiveDate ?: (string) $entry['effective_date'],
            -1 * (int) $entry['minutes'],
            'reversal',
            'time_account_entry',
            $entryId,
            $reason,
            $adminUserId,
            $adminUserId,
            $entryId
        );
    }

    public function reverseVacationEntry(int $entryId, int $adminUserId, string $reason, ?string $effectiveDate = null, bool $allowCutoverOwned = false): int
    {
        $entry = $this->vacationEntry($entryId);

        if ($entry === null) {
            throw new InvalidArgumentException('Die Urlaubskonto-Buchung wurde nicht gefunden.');
        }

        if (!$allowCutoverOwned && (string) ($entry['source_type'] ?? '') === 'employee_account_cutover') {
            throw new InvalidArgumentException('Eroeffnungsbuchungen eines Stichtags koennen nur ueber die Stichtags-Revidierung ausgeglichen werden.');
        }

        $this->assertNotAlreadyReversed('vacation_account_entries', $entryId);

        return $this->addVacationEntry(
            (int) $entry['user_id'],
            (int) $entry['leave_year'],
            $effectiveDate ?: (string) $entry['effective_date'],
            -1 * (float) $entry['days'],
            'reversal',
            'vacation_account_entry',
            $entryId,
            $reason,
            $adminUserId,
            $adminUserId,
            $entryId
        );
    }

    public function timeEntry(int $entryId): ?array
    {
        if ($entryId <= 0 || !$this->connection->tableExists('time_account_entries')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM time_account_entries WHERE id = :id LIMIT 1',
            ['id' => $entryId]
        );
    }

    public function vacationEntry(int $entryId): ?array
    {
        if ($entryId <= 0 || !$this->connection->tableExists('vacation_account_entries')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT * FROM vacation_account_entries WHERE id = :id LIMIT 1',
            ['id' => $entryId]
        );
    }

    public function timeEntriesForUser(int $userId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        if ($userId <= 0 || !$this->connection->tableExists('time_account_entries')) {
            return [];
        }

        $clauses = ['time_account_entries.user_id = :user_id'];
        $bindings = ['user_id' => $userId];

        if ($dateFrom !== null) {
            $clauses[] = 'time_account_entries.effective_date >= :date_from';
            $bindings['date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $clauses[] = 'time_account_entries.effective_date <= :date_to';
            $bindings['date_to'] = $dateTo;
        }

        return $this->connection->fetchAll(
            'SELECT time_account_entries.*, creators.first_name AS creator_first_name, creators.last_name AS creator_last_name
             FROM time_account_entries
             LEFT JOIN users AS creators ON creators.id = time_account_entries.created_by_user_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY time_account_entries.effective_date DESC, time_account_entries.id DESC',
            $bindings
        );
    }

    public function vacationEntriesForUser(int $userId, ?int $leaveYear = null): array
    {
        if ($userId <= 0 || !$this->connection->tableExists('vacation_account_entries')) {
            return [];
        }

        $clauses = ['vacation_account_entries.user_id = :user_id'];
        $bindings = ['user_id' => $userId];

        if ($leaveYear !== null) {
            $clauses[] = 'vacation_account_entries.leave_year = :leave_year';
            $bindings['leave_year'] = $leaveYear;
        }

        return $this->connection->fetchAll(
            'SELECT vacation_account_entries.*, creators.first_name AS creator_first_name, creators.last_name AS creator_last_name
             FROM vacation_account_entries
             LEFT JOIN users AS creators ON creators.id = vacation_account_entries.created_by_user_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY vacation_account_entries.effective_date DESC, vacation_account_entries.id DESC',
            $bindings
        );
    }

    public function timeSum(int $userId, ?string $dateFrom, ?string $dateTo): int
    {
        if ($userId <= 0 || !$this->connection->tableExists('time_account_entries')) {
            return 0;
        }

        $clauses = ['user_id = :user_id'];
        $bindings = ['user_id' => $userId];

        if ($dateFrom !== null) {
            $clauses[] = 'effective_date >= :date_from';
            $bindings['date_from'] = $dateFrom;
        }

        if ($dateTo !== null) {
            $clauses[] = 'effective_date <= :date_to';
            $bindings['date_to'] = $dateTo;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COALESCE(SUM(minutes), 0) FROM time_account_entries WHERE ' . implode(' AND ', $clauses),
            $bindings
        ) ?? 0);
    }

    public function vacationSum(int $userId, int $leaveYear): float
    {
        if ($userId <= 0 || !$this->connection->tableExists('vacation_account_entries')) {
            return 0.0;
        }

        return (float) ($this->connection->fetchColumn(
            'SELECT COALESCE(SUM(days), 0) FROM vacation_account_entries WHERE user_id = :user_id AND leave_year = :leave_year',
            ['user_id' => $userId, 'leave_year' => $leaveYear]
        ) ?? 0);
    }

    private function assertNotAlreadyReversed(string $table, int $entryId): void
    {
        $count = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE reversal_of_id = :entry_id',
            ['entry_id' => $entryId]
        ) ?? 0);

        if ($count > 0) {
            throw new InvalidArgumentException('Diese Buchung wurde bereits ausgeglichen.');
        }
    }
}
