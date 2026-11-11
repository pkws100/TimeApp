<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\TimesheetAuditService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Append actual break evidence. Existing work stamps and accounting values stay immutable. */
final class WorkTimePauseCorrectionService
{
    private TimesheetWriteGuard $guard;
    private ?bool $auditAvailable = null;

    public function __construct(
        private DatabaseConnection $connection,
        private WorkTimePolicyService $policies,
        private WorkTimePreviewService $preview,
        ?TimesheetWriteGuard $guard = null
    ) {
        $this->guard = $guard ?? new TimesheetWriteGuard($connection);
    }

    public function details(int $timesheetId, bool $forUpdate = false): array
    {
        $entry = $this->entry($timesheetId, $forUpdate);
        $breaks = $this->breaks($timesheetId, $forUpdate);
        $policy = $this->policies->policyFor((int) $entry['user_id'], (string) $entry['work_date']);
        $calculation = $this->preview->forTimesheet($entry, $policy)['calculation'];
        $documentedSeconds = 0;
        $block = '';
        $intervals = [];
        try {
            foreach ($breaks as $break) {
                if ($break['break_ended_at'] === null) {
                    throw new InvalidArgumentException('Eine bestehende Pause ist noch offen. Bitte diese zuerst im urspruenglichen Buchungsablauf abschliessen.');
                }
                $start = WorkTimeLocalTime::parse((string) $break['break_started_at']);
                $end = WorkTimeLocalTime::parse((string) $break['break_ended_at']);
                if ($end <= $start) {
                    throw new InvalidArgumentException('Ein vorhandenes Pausenintervall ist ungueltig. Es wird nicht automatisch ersetzt.');
                }
                if (!empty($entry['start_time']) && !empty($entry['end_time'])) {
                    $workStart = WorkTimeLocalTime::parse($entry['work_date'] . ' ' . $entry['start_time']);
                    $workEndDate = $entry['end_time'] < $entry['start_time'] ? (new DateTimeImmutable($entry['work_date']))->modify('+1 day')->format('Y-m-d') : $entry['work_date'];
                    $workEnd = WorkTimeLocalTime::parse($workEndDate . ' ' . $entry['end_time']);
                    if ($start < $workStart || $end > $workEnd) {
                        throw new InvalidArgumentException('Ein vorhandenes Pausenintervall liegt ausserhalb der Arbeitsbuchung. Es muss gesondert geprueft werden und wird hier nicht ersetzt.');
                    }
                }
                $intervals[] = [$start->getTimestamp(), $end->getTimestamp()];
                $documentedSeconds += $end->getTimestamp() - $start->getTimestamp();
            }
            $this->assertNoOverlap($intervals);
        } catch (InvalidArgumentException $exception) {
            $block = $exception->getMessage();
        }
        $signed = $this->hasActiveSignature($timesheetId, $forUpdate);
        if (!$this->policies->enabled()) {
            $block = 'Arbeitszeitfenster sind global deaktiviert.';
        } elseif (!$this->auditAvailable()) {
            $block = 'Die Migration fuer revisionsfaehige Pausennachweise wurde noch nicht angewendet.';
        } elseif ($policy === null) {
            $block = 'Fuer den Originalarbeitstag besteht keine individuelle Arbeitszeitregel.';
        } elseif ((int) ($entry['is_deleted'] ?? 0) === 1 || $entry['entry_type'] !== 'work') {
            $block = 'Archivierte Buchungen und Abwesenheiten werden hier nicht bearbeitet.';
        } elseif (empty($entry['start_time']) || empty($entry['end_time'])) {
            $block = 'Der Arbeitseinsatz ist noch nicht vollstaendig abgeschlossen.';
        } elseif ($signed) {
            $block = 'Eine aktive Kundenbestaetigung schuetzt diese Buchung. Pausen werden nicht ohne ausdrueckliche Klaerung der Bestaetigung geaendert.';
        }
        try {
            $this->guard->assertAccountingPeriodOpen((int) $entry['user_id'], $entry['project_id'] !== null ? (int) $entry['project_id'] : null, (string) $entry['work_date']);
        } catch (InvalidArgumentException $exception) {
            $block = $exception->getMessage();
        }
        $missing = max(0, (int) $entry['break_minutes'] * 60 - $documentedSeconds);
        if ($block === '' && $missing === 0) {
            $block = 'Die gespeicherte Pausendauer ist bereits vollstaendig belegt. Vorhandene Intervalle werden in diesem Nachweisweg nicht ersetzt.';
        }
        $snapshot = ['timesheet' => $entry, 'breaks' => $breaks, 'calculation' => $calculation];
        $source = ['timesheet' => $entry, 'breaks' => $breaks, 'signed' => $signed, 'policy_version_id' => $policy['policy_version_id'] ?? null];

        return [
            ...$snapshot,
            'stored_break_minutes' => (int) $entry['break_minutes'],
            'documented_break_minutes' => round($documentedSeconds / 60, 2),
            'missing_break_minutes' => round($missing / 60, 2),
            'clarification_reason' => $calculation['clarification_reason'] ?? ($missing > 0 ? 'Die Lage der gespeicherten Pausendauer ist noch nicht vollstaendig belegt.' : 'Die gespeicherte Pausendauer ist durch konkrete Intervalle belegt; offene Aussenzeiten werden separat geprueft.'),
            'can_document' => $block === '', 'blocked_reason' => $block, 'has_active_signature' => $signed,
            'source_token' => hash('sha256', $this->json($source)),
            'history' => $this->history($timesheetId),
        ];
    }

