<?php

declare(strict_types=1);

namespace App\Domain\TimeAccounts;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class AccountJournalService
{
    private const TIME_TYPES_REQUIRING_REASON = ['manual_adjustment', 'payout', 'time_off_compensation', 'reversal'];
    private const VACATION_TYPES_REQUIRING_REASON = ['manual_adjustment', 'expiry', 'reversal'];
    private const TIME_TYPES = ['opening_balance', 'manual_adjustment', 'payout', 'time_off_compensation', 'reversal'];
    private const VACATION_TYPES = ['annual_entitlement', 'carryover', 'opening_adjustment', 'manual_adjustment', 'expiry', 'reversal'];

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
        ?int $reversalOfId = null,
        ?int $cutoverId = null
    ): int {
        if (!$this->connection->tableExists('time_account_entries')) {
            throw new InvalidArgumentException('Das Zeitkonto-Journal ist noch nicht migriert.');
        }

        $description = trim($description);
        $this->assertValidTimeEntry($userId, $effectiveDate, $minutes, $entryType, $description, $reversalOfId, $cutoverId);

        if ($description === '' && in_array($entryType, self::TIME_TYPES_REQUIRING_REASON, true)) {
            throw new InvalidArgumentException('Bitte eine Begruendung fuer die Zeitkonto-Buchung angeben.');
        }

        $cutoverColumn = $this->connection->columnExists('time_account_entries', 'cutover_id') ? 'cutover_id, ' : '';
        $cutoverValue = $this->connection->columnExists('time_account_entries', 'cutover_id') ? ':cutover_id, ' : '';
        $bindings = [
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
        ];

        if ($cutoverColumn !== '') {
            $bindings['cutover_id'] = $cutoverId;
        }

        $this->connection->execute(
            'INSERT INTO time_account_entries (
                user_id, ' . $cutoverColumn . 'effective_date, minutes, entry_type, source_type, source_id, description, created_by_user_id, approved_by_user_id, reversal_of_id, created_at
             ) VALUES (
                :user_id, ' . $cutoverValue . ':effective_date, :minutes, :entry_type, :source_type, :source_id, :description, :created_by_user_id, :approved_by_user_id, :reversal_of_id, NOW()
             )',
            $bindings
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
        ?int $reversalOfId = null,
        ?int $cutoverId = null
    ): int {
        if (!$this->connection->tableExists('vacation_account_entries')) {
            throw new InvalidArgumentException('Das Urlaubskonto-Journal ist noch nicht migriert.');
        }

        $description = trim($description);
        $this->assertValidVacationEntry($userId, $leaveYear, $effectiveDate, $days, $entryType, $description, $reversalOfId, $cutoverId);

        if ($description === '' && in_array($entryType, self::VACATION_TYPES_REQUIRING_REASON, true)) {
            throw new InvalidArgumentException('Bitte eine Begruendung fuer die Urlaubskonto-Buchung angeben.');
        }

        $cutoverColumn = $this->connection->columnExists('vacation_account_entries', 'cutover_id') ? 'cutover_id, ' : '';
        $cutoverValue = $this->connection->columnExists('vacation_account_entries', 'cutover_id') ? ':cutover_id, ' : '';
        $bindings = [
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
        ];

        if ($cutoverColumn !== '') {
            $bindings['cutover_id'] = $cutoverId;
        }

        $this->connection->execute(
            'INSERT INTO vacation_account_entries (
                user_id, ' . $cutoverColumn . 'leave_year, effective_date, days, entry_type, source_type, source_id, description, created_by_user_id, approved_by_user_id, reversal_of_id, created_at
             ) VALUES (
                :user_id, ' . $cutoverValue . ':leave_year, :effective_date, :days, :entry_type, :source_type, :source_id, :description, :created_by_user_id, :approved_by_user_id, :reversal_of_id, NOW()
             )',
            $bindings
        );

        return $this->connection->lastInsertId();
    }

    public function reverseTimeEntry(int $entryId, int $adminUserId, string $reason, ?string $effectiveDate = null, bool $allowCutoverOwned = false): int
    {
        $entry = $this->timeEntry($entryId);

        if ($entry === null) {
            throw new InvalidArgumentException('Die Zeitkonto-Buchung wurde nicht gefunden.');
        }

        if ((string) ($entry['entry_type'] ?? '') === 'reversal') {
            throw new InvalidArgumentException('Gegenbuchungen koennen nicht erneut ausgeglichen werden.');
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
            $entryId,
            isset($entry['cutover_id']) ? (int) $entry['cutover_id'] : null
        );
    }

    public function reverseVacationEntry(int $entryId, int $adminUserId, string $reason, ?string $effectiveDate = null, bool $allowCutoverOwned = false): int
    {
        $entry = $this->vacationEntry($entryId);

        if ($entry === null) {
            throw new InvalidArgumentException('Die Urlaubskonto-Buchung wurde nicht gefunden.');
        }

        if ((string) ($entry['entry_type'] ?? '') === 'reversal') {
            throw new InvalidArgumentException('Gegenbuchungen koennen nicht erneut ausgeglichen werden.');
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
            $entryId,
            isset($entry['cutover_id']) ? (int) $entry['cutover_id'] : null
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

    public function timeEntriesForUser(int $userId, ?string $dateFrom = null, ?string $dateTo = null, ?int $cutoverId = null, int $limit = 100, int $offset = 0): array
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

        if ($cutoverId !== null && $this->connection->columnExists('time_account_entries', 'cutover_id')) {
            $clauses[] = 'time_account_entries.cutover_id = :cutover_id';
            $bindings['cutover_id'] = $cutoverId;
        }

        return $this->connection->fetchAll(
            'SELECT time_account_entries.*, creators.first_name AS creator_first_name, creators.last_name AS creator_last_name,
                    CASE WHEN time_account_entries.entry_type <> "reversal"
                              AND NOT EXISTS (
                                  SELECT 1 FROM time_account_entries AS existing_reversal
                                  WHERE existing_reversal.reversal_of_id = time_account_entries.id
                              )
                         THEN 1 ELSE 0 END AS is_open
             FROM time_account_entries
             LEFT JOIN users AS creators ON creators.id = time_account_entries.created_by_user_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY time_account_entries.effective_date DESC, time_account_entries.id DESC
             LIMIT ' . max(1, min(501, $limit)) . '
             OFFSET ' . max(0, $offset),
            $bindings
        );
    }

    public function vacationEntriesForUser(int $userId, ?int $leaveYear = null, ?int $cutoverId = null, int $limit = 100, int $offset = 0): array
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

        if ($cutoverId !== null && $this->connection->columnExists('vacation_account_entries', 'cutover_id')) {
            $clauses[] = 'vacation_account_entries.cutover_id = :cutover_id';
            $bindings['cutover_id'] = $cutoverId;
        }

        return $this->connection->fetchAll(
            'SELECT vacation_account_entries.*, creators.first_name AS creator_first_name, creators.last_name AS creator_last_name,
                    CASE WHEN vacation_account_entries.entry_type <> "reversal"
                              AND NOT EXISTS (
                                  SELECT 1 FROM vacation_account_entries AS existing_reversal
                                  WHERE existing_reversal.reversal_of_id = vacation_account_entries.id
                              )
                         THEN 1 ELSE 0 END AS is_open
             FROM vacation_account_entries
             LEFT JOIN users AS creators ON creators.id = vacation_account_entries.created_by_user_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY vacation_account_entries.effective_date DESC, vacation_account_entries.id DESC
             LIMIT ' . max(1, min(501, $limit)) . '
             OFFSET ' . max(0, $offset),
            $bindings
        );
    }

    public function openTimeEntriesForCutover(int $cutoverId): array
    {
        return $this->openEntriesForCutover('time_account_entries', $cutoverId);
    }

    public function openVacationEntriesForCutover(int $cutoverId): array
    {
        return $this->openEntriesForCutover('vacation_account_entries', $cutoverId);
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

    private function openEntriesForCutover(string $table, int $cutoverId): array
    {
        if ($cutoverId <= 0 || !$this->connection->tableExists($table)) {
            return [];
        }

        $cutoverClause = $this->connection->columnExists($table, 'cutover_id')
            ? 'original.cutover_id = :cutover_id'
            : 'original.source_type = "employee_account_cutover" AND original.source_id = :cutover_id';

        return $this->connection->fetchAll(
            'SELECT original.*
             FROM ' . $table . ' AS original
             LEFT JOIN ' . $table . ' AS reversal ON reversal.reversal_of_id = original.id
             WHERE ' . $cutoverClause . '
               AND original.entry_type <> "reversal"
               AND reversal.id IS NULL
             ORDER BY original.id ASC',
            ['cutover_id' => $cutoverId]
        );
    }

    private function assertValidTimeEntry(int $userId, string $effectiveDate, int $minutes, string $entryType, string $description, ?int $reversalOfId, ?int $cutoverId): void
    {
        if (!in_array($entryType, self::TIME_TYPES, true)) {
            throw new InvalidArgumentException('Unbekannte Zeitkonto-Buchungsart.');
        }

        if ($entryType === 'reversal' && $reversalOfId === null) {
            throw new InvalidArgumentException('Gegenbuchungen benoetigen eine Ursprungsbuchung.');
        }

        if ($entryType !== 'reversal' && $reversalOfId !== null) {
            throw new InvalidArgumentException('reversal_of_id darf nur fuer Gegenbuchungen gesetzt werden.');
        }

        if ($minutes === 0 && $entryType !== 'opening_balance') {
            throw new InvalidArgumentException('Nullbuchungen sind nicht zulaessig.');
        }

        if (in_array($entryType, ['payout', 'time_off_compensation'], true) && $minutes >= 0) {
            throw new InvalidArgumentException('Auszahlungen und Freizeitausgleich muessen negativ gebucht werden.');
        }

        $this->assertValidDate($effectiveDate);
        $this->assertUserExists($userId);
        $this->assertCutoverMatches($userId, $effectiveDate, $cutoverId, $entryType === 'opening_balance');
    }

    private function assertValidVacationEntry(int $userId, int $leaveYear, string $effectiveDate, float $days, string $entryType, string $description, ?int $reversalOfId, ?int $cutoverId): void
    {
        if (!in_array($entryType, self::VACATION_TYPES, true)) {
            throw new InvalidArgumentException('Unbekannte Urlaubskonto-Buchungsart.');
        }

        if ($entryType === 'reversal' && $reversalOfId === null) {
            throw new InvalidArgumentException('Gegenbuchungen benoetigen eine Ursprungsbuchung.');
        }

        if ($entryType !== 'reversal' && $reversalOfId !== null) {
            throw new InvalidArgumentException('reversal_of_id darf nur fuer Gegenbuchungen gesetzt werden.');
        }

        if (abs($days) < 0.005 && !in_array($entryType, ['annual_entitlement', 'carryover', 'opening_adjustment'], true)) {
            throw new InvalidArgumentException('Nullbuchungen sind nicht zulaessig.');
        }

        if ($entryType === 'expiry' && $days >= 0) {
            throw new InvalidArgumentException('Urlaubsverfall muss negativ gebucht werden.');
        }

        if ($entryType === 'annual_entitlement' && $days < 0) {
            throw new InvalidArgumentException('Jahresurlaub darf nicht negativ gebucht werden.');
        }

        if ($leaveYear < 2000 || $leaveYear > 2100 || substr($effectiveDate, 0, 4) > (string) ($leaveYear + 1)) {
            throw new InvalidArgumentException('Bitte ein plausibles Urlaubsjahr angeben.');
        }

        $this->assertValidDate($effectiveDate);
        $this->assertUserExists($userId);
        $this->assertCutoverMatches($userId, $effectiveDate, $cutoverId, in_array($entryType, ['annual_entitlement', 'carryover', 'opening_adjustment'], true));
    }

    private function assertValidDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $date || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Bitte ein gueltiges Wirksamkeitsdatum angeben.');
        }
    }

    private function assertUserExists(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Bitte einen gueltigen Mitarbeiter auswaehlen.');
        }

        if (!$this->connection->tableExists('users')) {
            return;
        }

        $exists = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM users WHERE id = :id AND COALESCE(is_deleted, 0) = 0',
            ['id' => $userId]
        ) ?? 0) > 0;

        if (!$exists) {
            throw new InvalidArgumentException('Der Mitarbeiter wurde nicht gefunden.');
        }
    }

    private function assertCutoverMatches(int $userId, string $effectiveDate, ?int $cutoverId, bool $allowOpening): void
    {
        if (!$this->connection->tableExists('employee_account_cutovers') || !$this->connection->columnExists('time_account_entries', 'cutover_id')) {
            return;
        }

        if ($cutoverId === null || $cutoverId <= 0) {
            throw new InvalidArgumentException('Journalbuchungen benoetigen eine aktive Stichtagsgeneration.');
        }

        $cutover = $this->connection->fetchOne(
            'SELECT id, user_id, effective_from, status, active_final_user_id
             FROM employee_account_cutovers
             WHERE id = :id
             LIMIT 1',
            ['id' => $cutoverId]
        );

        if ($cutover === null || (int) $cutover['user_id'] !== $userId) {
            throw new InvalidArgumentException('Die Stichtagsgeneration passt nicht zum Mitarbeiter.');
        }

        if (!$allowOpening && ((string) $cutover['status'] !== 'final' || (int) ($cutover['active_final_user_id'] ?? 0) !== $userId)) {
            throw new InvalidArgumentException('Journalbuchungen benoetigen einen aktiven finalen Stichtag.');
        }

        if ($effectiveDate < (string) $cutover['effective_from']) {
            throw new InvalidArgumentException('Journalbuchungen muessen am oder nach dem Stichtag liegen.');
        }
    }
}
