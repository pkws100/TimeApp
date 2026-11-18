<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PausePolicyService
{
    public const VIEW = 'pause_policies.view';
    public const MANAGE = 'pause_policies.manage';

    private const FIELDS = [
        'capture_mode' => 'stamped',
        'minimum_over_six_minutes' => 30,
        'minimum_over_nine_minutes' => 45,
        'additional_minutes' => 0,
        'planned_windows' => [],
    ];

    private ?bool $schemaAvailable = null;
    private array $employeeSchedules = [];
    private ?array $defaults = null;

    public function __construct(private DatabaseConnection $connection)
    {
    }

    public function available(): bool
    {
        return $this->schemaAvailable ??= $this->connection->tableExists('pause_policy_audit');
    }

    public function policyFor(int $userId, string $workDate): ?array
    {
        if ($userId <= 0 || !$this->available()) {
            return null;
        }
        $this->date($workDate);
        $employees = $this->employeeSchedules[$userId] ??= $this->schedules(false, $userId, true);
        foreach ($employees as $schedule) {
            if ($this->applies($schedule, $workDate)) {
                return $this->resolvedPolicy($schedule, false);
            }
        }
        $defaults = $this->defaults ??= $this->schedules(true, null, true);
        foreach ($defaults as $schedule) {
            if ($this->applies($schedule, $workDate)) {
                return $this->resolvedPolicy($schedule, true);
            }
        }
        return null;
    }

    public function listTemplates(): array
    {
        if (!$this->available()) {
            return [];
        }
        $templates = $this->connection->fetchAll('SELECT * FROM pause_policy_templates ORDER BY is_active DESC, name, id');
        $versions = $this->connection->fetchAll('SELECT * FROM pause_policy_versions ORDER BY template_id, revision DESC');
        $versionsByTemplate = [];
        foreach ($versions as $version) {
            $versionsByTemplate[(int) $version['template_id']][] = $this->versionRecord($version);
        }
        foreach ($templates as &$template) {
            $template['versions'] = $versionsByTemplate[(int) $template['id']] ?? [];
        }
        unset($template);
        return $templates;
    }

    public function listAssignments(?int $userId = null): array
    {
        return $this->available() ? $this->schedules(false, $userId) : [];
    }

    public function listDefaults(): array
    {
        return $this->available() ? $this->schedules(true) : [];
    }

    public function listAudit(?int $userId = null, int $beforeId = PHP_INT_MAX): array
    {
        if (!$this->available()) {
            return [];
        }
        $params = ['before_id' => $beforeId];
        $condition = '';
        if ($userId !== null) {
            $params['user_id'] = $userId;
            $condition = ' AND (a.affected_user_id = :user_id OR a.affected_user_id IS NULL)';
        }
        return $this->connection->fetchAll(
            'SELECT a.*, CONCAT(actor.first_name, " ", actor.last_name) AS actor_name,
                CONCAT(employee.first_name, " ", employee.last_name) AS employee_name
             FROM pause_policy_audit a JOIN users actor ON actor.id = a.actor_user_id
             LEFT JOIN users employee ON employee.id = a.affected_user_id
             WHERE a.id < :before_id' . $condition . ' ORDER BY a.id DESC LIMIT 51',
            $params
        );
    }

    public function createTemplate(array $input, int $actorId): array
    {
        $this->assertSchema();
        $name = $this->string($input['name'] ?? '', 'Vorlagenname');
        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Der Vorlagenname muss 1 bis 150 Zeichen enthalten.');
        }
        $policy = $this->normalizePolicy($input['policy'] ?? $input);
        return $this->connection->transaction(function () use ($name, $policy, $actorId): array {
            $this->connection->execute('INSERT INTO pause_policy_templates (name, created_by_user_id) VALUES (:name, :actor)', ['name' => $name, 'actor' => $actorId]);
            $id = $this->connection->lastInsertId();
            $version = $this->insertVersion($id, 1, $policy, $actorId);
            $after = ['id' => $id, 'name' => $name, 'is_active' => true, 'version' => $version];
            $this->audit('template_created', $id, null, $actorId, '', null, $after);
            return $after;
        });
    }

    public function addVersion(int $templateId, array $input, int $actorId): array
    {
        $this->assertSchema();
        $policy = $this->normalizePolicy($input['policy'] ?? $input);
        return $this->connection->transaction(function () use ($templateId, $policy, $actorId): array {
            $template = $this->connection->fetchOne('SELECT * FROM pause_policy_templates WHERE id = :id FOR UPDATE', ['id' => $templateId]);
            if ($template === null || !(bool) $template['is_active']) {
                throw new InvalidArgumentException('Die aktive Pausenvorlage wurde nicht gefunden.');
            }
            $previous = $this->connection->fetchOne('SELECT * FROM pause_policy_versions WHERE template_id = :id ORDER BY revision DESC LIMIT 1', ['id' => $templateId]);
            $revision = 1 + (int) ($previous['revision'] ?? 0);
            $version = $this->insertVersion($templateId, $revision, $policy, $actorId);
            $this->audit('version_created', $version['id'], null, $actorId, '', $previous, $version);
            return $version;
        });
    }

    public function archiveTemplate(int $templateId, int $actorId): void
    {
        $this->assertSchema();
        $this->connection->transaction(function () use ($templateId, $actorId): void {
            $before = $this->connection->fetchOne('SELECT * FROM pause_policy_templates WHERE id = :id FOR UPDATE', ['id' => $templateId]);
            if ($before === null) {
                throw new InvalidArgumentException('Die Pausenvorlage wurde nicht gefunden.');
            }
            if (!(bool) $before['is_active']) {
                return;
            }
            $this->connection->execute('UPDATE pause_policy_templates SET is_active = 0, archived_at = NOW(), archived_by_user_id = :actor WHERE id = :id', ['actor' => $actorId, 'id' => $templateId]);
            $this->audit('template_archived', $templateId, null, $actorId, '', $before, array_replace($before, ['is_active' => false]));
        });
    }

    public function assign(array $input, int $actorId): array
    {
        return $this->schedule($input, $actorId, false);
    }

    public function scheduleDefault(array $input, int $actorId): array
    {
        return $this->schedule($input, $actorId, true);
    }

    public function endAssignment(int $id, bool $global, string $effectiveTo, string $reason, int $actorId): ?int
    {
        $this->assertSchema();
        $table = $this->scheduleTable($global);
        $until = $this->date($effectiveTo);
        $reason = $this->reason($reason);
        return $this->writeSchedule(function () use ($table, $id, $global, $until, $reason, $actorId): ?int {
            $this->assertFutureChange($until, true);
            $before = $this->connection->fetchOne('SELECT * FROM ' . $table . ' WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($before === null || $before['superseded_at'] !== null) {
                throw new InvalidArgumentException('Diese Gültigkeit kann nicht beendet werden.');
            }
            if ($before['effective_to'] !== null && $until > $before['effective_to']) {
                throw new InvalidArgumentException('Ein bestehendes Ende kann nur vorgezogen werden.');
            }
            if ($until !== $before['effective_to']) {
                $this->shorten($table, $before, $until, $global, $actorId, $reason);
            }
            return $global ? null : (int) $before['user_id'];
        });
    }

    public function policyForVersion(int $versionId, array $overrides = []): array
    {
        $this->assertSchema();
        $version = $this->lookupVersion($versionId);
        return $this->applyOverrides($this->versionRecord($version)['policy'], $overrides);
    }

    public function normalizePolicy(mixed $input): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('Die Pausenregel muss als Feldgruppe angegeben werden.');
        }
        $values = array_replace(self::FIELDS, array_intersect_key($input, self::FIELDS));
        $mode = $values['capture_mode'];
        if (!in_array($mode, ['stamped', 'manual'], true)) {
            throw new InvalidArgumentException('Bitte einen gültigen Erfassungsmodus wählen.');
        }
        $short = $this->minutes($values['minimum_over_six_minutes'], 30, 'Mindestpause bei mehr als 6 Stunden');
        $long = $this->minutes($values['minimum_over_nine_minutes'], max(45, $short), 'Mindestpause bei mehr als 9 Stunden');
        return [
            'capture_mode' => $mode,
            'minimum_over_six_minutes' => $short,
            'minimum_over_nine_minutes' => $long,
            'additional_minutes' => $this->minutes($values['additional_minutes'], 0, 'Zusätzliche Pausenminuten'),
            'planned_windows' => $this->windows($values['planned_windows']),
        ];
    }

    private function schedule(array $input, int $actorId, bool $global): array
    {
        $this->assertSchema();
        $userId = $global ? null : $this->positiveId($input['user_id'] ?? null, 'Mitarbeiter');
        $versionId = $this->positiveId($input['policy_version_id'] ?? null, 'Regelversion');
        $from = $this->date($this->string($input['effective_from'] ?? '', 'Gültig ab'));
        $end = $this->string($input['effective_to'] ?? '', 'Gültig bis');
        $until = $end === '' ? null : $this->date($end);
        if ($until !== null && $until < $from) {
            throw new InvalidArgumentException('Das Gültigkeitsende liegt vor dem Beginn.');
        }
        $reason = $this->reason($input['reason'] ?? '');
        $overrides = $global ? [] : ($input['overrides'] ?? []);
        if (!is_array($overrides)) {
            throw new InvalidArgumentException('Individuelle Regeln müssen als Feldgruppe angegeben werden.');
        }
        $replace = in_array($input['replace_previous'] ?? false, [true, 1, '1'], true);
        $table = $this->scheduleTable($global);
        return $this->writeSchedule(function () use ($userId, $versionId, $from, $until, $reason, $overrides, $replace, $global, $table, $actorId): array {
            $this->assertFutureChange($from);
            $version = $this->lookupVersion($versionId, true);
            $base = $this->versionRecord($version)['policy'];
            $individual = array_intersect_key($this->applyOverrides($base, $overrides), $overrides);
            if ($userId !== null && !$this->connection->fetchOne('SELECT id FROM users WHERE id = :id AND is_deleted = 0 AND employment_status = "active" FOR UPDATE', ['id' => $userId])) {
                throw new InvalidArgumentException('Bitte einen aktiven Mitarbeiter wählen.');
            }
            $employeeFilter = $global ? '' : ' AND user_id = :user';
            $overlap = $this->connection->fetchAll('SELECT * FROM ' . $table . ' WHERE superseded_at IS NULL AND effective_from <= :date_to AND (effective_to IS NULL OR effective_to >= :date_from)' . $employeeFilter . ' ORDER BY effective_from FOR UPDATE',
                ['date_from' => $from, 'date_to' => $until ?? '9999-12-31'] + ($global ? [] : ['user' => $userId]));
            if ($overlap !== []) {
                if (!$replace || count($overlap) > 1 || $overlap[0]['effective_from'] > $from) {
                    $existing = $overlap[0];
                    throw new InvalidArgumentException('Die Gültigkeit überschneidet sich mit der Regel ab ' . $existing['effective_from'] . '. Bitte die Daten eingrenzen oder die bisherige Regel ausdrücklich ersetzen.');
                }
                $newEnd = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
                $this->shorten($table, $overlap[0], $newEnd, $global, $actorId, $reason);
            }
            $params = ['version' => $versionId, 'date_from' => $from, 'date_to' => $until, 'actor' => $actorId];
            if ($global) {
                $this->connection->execute('INSERT INTO pause_policy_defaults (policy_version_id, effective_from, effective_to, created_by_user_id) VALUES (:version, :date_from, :date_to, :actor)', $params);
            } else {
                $this->connection->execute('INSERT INTO employee_pause_policy_assignments (user_id, policy_version_id, effective_from, effective_to, overrides_json, created_by_user_id) VALUES (:user, :version, :date_from, :date_to, :overrides, :actor)', $params + ['user' => $userId, 'overrides' => $this->json($individual)]);
            }
            $id = $this->connection->lastInsertId();
            $record = ['id' => $id, 'user_id' => $userId, 'policy_version_id' => $versionId, 'effective_from' => $from, 'effective_to' => $until, 'overrides' => $individual];
            $this->audit($global ? 'default_scheduled' : 'assignment_created', $id, $userId, $actorId, $reason, null, $record);
            return $record;
        });
    }

    private function shorten(string $table, array $before, string $until, bool $global, int $actorId, string $reason): void
    {
        $id = (int) $before['id'];
        if ($until < $before['effective_from']) {
            $this->connection->execute('UPDATE ' . $table . ' SET superseded_at = NOW() WHERE id = :id', ['id' => $id]);
            $action = $global ? 'default_superseded' : 'assignment_superseded';
        } else {
            $this->connection->execute('UPDATE ' . $table . ' SET effective_to = :end WHERE id = :id', ['end' => $until, 'id' => $id]);
            $action = $global ? 'default_ended' : 'assignment_ended';
        }
        $after = $this->connection->fetchOne('SELECT * FROM ' . $table . ' WHERE id = :id', ['id' => $id]) ?? [];
        $this->audit($action, $id, $global ? null : (int) $before['user_id'], $actorId, $reason, $before, $after);
    }

    private function schedules(bool $global, ?int $userId = null, bool $activeOnly = false): array
    {
        $table = $this->scheduleTable($global);
        $employeeColumn = $global ? '' : ', CONCAT(u.first_name, " ", u.last_name) AS employee_name';
        $employeeJoin = $global ? '' : ' JOIN users u ON u.id = a.user_id';
        $employeeFilter = $userId === null ? '' : ' AND a.user_id = :user';
        $activeFilter = $activeOnly ? ' AND a.superseded_at IS NULL' : '';
        return $this->connection->fetchAll(
            'SELECT a.*, v.revision, t.name AS template_name, v.policy_json' . $employeeColumn .
            ' FROM ' . $table . ' a JOIN pause_policy_versions v ON v.id = a.policy_version_id
              JOIN pause_policy_templates t ON t.id = v.template_id' . $employeeJoin . ' WHERE 1=1' . $employeeFilter . $activeFilter .
            ' ORDER BY a.effective_from DESC, a.id DESC',
            $userId === null ? [] : ['user' => $userId]
        );
    }

    private function applies(array $row, string $date): bool
    {
        return $row['effective_from'] <= $date && ($row['effective_to'] === null || $row['effective_to'] >= $date);
    }

    private function resolvedPolicy(array $row, bool $global): array
    {
        $base = json_decode((string) $row['policy_json'], true, 32, JSON_THROW_ON_ERROR);
        $overrides = $global ? [] : json_decode((string) $row['overrides_json'], true, 32, JSON_THROW_ON_ERROR);
        return $this->applyOverrides($base, $overrides) + [
            'version_id' => (int) $row['policy_version_id'],
            'revision' => (int) $row['revision'],
            'assignment_id' => $global ? null : (int) $row['id'],
            'default_id' => $global ? (int) $row['id'] : null,
            'template_name' => $row['template_name'],
            'effective_from' => $row['effective_from'],
            'effective_to' => $row['effective_to'],
        ];
    }

    private function applyOverrides(array $base, array $overrides): array
    {
        if (array_diff_key($overrides, self::FIELDS) !== []) {
            throw new InvalidArgumentException('Die individuellen Pausenregeln enthalten unbekannte Felder.');
        }
        return $this->normalizePolicy(array_replace($base, $overrides));
    }

    private function writeSchedule(callable $operation): mixed
    {
        // The same lock protects booking snapshots and period closures.
        $result = (new TimesheetWriteGuard($this->connection))->withAccountingWriteLock(fn () => $this->connection->transaction($operation));
        $this->employeeSchedules = [];
        $this->defaults = null;
        return $result;
    }

    private function lookupVersion(int $id, bool $activeOnly = false): array
    {
        $row = $this->connection->fetchOne(
            'SELECT v.* FROM pause_policy_versions v JOIN pause_policy_templates t ON t.id = v.template_id WHERE v.id = :id' . ($activeOnly ? ' AND t.is_active = 1 FOR UPDATE' : ''),
            ['id' => $id]
        );
        if ($row === null) {
            throw new InvalidArgumentException('Die gewählte Pausenregelversion ist nicht verfügbar.');
        }
        return $row;
    }

    private function insertVersion(int $id, int $revision, array $policy, int $actor): array
    {
        $this->connection->execute('INSERT INTO pause_policy_versions (template_id, revision, policy_json, created_by_user_id) VALUES (:template, :revision, :policy, :actor)',
            ['template' => $id, 'revision' => $revision, 'policy' => $this->json($policy), 'actor' => $actor]);
        return ['id' => $this->connection->lastInsertId(), 'template_id' => $id, 'revision' => $revision, 'policy' => $policy];
    }

    private function versionRecord(array $row): array
    {
        $row['policy'] = json_decode((string) $row['policy_json'], true, 32, JSON_THROW_ON_ERROR);
        return $row;
    }

    private function audit(string $action, int $id, ?int $userId, int $actor, string $reason, ?array $before, array $after): void
    {
        $this->connection->execute('INSERT INTO pause_policy_audit (actor_user_id, affected_user_id, action, entity_id, reason, before_json, after_json) VALUES (:actor, :user, :action, :entity, :reason, :before, :after)', [
            'actor' => $actor, 'user' => $userId, 'action' => $action, 'entity' => $id, 'reason' => $reason,
            'before' => $before === null ? null : $this->json($before), 'after' => $this->json($after),
        ]);
    }

    private function scheduleTable(bool $global): string
    {
        return $global ? 'pause_policy_defaults' : 'employee_pause_policy_assignments';
    }

    private function assertSchema(): void
    {
        if (!$this->available()) {
            throw new InvalidArgumentException('Die Datenbankmigration für Pausenmodelle fehlt.');
        }
    }

    private function assertFutureChange(string $date, bool $end = false): void
    {
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        if ($date < $today || (!$end && $date === $today)) {
            throw new InvalidArgumentException('Neue Pausenregeln gelten frühestens ab morgen. Ein bestehendes Modell kann mit Ablauf des heutigen Tages enden.');
        }
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') {
            throw new InvalidArgumentException('Bitte ein gültiges Datum im Format JJJJ-MM-TT eingeben.');
        }
        return $value;
    }

    private function minutes(mixed $value, int $min, string $field): int
    {
        $minutes = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 1440]]) : false;
        if ($minutes === false) {
            throw new InvalidArgumentException($field . ' muss zwischen ' . $min . ' und 1440 Minuten liegen.');
        }
        return $minutes;
    }

    private function windows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 8) {
            throw new InvalidArgumentException('Bis zu acht geplante Pausenfenster sind möglich.');
        }
        $windows = [];
        $pieces = [];
        foreach ($value as $window) {
            if (!is_array($window)) {
                throw new InvalidArgumentException('Ein Pausenfenster benötigt Beginn und Ende.');
            }
            $start = $this->string($window['start'] ?? '', 'Pausenbeginn');
            $end = $this->string($window['end'] ?? '', 'Pausenende');
            if (!$this->validTime($start) || !$this->validTime($end) || $start === $end) {
                throw new InvalidArgumentException('Pausenfenster benötigen zwei unterschiedliche Uhrzeiten (HH:MM).');
            }
            $windows[] = ['start' => $start, 'end' => $end];
            if ($end > $start) {
                $pieces[] = [$start, $end];
            } else {
                $pieces[] = [$start, '24:00'];
                if ($end !== '00:00') {
                    $pieces[] = ['00:00', $end];
                }
            }
        }
        sort($pieces);
        for ($index = 1; $index < count($pieces); ++$index) {
            if ($pieces[$index][0] < $pieces[$index - 1][1]) {
                throw new InvalidArgumentException('Geplante Pausenfenster dürfen sich nicht überschneiden.');
            }
        }
        usort($windows, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        return $windows;
    }

    private function validTime(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value) === 1;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Bitte ' . $field . ' auswählen.');
        }
        return $id;
    }

    private function reason(mixed $value): string
    {
        $reason = $this->string($value, 'Begründung');
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new InvalidArgumentException('Bitte eine Begründung mit höchstens 2.000 Zeichen eingeben.');
        }
        return $reason;
    }

    private function string(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($field . ' ist ungültig.');
        }
        return trim($value);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
