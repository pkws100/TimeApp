<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Domain\Timesheets\AdminBookingService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class AccountingClosureService
{
    private const ACCOUNTING_WRITE_LOCK = 'accounting-timesheet-write';

    public function __construct(
        private DatabaseConnection $connection,
        private AdminBookingService $bookingService
    ) {
    }

    public function selectionFromInput(array $input): array
    {
        $type = (string) ($input['type'] ?? $input['closure_type'] ?? 'month');
        $type = in_array($type, ['month', 'project'], true) ? $type : 'month';
        $projectId = $this->positiveIntOrNull($input['project_id'] ?? null);
        $userId = $this->positiveIntOrNull($input['user_id'] ?? null);
        $dateFrom = $this->normalizeDate($input['date_from'] ?? null);
        $dateTo = $this->normalizeDate($input['date_to'] ?? null);
        $period = trim((string) ($input['period'] ?? ''));

        if ($type === 'month') {
            $month = $this->normalizeMonth($period);
            $dateFrom = $month->format('Y-m-01');
            $dateTo = $month->modify('last day of this month')->format('Y-m-d');
            $period = $month->format('Y-m');
            $projectId = $this->positiveIntOrNull($input['project_id'] ?? null);
        } else {
            $period = $dateFrom !== null && $dateTo !== null
                ? $dateFrom . '_' . $dateTo
                : 'projektlaufzeit';
        }

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'type' => $type,
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'project_id' => $projectId,
            'user_id' => $userId,
        ];
    }

    public function previewPackage(array $input): array
    {
        $selection = $this->selectionFromInput($input);
        $items = $this->snapshotRows($this->activeRows($selection));
        [$periodStart, $periodEnd] = $this->periodBounds($selection, $items);
        $number = $this->previewNumber($selection, $periodStart, $periodEnd);
        $closure = [
            'id' => null,
            'closure_number' => $number,
            'closure_type' => $selection['type'],
            'status' => 'draft',
            'status_label' => 'VORLAEUFIG - nicht festgeschrieben',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_label' => $this->periodLabel($selection, $periodStart, $periodEnd),
            'project_id' => $selection['project_id'],
            'user_id' => $selection['user_id'],
            'snapshot_hash' => '',
            'item_count' => count($items),
            'total_net_minutes' => $this->totalNetMinutes($items),
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'finalized_at' => null,
            'note' => '',
        ];

        return $this->package($closure, $items);
    }

    public function createClosure(array $input, int $userId, string $status = 'final', ?int $originalClosureId = null): array
    {
        if ($status !== 'final') {
            throw new InvalidArgumentException('Korrekturabschluesse benoetigen einen separaten Delta-Workflow und werden in dieser Version nicht automatisch erzeugt.');
        }

        if (!$this->hasClosureTables()) {
            throw new RuntimeException('Die Abrechnungstabellen sind noch nicht verfuegbar. Bitte Migrationen ausfuehren.');
        }

        $selection = $this->selectionFromInput($input);
        $lockName = $this->lockName($selection);
        $this->acquireLock($lockName);

        try {
            $validation = $this->validateFinalization($selection);

            if (!$validation['ok']) {
                throw new InvalidArgumentException(implode(' ', $validation['errors']));
            }

            $items = $this->snapshotRows($this->activeRows($selection));
            [$periodStart, $periodEnd] = $this->periodBounds($selection, $items);

            $this->assertNoLockedTimesheets($items);
            $this->assertNoDuplicateFinalClosure($selection, $periodStart, $periodEnd);

            $closureId = 0;
            $this->connection->transaction(function () use ($selection, $items, $periodStart, $periodEnd, $userId, $status, $originalClosureId, &$closureId): void {
                $pendingNumber = 'PENDING-' . bin2hex(random_bytes(8));
                $this->connection->execute(
                    'INSERT INTO accounting_closures (
                        closure_number, closure_type, status, period_start, period_end, project_id, user_id, original_closure_id,
                        snapshot_hash, item_count, total_net_minutes, created_by_user_id, finalized_by_user_id, created_at, finalized_at, note
                     ) VALUES (
                        :closure_number, :closure_type, :status, :period_start, :period_end, :project_id, :user_id, :original_closure_id,
                        "", :item_count, :total_net_minutes, :created_by_user_id, :finalized_by_user_id, NOW(), NOW(), :note
                     )',
                    [
                        'closure_number' => $pendingNumber,
                        'closure_type' => $selection['type'],
                        'status' => $status,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'project_id' => $selection['project_id'],
                        'user_id' => $selection['user_id'],
                        'original_closure_id' => $originalClosureId,
                        'item_count' => count($items),
                        'total_net_minutes' => $this->totalNetMinutes($items),
                        'created_by_user_id' => $userId > 0 ? $userId : null,
                        'finalized_by_user_id' => $userId > 0 ? $userId : null,
                        'note' => trim((string) ($selection['note'] ?? '')),
                    ]
                );
                $closureId = $this->connection->lastInsertId();
                $closureNumber = $this->closureNumber($closureId, $selection['type'], $periodStart, $periodEnd, $status);

                $this->connection->execute(
                    'UPDATE accounting_closures SET closure_number = :closure_number WHERE id = :id',
                    ['id' => $closureId, 'closure_number' => $closureNumber]
                );

                foreach ($items as $item) {
                    $this->insertItem($closureId, $item);
                }

                $snapshotHash = $this->snapshotHash([
                    'closure_number' => $closureNumber,
                    'closure_type' => $selection['type'],
                    'status' => $status,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'project_id' => $selection['project_id'],
                    'user_id' => $selection['user_id'],
                    'items' => array_column($items, 'row_hash'),
                ]);

                $this->connection->execute(
                    'UPDATE accounting_closures SET snapshot_hash = :snapshot_hash WHERE id = :id',
                    ['id' => $closureId, 'snapshot_hash' => $snapshotHash]
                );
            });

            $closure = $this->findClosure($closureId);

            if ($closure === null) {
                throw new RuntimeException('Der Abschluss konnte nach dem Speichern nicht geladen werden.');
            }

            return $closure;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    public function validateFinalization(array $selection): array
    {
        $allRows = $this->rows($selection, 'all');
        $activeRows = array_values(array_filter($allRows, static fn (array $row): bool => (int) ($row['is_deleted'] ?? 0) === 0));
        $errors = [];

        if ($activeRows === []) {
            $errors[] = 'Im gewaehlten Abschlussbereich liegen keine aktiven Buchungen.';
        }

        if (($selection['type'] ?? 'month') === 'project' && ($selection['project_id'] ?? null) === null) {
            $errors[] = 'Fuer einen Projektabschluss muss ein Projekt ausgewaehlt werden.';
        }

        foreach ($allRows as $row) {
            if ((int) ($row['is_deleted'] ?? 0) === 1) {
                $errors[] = 'Im Abschlussbereich liegen archivierte Buchungen. Bitte den Bereich pruefen und bewusst bereinigen.';
                break;
            }
        }

        foreach ($activeRows as $row) {
            $entryType = (string) ($row['entry_type'] ?? 'work');

            if ($entryType === 'work' && (trim((string) ($row['start_time'] ?? '')) === '' || trim((string) ($row['end_time'] ?? '')) === '')) {
                $errors[] = 'Mindestens eine Arbeitsbuchung ist offen oder unvollstaendig.';
                break;
            }
        }

        foreach ($activeRows as $row) {
            if ((bool) ($row['needs_project_assignment'] ?? false)) {
                $errors[] = 'Mindestens eine Arbeitsbuchung hat keine Projektzuordnung.';
                break;
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public function listClosures(array $filters = []): array
    {
        if (!$this->hasClosureTables()) {
            return [];
        }

        $clauses = [$this->publicClosureClause()];
        $bindings = [];

        if (($filters['status'] ?? '') !== '') {
            $clauses[] = 'accounting_closures.status = :status';
            $bindings['status'] = (string) $filters['status'];
        }

        if (($filters['type'] ?? '') !== '') {
            $clauses[] = 'accounting_closures.closure_type = :type';
            $bindings['type'] = (string) $filters['type'];
        }

        return $this->connection->fetchAll(
            'SELECT accounting_closures.*, projects.project_number, projects.name AS project_name,
                    users.employee_number, users.first_name, users.last_name
             FROM accounting_closures
             LEFT JOIN projects ON projects.id = accounting_closures.project_id
             LEFT JOIN users ON users.id = accounting_closures.user_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY accounting_closures.created_at DESC, accounting_closures.id DESC
             LIMIT 100',
            $bindings
        );
    }

    public function findClosure(int $id): ?array
    {
        if ($id <= 0 || !$this->hasClosureTables()) {
            return null;
        }

        $closure = $this->connection->fetchOne(
            'SELECT accounting_closures.*, projects.project_number, projects.name AS project_name,
                    users.employee_number, users.first_name, users.last_name
             FROM accounting_closures
             LEFT JOIN projects ON projects.id = accounting_closures.project_id
             LEFT JOIN users ON users.id = accounting_closures.user_id
             WHERE accounting_closures.id = :id
             LIMIT 1',
            ['id' => $id]
        );

        if (!is_array($closure)) {
            return null;
        }

        $items = $this->connection->fetchAll(
            'SELECT * FROM accounting_closure_items WHERE closure_id = :id ORDER BY work_date ASC, employee_name ASC, start_time ASC, id ASC',
            ['id' => $id]
        );

        return $this->package($this->hydrateClosure($closure), $items);
    }

    public function isTimesheetLocked(int $timesheetId): bool
    {
        if ($timesheetId <= 0 || !$this->hasClosureTables()) {
            return false;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_closure_items
             INNER JOIN accounting_closures ON accounting_closures.id = accounting_closure_items.closure_id
             WHERE accounting_closure_items.timesheet_id = :timesheet_id
               AND accounting_closures.status IN ("final", "correction")',
            ['timesheet_id' => $timesheetId]
        ) ?? 0) > 0;
    }

    private function assertNoLockedTimesheets(array $items): void
    {
        foreach ($items as $item) {
            if ($this->isTimesheetLocked((int) ($item['timesheet_id'] ?? 0))) {
                throw new InvalidArgumentException('Mindestens eine Buchung ist bereits Teil eines festgeschriebenen Abschlusses.');
            }
        }
    }

    private function assertNoDuplicateFinalClosure(array $selection, string $periodStart, string $periodEnd): void
    {
        if (!$this->hasClosureTables()) {
            return;
        }

        $exists = (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_closures
             WHERE status = "final"
               AND closure_type = :closure_type
               AND period_start = :period_start
               AND period_end = :period_end
               AND ((project_id IS NULL AND :project_id_is_null = 1) OR project_id = :project_id)
               AND ((user_id IS NULL AND :user_id_is_null = 1) OR user_id = :user_id)
               AND ' . $this->publicClosureClause(),
            [
                'closure_type' => (string) ($selection['type'] ?? 'month'),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'project_id' => $selection['project_id'],
                'project_id_is_null' => $selection['project_id'] === null ? 1 : 0,
                'user_id' => $selection['user_id'],
                'user_id_is_null' => $selection['user_id'] === null ? 1 : 0,
            ]
        ) ?? 0) > 0;

        if ($exists) {
            throw new InvalidArgumentException('Dieser Abschlussbereich wurde bereits festgeschrieben.');
        }
    }

    private function lockName(array $selection): string
    {
        return self::ACCOUNTING_WRITE_LOCK;
    }

    private function publicClosureClause(): string
    {
        return $this->connection->columnExists('accounting_closures', 'source_type')
            ? '(accounting_closures.source_type IS NULL OR accounting_closures.source_type <> "employee_account_cutover")'
            : 'accounting_closures.closure_number NOT LIKE "ZK-%"';
    }

    private function acquireLock(string $lockName): void
    {
        $locked = (int) ($this->connection->fetchColumn('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $lockName]) ?? 0);

        if ($locked !== 1) {
            throw new RuntimeException('Der Abschlussbereich wird gerade verarbeitet. Bitte erneut versuchen.');
        }
    }

    private function releaseLock(string $lockName): void
    {
        $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName]);
    }

    private function activeRows(array $selection): array
    {
        return $this->rows($selection, 'active');
    }

    private function rows(array $selection, string $scope): array
    {
        return $this->bookingService->list([
            'date_from' => $selection['date_from'],
            'date_to' => $selection['date_to'],
            'project_id' => $selection['project_id'] !== null ? (string) $selection['project_id'] : '',
            'user_id' => $selection['user_id'] !== null ? (string) $selection['user_id'] : '',
            'entry_type' => '',
            'scope' => $scope,
        ]);
    }

    private function snapshotRows(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            $item = [
                'timesheet_id' => (int) ($row['id'] ?? 0),
                'work_date' => (string) ($row['work_date'] ?? ''),
                'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                'employee_number' => (string) ($row['employee_number'] ?? ''),
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
                'project_number' => (string) ($row['project_number'] ?? ''),
                'project_name' => (string) ($row['project_name_display'] ?? $row['project_name'] ?? ''),
                'entry_type' => (string) ($row['entry_type'] ?? ''),
                'source' => (string) ($row['source'] ?? 'app'),
                'source_label' => (string) ($row['source_label'] ?? 'App'),
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'gross_minutes' => (int) ($row['gross_minutes'] ?? 0),
                'break_minutes' => (int) ($row['break_minutes'] ?? 0),
                'net_minutes' => (int) ($row['net_minutes'] ?? 0),
                'expenses_amount' => (string) ($row['expenses_amount'] ?? '0.00'),
                'note' => (string) ($row['note'] ?? ''),
                'change_count' => (int) ($row['change_count'] ?? 0),
                'version_hint' => (string) ($row['version_hint'] ?? ''),
                'booking_updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
            $item['row_hash'] = $this->snapshotHash($item);
            $items[] = $item;
        }

        return $items;
    }

    private function insertItem(int $closureId, array $item): void
    {
        $this->connection->execute(
            'INSERT INTO accounting_closure_items (
                closure_id, timesheet_id, work_date, user_id, employee_number, employee_name, project_id, project_number, project_name,
                entry_type, source, source_label, start_time, end_time, gross_minutes, break_minutes, net_minutes, expenses_amount,
                note, change_count, version_hint, booking_updated_at, row_hash, created_at
             ) VALUES (
                :closure_id, :timesheet_id, :work_date, :user_id, :employee_number, :employee_name, :project_id, :project_number, :project_name,
                :entry_type, :source, :source_label, :start_time, :end_time, :gross_minutes, :break_minutes, :net_minutes, :expenses_amount,
                :note, :change_count, :version_hint, :booking_updated_at, :row_hash, NOW()
             )',
            ['closure_id' => $closureId, ...$item]
        );
    }

    private function package(array $closure, array $items): array
    {
        $closure = $this->hydrateClosure($closure);

        return [
            'closure' => $closure,
            'items' => $items,
            'employee_totals' => $this->totalsBy($items, 'employee_name', 'employee_number'),
            'project_totals' => $this->totalsBy($items, 'project_name', 'project_number'),
            'entry_type_totals' => $this->totalsBy($items, 'entry_type', null),
        ];
    }

    private function hydrateClosure(array $closure): array
    {
        $status = (string) ($closure['status'] ?? 'draft');
        $closure['status_label'] = match ($status) {
            'final' => 'FESTGESCHRIEBEN',
            'correction' => 'KORREKTURABSCHLUSS',
            default => 'VORLAEUFIG - nicht festgeschrieben',
        };
        $closure['period_label'] = $this->periodLabel([
            'type' => (string) ($closure['closure_type'] ?? 'month'),
            'period' => '',
            'date_from' => (string) ($closure['period_start'] ?? ''),
            'date_to' => (string) ($closure['period_end'] ?? ''),
            'project_id' => $closure['project_id'] ?? null,
            'user_id' => $closure['user_id'] ?? null,
        ], (string) ($closure['period_start'] ?? ''), (string) ($closure['period_end'] ?? ''));

        return $closure;
    }

    private function totalsBy(array $items, string $labelKey, ?string $numberKey): array
    {
        $totals = [];

        foreach ($items as $item) {
            $label = (string) ($item[$labelKey] ?? '');
            $number = $numberKey !== null ? (string) ($item[$numberKey] ?? '') : '';
            $key = $number !== '' ? $number . ' ' . $label : $label;
            $key = trim($key) !== '' ? trim($key) : 'Ohne Angabe';

            $totals[$key] ??= [
                'label' => $key,
                'count' => 0,
                'gross_minutes' => 0,
                'break_minutes' => 0,
                'net_minutes' => 0,
            ];
            $totals[$key]['count']++;
            $totals[$key]['gross_minutes'] += (int) ($item['gross_minutes'] ?? 0);
            $totals[$key]['break_minutes'] += (int) ($item['break_minutes'] ?? 0);
            $totals[$key]['net_minutes'] += (int) ($item['net_minutes'] ?? 0);
        }

        ksort($totals);

        return array_values($totals);
    }

    private function periodBounds(array $selection, array $items): array
    {
        $start = $selection['date_from'];
        $end = $selection['date_to'];

        if (($start === null || $end === null) && $items !== []) {
            $dates = array_values(array_filter(array_map(static fn (array $item): string => (string) ($item['work_date'] ?? ''), $items)));
            sort($dates);
            $start ??= $dates[0] ?? null;
            $end ??= $dates[count($dates) - 1] ?? null;
        }

        $today = (new DateTimeImmutable())->format('Y-m-d');

        return [$start ?? $today, $end ?? $today];
    }

    private function periodLabel(array $selection, string $start, string $end): string
    {
        if (($selection['type'] ?? 'month') === 'month') {
            return substr($start, 0, 7);
        }

        return $start === $end ? $start : $start . ' bis ' . $end;
    }

    private function closureNumber(int $id, string $type, string $start, string $end, string $status): string
    {
        $prefix = $status === 'correction' ? 'KORR' : 'ABR';
        $period = $type === 'month' ? substr($start, 0, 7) : $start . '_' . $end;

        return $prefix . '-' . strtoupper($type) . '-' . $period . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    private function previewNumber(array $selection, string $start, string $end): string
    {
        $period = ($selection['type'] ?? 'month') === 'month' ? substr($start, 0, 7) : $start . '_' . $end;

        return 'VORLAEUFIG-' . strtoupper((string) ($selection['type'] ?? 'month')) . '-' . $period;
    }

    private function snapshotHash(array $data): string
    {
        return hash('sha256', (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function totalNetMinutes(array $items): int
    {
        return array_sum(array_map(static fn (array $item): int => (int) ($item['net_minutes'] ?? 0), $items));
    }

    private function hasClosureTables(): bool
    {
        return $this->connection->tableExists('accounting_closures')
            && $this->connection->tableExists('accounting_closure_items');
    }

    private function normalizeMonth(string $month): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return new DateTimeImmutable('first day of this month');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && $date->format('Y-m') === $month && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            return $date;
        }

        return new DateTimeImmutable('first day of this month');
    }

    private function normalizeDate(mixed $value): ?string
    {
        $date = trim((string) $value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))
            ? $date
            : null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
