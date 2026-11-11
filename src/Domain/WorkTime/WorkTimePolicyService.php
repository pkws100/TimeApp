<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class WorkTimePolicyService
{
    /** Request-scoped only; all public mutation methods invalidate it. */
    private array $policyCache = [];
    private array $assignmentTimelines = [];
    private ?bool $assignmentSchemaAvailable = null;
    public function __construct(private DatabaseConnection $connection, private bool $featureEnabled = false)
    {
    }

    public function enabled(): bool { return $this->featureEnabled; }

    public function listTemplates(): array
    {
        if (!$this->connection->tableExists('work_time_policy_templates')) { return []; }
        $templates = $this->connection->fetchAll('SELECT * FROM work_time_policy_templates ORDER BY is_active DESC, name, id');
        foreach ($templates as &$template) {
            $template['id'] = (int) $template['id'];
            $template['versions'] = array_map(fn (array $row): array => $this->versionRecord($row), $this->connection->fetchAll('SELECT * FROM work_time_policy_versions WHERE template_id = :template_id ORDER BY revision DESC', ['template_id' => $template['id']]));
        }
        return $templates;
    }

    public function listAssignments(?int $userId = null): array
    {
        if (!$this->connection->tableExists('employee_work_time_assignments')) { return []; }
        $rows = $this->connection->fetchAll('SELECT a.*, t.name AS template_name, v.revision, CONCAT(u.first_name, " ", u.last_name) AS employee_name FROM employee_work_time_assignments a JOIN work_time_policy_versions v ON v.id = a.policy_version_id JOIN work_time_policy_templates t ON t.id = v.template_id JOIN users u ON u.id = a.user_id' . ($userId !== null ? ' WHERE a.user_id = :user_id' : '') . ' ORDER BY a.effective_from DESC, a.id DESC', $userId !== null ? ['user_id' => $userId] : []);
        return array_map(fn (array $row): array => $this->assignmentRecord($row), $rows);
    }

    public function createTemplate(array $input, int $actorId): array
    {
        $this->assertSchema();
        $this->policyCache = [];
        $this->assignmentTimelines = [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) { throw new InvalidArgumentException('Der Vorlagenname muss 1 bis 150 Zeichen enthalten.'); }
        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 10000) { throw new InvalidArgumentException('Die Beschreibung darf hoechstens 10.000 Zeichen enthalten.'); }
        $policy = $this->normalizePolicy($input['policy'] ?? $input);
        return $this->connection->transaction(function () use ($name, $description, $policy, $actorId): array {
            $this->connection->execute('INSERT INTO work_time_policy_templates (name, description, created_by_user_id) VALUES (:name, :description, :actor)', ['name' => $name, 'description' => $description ?: null, 'actor' => $actorId]);
            $id = $this->connection->lastInsertId();
            $version = $this->insertVersion($id, 1, $policy, $actorId);
            $result = ['id' => $id, 'name' => $name, 'description' => $description, 'is_active' => true, 'version' => $version];
            $this->audit('template_created', $id, $actorId, null, $result);
            return $result;
        });
    }

    public function addVersion(int $templateId, array $input, int $actorId): array
    {
        $this->assertSchema();
        $this->policyCache = [];
        $this->assignmentTimelines = [];
        $policy = $this->normalizePolicy($input['policy'] ?? $input);
        return $this->connection->transaction(function () use ($templateId, $policy, $actorId): array {
            $template = $this->connection->fetchOne('SELECT * FROM work_time_policy_templates WHERE id = :id FOR UPDATE', ['id' => $templateId]);
            if ($template === null || !(bool) $template['is_active']) { throw new InvalidArgumentException('Die aktive Arbeitszeitvorlage wurde nicht gefunden.'); }
            $revision = 1 + (int) $this->connection->fetchColumn('SELECT COALESCE(MAX(revision), 0) FROM work_time_policy_versions WHERE template_id = :id', ['id' => $templateId]);
            $version = $this->insertVersion($templateId, $revision, $policy, $actorId);
            $this->audit('version_created', $version['id'], $actorId, null, $version);
            return $version;
        });
    }

    public function archiveTemplate(int $templateId, int $actorId): void
    {
        $this->assertSchema();
        $this->policyCache = [];
        $this->assignmentTimelines = [];
        $this->connection->transaction(function () use ($templateId, $actorId): void {
            $before = $this->connection->fetchOne('SELECT * FROM work_time_policy_templates WHERE id = :id FOR UPDATE', ['id' => $templateId]);
            if ($before === null) { throw new InvalidArgumentException('Die Vorlage wurde nicht gefunden.'); }
            if (!(bool) $before['is_active']) { return; }
            $this->connection->execute('UPDATE work_time_policy_templates SET is_active = 0, archived_at = NOW(), archived_by_user_id = :actor WHERE id = :id', ['actor' => $actorId, 'id' => $templateId]);
            $this->audit('template_archived', $templateId, $actorId, $before, ['is_active' => false]);
        });
    }

    public function assign(array $input, int $actorId): array
    {
        $this->assertSchema();
        $this->policyCache = [];
        $this->assignmentTimelines = [];
        $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $versionId = filter_var($input['policy_version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false || $versionId === false) { throw new InvalidArgumentException('Mitarbeiter und Regelversion sind erforderlich.'); }
        $from = $this->date((string) ($input['effective_from'] ?? ''));
        $until = trim((string) ($input['effective_to'] ?? ''));
        $until = $until === '' ? null : $this->date($until);
        if ($from <= (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')) { throw new InvalidArgumentException('Neue Arbeitszeitregeln duerfen ausschliesslich ab einem zukuenftigen Datum gelten.'); }
        if ($until !== null && $until < $from) { throw new InvalidArgumentException('Das Gueltigkeitsende liegt vor dem Beginn.'); }
        $overrides = $input['overrides'] ?? [];
        if (is_string($overrides)) { $overrides = json_decode($overrides, true, 16, JSON_THROW_ON_ERROR); }
        if (!is_array($overrides)) { throw new InvalidArgumentException('Individuelle Regeln muessen ein Objekt sein.'); }
        $supervisors = $input['supervisor_user_ids'] ?? [];
        if (!is_array($supervisors)) { throw new InvalidArgumentException('Vorgesetzte muessen als Liste angegeben werden.'); }
        $supervisors = array_values(array_unique(array_map(static function (mixed $value): int {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) { throw new InvalidArgumentException('Ungueltiger Vorgesetzter.'); }
            return $id;
        }, $supervisors)));
        if (in_array($userId, $supervisors, true)) { throw new InvalidArgumentException('Ein Mitarbeiter kann nicht sein eigener Vorgesetzter sein.'); }
        $lock = 'work-time-assignment-user-' . $userId;
        if ((int) $this->connection->fetchColumn('SELECT GET_LOCK(:name, 10)', ['name' => $lock]) !== 1) { throw new RuntimeException('Die Arbeitszeitzuordnung wird gerade bearbeitet.'); }
        try {
            return (new TimesheetWriteGuard($this->connection))->withAccountingWriteLock(fn (): array => $this->connection->transaction(function () use ($userId, $versionId, $from, $until, $overrides, $supervisors, $actorId): array {
                $this->assertActiveUser($userId);
                foreach ($supervisors as $supervisor) { $this->assertActiveUser($supervisor); }
                $version = $this->connection->fetchOne('SELECT v.* FROM work_time_policy_versions v JOIN work_time_policy_templates t ON t.id = v.template_id WHERE v.id = :id AND t.is_active = 1', ['id' => $versionId]);
                if ($version === null) { throw new InvalidArgumentException('Die aktive Regelversion wurde nicht gefunden.'); }
                $base = json_decode((string) $version['policy_json'], true, 32, JSON_THROW_ON_ERROR);
                $normalized = $this->normalizePolicy(array_replace($base, $overrides));
                // Store only explicitly supplied override fields; old versions remain immutable.
                $overrides = array_intersect_key($normalized, $overrides);
                $overlap = $this->connection->fetchAll('SELECT * FROM employee_work_time_assignments WHERE user_id = :user_id AND effective_from <= :end_date AND (effective_to IS NULL OR effective_to >= :start_date) FOR UPDATE', ['user_id' => $userId, 'end_date' => $until ?? '9999-12-31', 'start_date' => $from]);
                foreach ($overlap as $existing) {
                    // Appending a later assignment explicitly ends an older open-ended assignment.
                    if ($existing['effective_to'] === null && (string) $existing['effective_from'] < $from) {
                        $previousEnd = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
                        $this->connection->execute('UPDATE employee_work_time_assignments SET effective_to = :end_date WHERE id = :id', ['end_date' => $previousEnd, 'id' => $existing['id']]);
                        $this->audit('assignment_ended', (int) $existing['id'], $actorId, $existing, ['effective_to' => $previousEnd]);
                    } else { throw new InvalidArgumentException('Die Arbeitszeitzuordnung ueberschneidet sich mit einer vorhandenen Zuordnung.'); }
                }
                $this->connection->execute('INSERT INTO employee_work_time_assignments (user_id, policy_version_id, effective_from, effective_to, overrides_json, created_by_user_id) VALUES (:user_id, :version, :date_from, :date_to, :overrides, :actor)', ['user_id' => $userId, 'version' => $versionId, 'date_from' => $from, 'date_to' => $until, 'overrides' => $this->json($overrides), 'actor' => $actorId]);
                $id = $this->connection->lastInsertId();
                foreach ($supervisors as $supervisor) { $this->connection->execute('INSERT INTO employee_work_time_supervisors (assignment_id, user_id) VALUES (:assignment_id, :user_id)', ['assignment_id' => $id, 'user_id' => $supervisor]); }
                $result = ['id' => $id, 'user_id' => $userId, 'policy_version_id' => $versionId, 'effective_from' => $from, 'effective_to' => $until, 'overrides' => $overrides, 'supervisor_user_ids' => $supervisors];
                $this->audit('assignment_created', $id, $actorId, null, $result);
                return $result;
            }));
        } finally { $this->connection->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $lock]); }
    }

    public function endAssignment(int $assignmentId, string $effectiveTo, int $actorId, string $reason): void
    {
        $this->assertSchema();
        $this->policyCache = [];
        $this->assignmentTimelines = [];
        $effectiveTo = $this->date($effectiveTo);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) { throw new InvalidArgumentException('Bitte eine Begruendung mit hoechstens 2.000 Zeichen angeben.'); }
        if ($effectiveTo < (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')) { throw new InvalidArgumentException('Eine Zuordnung darf nicht rueckwirkend beendet werden.'); }
        $assignment = $this->connection->fetchOne('SELECT user_id FROM employee_work_time_assignments WHERE id = :id', ['id' => $assignmentId]);
        if ($assignment === null) { throw new InvalidArgumentException('Die Zuordnung wurde nicht gefunden.'); }
        $lock = 'work-time-assignment-user-' . $assignment['user_id'];
        if ((int) $this->connection->fetchColumn('SELECT GET_LOCK(:name, 10)', ['name' => $lock]) !== 1) { throw new RuntimeException('Die Arbeitszeitzuordnung wird gerade bearbeitet.'); }
        try {
            (new TimesheetWriteGuard($this->connection))->withAccountingWriteLock(fn () => $this->connection->transaction(function () use ($assignmentId, $effectiveTo, $actorId, $reason): void {
                $before = $this->connection->fetchOne('SELECT * FROM employee_work_time_assignments WHERE id = :id FOR UPDATE', ['id' => $assignmentId]);
                if ($effectiveTo < $before['effective_from']) { throw new InvalidArgumentException('Das Ende liegt vor dem Zuordnungsbeginn.'); }
                if ($before['effective_to'] !== null && $effectiveTo > $before['effective_to']) { throw new InvalidArgumentException('Eine beendete Zuordnung darf nicht verlaengert werden.'); }
                if ($before['effective_to'] === $effectiveTo) { return; }
                $this->connection->execute('UPDATE employee_work_time_assignments SET effective_to = :end_date WHERE id = :id', ['end_date' => $effectiveTo, 'id' => $assignmentId]);
                $this->audit('assignment_ended', $assignmentId, $actorId, $before, ['effective_to' => $effectiveTo, 'reason' => $reason]);
            }));
        } finally { $this->connection->fetchColumn('SELECT RELEASE_LOCK(:name)', ['name' => $lock]); }
    }

    public function policyFor(int $userId, string $date): ?array
    {
        if (!$this->enabled() || !($this->assignmentSchemaAvailable ??= $this->connection->tableExists('employee_work_time_assignments'))) { return null; }
        $cacheKey = $userId . ':' . $date;
        if (array_key_exists($cacheKey, $this->policyCache)) { return $this->policyCache[$cacheKey]; }
        $this->date($date);
        if (!array_key_exists($userId, $this->assignmentTimelines)) {
            $this->assignmentTimelines[$userId] = $this->connection->fetchAll('SELECT a.*, v.policy_json, v.revision, t.name AS template_name FROM employee_work_time_assignments a JOIN work_time_policy_versions v ON v.id = a.policy_version_id JOIN work_time_policy_templates t ON t.id = v.template_id WHERE a.user_id = :user_id ORDER BY a.effective_from DESC', ['user_id' => $userId]);
            foreach ($this->assignmentTimelines[$userId] as &$assignment) { $assignment['supervisor_user_ids'] = $this->supervisors((int) $assignment['id']); }
            unset($assignment);
        }
        $row = null;
        foreach ($this->assignmentTimelines[$userId] as $assignment) {
            if ($assignment['effective_from'] <= $date && ($assignment['effective_to'] === null || $assignment['effective_to'] >= $date)) { $row = $assignment; break; }
        }
        if ($row === null) { return $this->policyCache[$cacheKey] = null; }
        $policy = array_replace(json_decode((string) $row['policy_json'], true, 32, JSON_THROW_ON_ERROR), json_decode((string) $row['overrides_json'], true, 32, JSON_THROW_ON_ERROR));
        return $this->policyCache[$cacheKey] = [...$policy, 'id' => (int) $row['policy_version_id'], 'policy_version_id' => (int) $row['policy_version_id'], 'assignment_id' => (int) $row['id'], 'effective_from' => $row['effective_from'], 'effective_to' => $row['effective_to'], 'template_name' => $row['template_name'], 'revision' => (int) $row['revision'], 'supervisor_user_ids' => $row['supervisor_user_ids']];
    }

    public function preview(array $input): array
    {
        $policy = $this->normalizePolicy($input['policy'] ?? $input);
        $date = $this->date((string) ($input['work_date'] ?? '2030-01-07'));
        $start = (string) ($input['start_time'] ?? '07:42');
        $end = (string) ($input['end_time'] ?? '16:35');
        $this->time($start); $this->time($end);
        $endDate = $end <= $start ? (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') : $date;
        $breaks = $input['break_intervals'] ?? [];
        if (!is_array($breaks)) { throw new InvalidArgumentException('Pausenintervalle muessen eine Liste sein.'); }
        $breaks = array_map(function (array $break) use ($date): array {
            foreach (['start', 'end'] as $key) {
                $value = (string) ($break[$key] ?? '');
                if (preg_match('/^\d{2}:\d{2}$/', $value)) { $this->time($value); $break[$key] = $date . ' ' . $value . ':00'; }
            }
            return $break;
        }, $breaks);
        $calc = (new WorkTimeWindowCalculator())->calculate([['start' => $date . ' ' . $start . ':00', 'end' => $endDate . ' ' . $end . ':00']], $breaks, $policy, $date, [], isset($input['break_minutes']) ? max(0, (int) $input['break_minutes']) : null);
        return [...$calc, 'policy' => $policy, 'work_date' => $date, 'preview_only' => true];
    }

    public function normalizePolicy(array $input): array
    {
        $defaultWindows = [];
        for ($day = 1; $day <= 7; ++$day) { $defaultWindows[$day] = $day <= 5 ? [['start' => '08:00', 'end' => '16:00']] : []; }
        $windows = $input['windows'] ?? $defaultWindows;
        if (is_string($windows)) { $windows = json_decode($windows, true, 32, JSON_THROW_ON_ERROR); }
        if (!is_array($windows)) { throw new InvalidArgumentException('Zeitfenster muessen ein Objekt mit Wochentagen 1 bis 7 sein.'); }
        $normalizedWindows = array_fill(1, 7, []);
        foreach ($windows as $day => $items) {
            if (!in_array((string) $day, ['1','2','3','4','5','6','7'], true) || !is_array($items) || count($items) > 8) { throw new InvalidArgumentException('Zeitfenster benoetigen Wochentage 1 bis 7 und hoechstens acht Intervalle pro Tag.'); }
            foreach ($items as $window) {
                if (!is_array($window)) { throw new InvalidArgumentException('Ein Zeitfenster benoetigt Beginn und Ende.'); }
                $start = (string) ($window['start'] ?? ''); $end = (string) ($window['end'] ?? '');
                $this->time($start); $this->time($end);
                if ($start === $end) { throw new InvalidArgumentException('Ein Zeitfenster darf nicht leer oder 24 Stunden lang sein.'); }
                $normalizedWindows[(int) $day][] = ['start' => $start, 'end' => $end];
            }
        }
        // Reject overlap also across weekday boundaries, including Sunday to Monday.
        $weekly = [];
        foreach ($normalizedWindows as $day => $items) {
            foreach ($items as $window) {
                $start = ($day - 1) * 1440 + $this->minute($window['start']);
                $end = ($day - 1) * 1440 + $this->minute($window['end']);
                if ($end <= $start) { $end += 1440; }
                foreach ([-10080, 0, 10080] as $shift) { $weekly[] = [$start + $shift, $end + $shift]; }
            }
        }
        usort($weekly, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($weekly); ++$i) { if ($weekly[$i][0] < $weekly[$i - 1][1]) { throw new InvalidArgumentException('Zeitfenster duerfen sich nicht ueberschneiden.'); } }
        $mode = (string) ($input['early_check_in_mode'] ?? 'work_started');
        if (!in_array($mode, ['work_started', 'presence_only'], true)) { throw new InvalidArgumentException('Ungueltiger Modus fuer fruehes Einchecken.'); }
        $zone = (string) ($input['timezone'] ?? 'Europe/Berlin');
        if ($zone !== 'Europe/Berlin') { throw new InvalidArgumentException('Arbeitszeitregeln verwenden die Zeitzone Europe/Berlin.'); }
        $delay = filter_var($input['checkout_delay_minutes'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]]);
        if ($delay === false) { throw new InvalidArgumentException('Die Verzoegerung muss zwischen 0 und 1440 Minuten liegen.'); }
        $result = ['windows' => $normalizedWindows, 'timezone' => $zone, 'early_check_in_mode' => $mode, 'checkout_delay_minutes' => $delay];
        $outsideDelay = filter_var($input['outside_window_delay_minutes'] ?? 5, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]]);
        if ($outsideDelay === false) { throw new InvalidArgumentException('Die Benachrichtigungsverzoegerung muss zwischen 0 und 1440 Minuten liegen.'); }
        $result['outside_window_delay_minutes'] = $outsideDelay;
        foreach (['checkout_proposals_enabled', 'outside_window_notifications_enabled', 'notify_employee_push', 'notify_employee_sms', 'notify_supervisor_push', 'notify_supervisor_sms'] as $key) {
            $result[$key] = filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($result[$key] === null) { throw new InvalidArgumentException('Ungueltiger Schalter: ' . $key); }
        }
        return $result;
    }

    private function insertVersion(int $template, int $revision, array $policy, int $actor): array
    {
        $this->connection->execute('INSERT INTO work_time_policy_versions (template_id, revision, policy_json, created_by_user_id) VALUES (:template, :revision, :policy, :actor)', ['template' => $template, 'revision' => $revision, 'policy' => $this->json($policy), 'actor' => $actor]);
        return ['id' => $this->connection->lastInsertId(), 'template_id' => $template, 'revision' => $revision, 'policy' => $policy];
    }
    private function versionRecord(array $row): array { $row['id'] = (int) $row['id']; $row['policy'] = json_decode((string) $row['policy_json'], true, 32, JSON_THROW_ON_ERROR); return $row; }
    private function assignmentRecord(array $row): array { foreach (['id','user_id','policy_version_id'] as $key) { $row[$key] = (int) $row[$key]; } $row['overrides'] = json_decode((string) $row['overrides_json'], true, 32, JSON_THROW_ON_ERROR); $row['supervisor_user_ids'] = $this->supervisors($row['id']); return $row; }
    private function supervisors(int $assignment): array { return array_map('intval', array_column($this->connection->fetchAll('SELECT user_id FROM employee_work_time_supervisors WHERE assignment_id = :id ORDER BY user_id', ['id' => $assignment]), 'user_id')); }
    private function audit(string $action, int $id, int $actor, ?array $before, array $after): void { $this->connection->execute('INSERT INTO work_time_policy_audit (actor_user_id, action, entity_id, before_json, after_json) VALUES (:actor, :action, :id, :before, :after)', ['actor' => $actor, 'action' => $action, 'id' => $id, 'before' => $before === null ? null : $this->json($before), 'after' => $this->json($after)]); }
    private function assertSchema(): void { if (!$this->connection->tableExists('work_time_policy_templates')) { throw new RuntimeException('Die Arbeitszeitregeln sind noch nicht migriert.'); } }
    private function assertActiveUser(int $user): void { if ($this->connection->fetchOne('SELECT id FROM users WHERE id = :id AND COALESCE(is_deleted,0) = 0 AND employment_status = "active"', ['id' => $user]) === null) { throw new InvalidArgumentException('Mitarbeiter oder Vorgesetzter ist nicht aktiv.'); } }
    private function date(string $date): string { $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date); if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new InvalidArgumentException('Ungueltiges Datum.'); } return $date; }
    private function time(string $time): void { if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) { throw new InvalidArgumentException('Zeitangaben muessen HH:MM entsprechen.'); } }
    private function minute(string $time): int { return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2); }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
}
