<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Infrastructure\Database\DatabaseConnection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

final class AbsencePeriodService
{
    private const MAX_CALENDAR_DAYS = 366;
    private const PAID_REASONS = ['vacation_paid', 'sick_paid', 'paid_leave', 'employer_release_paid'];
    private const SOURCES = ['admin_calendar', 'admin_vacation', 'vacation_request'];
    private const TYPES = ['vacation', 'sick', 'absent'];

    public function __construct(
        private DatabaseConnection $connection,
        private CalendarPolicyService $calendarPolicyService,
        private DailyTargetService $dailyTargetService,
        private TimesheetWriteGuard $writeGuard,
        private TimesheetDayConflictService $dayConflictService,
        private TimesheetAuditService $timesheetAuditService,
        private ?TimeAccountService $timeAccountService = null
    ) {
    }

    public function preview(array $payload, ?int $periodId = null, string $source = 'admin_calendar'): array
    {
        $period = $periodId !== null ? $this->find($periodId) : null;

        if ($periodId !== null && $period === null) {
            throw new InvalidArgumentException('Der Abwesenheitszeitraum wurde nicht gefunden.');
        }

        return $this->buildPreview($payload, $period, $source, true);
    }

    public function create(array $payload, int $actorUserId, string $source = 'admin_calendar'): array
    {
        $source = $this->normalizeSource($source);
        $reason = $this->requiredChangeReason($payload);
        $userId = (int) ($payload['user_id'] ?? 0);

        return $this->withEmployeeLock($userId, function () use ($payload, $actorUserId, $source, $reason): array {
            return $this->writeGuard->withAccountingWriteLock(function () use ($payload, $actorUserId, $source, $reason): array {
                $preview = $this->buildPreview($payload, null, $source, false);
                $this->assertPreviewToken($preview, $payload);
                $this->assertWarningsConfirmed($preview, $payload);

                return $this->connection->transaction(function () use ($preview, $actorUserId, $source, $reason): array {
                    $periodId = $this->insertPeriod($preview, $actorUserId, $source, null);
                    $this->insertDesiredTimesheets($periodId, $preview, $actorUserId, $reason, null);
                    $after = $this->find($periodId);
                    $this->logPeriodChange($periodId, 'created', $actorUserId, $reason, null, $after);

                    return $after ?? [];
                });
            });
        });
    }

    public function update(int $periodId, array $payload, int $actorUserId): array
    {
        $before = $this->find($periodId);

        if ($before === null || (int) ($before['is_deleted'] ?? 0) === 1) {
            throw new InvalidArgumentException('Der aktive Abwesenheitszeitraum wurde nicht gefunden.');
        }

        $reason = $this->requiredChangeReason($payload);
        $expectedVersion = (int) ($payload['lock_version'] ?? 0);

        if ($expectedVersion <= 0) {
            throw new InvalidArgumentException('Bitte den Zeitraum neu laden und die Aenderung erneut pruefen.');
        }

        return $this->withEmployeeLock((int) $before['user_id'], function () use ($periodId, $payload, $actorUserId, $reason, $expectedVersion): array {
            return $this->writeGuard->withAccountingWriteLock(function () use ($periodId, $payload, $actorUserId, $reason, $expectedVersion): array {
                $fresh = $this->find($periodId);

                if ($fresh === null || (int) ($fresh['is_deleted'] ?? 0) === 1) {
                    throw new InvalidArgumentException('Der aktive Abwesenheitszeitraum wurde nicht gefunden.');
                }

                if ((int) $fresh['lock_version'] !== $expectedVersion) {
                    throw new InvalidArgumentException('Der Zeitraum wurde zwischenzeitlich geaendert. Bitte neu laden.');
                }

                if (isset($payload['user_id']) && (int) $payload['user_id'] !== (int) $fresh['user_id']) {
                    throw new InvalidArgumentException('Der Mitarbeiter eines bestehenden Zeitraums kann nicht geaendert werden.');
                }

                $preview = $this->buildPreview(
                    ['user_id' => (int) $fresh['user_id']] + $payload,
                    $fresh,
                    (string) $fresh['source'],
                    false
                );
                $this->assertPreviewToken($preview, $payload);
                $this->assertWarningsConfirmed($preview, $payload);

                return $this->connection->transaction(function () use ($periodId, $fresh, $preview, $actorUserId, $reason, $expectedVersion): array {
                    $this->applyTimesheetDiff($periodId, $preview, $actorUserId, $reason);
                    $updated = $this->connection->execute(
                        'UPDATE absence_periods
                         SET date_from = :date_from,
                             date_to = :date_to,
                             entry_type = :entry_type,
                             absence_reason_code = :absence_reason_code,
                             booked_day_count = :booked_day_count,
                             note = :note,
                             lock_version = lock_version + 1,
                             updated_by_user_id = :updated_by_user_id,
                             updated_at = NOW()
                         WHERE id = :id
                           AND lock_version = :lock_version
                           AND COALESCE(is_deleted, 0) = 0',
                        [
                            'id' => $periodId,
                            'date_from' => $preview['date_from'],
                            'date_to' => $preview['date_to'],
                            'entry_type' => $preview['entry_type'],
                            'absence_reason_code' => $preview['absence_reason_code'],
                            'booked_day_count' => $preview['booked_day_count'],
                            'note' => $preview['note'],
                            'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                            'lock_version' => $expectedVersion,
                        ]
                    );

                    if (!$updated) {
                        throw new InvalidArgumentException('Der Zeitraum konnte nicht aktualisiert werden.');
                    }

                    if ((string) ($fresh['source'] ?? '') === 'vacation_request'
                        && (int) ($fresh['vacation_request_id'] ?? 0) > 0) {
                        $this->connection->execute(
                            'UPDATE vacation_requests
                             SET date_from = :date_from,
                                 date_to = :date_to,
                                 day_count = :day_count,
                                 updated_at = NOW()
                             WHERE id = :id AND status = "approved"',
                            [
                                'id' => (int) $fresh['vacation_request_id'],
                                'date_from' => $preview['date_from'],
                                'date_to' => $preview['date_to'],
                                'day_count' => $preview['booked_day_count'],
                            ]
                        );
                    }

                    $after = $this->find($periodId);
                    $this->logPeriodChange($periodId, 'updated', $actorUserId, $reason, $fresh, $after);

                    return $after ?? [];
                });
            });
        });
    }

