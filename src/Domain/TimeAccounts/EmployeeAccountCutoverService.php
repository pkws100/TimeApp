<?php

declare(strict_types=1);

namespace App\Domain\TimeAccounts;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class EmployeeAccountCutoverService
{
    public function __construct(
        private DatabaseConnection $connection,
        private AccountJournalService $journalService
    ) {
    }

    public function preview(array $payload): array
    {
        $record = $this->normalizePayload($payload);
        $user = $this->user((int) $record['user_id']);

        if ($user === null) {
            throw new InvalidArgumentException('Bitte einen gueltigen Mitarbeiter auswaehlen.');
        }

        $entitlement = (float) $record['annual_leave_entitlement_days'];
        $carryover = (float) $record['leave_carryover_days'];
        $remaining = (float) $record['opening_remaining_leave_days'];
        $openingAdjustment = $remaining - $entitlement - $carryover;
        $effectiveFrom = (string) $record['effective_from'];
        $warnings = [];

        if ($remaining > ($entitlement + $carryover)) {
            $warnings[] = 'Resturlaub ist groesser als Jahresanspruch plus Uebertrag; es entsteht eine positive Eroeffnungsanpassung.';
        }

        if ($remaining < 0) {
            $warnings[] = 'Der Resturlaub ist negativ.';
        }

        $today = new DateTimeImmutable('today');
        $cutoverDate = new DateTimeImmutable($effectiveFrom);
        $daysFromToday = (int) $today->diff($cutoverDate)->format('%r%a');

        if (abs($daysFromToday) > 366) {
            $warnings[] = 'Der Stichtag liegt mehr als ein Jahr vom heutigen Datum entfernt.';
        }

        $timesheetsAfter = $this->timesheetCountOnOrAfter((int) $record['user_id'], $effectiveFrom);

        if ($timesheetsAfter > 0) {
            $warnings[] = 'Es existieren bereits Buchungen ab dem gewuenschten Stichtag.';
        }

        if ($this->activeCutover((int) $record['user_id']) !== null) {
            $warnings[] = 'Fuer diesen Mitarbeiter ist bereits ein finaler Stichtag eingerichtet.';
        }

        $closureCount = $this->finalClosureCount((int) $record['user_id'], $effectiveFrom);

        if ($closureCount > 0) {
            $warnings[] = 'Im betroffenen Zeitraum existieren bereits finale Abrechnungsabschluesse.';
        }

        return [
            ...$record,
            'employee_name' => $this->userLabel($user),
            'opening_time_balance_label' => $this->signedDurationLabel((int) $record['opening_time_balance_minutes']),
            'opening_adjustment_days' => $openingAdjustment,
            'locked_until' => (new DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d'),
            'timesheets_after_cutover' => $timesheetsAfter,
            'final_closures_in_legacy_period' => $closureCount,
            'warnings' => $warnings,
        ];
    }

    public function finalize(array $payload, int $adminUserId): array
    {
        $preview = $this->preview($payload);
        $userId = (int) $preview['user_id'];

        return $this->withUserLock($userId, function () use ($preview, $adminUserId): array {
            return $this->connection->transaction(function () use ($preview, $adminUserId): array {
                $userId = (int) $preview['user_id'];

                if ($this->activeCutover($userId) !== null) {
                    throw new InvalidArgumentException('Fuer diesen Mitarbeiter ist bereits ein finaler Stichtag eingerichtet.');
                }

                $this->connection->execute(
                    'INSERT INTO employee_account_cutovers (
                        user_id, active_final_user_id, effective_from, opening_time_balance_minutes, leave_year,
                        annual_leave_entitlement_days, leave_carryover_days, opening_remaining_leave_days,
                        source_reference, note, status, created_by_user_id, finalized_by_user_id, finalized_at,
                        reversed_by_user_id, reversed_at, reversal_note, created_at, updated_at
                     ) VALUES (
                        :user_id, :active_final_user_id, :effective_from, :opening_time_balance_minutes, :leave_year,
                        :annual_leave_entitlement_days, :leave_carryover_days, :opening_remaining_leave_days,
                        :source_reference, :note, "final", :created_by_user_id, :finalized_by_user_id, NOW(),
                        NULL, NULL, NULL, NOW(), NOW()
                     )',
                    [
                        'user_id' => $userId,
                        'active_final_user_id' => $userId,
                        'effective_from' => (string) $preview['effective_from'],
                        'opening_time_balance_minutes' => (int) $preview['opening_time_balance_minutes'],
                        'leave_year' => (int) $preview['leave_year'],
                        'annual_leave_entitlement_days' => number_format((float) $preview['annual_leave_entitlement_days'], 2, '.', ''),
                        'leave_carryover_days' => number_format((float) $preview['leave_carryover_days'], 2, '.', ''),
                        'opening_remaining_leave_days' => number_format((float) $preview['opening_remaining_leave_days'], 2, '.', ''),
                        'source_reference' => $preview['source_reference'] ?: null,
                        'note' => $preview['note'] ?: null,
                        'created_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                        'finalized_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                    ]
                );

                $cutoverId = $this->connection->lastInsertId();
                $this->journalService->addTimeEntry(
                    $userId,
                    (string) $preview['effective_from'],
                    (int) $preview['opening_time_balance_minutes'],
                    'opening_balance',
                    'employee_account_cutover',
                    $cutoverId,
                    'Eroeffnungssaldo zum Stichtag ' . (string) $preview['effective_from'],
                    $adminUserId,
                    $adminUserId
                );

                $leaveYear = (int) $preview['leave_year'];
                $this->journalService->addVacationEntry($userId, $leaveYear, (string) $preview['effective_from'], (float) $preview['annual_leave_entitlement_days'], 'annual_entitlement', 'employee_account_cutover', $cutoverId, 'Jahresurlaub zum Stichtag', $adminUserId, $adminUserId);
                $this->journalService->addVacationEntry($userId, $leaveYear, (string) $preview['effective_from'], (float) $preview['leave_carryover_days'], 'carryover', 'employee_account_cutover', $cutoverId, 'Uebertrag zum Stichtag', $adminUserId, $adminUserId);
                $this->journalService->addVacationEntry($userId, $leaveYear, (string) $preview['effective_from'], (float) $preview['opening_adjustment_days'], 'opening_adjustment', 'employee_account_cutover', $cutoverId, 'Anpassung auf Resturlaub am Stichtag', $adminUserId, $adminUserId);
                $this->createLegacyClosure($cutoverId, $userId, (string) $preview['effective_from'], $adminUserId);

                return $this->find($cutoverId) ?? [];
            });
        });
    }