    public function listForUser(int $userId, string $dateFrom, string $dateTo): array
    {
        if (!$this->policies->enabled()) {
            return [];
        }
        $this->date($dateFrom);
        $this->date($dateTo);
        if ($dateFrom > $dateTo || (new DateTimeImmutable($dateFrom))->modify('+1 year')->format('Y-m-d') < $dateTo) {
            throw new InvalidArgumentException('Bitte einen gueltigen Zeitraum von hoechstens einem Jahr waehlen.');
        }
        $rows = $this->connection->fetchAll('SELECT id FROM timesheets WHERE user_id = :user_id AND work_date BETWEEN :date_from AND :date_to AND entry_type = "work" AND COALESCE(is_deleted, 0) = 0 ORDER BY work_date DESC, id DESC LIMIT 100', ['user_id' => $userId, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
        $result = [];
        foreach ($rows as $row) {
            $detail = $this->details((int) $row['id']);
            if (($detail['calculation']['calculation_status'] ?? '') === 'needs_clarification' || $detail['history'] !== []) {
                $result[] = $detail;
            }
        }
        return $result;
    }

    /** Calling controllers must additionally authorize both employee scope and timesheet management. */
    public function document(int $timesheetId, array $intervals, int $actorId, string $reason, string $requestId, string $sourceToken): array
    {
        if (!$this->policies->enabled()) {
            throw new InvalidArgumentException('Arbeitszeitfenster sind global deaktiviert.');
        }
        if (!$this->auditAvailable()) {
            throw new InvalidArgumentException('Die Migration fuer revisionsfaehige Pausennachweise wurde noch nicht angewendet.');
        }
        $reason = trim($reason);
        if ($actorId <= 0 || mb_strlen($reason) < 5 || mb_strlen($reason) > 2000 || $requestId === '' || strlen($requestId) > 80 || !preg_match('/^[a-f0-9]{64}$/D', $sourceToken)) {
            throw new InvalidArgumentException('Akteur, Pflichtbegruendung, Anfrage-ID und aktueller Pruefstand sind erforderlich.');
        }
        if ($intervals === [] || count($intervals) > 12) {
            throw new InvalidArgumentException('Bitte ein bis zwoelf tatsaechlich genommene Pausenintervalle angeben.');
        }
        $normalized = [];
        foreach ($intervals as $interval) {
            if (!is_array($interval) || !is_string($interval['start'] ?? null) || !is_string($interval['end'] ?? null)) {
                throw new InvalidArgumentException('Jede Pause benoetigt einen vollstaendigen Beginn und ein Ende.');
            }
            $start = WorkTimeLocalTime::fromInput($interval['start']);
            $end = WorkTimeLocalTime::fromInput($interval['end']);
            if ($end <= $start || $start->format('s') !== '00' || $end->format('s') !== '00') {
                throw new InvalidArgumentException('Pausen muessen minutengenau beginnen und nach ihrem Beginn enden.');
            }
            $normalized[] = ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
        }
        usort($normalized, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $this->assertNoOverlap(array_map(static fn (array $i): array => [(new DateTimeImmutable($i['start']))->getTimestamp(), (new DateTimeImmutable($i['end']))->getTimestamp()], $normalized));

        return $this->guard->withAccountingWriteLock(function () use ($timesheetId, $normalized, $actorId, $reason, $requestId, $sourceToken): array {
            return $this->connection->transaction(function () use ($timesheetId, $normalized, $actorId, $reason, $requestId, $sourceToken): array {
                $previous = $this->connection->fetchOne('SELECT * FROM work_time_pause_corrections WHERE actor_user_id = :actor AND request_id = :request_id', ['actor' => $actorId, 'request_id' => $requestId]);
                if ($previous !== null) {
                    if ((int) $previous['timesheet_id'] !== $timesheetId || $previous['reason'] !== $reason || $previous['intervals_json'] !== $this->json($normalized) || $previous['source_token'] !== $sourceToken) {
                        throw new InvalidArgumentException('Diese Anfrage-ID wurde bereits fuer einen anderen Pausennachweis verwendet.');
                    }
                    return ['replayed' => true, 'audit_id' => (int) $previous['id'], 'data' => $this->details($timesheetId)];
                }
                // Signature creation takes this same row lock before recording a signature.
                $before = $this->details($timesheetId, true);
                if (!$before['can_document']) {
                    throw new InvalidArgumentException($before['blocked_reason']);
                }
                if (!hash_equals($before['source_token'], $sourceToken)) {
                    throw new InvalidArgumentException('Buchung, Pausen oder Schutzstatus wurden inzwischen geaendert. Bitte erneut pruefen.');
                }
                $entry = $before['timesheet'];
                $workStart = WorkTimeLocalTime::parse($entry['work_date'] . ' ' . $entry['start_time']);
                $endDate = $entry['end_time'] < $entry['start_time'] ? (new DateTimeImmutable($entry['work_date']))->modify('+1 day')->format('Y-m-d') : $entry['work_date'];
                $workEnd = WorkTimeLocalTime::parse($endDate . ' ' . $entry['end_time']);
                $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
                $all = array_map(static fn (array $b): array => [(new DateTimeImmutable($b['break_started_at']))->getTimestamp(), (new DateTimeImmutable($b['break_ended_at']))->getTimestamp()], $before['breaks']);
                foreach ($normalized as $interval) {
                    $start = WorkTimeLocalTime::parse($interval['start']);
                    $end = WorkTimeLocalTime::parse($interval['end']);
                    if ($start < $workStart || $end > $workEnd || $end > $now) {
                        throw new InvalidArgumentException('Jede Pause muss innerhalb der abgeschlossenen Arbeitsbuchung und darf nicht in der Zukunft liegen.');
                    }
                    $all[] = [$start->getTimestamp(), $end->getTimestamp()];
                }
                $this->assertNoOverlap($all);
                $seconds = array_sum(array_map(static fn (array $i): int => $i[1] - $i[0], $all));
                if ($seconds !== (int) $entry['break_minutes'] * 60) {
                    throw new InvalidArgumentException('Die belegten Pausen muessen zusammen exakt der gespeicherten Dauer von ' . (int) $entry['break_minutes'] . ' Minuten entsprechen. Rechnerische Abzuege duerfen nicht als tatsaechliche Pause unterstellt werden.');
                }
                foreach ($normalized as $interval) {
                    $this->connection->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source, note, created_at, updated_at) VALUES (:timesheet, :user_id, :starts_at, :ends_at, "admin_evidence", :note, NOW(), NOW())', ['timesheet' => $timesheetId, 'user_id' => $entry['user_id'], 'starts_at' => $interval['start'], 'ends_at' => $interval['end'], 'note' => mb_substr('Nachweis: ' . $reason, 0, 255)]);
                }
                $after = $this->details($timesheetId);
                $this->connection->execute('INSERT INTO work_time_pause_corrections (timesheet_id, user_id, actor_user_id, request_id, reason, source_token, intervals_json, before_json, after_json) VALUES (:timesheet, :user_id, :actor, :request_id, :reason, :source_token, :intervals, :before_json, :after_json)', ['timesheet' => $timesheetId, 'user_id' => $entry['user_id'], 'actor' => $actorId, 'request_id' => $requestId, 'reason' => $reason, 'source_token' => $sourceToken, 'intervals' => $this->json($normalized), 'before_json' => $this->json($this->snapshot($before)), 'after_json' => $this->json($this->snapshot($after))]);
                $auditId = $this->connection->lastInsertId();
                (new TimesheetAuditService($this->connection))->log($timesheetId, 'pause_intervals_documented', $actorId, $reason, $this->snapshot($before), $this->snapshot($after));
                return ['replayed' => false, 'audit_id' => $auditId, 'data' => $this->details($timesheetId)];
            });
        });
    }

    private function entry(int $id, bool $forUpdate = false): array
    {
        $row = $id > 0 ? $this->connection->fetchOne('SELECT *, row_start AS work_time_source_revision FROM timesheets WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $id]) : null;
        if ($row === null) {
            throw new InvalidArgumentException('Die Arbeitsbuchung wurde nicht gefunden.');
        }
        return $row;
    }

    private function breaks(int $id, bool $forUpdate = false): array
    {
        return $this->connection->fetchAll('SELECT * FROM timesheet_breaks WHERE timesheet_id = :id ORDER BY break_started_at, id' . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    private function hasActiveSignature(int $id, bool $forUpdate = false): bool
    {
        return $this->connection->tableExists('timesheet_customer_signatures')
            && $this->connection->fetchOne('SELECT id FROM timesheet_customer_signatures WHERE timesheet_id = :id AND archived_at IS NULL LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''), ['id' => $id]) !== null;
    }

    private function history(int $id): array
    {
        return $this->auditAvailable() ? $this->connection->fetchAll('SELECT c.*, CONCAT(u.first_name, " ", u.last_name) AS actor_name FROM work_time_pause_corrections c JOIN users u ON u.id = c.actor_user_id WHERE c.timesheet_id = :id ORDER BY c.id DESC', ['id' => $id]) : [];
    }

    private function auditAvailable(): bool
    {
        return $this->auditAvailable ??= $this->connection->tableExists('work_time_pause_corrections');
    }

    private function assertNoOverlap(array $intervals): void
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $end = null;
        foreach ($intervals as [$start, $nextEnd]) {
            if ($end !== null && $start < $end) {
                throw new InvalidArgumentException('Pausenintervalle duerfen sich nicht ueberschneiden. Vorhandene Intervalle werden nicht ersetzt.');
            }
            $end = $nextEnd;
        }
    }

    private function snapshot(array $details): array
    {
        return array_intersect_key($details, array_flip(['timesheet', 'breaks', 'calculation', 'stored_break_minutes', 'documented_break_minutes', 'missing_break_minutes', 'clarification_reason', 'has_active_signature']));
    }

    private function date(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Das Pausen-Pruefdatum ist ungueltig.');
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