    public function archive(int $periodId, int $actorUserId, string $reason, int $expectedVersion): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine fachliche Begruendung angeben.');
        }
        if ($expectedVersion <= 0) {
            throw new InvalidArgumentException('Bitte den Zeitraum neu laden und die Archivierung erneut pruefen.');
        }

        $before = $this->find($periodId);

        if ($before === null || (int) ($before['is_deleted'] ?? 0) === 1) {
            throw new InvalidArgumentException('Der aktive Abwesenheitszeitraum wurde nicht gefunden.');
        }

        $this->withEmployeeLock((int) $before['user_id'], function () use ($periodId, $actorUserId, $reason, $expectedVersion): void {
            $this->writeGuard->withAccountingWriteLock(function () use ($periodId, $actorUserId, $reason, $expectedVersion): void {
                $fresh = $this->find($periodId);

                if ($fresh === null || (int) ($fresh['is_deleted'] ?? 0) === 1) {
                    throw new InvalidArgumentException('Der aktive Abwesenheitszeitraum wurde nicht gefunden.');
                }
                if ((int) $fresh['lock_version'] !== $expectedVersion) {
                    throw new InvalidArgumentException('Der Zeitraum wurde zwischenzeitlich geaendert. Bitte neu laden.');
                }

                $children = $this->periodTimesheets($periodId, false);
                $this->assertDatesWritable((int) $fresh['user_id'], array_column($children, 'work_date'));

                $this->connection->transaction(function () use ($periodId, $fresh, $children, $actorUserId, $reason): void {
                    foreach ($children as $child) {
                        $this->archiveTimesheet($child, $actorUserId, $reason, 'absence_period_archived');
                    }

                    $this->connection->execute(
                        'UPDATE absence_periods
                         SET is_deleted = 1,
                             deleted_at = NOW(),
                             deleted_by_user_id = :deleted_by_user_id,
                             updated_by_user_id = :updated_by_user_id,
                             lock_version = lock_version + 1,
                             updated_at = NOW()
                         WHERE id = :id AND COALESCE(is_deleted, 0) = 0',
                        [
                            'id' => $periodId,
                            'deleted_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                            'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                        ]
                    );

                    if ((int) ($fresh['vacation_request_id'] ?? 0) > 0) {
                        $this->connection->execute(
                            'UPDATE vacation_requests
                             SET status = "cancelled",
                                 updated_at = NOW()
                             WHERE id = :id AND status = "approved"',
                            [
                                'id' => (int) $fresh['vacation_request_id'],
                            ]
                        );
                    }

                    $after = $this->find($periodId);
                    $this->logPeriodChange($periodId, 'archived', $actorUserId, $reason, $fresh, $after);
                });
            });
        });
    }

    public function approveVacationRequest(int $requestId, int $actorUserId, ?string $decisionNote = null): array
    {
        $request = $this->vacationRequest($requestId);

        if ($request === null) {
            throw new InvalidArgumentException('Der Urlaubsantrag wurde nicht gefunden.');
        }

        if ((string) $request['status'] === 'approved') {
            return $request;
        }

        if ((string) $request['status'] !== 'pending') {
            throw new InvalidArgumentException('Dieser Statusuebergang ist fuer Urlaubsantraege nicht erlaubt.');
        }

        $userId = (int) $request['user_id'];
        $reason = trim((string) $decisionNote) ?: 'Urlaubsantrag genehmigt';

        return $this->withEmployeeLock($userId, function () use ($requestId, $actorUserId, $decisionNote, $reason): array {
            return $this->writeGuard->withAccountingWriteLock(function () use ($requestId, $actorUserId, $decisionNote, $reason): array {
                $fresh = $this->vacationRequest($requestId);

                if ($fresh === null || (string) $fresh['status'] !== 'pending') {
                    if ($fresh !== null && (string) $fresh['status'] === 'approved') {
                        return $fresh;
                    }

                    throw new InvalidArgumentException('Dieser Statusuebergang ist fuer Urlaubsantraege nicht erlaubt.');
                }

                $preview = $this->buildPreview([
                    'user_id' => (int) $fresh['user_id'],
                    'date_from' => (string) $fresh['date_from'],
                    'date_to' => (string) $fresh['date_to'],
                    'entry_type' => 'vacation',
                    'absence_reason_code' => 'vacation_paid',
                    'note' => $fresh['employee_note'] ?? null,
                ], null, 'vacation_request', false, $requestId);

                return $this->connection->transaction(function () use ($fresh, $requestId, $actorUserId, $decisionNote, $reason, $preview): array {
                    $updated = $this->connection->execute(
                        'UPDATE vacation_requests
                         SET status = "approved",
                             day_count = :day_count,
                             decision_note = :decision_note,
                             decided_at = NOW(),
                             decided_by_user_id = :decided_by_user_id,
                             updated_at = NOW()
                         WHERE id = :id AND status = "pending" AND COALESCE(is_deleted, 0) = 0',
                        [
                            'id' => $requestId,
                            'day_count' => $preview['booked_day_count'],
                            'decision_note' => $this->nullableTrimmed($decisionNote),
                            'decided_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                        ]
                    );

                    if (!$updated) {
                        throw new InvalidArgumentException('Dieser Statusuebergang ist fuer Urlaubsantraege nicht erlaubt.');
                    }

                    $periodId = $this->insertPeriod($preview, $actorUserId, 'vacation_request', $requestId);
                    $this->insertDesiredTimesheets($periodId, $preview, $actorUserId, $reason, $requestId);
                    $after = $this->find($periodId);
                    $this->logPeriodChange($periodId, 'created_from_vacation_request', $actorUserId, $reason, null, $after);

                    return $this->vacationRequest($requestId) ?? $fresh;
                });
            });
        });
    }

    public function find(int $periodId): ?array
    {
        if ($periodId <= 0 || !$this->connection->tableExists('absence_periods')) {
            return null;
        }

        $row = $this->connection->fetchOne(
            'SELECT
                absence_periods.*,
                users.employee_number,
                users.first_name,
                users.last_name
             FROM absence_periods
             INNER JOIN users ON users.id = absence_periods.user_id
             WHERE absence_periods.id = :id
             LIMIT 1',
            ['id' => $periodId]
        );

        return $row === null ? null : $this->normalizePeriodRow($row);
    }

    public function listVacationPeriods(array $filters = []): array
    {
        if (!$this->connection->tableExists('absence_periods')) {
            return [];
        }

        $where = [
            'absence_periods.entry_type = "vacation"',
            'absence_periods.source IN ("admin_calendar", "admin_vacation")',
            'COALESCE(absence_periods.is_deleted, 0) = 0',
        ];
        $bindings = [];
        $userId = (int) ($filters['user_id'] ?? 0);
        $year = (int) ($filters['year'] ?? 0);

        if ($userId > 0) {
            $where[] = 'absence_periods.user_id = :user_id';
            $bindings['user_id'] = $userId;
        }

        if ($year >= 2000 && $year <= 2100) {
            $where[] = 'absence_periods.date_from <= :year_end';
            $where[] = 'absence_periods.date_to >= :year_start';
            $bindings['year_start'] = sprintf('%04d-01-01', $year);
            $bindings['year_end'] = sprintf('%04d-12-31', $year);
        }

        return array_map(
            fn (array $row): array => $this->normalizePeriodRow($row),
            $this->connection->fetchAll(
                'SELECT
                    absence_periods.*,
                    users.employee_number,
                    users.first_name,
                    users.last_name
                 FROM absence_periods
                 INNER JOIN users ON users.id = absence_periods.user_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY absence_periods.date_from DESC, absence_periods.id DESC',
                $bindings
            )
        );
    }

    public function findByVacationRequest(int $requestId): ?array
    {
        if ($requestId <= 0 || !$this->connection->tableExists('absence_periods')) {
            return null;
        }

        $periodId = (int) ($this->connection->fetchColumn(
            'SELECT id
             FROM absence_periods
             WHERE vacation_request_id = :vacation_request_id
             ORDER BY id DESC
             LIMIT 1',
            ['vacation_request_id' => $requestId]
        ) ?? 0);

        return $periodId > 0 ? $this->find($periodId) : null;
    }

    private function buildPreview(
        array $payload,
        ?array $period,
        string $source,
        bool $collectBlockers,
        int $trustedVacationRequestId = 0
    ): array
    {
        $this->assertSchemaAvailable();
        $source = $period !== null ? (string) $period['source'] : $this->normalizeSource($source);
        $userId = $period !== null ? (int) $period['user_id'] : (int) ($payload['user_id'] ?? 0);
        $user = $this->user($userId);

        if ($user === null) {
            throw new InvalidArgumentException('Bitte einen aktiven Mitarbeiter auswaehlen.');
        }

        $dateFrom = $this->normalizeDate($payload['date_from'] ?? ($period['date_from'] ?? null));
        $dateTo = $this->normalizeDate($payload['date_to'] ?? ($period['date_to'] ?? null));

        if ($dateFrom === null || $dateTo === null) {
            throw new InvalidArgumentException('Bitte einen gueltigen Zeitraum angeben.');
        }

        if ($dateTo < $dateFrom) {
            throw new InvalidArgumentException('Das Enddatum darf nicht vor dem Startdatum liegen.');
        }

        $calendarDays = (int) (new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days + 1;

        if ($calendarDays > self::MAX_CALENDAR_DAYS) {
            throw new InvalidArgumentException('Ein Abwesenheitszeitraum darf maximal 366 Kalendertage umfassen.');
        }

        $entryType = $this->normalizeEntryType($payload['entry_type'] ?? ($period['entry_type'] ?? 'vacation'), $source);
        $absenceReason = $this->normalizeAbsenceReason(
            $entryType,
            $payload['absence_reason_code'] ?? ($period['absence_reason_code'] ?? null)
        );
        $note = $this->nullableTrimmed($payload['note'] ?? ($period['note'] ?? null));
        [$bookDates, $skippedDates, $creditedByDate] = $this->resolveDates($user, $dateFrom, $dateTo, $absenceReason);

        if ($bookDates === []) {
            throw new InvalidArgumentException('Im gewaehlten Zeitraum liegt kein anrechenbarer Arbeitstag.');
        }

        $oldChildren = $period !== null ? $this->periodTimesheets((int) $period['id'], false) : [];
        $oldByDate = [];
        foreach ($oldChildren as $child) {
            $oldByDate[(string) $child['work_date']] = $child;
        }

        $oldDates = array_keys($oldByDate);
        $unchanged = array_values(array_intersect($bookDates, $oldDates));
        $added = array_values(array_diff($bookDates, $oldDates));
        $removed = array_values(array_diff($oldDates, $bookDates));
        sort($unchanged);
        sort($added);
        sort($removed);

        $blockers = $this->collectBlockers(
            $userId,
            array_values(array_unique([...$oldDates, ...$bookDates])),
            $bookDates,
            $period,
            $period !== null
                ? (int) ($period['vacation_request_id'] ?? 0)
                : ($source === 'vacation_request' ? $trustedVacationRequestId : 0),
            $dateFrom,
            $dateTo
        );

        if (!$collectBlockers && $blockers !== []) {
            throw new InvalidArgumentException((string) ($blockers[0]['message'] ?? 'Der Zeitraum enthaelt einen Konflikt.'));
        }

        $warnings = $this->warnings($dateFrom, $dateTo, $calendarDays);
        $vacationImpact = $this->vacationImpact($userId, $entryType, $bookDates, $period, $oldDates);

        foreach ($vacationImpact as $yearImpact) {
            if (($yearImpact['available_after'] ?? 0) < 0) {
                $warnings[] = [
                    'code' => 'negative_vacation_balance',
                    'message' => 'Der verfuegbare Urlaub wird im Jahr ' . (int) $yearImpact['year'] . ' negativ.',
                ];
            }
        }

        $result = [
            'period_id' => $period !== null ? (int) $period['id'] : null,
            'lock_version' => $period !== null ? (int) $period['lock_version'] : null,
            'user_id' => $userId,
            'employee_name' => trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'calendar_day_count' => $calendarDays,
            'entry_type' => $entryType,
            'absence_reason_code' => $absenceReason,
            'note' => $note,
            'source' => $source,
            'booked_day_count' => count($bookDates),
            'credited_minutes_total' => array_sum($creditedByDate),
            'book_dates' => $bookDates,
            'credited_minutes_by_date' => $creditedByDate,
            'skipped_dates' => $skippedDates,
            'blockers' => $blockers,
            'warnings' => array_values($warnings),
            'diff' => [
                'unchanged' => $unchanged,
                'added' => $added,
                'removed' => $removed,
            ],
            'vacation_impact' => $vacationImpact,
        ];
        $result['preview_token'] = $this->previewToken($result);

        return $result;
    }

    private function resolveDates(array $user, string $dateFrom, string $dateTo, string $reason): array
    {
        $breakdown = $this->dailyTargetService->breakdown($user, $dateFrom, $dateTo);
        $bookDates = [];
        $skipped = [];
        $credited = [];

        foreach (new DatePeriod(
            new DateTimeImmutable($dateFrom),
            new DateInterval('P1D'),
            (new DateTimeImmutable($dateTo))->modify('+1 day')
        ) as $date) {
            $dateString = $date->format('Y-m-d');
            $day = $breakdown[$dateString] ?? [];
            $policy = $this->calendarPolicyService->dayPolicy($dateString);
            $skipReason = null;

            if (!(bool) ($day['is_workday'] ?? false)) {
                $skipReason = 'Kein planmaessiger Arbeitstag';
            } elseif ((bool) ($policy['is_public_holiday'] ?? false)) {
                $skipReason = (string) ($policy['holiday_name'] ?? 'Gesetzlicher Feiertag');
            } elseif ((bool) ($policy['is_company_closure'] ?? false)) {
                $skipReason = 'Betriebsurlaub';
            } elseif ((int) ($day['effective_minutes'] ?? 0) <= 0) {
                $skipReason = 'Keine anrechenbare Sollzeit';
            }

            if ($skipReason !== null) {
                $skipped[] = ['date' => $dateString, 'reason' => $skipReason];
                continue;
            }

            $bookDates[] = $dateString;
            $credited[$dateString] = in_array($reason, self::PAID_REASONS, true)
                ? (int) ($day['effective_minutes'] ?? 0)
                : 0;
        }

        return [$bookDates, $skipped, $credited];
    }

    private function collectBlockers(
        int $userId,
        array $affectedDates,
        array $desiredDates,
        ?array $period,
        int $vacationRequestId,
        string $dateFrom,
        string $dateTo
    ): array {
        $blockers = [];

        foreach ($affectedDates as $date) {
            try {
                $this->writeGuard->assertAccountingPeriodOpen($userId, null, $date);
            } catch (InvalidArgumentException $exception) {
                $blockers[] = ['date' => $date, 'code' => 'accounting_lock', 'message' => $exception->getMessage()];
            }
        }

        if ($desiredDates !== []) {
            $bindings = ['user_id' => $userId];
            $placeholders = [];
            foreach ($desiredDates as $index => $date) {
                $key = 'date_' . $index;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $date;
            }

            $exclude = '';
            if ($period !== null) {
                $exclude = ' AND (absence_period_id IS NULL OR absence_period_id <> :absence_period_id)';
                $bindings['absence_period_id'] = (int) $period['id'];
            }

            $rows = $this->connection->fetchAll(
                'SELECT id, work_date, entry_type
                 FROM timesheets
                 WHERE user_id = :user_id
                   AND work_date IN (' . implode(', ', $placeholders) . ')
                   AND COALESCE(is_deleted, 0) = 0' . $exclude . '
                 ORDER BY work_date ASC, id ASC',
                $bindings
            );

            foreach ($rows as $row) {
                $blockers[] = [
                    'date' => (string) $row['work_date'],
                    'code' => 'timesheet_conflict',
                    'message' => 'Am ' . (string) $row['work_date'] . ' besteht bereits eine aktive Buchung (' . (string) $row['entry_type'] . ').',
                ];
            }
        }

        if ($this->connection->tableExists('vacation_requests')) {
            $bindings = [
                'user_id' => $userId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ];
            $exclude = '';
            if ($vacationRequestId > 0) {
                $exclude = ' AND id <> :vacation_request_id';
                $bindings['vacation_request_id'] = $vacationRequestId;
            }

            $overlap = $this->connection->fetchOne(
                'SELECT id, date_from, date_to, status
                 FROM vacation_requests
                 WHERE user_id = :user_id
                   AND status IN ("pending", "approved")
                   AND date_from <= :date_to
                   AND date_to >= :date_from
                   AND COALESCE(is_deleted, 0) = 0' . $exclude . '
                 ORDER BY date_from ASC, id ASC
                 LIMIT 1',
                $bindings
            );

            if ($overlap !== null) {
                $blockers[] = [
                    'date' => (string) $overlap['date_from'],
                    'code' => 'vacation_request_overlap',
                    'message' => 'Der Zeitraum ueberschneidet sich mit Urlaubsantrag #' . (int) $overlap['id'] . '.',
                ];
            }
        }

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker['code'] . '|' . $blocker['date'] . '|' . $blocker['message']] = $blocker;
        }

        return array_values($unique);
    }

    private function applyTimesheetDiff(int $periodId, array $preview, int $actorUserId, string $reason): void
    {
        $children = $this->periodTimesheets($periodId, false);
        $byDate = [];
        foreach ($children as $child) {
            $byDate[(string) $child['work_date']] = $child;
        }

        foreach ($preview['diff']['removed'] as $date) {
            if (isset($byDate[$date])) {
                $this->archiveTimesheet($byDate[$date], $actorUserId, $reason, 'absence_period_date_removed');
            }
        }

        foreach ($preview['diff']['unchanged'] as $date) {
            $before = $byDate[$date] ?? null;
            if ($before === null) {
                continue;
            }
            if (!$this->timesheetNeedsUpdate($before, $preview, $date)) {
                continue;
            }

            $this->connection->execute(
                'UPDATE timesheets
                 SET project_id = NULL,
                     start_time = NULL,
                     end_time = NULL,
                     gross_minutes = 0,
                     break_minutes = 0,
                     net_minutes = 0,
                     credited_minutes = :credited_minutes,
                     expenses_amount = 0,
                     entry_type = :entry_type,
                     absence_reason_code = :absence_reason_code,
                     note = :note,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'id' => (int) $before['id'],
                    'credited_minutes' => (int) ($preview['credited_minutes_by_date'][$date] ?? 0),
                    'entry_type' => $preview['entry_type'],
                    'absence_reason_code' => $preview['absence_reason_code'],
                    'note' => $preview['note'],
                ]
            );
            $after = $this->timesheetRow((int) $before['id']);
            $this->timesheetAuditService->log(
                (int) $before['id'],
                'absence_period_updated',
                $actorUserId,
                $reason,
                $before,
                $after
            );
        }

        $addedPreview = $preview;
        $addedPreview['book_dates'] = $preview['diff']['added'];
        $this->insertDesiredTimesheets(
            $periodId,
            $addedPreview,
            $actorUserId,
            $reason,
            isset($preview['vacation_request_id']) ? (int) $preview['vacation_request_id'] : null
        );
    }

    private function insertDesiredTimesheets(
        int $periodId,
        array $preview,
        int $actorUserId,
        string $reason,
        ?int $vacationRequestId
    ): void {
        $period = $this->find($periodId);
        $requestId = $vacationRequestId ?? (isset($period['vacation_request_id']) ? (int) $period['vacation_request_id'] : null);
        $hasCreditedMinutes = $this->connection->columnExists('timesheets', 'credited_minutes');
        $hasAbsenceReason = $this->connection->columnExists('timesheets', 'absence_reason_code');
        $hasSource = $this->connection->columnExists('timesheets', 'source');
        $hasVacationRequest = $this->connection->columnExists('timesheets', 'vacation_request_id');

        foreach ($preview['book_dates'] as $date) {
            $columns = [
                'user_id', 'project_id', 'created_by_user_id', 'work_date',
                'start_time', 'end_time', 'gross_minutes', 'break_minutes', 'net_minutes',
            ];
            $values = [
                ':user_id', 'NULL', ':created_by_user_id', ':work_date',
                'NULL', 'NULL', '0', '0', '0',
            ];
            $bindings = [
                'user_id' => (int) $preview['user_id'],
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                'work_date' => $date,
                'entry_type' => $preview['entry_type'],
                'absence_reason_code' => $preview['absence_reason_code'],
                'credited_minutes' => (int) ($preview['credited_minutes_by_date'][$date] ?? 0),
                'note' => $preview['note'],
                'absence_period_id' => $periodId,
            ];

            if ($hasCreditedMinutes) {
                $columns[] = 'credited_minutes';
                $values[] = ':credited_minutes';
            } else {
                unset($bindings['credited_minutes']);
            }

            $columns[] = 'expenses_amount';
            $values[] = '0';
            $columns[] = 'entry_type';
            $values[] = ':entry_type';

            if ($hasAbsenceReason) {
                $columns[] = 'absence_reason_code';
                $values[] = ':absence_reason_code';
            } else {
                unset($bindings['absence_reason_code']);
            }

            if ($hasSource) {
                $columns[] = 'source';
                $values[] = $requestId !== null && $requestId > 0 ? '"vacation_request"' : '"admin"';
            }

            if ($hasVacationRequest) {
                $columns[] = 'vacation_request_id';
                $values[] = ':vacation_request_id';
                $bindings['vacation_request_id'] = $requestId !== null && $requestId > 0 ? $requestId : null;
            }

            $columns[] = 'absence_period_id';
            $values[] = ':absence_period_id';
            $columns[] = 'note';
            $values[] = ':note';
            $columns[] = 'created_at';
            $values[] = 'NOW()';
            $columns[] = 'updated_at';
            $values[] = 'NOW()';

            $this->connection->execute(
                'INSERT INTO timesheets (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')',
                $bindings
            );
            $timesheetId = $this->connection->lastInsertId();
            $this->timesheetAuditService->log(
                $timesheetId,
                'absence_period_created',
                $actorUserId,
                $reason,
                null,
                $this->timesheetRow($timesheetId)
            );
        }
    }

    private function insertPeriod(
        array $preview,
        int $actorUserId,
        string $source,
        ?int $vacationRequestId
    ): int {
        $this->connection->execute(
            'INSERT INTO absence_periods (
                user_id, date_from, date_to, entry_type, absence_reason_code, source,
                vacation_request_id, booked_day_count, note, lock_version,
                created_by_user_id, updated_by_user_id, created_at, updated_at,
                is_deleted, deleted_at, deleted_by_user_id
             ) VALUES (
                :user_id, :date_from, :date_to, :entry_type, :absence_reason_code, :source,
                :vacation_request_id, :booked_day_count, :note, 1,
                :created_by_user_id, :updated_by_user_id, NOW(), NOW(),
                0, NULL, NULL
             )',
            [
                'user_id' => (int) $preview['user_id'],
                'date_from' => $preview['date_from'],
                'date_to' => $preview['date_to'],
                'entry_type' => $preview['entry_type'],
                'absence_reason_code' => $preview['absence_reason_code'],
                'source' => $source,
                'vacation_request_id' => $vacationRequestId,
                'booked_day_count' => (int) $preview['booked_day_count'],
                'note' => $preview['note'],
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ]
        );

        return $this->connection->lastInsertId();
    }

    private function archiveTimesheet(
        array $before,
        int $actorUserId,
        string $reason,
        string $actionType
    ): void {
        $id = (int) ($before['id'] ?? 0);
        $this->connection->execute(
            'UPDATE timesheets
             SET is_deleted = 1,
                 deleted_at = NOW(),
                 deleted_by_user_id = :deleted_by_user_id,
                 updated_at = NOW()
             WHERE id = :id AND COALESCE(is_deleted, 0) = 0',
            [
                'id' => $id,
                'deleted_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ]
        );
        $this->timesheetAuditService->log(
            $id,
            $actionType,
            $actorUserId,
            $reason,
            $before,
            $this->timesheetRow($id)
        );
    }

    private function periodTimesheets(int $periodId, bool $includeArchived): array
    {
        if ($periodId <= 0 || !$this->connection->columnExists('timesheets', 'absence_period_id')) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT *
             FROM timesheets
             WHERE absence_period_id = :absence_period_id
               AND ' . ($includeArchived ? '1 = 1' : 'COALESCE(is_deleted, 0) = 0') . '
             ORDER BY work_date ASC, id ASC',
            ['absence_period_id' => $periodId]
        );
    }

    private function timesheetRow(int $timesheetId): ?array
    {
        return $this->connection->fetchOne(
            'SELECT * FROM timesheets WHERE id = :id LIMIT 1',
            ['id' => $timesheetId]
        );
    }

    private function warnings(string $dateFrom, string $dateTo, int $calendarDays): array
    {
        $warnings = [];
        $today = new DateTimeImmutable('today');
        $lower = $today->modify('-18 months')->format('Y-m-d');
        $upper = $today->modify('+18 months')->format('Y-m-d');

        if ($calendarDays > 31) {
            $warnings[] = ['code' => 'long_period', 'message' => 'Der Zeitraum umfasst mehr als 31 Kalendertage.'];
        }

        if (substr($dateFrom, 0, 4) !== substr($dateTo, 0, 4)) {
            $warnings[] = ['code' => 'year_change', 'message' => 'Der Zeitraum ueberschreitet ein Kalenderjahr.'];
        }

        if ($dateFrom < $lower || $dateTo > $upper) {
            $warnings[] = ['code' => 'distant_date', 'message' => 'Mindestens ein Datum liegt mehr als 18 Monate von heute entfernt.'];
        }

        return $warnings;
    }

    private function vacationImpact(
        int $userId,
        string $entryType,
        array $newDates,
        ?array $period,
        array $oldDates
    ): array {
        $oldWasVacation = $period !== null && (string) ($period['entry_type'] ?? '') === 'vacation';
        $newByYear = $entryType === 'vacation' ? $this->countDatesByYear($newDates) : [];
        $oldByYear = $oldWasVacation ? $this->countDatesByYear($oldDates) : [];
        $years = array_values(array_unique([...array_keys($newByYear), ...array_keys($oldByYear)]));
        sort($years);
        $impact = [];

        foreach ($years as $year) {
            $before = $this->timeAccountService?->vacationYear($userId, (int) $year) ?? [
                'remaining_days' => 0.0,
                'available_days' => 0.0,
            ];
            $delta = (float) (($newByYear[$year] ?? 0) - ($oldByYear[$year] ?? 0));
            $impact[] = [
                'year' => (int) $year,
                'days_before' => (int) ($oldByYear[$year] ?? 0),
                'days_after' => (int) ($newByYear[$year] ?? 0),
                'delta_days' => $delta,
                'remaining_before' => (float) ($before['remaining_days'] ?? 0),
                'remaining_after' => (float) ($before['remaining_days'] ?? 0) - $delta,
                'available_before' => (float) ($before['available_days'] ?? 0),
                'available_after' => (float) ($before['available_days'] ?? 0) - $delta,
            ];
        }

        return $impact;
    }

    private function countDatesByYear(array $dates): array
    {
        $counts = [];
        foreach ($dates as $date) {
            $year = (int) substr((string) $date, 0, 4);
            $counts[$year] = ($counts[$year] ?? 0) + 1;
        }

        return $counts;
    }

    private function assertDatesWritable(int $userId, array $dates): void
    {
        foreach (array_values(array_unique(array_filter(array_map('strval', $dates)))) as $date) {
            $this->writeGuard->assertAccountingPeriodOpen($userId, null, $date);
        }
    }

    private function assertWarningsConfirmed(array $preview, array $payload): void
    {
        if (($preview['warnings'] ?? []) !== [] && (string) ($payload['confirm_warnings'] ?? '') !== '1') {
            throw new InvalidArgumentException('Bitte die Hinweise der Zeitraumvorschau ausdruecklich bestaetigen.');
        }
    }

    private function assertPreviewToken(array $preview, array $payload): void
    {
        $provided = trim((string) ($payload['preview_token'] ?? ''));

        if ($provided === '' || !hash_equals((string) ($preview['preview_token'] ?? ''), $provided)) {
            throw new InvalidArgumentException('Die Zeitraumvorschau ist nicht mehr aktuell. Bitte erneut pruefen.');
        }
    }

    private function previewToken(array $preview): string
    {
        $snapshot = [];
        foreach ([
            'period_id', 'lock_version', 'user_id', 'date_from', 'date_to', 'entry_type',
            'absence_reason_code', 'note', 'book_dates', 'credited_minutes_by_date',
            'skipped_dates', 'blockers', 'warnings', 'diff', 'vacation_impact',
        ] as $key) {
            $snapshot[$key] = $preview[$key] ?? null;
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeEntryType(mixed $value, string $source): string
    {
        $entryType = trim((string) $value);

        if ($source === 'admin_vacation' || $source === 'vacation_request') {
            return 'vacation';
        }

        if (!in_array($entryType, self::TYPES, true)) {
            throw new InvalidArgumentException('Bitte Urlaub, Krankheit oder Fehlzeit auswaehlen.');
        }

        return $entryType;
    }

    private function normalizeAbsenceReason(string $entryType, mixed $value): string
    {
        $reason = trim((string) $value);
        $allowed = match ($entryType) {
            'vacation' => ['vacation_paid'],
            'sick' => ['sick_paid', 'sick_unpaid'],
            'absent' => ['paid_leave', 'employer_release_paid', 'unpaid_leave', 'unexcused_absence'],
            default => [],
        };

        if (!in_array($reason, $allowed, true)) {
            throw new InvalidArgumentException('Bitte einen eindeutigen Abwesenheitsgrund auswaehlen.');
        }

        return $reason;
    }

    private function normalizeSource(string $source): string
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Die Herkunft des Abwesenheitszeitraums ist ungueltig.');
        }

        return $source;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || !($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            return null;
        }

        $year = (int) $date->format('Y');

        return $year >= 2000 && $year <= 2100 ? $value : null;
    }

    private function requiredChangeReason(array $payload): string
    {
        $reason = trim((string) ($payload['change_reason'] ?? ''));

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine fachliche Begruendung angeben.');
        }

        return $reason;
    }

    private function user(int $userId): ?array
    {
        if ($userId <= 0 || !$this->connection->tableExists('users')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT
                id, employee_number, first_name, last_name,
                target_hours_month, target_hours_mode, target_hours_week, workdays_mask
             FROM users
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0
               AND employment_status = "active"
             LIMIT 1',
            ['id' => $userId]
        );
    }

    private function vacationRequest(int $requestId): ?array
    {
        if ($requestId <= 0 || !$this->connection->tableExists('vacation_requests')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT *
             FROM vacation_requests
             WHERE id = :id AND COALESCE(is_deleted, 0) = 0
             LIMIT 1',
            ['id' => $requestId]
        );
    }

    private function normalizePeriodRow(array $row): array
    {
        return [
            ...$row,
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'vacation_request_id' => isset($row['vacation_request_id']) ? (int) $row['vacation_request_id'] : null,
            'booked_day_count' => (int) ($row['booked_day_count'] ?? 0),
            'lock_version' => (int) ($row['lock_version'] ?? 1),
            'is_deleted' => (int) ($row['is_deleted'] ?? 0),
            'employee_name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
            'edit_url' => '/admin/absence-periods/' . (int) ($row['id'] ?? 0),
        ];
    }

    private function logPeriodChange(
        int $periodId,
        string $actionType,
        int $actorUserId,
        string $reason,
        ?array $before,
        ?array $after
    ): void {
        if (!$this->connection->tableExists('absence_period_change_log')) {
            return;
        }

        $this->connection->execute(
            'INSERT INTO absence_period_change_log (
                absence_period_id, action_type, changed_by_user_id, change_reason,
                before_snapshot, after_snapshot, created_at
             ) VALUES (
                :absence_period_id, :action_type, :changed_by_user_id, :change_reason,
                :before_snapshot, :after_snapshot, NOW()
             )',
            [
                'absence_period_id' => $periodId,
                'action_type' => $actionType,
                'changed_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                'change_reason' => trim($reason),
                'before_snapshot' => $before !== null
                    ? json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : null,
                'after_snapshot' => $after !== null
                    ? json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                    : null,
            ]
        );
    }

    private function withEmployeeLock(int $userId, callable $callback): mixed
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Bitte einen aktiven Mitarbeiter auswaehlen.');
        }

        $lockName = 'employee-absence-user-' . $userId;
        $locked = (int) ($this->connection->fetchColumn(
            'SELECT GET_LOCK(:lock_name, 10)',
            ['lock_name' => $lockName]
        ) ?? 0);

        if ($locked !== 1) {
            throw new InvalidArgumentException('Abwesenheiten fuer diesen Mitarbeiter werden gerade verarbeitet. Bitte erneut versuchen.');
        }

        try {
            return $callback();
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName]);
        }
    }

    private function assertSchemaAvailable(): void
    {
        if (!$this->connection->tableExists('absence_periods')
            || !$this->connection->columnExists('timesheets', 'absence_period_id')) {
            throw new InvalidArgumentException('Abwesenheitszeitraeume sind noch nicht migriert.');
        }
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function timesheetNeedsUpdate(array $before, array $preview, string $date): bool
    {
        return ($before['project_id'] ?? null) !== null
            || ($before['start_time'] ?? null) !== null
            || ($before['end_time'] ?? null) !== null
            || (int) ($before['gross_minutes'] ?? 0) !== 0
            || (int) ($before['break_minutes'] ?? 0) !== 0
            || (int) ($before['net_minutes'] ?? 0) !== 0
            || (int) ($before['credited_minutes'] ?? 0) !== (int) ($preview['credited_minutes_by_date'][$date] ?? 0)
            || (float) ($before['expenses_amount'] ?? 0) !== 0.0
            || (string) ($before['entry_type'] ?? '') !== (string) $preview['entry_type']
            || (string) ($before['absence_reason_code'] ?? '') !== (string) $preview['absence_reason_code']
            || $this->nullableTrimmed($before['note'] ?? null) !== $preview['note'];
    }
}
