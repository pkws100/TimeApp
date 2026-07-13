<?php

declare(strict_types=1);

namespace App\Domain\TimeAccounts;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;

final class VacationAccountYearService
{
    public function __construct(
        private DatabaseConnection $connection,
        private AccountJournalService $journalService,
        private EmployeeAccountCutoverService $cutoverService
    ) {
    }

    public function ensureYearOpened(int $userId, int $leaveYear, int $actorUserId): void
    {
        if ($userId <= 0 || $leaveYear < 2000 || $leaveYear > 2100) {
            throw new InvalidArgumentException('Bitte ein gueltiges Urlaubsjahr angeben.');
        }

        $this->cutoverService->withAccountWriteLocks(
            $userId,
            fn (): mixed => $this->ensureYearOpenedWithinAccountLocks($userId, $leaveYear, $actorUserId)
        );
    }

    public function ensureYearOpenedWithinAccountLocks(int $userId, int $leaveYear, int $actorUserId): void
    {
        if ($userId <= 0 || $leaveYear < 2000 || $leaveYear > 2100) {
            throw new InvalidArgumentException('Bitte ein gueltiges Urlaubsjahr angeben.');
        }

        $cutover = $this->cutoverService->activeCutover($userId);

        if ($cutover === null) {
            throw new InvalidArgumentException('Bitte zuerst einen Zeitkonto-Stichtag fuer diesen Mitarbeiter finalisieren.');
        }

        $cutoverId = (int) $cutover['id'];
        $this->withOpeningLock($userId, $leaveYear, $cutoverId, function () use ($userId, $leaveYear, $cutoverId, $actorUserId): void {
            if ($this->isOpened($userId, $leaveYear, $cutoverId)) {
                return;
            }

            $cutover = $this->cutoverService->activeCutover($userId);
            if ($cutover === null || (int) $cutover['id'] !== $cutoverId) {
                throw new InvalidArgumentException('Die aktive Stichtagsgeneration hat sich geaendert. Bitte erneut versuchen.');
            }

            $user = $this->user($userId);
            $effectiveDate = max(sprintf('%04d-01-01', $leaveYear), (string) $cutover['effective_from']);
            $entitlement = (float) ($user['vacation_days_year'] ?? 0);
            $carryover = (float) ($user['vacation_carryover_days'] ?? 0);

            $this->connection->transaction(function () use ($userId, $leaveYear, $cutoverId, $effectiveDate, $entitlement, $carryover, $actorUserId): void {
                if ($this->isOpened($userId, $leaveYear, $cutoverId)) {
                    return;
                }

                $this->journalService->addVacationEntry(
                    $userId,
                    $leaveYear,
                    $effectiveDate,
                    $entitlement,
                    'annual_entitlement',
                    $this->sourceType($userId, $cutoverId),
                    $leaveYear,
                    'Jahresurlaub ' . $leaveYear,
                    $actorUserId,
                    $actorUserId,
                    null,
                    $cutoverId
                );

                $this->journalService->addVacationEntry(
                    $userId,
                    $leaveYear,
                    $effectiveDate,
                    $carryover,
                    'carryover',
                    $this->sourceType($userId, $cutoverId),
                    $leaveYear,
                    'Urlaubsuebertrag ' . $leaveYear,
                    $actorUserId,
                    $actorUserId,
                    null,
                    $cutoverId
                );
            });
        });
    }

    private function isOpened(int $userId, int $leaveYear, int $cutoverId): bool
    {
        if (!$this->connection->tableExists('vacation_account_entries')
            || !$this->connection->columnExists('vacation_account_entries', 'cutover_id')) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM vacation_account_entries
             WHERE user_id = :user_id
               AND leave_year = :leave_year
               AND cutover_id = :cutover_id
               AND entry_type = "annual_entitlement"',
            ['user_id' => $userId, 'leave_year' => $leaveYear, 'cutover_id' => $cutoverId]
        ) ?? 0) > 0;
    }

    private function user(int $userId): array
    {
        if (!$this->connection->tableExists('users')) {
            return [];
        }

        return $this->connection->fetchOne(
            'SELECT id,
                    ' . $this->columnOrLiteral('vacation_days_year', '0', 'vacation_days_year') . ',
                    ' . $this->columnOrLiteral('vacation_carryover_days', '0', 'vacation_carryover_days') . '
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $userId]
        ) ?? [];
    }

    private function sourceType(int $userId, int $cutoverId): string
    {
        return 'vacation_year_opening_' . $userId . '_' . $cutoverId;
    }

    private function withOpeningLock(int $userId, int $leaveYear, int $cutoverId, callable $callback): void
    {
        $lockName = sprintf('vacation-year-opening-%d-%d-%d', $userId, $leaveYear, $cutoverId);
        $locked = (int) ($this->connection->fetchColumn('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $lockName]) ?? 0);

        if ($locked !== 1) {
            throw new InvalidArgumentException('Die Urlaubskonto-Jahreseroeffnung ist gerade gesperrt. Bitte erneut versuchen.');
        }

        try {
            $callback();
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName]);
        }
    }

    private function columnOrLiteral(string $column, string $literal, string $alias): string
    {
        return $this->connection->columnExists('users', $column)
            ? $column . ' AS ' . $alias
            : $literal . ' AS ' . $alias;
    }
}