    public function reverse(int $cutoverId, int $adminUserId, string $reason): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine Begruendung fuer die Revidierung angeben.');
        }

        $cutover = $this->find($cutoverId);

        if ($cutover === null || (string) ($cutover['status'] ?? '') !== 'final') {
            throw new InvalidArgumentException('Der finale Stichtag wurde nicht gefunden.');
        }

        $userId = (int) $cutover['user_id'];

        return $this->withUserLock($userId, function () use ($cutoverId, $adminUserId, $reason, $userId): array {
            return $this->connection->transaction(function () use ($cutoverId, $adminUserId, $reason, $userId): array {
                $fresh = $this->find($cutoverId);

                if ($fresh === null || (string) ($fresh['status'] ?? '') !== 'final') {
                    throw new InvalidArgumentException('Der finale Stichtag wurde nicht gefunden.');
                }

                foreach ($this->entriesForCutover('time_account_entries', $cutoverId) as $entry) {
                    $this->journalService->reverseTimeEntry((int) $entry['id'], $adminUserId, $reason, (string) $fresh['effective_from'], true);
                }

                foreach ($this->entriesForCutover('vacation_account_entries', $cutoverId) as $entry) {
                    $this->journalService->reverseVacationEntry((int) $entry['id'], $adminUserId, $reason, (string) $fresh['effective_from'], true);
                }

                $this->connection->execute(
                    'UPDATE employee_account_cutovers
                     SET status = "reversed",
                         active_final_user_id = NULL,
                         reversed_by_user_id = :reversed_by_user_id,
                         reversed_at = NOW(),
                         reversal_note = :reversal_note,
                         updated_at = NOW()
                     WHERE id = :id AND user_id = :user_id AND status = "final"',
                    [
                        'id' => $cutoverId,
                        'user_id' => $userId,
                        'reversed_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                        'reversal_note' => $reason,
                    ]
                );
                $this->releaseLegacyClosure($cutoverId, $reason);

                return $this->find($cutoverId) ?? [];
            });
        });
    }

    public function addManualTimeAdjustment(int $userId, string $effectiveDate, int $minutes, string $reason, int $adminUserId, string $type = 'manual_adjustment'): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine Begruendung angeben.');
        }

        if (!in_array($type, ['manual_adjustment', 'payout', 'time_off_compensation'], true)) {
            $type = 'manual_adjustment';
        }

        $this->assertEffectiveDateAfterCutover($userId, $effectiveDate);

        return $this->journalService->addTimeEntry($userId, $effectiveDate, $minutes, $type, null, null, $reason, $adminUserId, $adminUserId);
    }

    public function addManualVacationAdjustment(int $userId, int $leaveYear, string $effectiveDate, float $days, string $reason, int $adminUserId, string $type = 'manual_adjustment'): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Bitte eine Begruendung angeben.');
        }

        if (!in_array($type, ['manual_adjustment', 'expiry'], true)) {
            $type = 'manual_adjustment';
        }

        $this->assertEffectiveDateAfterCutover($userId, $effectiveDate);

        return $this->journalService->addVacationEntry($userId, $leaveYear, $effectiveDate, $days, $type, null, null, $reason, $adminUserId, $adminUserId);
    }

    public function saveDraft(array $payload, int $adminUserId): array
    {
        $record = $this->normalizePayload($payload);
        $userId = (int) $record['user_id'];

        if ($this->user($userId) === null) {
            throw new InvalidArgumentException('Bitte einen gueltigen Mitarbeiter auswaehlen.');
        }

        return $this->withUserLock($userId, function () use ($record, $adminUserId, $userId): array {
            return $this->connection->transaction(function () use ($record, $adminUserId, $userId): array {
                $existing = $this->connection->fetchOne(
                    'SELECT id FROM employee_account_cutovers WHERE user_id = :user_id AND status = "draft" ORDER BY id DESC LIMIT 1',
                    ['user_id' => $userId]
                );
                $bindings = [
                    'user_id' => $userId,
                    'effective_from' => (string) $record['effective_from'],
                    'opening_time_balance_minutes' => (int) $record['opening_time_balance_minutes'],
                    'leave_year' => (int) $record['leave_year'],
                    'annual_leave_entitlement_days' => number_format((float) $record['annual_leave_entitlement_days'], 2, '.', ''),
                    'leave_carryover_days' => number_format((float) $record['leave_carryover_days'], 2, '.', ''),
                    'opening_remaining_leave_days' => number_format((float) $record['opening_remaining_leave_days'], 2, '.', ''),
                    'source_reference' => $record['source_reference'] ?: null,
                    'note' => $record['note'] ?: null,
                    'created_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                ];

                if ($existing !== null) {
                    $this->connection->execute(
                        'UPDATE employee_account_cutovers
                         SET effective_from = :effective_from,
                             opening_time_balance_minutes = :opening_time_balance_minutes,
                             leave_year = :leave_year,
                             annual_leave_entitlement_days = :annual_leave_entitlement_days,
                             leave_carryover_days = :leave_carryover_days,
                             opening_remaining_leave_days = :opening_remaining_leave_days,
                             source_reference = :source_reference,
                             note = :note,
                             updated_at = NOW()
                         WHERE id = :id AND user_id = :user_id AND status = "draft"',
                        ['id' => (int) $existing['id']] + $bindings
                    );

                    return $this->find((int) $existing['id']) ?? [];
                }

                $this->connection->execute(
                    'INSERT INTO employee_account_cutovers (
                        user_id, active_final_user_id, effective_from, opening_time_balance_minutes, leave_year,
                        annual_leave_entitlement_days, leave_carryover_days, opening_remaining_leave_days,
                        source_reference, note, status, created_by_user_id, finalized_by_user_id, finalized_at,
                        reversed_by_user_id, reversed_at, reversal_note, created_at, updated_at
                     ) VALUES (
                        :user_id, NULL, :effective_from, :opening_time_balance_minutes, :leave_year,
                        :annual_leave_entitlement_days, :leave_carryover_days, :opening_remaining_leave_days,
                        :source_reference, :note, "draft", :created_by_user_id, NULL, NULL,
                        NULL, NULL, NULL, NOW(), NOW()
                     )',
                    $bindings
                );

                return $this->find($this->connection->lastInsertId()) ?? [];
            });
        });
    }

    public function activeCutover(int $userId): ?array
    {
        if ($userId <= 0 || !$this->connection->tableExists('employee_account_cutovers')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT cutovers.*, users.employee_number, users.first_name, users.last_name, users.email
             FROM employee_account_cutovers AS cutovers
             INNER JOIN users ON users.id = cutovers.user_id
             WHERE cutovers.user_id = :user_id
               AND cutovers.status = "final"
               AND cutovers.active_final_user_id = :active_final_user_id
             ORDER BY cutovers.effective_from DESC, cutovers.id DESC
             LIMIT 1',
            ['user_id' => $userId, 'active_final_user_id' => $userId]
        );
    }

    public function find(int $cutoverId): ?array
    {
        if ($cutoverId <= 0 || !$this->connection->tableExists('employee_account_cutovers')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT cutovers.*, users.employee_number, users.first_name, users.last_name, users.email
             FROM employee_account_cutovers AS cutovers
             INNER JOIN users ON users.id = cutovers.user_id
             WHERE cutovers.id = :id
             LIMIT 1',
            ['id' => $cutoverId]
        );
    }

    public function activeCutoversByUser(): array
    {
        if (!$this->connection->tableExists('employee_account_cutovers')) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT * FROM employee_account_cutovers
             WHERE status = "final" AND active_final_user_id IS NOT NULL
             ORDER BY effective_from DESC, id DESC'
        );
        $byUser = [];

        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);

            if ($userId > 0 && !isset($byUser[$userId])) {
                $byUser[$userId] = $row;
            }
        }

        return $byUser;
    }

    public function protocolPdf(int $cutoverId, array $companySettings = []): array
    {
        $cutover = $this->find($cutoverId);

        if ($cutover === null) {
            throw new InvalidArgumentException('Der Stichtag wurde nicht gefunden.');
        }

        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('mPDF ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $companyName = trim((string) ($companySettings['company_name'] ?? ''));
        $address = trim(implode(', ', array_filter([
            (string) ($companySettings['address_line_1'] ?? ''),
            trim((string) ($companySettings['postal_code'] ?? '') . ' ' . (string) ($companySettings['city'] ?? '')),
        ])));
        $employee = $this->userLabel($cutover);
        $html = '<h1>Stichtagsprotokoll Zeit- und Urlaubskonto</h1>'
            . '<p><strong>' . $this->e($companyName !== '' ? $companyName : 'Unternehmen') . '</strong><br>' . $this->e($address) . '</p>'
            . '<table width="100%" cellpadding="6" cellspacing="0" border="1">'
            . '<tr><th align="left">Mitarbeiter</th><td>' . $this->e($employee) . '</td></tr>'
            . '<tr><th align="left">Personalnummer</th><td>' . $this->e((string) ($cutover['employee_number'] ?? '')) . '</td></tr>'
            . '<tr><th align="left">Stichtag</th><td>' . $this->e((string) $cutover['effective_from']) . '</td></tr>'
            . '<tr><th align="left">Eroeffnungssaldo Zeitkonto</th><td>' . $this->e($this->signedDurationLabel((int) $cutover['opening_time_balance_minutes'])) . '</td></tr>'
            . '<tr><th align="left">Urlaubsjahr</th><td>' . (int) $cutover['leave_year'] . '</td></tr>'
            . '<tr><th align="left">Jahresanspruch</th><td>' . $this->formatDays((float) $cutover['annual_leave_entitlement_days']) . '</td></tr>'
            . '<tr><th align="left">Uebertrag</th><td>' . $this->formatDays((float) $cutover['leave_carryover_days']) . '</td></tr>'
            . '<tr><th align="left">Resturlaub am Stichtag</th><td>' . $this->formatDays((float) $cutover['opening_remaining_leave_days']) . '</td></tr>'
            . '<tr><th align="left">Quelle</th><td>' . $this->e((string) ($cutover['source_reference'] ?? '')) . '</td></tr>'
            . '<tr><th align="left">Bemerkung</th><td>' . nl2br($this->e((string) ($cutover['note'] ?? ''))) . '</td></tr>'
            . '<tr><th align="left">Finalisiert am</th><td>' . $this->e((string) ($cutover['finalized_at'] ?? '')) . '</td></tr>'
            . '</table>'
            . '<p>Die aufgefuehrten Werte wurden aus den bisherigen Unterlagen in das neue System uebernommen. Gesetzliche, tarifliche oder arbeitsvertragliche Ansprueche werden durch die technische Uebernahme nicht abbedungen. Nachgewiesene Abweichungen koennen durch revisionsfaehige Korrekturbuchungen berichtigt werden.</p>'
            . '<table width="100%" style="margin-top:60px"><tr><td>_____________________________<br>Arbeitgeber</td><td>_____________________________<br>Mitarbeiter/Kenntnisnahme</td></tr></table>';

        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'baustelle-mpdf';

        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new RuntimeException('PDF-Temp-Verzeichnis konnte nicht erstellt werden.');
        }

        $pdf = new \Mpdf\Mpdf(['tempDir' => $tempDir]);
        $pdf->WriteHTML($html);

        return [
            'content' => $pdf->Output('', 'S'),
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="stichtagsprotokoll-' . $cutoverId . '.pdf"',
            ],
        ];
    }

    private function normalizePayload(array $payload): array
    {
        $effectiveFrom = $this->normalizeDate($payload['effective_from'] ?? null);

        if ($effectiveFrom === null) {
            throw new InvalidArgumentException('Bitte einen gueltigen Stichtag angeben.');
        }

        $leaveYear = (int) ($payload['leave_year'] ?? (int) substr($effectiveFrom, 0, 4));

        if ($leaveYear < 2000 || $leaveYear > 2100) {
            throw new InvalidArgumentException('Bitte ein gueltiges Urlaubsjahr angeben.');
        }

        return [
            'user_id' => max(0, (int) ($payload['user_id'] ?? 0)),
            'effective_from' => $effectiveFrom,
            'opening_time_balance_minutes' => $this->parseSignedDuration($payload['opening_time_balance'] ?? ($payload['opening_time_balance_minutes'] ?? '0:00')),
            'leave_year' => $leaveYear,
            'annual_leave_entitlement_days' => $this->decimalDays($payload['annual_leave_entitlement_days'] ?? 0),
            'leave_carryover_days' => $this->decimalDays($payload['leave_carryover_days'] ?? 0),
            'opening_remaining_leave_days' => $this->decimalDays($payload['opening_remaining_leave_days'] ?? 0),
            'source_reference' => mb_substr(trim((string) ($payload['source_reference'] ?? '')), 0, 255),
            'note' => trim((string) ($payload['note'] ?? '')),
        ];
    }

    private function parseSignedDuration(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        $text = trim((string) $value);

        if (preg_match('/^([+-])?\s*(\d{1,5})(?::([0-5]\d))?$/', $text, $matches) !== 1) {
            throw new InvalidArgumentException('Bitte den Zeitkontostand im Format +12:30, -03:45 oder 0:00 angeben.');
        }

        $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
        $hours = (int) $matches[2];
        $minutes = isset($matches[3]) ? (int) $matches[3] : 0;

        return $sign * (($hours * 60) + $minutes);
    }

    private function decimalDays(mixed $value): float
    {
        $number = (float) str_replace(',', '.', trim((string) $value));

        return round($number, 2);
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            return $date->format('Y-m-d');
        }

        return null;
    }

    private function createLegacyClosure(int $cutoverId, int $userId, string $effectiveFrom, int $adminUserId): void
    {
        if (!$this->connection->tableExists('accounting_closures')) {
            return;
        }

        $periodEnd = (new DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d');

        if ($periodEnd < '1900-01-01') {
            return;
        }

        $this->connection->execute(
            'INSERT INTO accounting_closures (
                closure_number, closure_type, status, period_start, period_end, project_id, user_id, original_closure_id,
                snapshot_hash, item_count, total_net_minutes, created_by_user_id, finalized_by_user_id, created_at, finalized_at, note
             ) VALUES (
                :closure_number, "month", "final", "1900-01-01", :period_end, NULL, :user_id, NULL,
                :snapshot_hash, 0, 0, :created_by_user_id, :finalized_by_user_id, NOW(), NOW(), :note
             )',
            [
                'closure_number' => 'ZK-' . $cutoverId,
                'period_end' => $periodEnd,
                'user_id' => $userId,
                'snapshot_hash' => hash('sha256', 'employee-account-cutover-' . $cutoverId),
                'created_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                'finalized_by_user_id' => $adminUserId > 0 ? $adminUserId : null,
                'note' => 'Zeitkonto-Stichtag: Altzeitraum bis ' . $periodEnd . ' festgeschrieben.',
            ]
        );
    }

    private function releaseLegacyClosure(int $cutoverId, string $reason): void
    {
        if (!$this->connection->tableExists('accounting_closures')) {
            return;
        }

        $this->connection->execute(
            'UPDATE accounting_closures
             SET status = "draft",
                 note = CONCAT(COALESCE(note, ""), "\nRevidiert: ", :reason)
             WHERE closure_number = :closure_number',
            ['closure_number' => 'ZK-' . $cutoverId, 'reason' => $reason]
        );
    }

    private function timesheetCountOnOrAfter(int $userId, string $effectiveFrom): int
    {
        if (!$this->connection->tableExists('timesheets')) {
            return 0;
        }

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*) FROM timesheets WHERE user_id = :user_id AND work_date >= :effective_from AND COALESCE(is_deleted, 0) = 0',
            ['user_id' => $userId, 'effective_from' => $effectiveFrom]
        ) ?? 0);
    }

    private function finalClosureCount(int $userId, string $effectiveFrom): int
    {
        if (!$this->connection->tableExists('accounting_closures')) {
            return 0;
        }

        $legacyEnd = (new DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d');

        return (int) ($this->connection->fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_closures
             WHERE status IN ("final", "correction")
               AND period_start <= :legacy_end
               AND period_end >= "1900-01-01"
               AND (user_id IS NULL OR user_id = :user_id)',
            ['legacy_end' => $legacyEnd, 'user_id' => $userId]
        ) ?? 0);
    }

    private function assertEffectiveDateAfterCutover(int $userId, string $effectiveDate): void
    {
        $date = $this->normalizeDate($effectiveDate);

        if ($date === null) {
            throw new InvalidArgumentException('Bitte ein gueltiges Wirksamkeitsdatum angeben.');
        }

        $cutover = $this->activeCutover($userId);

        if ($cutover === null) {
            throw new InvalidArgumentException('Bitte zuerst einen Zeitkonto-Stichtag fuer diesen Mitarbeiter finalisieren.');
        }

        if ($date < (string) $cutover['effective_from']) {
            throw new InvalidArgumentException('Korrekturbuchungen muessen am oder nach dem Zeitkonto-Stichtag liegen.');
        }
    }

    private function user(int $userId): ?array
    {
        if ($userId <= 0 || !$this->connection->tableExists('users')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT id, employee_number, first_name, last_name, email FROM users WHERE id = :id AND COALESCE(is_deleted, 0) = 0 LIMIT 1',
            ['id' => $userId]
        );
    }

    private function userLabel(array $user): string
    {
        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        $number = trim((string) ($user['employee_number'] ?? ''));

        return trim(($number !== '' ? $number . ' ' : '') . ($name !== '' ? $name : (string) ($user['email'] ?? '')));
    }

    private function signedDurationLabel(int $minutes): string
    {
        $prefix = $minutes >= 0 ? '+' : '-';
        $absolute = abs($minutes);

        return $prefix . sprintf('%02d:%02d', intdiv($absolute, 60), $absolute % 60);
    }

    private function formatDays(float $days): string
    {
        return number_format($days, 2, ',', '.') . ' Tage';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function entriesForCutover(string $table, int $cutoverId): array
    {
        if (!$this->connection->tableExists($table)) {
            return [];
        }

        return $this->connection->fetchAll(
            'SELECT * FROM ' . $table . ' WHERE source_type = "employee_account_cutover" AND source_id = :source_id ORDER BY id ASC',
            ['source_id' => $cutoverId]
        );
    }

    private function withUserLock(int $userId, callable $callback): mixed
    {
        $lockName = 'employee-account-cutover-' . max(0, $userId);
        $locked = (int) ($this->connection->fetchColumn('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $lockName]) ?? 0);

        if ($locked !== 1) {
            throw new InvalidArgumentException('Das Zeitkonto dieses Mitarbeiters wird gerade verarbeitet. Bitte erneut versuchen.');
        }

        try {
            return $callback();
        } finally {
            $this->connection->fetchColumn('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lockName]);
        }
    }
}
