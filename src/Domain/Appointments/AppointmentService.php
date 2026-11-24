<?php

declare(strict_types=1);

namespace App\Domain\Appointments;

use App\Domain\Projects\ProjectAccessService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class AppointmentService
{
    private const PER_PAGE = 25;
    private const OWN_PER_PAGE = 50;
    private const STATUSES = ['planned', 'completed', 'cancelled'];
    private const COLUMNS = 'a.id, a.title, a.description, a.starts_at_utc, a.ends_at_utc, a.timezone, a.project_id,
        a.location, a.status, a.version, a.archived_at, p.name AS project_name, p.project_number';
    private const FROM = ' FROM appointments a LEFT JOIN projects p ON p.id = a.project_id';
    private const OWN_SELECT = 'SELECT a.id, a.title, a.description, a.starts_at_utc, a.ends_at_utc, a.location,
        a.status, a.version, p.name AS project_name, p.project_number, ap.acknowledged_at_utc, ap.acknowledged_version
        FROM appointment_participants ap JOIN appointments a ON a.id = ap.appointment_id
        LEFT JOIN projects p ON p.id = a.project_id
        WHERE ap.user_id = :own_user AND a.archived_at IS NULL';

    private readonly AppointmentTime $time;

    public function __construct(
        private readonly DatabaseConnection $connection,
        private readonly ProjectAccessService $projectAccess,
        string $timezone = 'Europe/Berlin',
    ) {
        $this->time = new AppointmentTime(new DateTimeZone($timezone));
    }

    /** @return array{items: list<array>, total: int, page: int, per_page: int} */
    public function list(array $actor, array $filters): array
    {
        $this->requireRight($actor);
        $params = [];
        $conditions = ['a.archived_at IS NULL', $this->projectScope($actor, 'a.project_id', $params)];
        if (($filters['period'] ?? 'upcoming') === 'upcoming') {
            $conditions[] = 'a.ends_at_utc > :upcoming';
            $params['upcoming'] = $this->time->formatUtc(new DateTimeImmutable('now', $this->time->timezone));
        } else {
            [$from, $until] = $this->filterDates($filters);
            $conditions[] = $this->overlap($from, $until, $params);
        }

        $project = $this->optionalId($filters['project_id'] ?? null, 'Projektfilter');
        if ($project !== null) {
            $conditions[] = 'a.project_id = :project_filter';
            $params['project_filter'] = $project;
        }
        $participant = $this->optionalId($filters['user_id'] ?? null, 'Mitarbeiterfilter');
        if ($participant !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM appointment_participants ap
                WHERE ap.appointment_id = a.id AND ap.user_id = :participant_filter)';
            $params['participant_filter'] = $participant;
        }
        $status = $filters['status'] ?? '';
        if ($status !== '') {
            $conditions[] = 'a.status = :status_filter';
            $params['status_filter'] = $this->status($status);
        }
        $search = $this->text($filters['q'] ?? '', 'Suchtext', 160);
        if ($search !== '') {
            $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $matches = [];
            foreach (['a.title', 'a.description', 'a.location', 'p.name', 'p.project_number'] as $index => $column) {
                $name = 'search_' . $index;
                $matches[] = $column . ' LIKE :' . $name . " ESCAPE '!'";
                $params[$name] = $pattern;
            }
            $conditions[] = '(' . implode(' OR ', $matches) . ')';
        }

        $page = $this->optionalId($filters['page'] ?? null, 'Seite') ?? 1;
        $where = ' WHERE ' . implode(' AND ', $conditions);
        $total = (int) $this->connection->fetchColumn('SELECT COUNT(*)' . self::FROM . $where, $params);
        $page = min($page, max(1, (int) ceil($total / self::PER_PAGE)));
        $offset = ($page - 1) * self::PER_PAGE;
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . self::FROM . $where . ' ORDER BY a.starts_at_utc, a.id LIMIT ' . self::PER_PAGE . ' OFFSET ' . $offset,
            $params
        );

        return ['items' => $this->hydrate($rows), 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE];
    }

    public function find(array $actor, int $id): ?array
    {
        $this->requireRight($actor);
        $params = ['id' => $id];
        $scope = $this->projectScope($actor, 'a.project_id', $params);
        $row = $this->connection->fetchOne(
            'SELECT ' . self::COLUMNS . self::FROM . ' WHERE a.id = :id AND ' . $scope,
            $params
        );

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /** @return array{items: list<array>, page: int, has_more: bool} */
    public function listOwn(array $actor, string $filter, int $page = 1): array
    {
        $params = ['own_user' => $this->activeActorId($actor)];
        $now = new DateTimeImmutable('now', $this->time->timezone);
        $condition = match ($filter) {
            'today' => $this->overlap($now->setTime(0, 0), $now->setTime(0, 0)->modify('+1 day'), $params),
            'upcoming' => 'a.ends_at_utc > :now AND a.status = "planned"',
            'past' => '(a.ends_at_utc <= :now OR a.status IN ("completed", "cancelled"))',
            default => throw new InvalidArgumentException('Bitte wählen Sie einen gültigen Terminfilter.'),
        };
        if ($filter !== 'today') {
            $params['now'] = $this->time->formatUtc($now);
        }
        $page = $this->positiveId($page, 'Seite');
        $limit = self::OWN_PER_PAGE + 1;
        $offset = ($page - 1) * self::OWN_PER_PAGE;
        $direction = $filter === 'past' ? 'DESC' : 'ASC';
        $rows = $this->connection->fetchAll(
            self::OWN_SELECT . " AND {$condition} ORDER BY a.starts_at_utc {$direction}, a.id {$direction} LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return [
            'items' => array_map($this->hydrateOwn(...), array_slice($rows, 0, self::OWN_PER_PAGE)),
            'page' => $page,
            'has_more' => count($rows) > self::OWN_PER_PAGE,
        ];
    }

    public function findOwn(array $actor, int $id): ?array
    {
        $row = $this->connection->fetchOne(self::OWN_SELECT . ' AND a.id = :id', [
            'own_user' => $this->activeActorId($actor), 'id' => $id,
        ]);

        return $row === null ? null : $this->hydrateOwn($row);
    }

    public function acknowledge(array $actor, int $id, int $version): array
    {
        $userId = $this->activeActorId($actor);
        $this->positiveId($version, 'Terminversion');

        return $this->connection->transaction(function () use ($actor, $userId, $id, $version): array {
            // Lock the appointment first, as in admin update and archive.
            $currentVersion = $this->connection->fetchColumn(
                'SELECT a.version FROM appointments a WHERE a.id = :id AND a.archived_at IS NULL
                 AND EXISTS (SELECT 1 FROM appointment_participants ap WHERE ap.appointment_id = a.id AND ap.user_id = :user)
                 FOR UPDATE',
                ['id' => $id, 'user' => $userId]
            );
            if (!$currentVersion) {
                throw $this->notFound();
            }
            $participant = $this->connection->fetchOne(
                'SELECT id, acknowledged_version FROM appointment_participants WHERE appointment_id = :id AND user_id = :user FOR UPDATE',
                ['id' => $id, 'user' => $userId]
            );
            if ($participant === null) {
                throw $this->notFound();
            }
            if ((int) $currentVersion !== $version) {
                throw new AppointmentConflictException('Der Termin wurde geändert. Bitte prüfen Sie den aktuellen Stand.');
            }
            if ((int) $participant['acknowledged_version'] !== $version) {
                $this->write(
                    'UPDATE appointment_participants SET acknowledged_at_utc = UTC_TIMESTAMP(), acknowledged_version = :version WHERE id = :id',
                    ['version' => $version, 'id' => (int) $participant['id']]
                );
            }

            return $this->findOwn($actor, $id) ?? throw $this->notFound();
        });
    }

    public function acknowledgements(array $actor, int $id): array
    {
        $this->requireRight($actor);
        $params = ['id' => $id];
        $scope = $this->projectScope($actor, 'a.project_id', $params);
        $rows = $this->connection->fetchAll(
            "SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name, a.version,
                ap.acknowledged_at_utc, ap.acknowledged_version
             FROM appointments a JOIN appointment_participants ap ON ap.appointment_id = a.id
             JOIN users u ON u.id = ap.user_id WHERE a.id = :id AND " . $scope . ' ORDER BY u.last_name, u.first_name, u.id',
            $params
        );

        return array_map(function (array $row): array {
            $state = $this->acknowledgementState($row);
            return ['id' => (int) $row['id'], 'name' => $row['name'], ...$state];
        }, $rows);
    }

    /** @return array{projects: list<array>, participants: list<array>} */
    public function formOptions(array $actor, ?array $appointment = null): array
    {
        $this->requireRight($actor);
        if ($appointment !== null) {
            $appointment = $this->find($actor, (int) ($appointment['id'] ?? 0)) ?? throw $this->notFound();
        }

        $params = [];
        $scope = $this->projectScope($actor, 'p.id', $params);
        $activeProject = 'COALESCE(p.is_deleted, 0) = 0 AND p.status <> "archived"';
        $projectFilter = '(' . $activeProject . ')';
        if ($appointment !== null && $appointment['project_id'] !== null) {
            $projectFilter .= ' OR p.id = :current_project';
            $params['current_project'] = $appointment['project_id'];
        }
        $projects = $this->connection->fetchAll(
            'SELECT p.id, p.name, p.project_number, NOT (' . $activeProject . ') AS archived
             FROM projects p WHERE ' . $scope . ' AND (' . $projectFilter . ')
             ORDER BY archived, p.project_number, p.name, p.id',
            $params
        );

        $params = [];
        $activeUser = 'COALESCE(u.is_deleted, 0) = 0 AND u.employment_status = "active"';
        $userFilter = '(' . $activeUser . ')';
        $retained = array_column($appointment['participants'] ?? [], 'id');
        if ($retained !== []) {
            $userFilter .= ' OR u.id IN (' . $this->ids($retained, 'retained_user', $params) . ')';
        }
        $participants = $this->connection->fetchAll(
            "SELECT u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name, NOT (" . $activeUser . ') AS archived
             FROM users u WHERE ' . $userFilter . ' ORDER BY archived, u.last_name, u.first_name, u.id',
            $params
        );

        return [
            'projects' => array_map($this->hydrateOption(...), $projects),
            'participants' => array_map($this->hydrateOption(...), $participants),
        ];
    }

    public function create(array $actor, array $data): int
    {
        $actorId = $this->requireRight($actor, true);
        $values = $this->values($data);
        return $this->connection->transaction(function () use ($actor, $actorId, $values): int {
            $participants = $values['participant_ids'];
            unset($values['participant_ids']);
            $this->assertNewProject($actor, $values['project_id']);
            $this->assertNewParticipants($participants);
            $this->write(
                'INSERT INTO appointments
                    (title, description, starts_at_utc, ends_at_utc, timezone, project_id, location, status,
                     created_by_user_id, updated_by_user_id, created_at, updated_at, version)
                 VALUES (:title, :description, :starts_at_utc, :ends_at_utc, :timezone, :project_id, :location, :status,
                     :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)',
                $values + ['created_by' => $actorId, 'updated_by' => $actorId]
            );
            $id = $this->connection->lastInsertId();
            if ($id < 1) {
                throw new RuntimeException('Der Termin konnte nicht gespeichert werden.');
            }
            $this->syncParticipants($id, [], $participants);

            return $id;
        });
    }

    public function update(array $actor, int $id, array $data): void
    {
        $actorId = $this->requireRight($actor, true);
        $version = $this->positiveId($data['version'] ?? null, 'Terminversion');
        $this->connection->transaction(function () use ($actor, $actorId, $id, $version, $data): void {
            $current = $this->lockedAppointment($actor, $id, $version);
            $existing = $this->participants([$id])[$id] ?? [];
            $current['participant_ids'] = array_column($existing, 'id');
            sort($current['participant_ids'], SORT_NUMERIC);
            $current['project_id'] = $current['project_id'] === null ? null : (int) $current['project_id'];
            $values = $this->values($data, $current);
            if ($current['status'] !== 'planned' && $values['status'] !== $current['status']) {
                throw new InvalidArgumentException('Ein erledigter oder abgesagter Termin kann seinen Status nicht mehr wechseln.');
            }
            if ($values['project_id'] !== $current['project_id']) {
                $this->assertNewProject($actor, $values['project_id']);
            }
            $participants = $values['participant_ids'];
            unset($values['participant_ids']);
            $originalFields = array_intersect_key($current, $values);
            ksort($originalFields);
            ksort($values);
            if ($originalFields === $values && $participants === $current['participant_ids']) {
                return;
            }
            $this->assertNewParticipants(array_values(array_diff($participants, $current['participant_ids'])));

            $this->write(
                'UPDATE appointments SET title = :title, description = :description, starts_at_utc = :starts_at_utc,
                    ends_at_utc = :ends_at_utc, timezone = :timezone, project_id = :project_id, location = :location,
                    status = :status, updated_by_user_id = :updated_by, updated_at = UTC_TIMESTAMP(), version = version + 1
                 WHERE id = :id AND version = :expected_version AND archived_at IS NULL',
                $values + ['id' => $id, 'updated_by' => $actorId, 'expected_version' => $version]
            );
            $this->syncParticipants($id, $current['participant_ids'], $participants);
        });
    }

    public function archive(array $actor, int $id, int $version): void
    {
        $actorId = $this->requireRight($actor, true);
        $this->positiveId($version, 'Terminversion');
        $this->connection->transaction(function () use ($actor, $actorId, $id, $version): void {
            $this->lockedAppointment($actor, $id, $version);
            $this->write(
                'UPDATE appointments SET archived_at = UTC_TIMESTAMP(), archived_by_user_id = :archived_by,
                    updated_at = UTC_TIMESTAMP(), updated_by_user_id = :updated_by, version = version + 1
                 WHERE id = :id AND version = :expected_version AND archived_at IS NULL',
                ['id' => $id, 'archived_by' => $actorId, 'updated_by' => $actorId, 'expected_version' => $version]
            );
        });
    }

    /** @return list<array> */
    public function calendarBetween(array $actor, string $fromDate, string $toDate, bool $includeDetails = true): array
    {
        $this->requireRight($actor);
        [$from, $until] = $this->dateRange($fromDate, $toDate);
        $params = [];
        $scope = $this->projectScope($actor, 'a.project_id', $params);
        $overlap = $this->overlap($from, $until, $params);
        $where = ' WHERE a.archived_at IS NULL AND ' . $scope . ' AND ' . $overlap;
        $order = ' ORDER BY a.starts_at_utc, a.id';
        if ($includeDetails) {
            return $this->hydrate($this->connection->fetchAll('SELECT ' . self::COLUMNS . self::FROM . $where . $order, $params));
        }

        $rows = $this->connection->fetchAll(
            'SELECT a.id, a.title, a.starts_at_utc, a.ends_at_utc FROM appointments a'
                . $where . ' AND a.status = "planned"' . $order,
            $params
        );

        return array_map(static fn (array $row): array => ['id' => (int) $row['id']] + $row, $rows);
    }

    private function filterDates(array $filters): array
    {
        $today = new DateTimeImmutable('today', $this->time->timezone);

        return match ($filters['period'] ?? 'upcoming') {
            'today' => [$today, $today->modify('+1 day')],
            'week' => [$today, $today->modify('+7 days')],
            'month' => [$today, $today->modify('+30 days')],
            'custom' => $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null),
            default => throw new InvalidArgumentException('Bitte wählen Sie einen gültigen Zeitraum aus.'),
        };
    }

    private function dateRange(mixed $from, mixed $to): array
    {
        $start = $this->time->date($from);
        $end = $this->time->date($to);
        if ($end < $start) {
            throw new InvalidArgumentException('Das Ende des Datumsbereichs liegt vor seinem Beginn.');
        }

        return [$start, $end->modify('+1 day')];
    }

    private function overlap(DateTimeImmutable $from, DateTimeImmutable $until, array &$params): string
    {
        $params['starts_before'] = $this->time->formatUtc($until);
        $params['ends_after'] = $this->time->formatUtc($from);

        return 'a.starts_at_utc < :starts_before AND a.ends_at_utc > :ends_after';
    }

    private function values(array $input, array $current = []): array
    {
        $title = $this->text($this->inputValue($input, $current, 'title', ''), 'Titel', 180);
        if ($title === '') {
            throw new InvalidArgumentException('Bitte geben Sie einen Termintitel ein.');
        }
        $start = $this->appointmentTime($input, $current, 'starts_at', 'Beginn');
        $end = $this->appointmentTime($input, $current, 'ends_at', 'Ende');
        if ($end <= $start) {
            throw new InvalidArgumentException('Das Terminende muss nach dem Beginn liegen.');
        }

        $project = $this->inputValue($input, $current, 'project_id');
        $sameInterval = ($current['starts_at_utc'] ?? null) === $start && ($current['ends_at_utc'] ?? null) === $end;
        return [
            'title' => $title,
            'description' => $this->nullableText($input, $current, 'description', 'Beschreibung', 4000),
            'starts_at_utc' => $start,
            'ends_at_utc' => $end,
            'timezone' => $sameInterval ? $current['timezone'] : $this->time->timezone->getName(),
            'project_id' => $this->optionalId($project, 'Projekt'),
            'location' => $this->nullableText($input, $current, 'location', 'Einsatzort', 255),
            'status' => $this->status($this->inputValue($input, $current, 'status', 'planned')),
            'participant_ids' => $this->participantIds($this->inputValue($input, $current, 'participant_ids', [])),
        ];
    }

    private function appointmentTime(array $input, array $current, string $key, string $label): string
    {
        $saved = $current[$key . '_utc'] ?? null;
        if ($saved !== null && (!array_key_exists($key, $input) || $input[$key] === $this->time->fromUtc($saved))) {
            return $saved;
        }

        return $this->time->toUtc($input[$key] ?? null, $label);
    }

    private function nullableText(array $input, array $current, string $key, string $label, int $max): ?string
    {
        $value = $this->inputValue($input, $current, $key);
        $value = $this->text($value ?? '', $label, $max);

        return $value === '' ? null : $value;
    }

    private function inputValue(array $input, array $current, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? $default);
    }

    private function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException($label . ' enthält einen ungültigen Textwert.');
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException($label . ' darf höchstens ' . $max . ' Zeichen enthalten.');
        }

        return $value;
    }

    private function optionalId(mixed $value, string $label): ?int
    {
        return $value === '' || $value === null ? null : $this->positiveId($value, $label);
    }

    private function positiveId(mixed $value, string $label): int
    {
        if ((!is_int($value) && (!is_string($value) || preg_match('/\A[1-9][0-9]{0,9}\z/', $value) !== 1))
            || (int) $value < 1 || (int) $value > 4294967295) {
            throw new InvalidArgumentException($label . ' enthält eine ungültige Auswahl.');
        }

        return (int) $value;
    }

    private function status(mixed $status): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Bitte wählen Sie einen gültigen Terminstatus.');
        }

        return $status;
    }

    /** @return list<int> */
    private function participantIds(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new InvalidArgumentException('Bitte wählen Sie gültige Mitarbeiter aus.');
        }
        $ids = array_map(fn (mixed $value): int => $this->positiveId($value, 'Mitarbeiter'), $values);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function lockedAppointment(array $actor, int $id, int $version): array
    {
        $row = $this->connection->fetchOne(
            'SELECT id, title, description, starts_at_utc, ends_at_utc, timezone, project_id, location, status, archived_at, version
             FROM appointments WHERE id = :id FOR UPDATE',
            ['id' => $id]
        );
        if ($row === null || ($row['project_id'] !== null && !$this->projectAccess->canAccess($actor, (int) $row['project_id']))) {
            throw $this->notFound();
        }
        if ($row['archived_at'] !== null) {
            throw new AppointmentConflictException('Der Termin wurde bereits archiviert.');
        }
        if ((int) $row['version'] !== $version) {
            throw new AppointmentConflictException('Der Termin wurde zwischenzeitlich geändert. Bitte laden Sie die aktuelle Version.');
        }

        return $row;
    }

    private function assertNewProject(array $actor, ?int $id): void
    {
        if ($id === null) {
            return;
        }
        $exists = $this->connection->fetchColumn(
            'SELECT id FROM projects WHERE id = :id AND COALESCE(is_deleted, 0) = 0 AND status <> "archived" LOCK IN SHARE MODE',
            ['id' => $id]
        );
        if (!$exists || !$this->projectAccess->canAccess($actor, $id)) {
            throw new InvalidArgumentException('Das gewählte Projekt ist für die Zuordnung nicht verfügbar.');
        }
    }

    /** @param list<int> $ids */
    private function assertNewParticipants(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $params = [];
        $in = $this->ids($ids, 'selected_user', $params);
        $rows = $this->connection->fetchAll(
            'SELECT id FROM users WHERE id IN (' . $in . ') AND COALESCE(is_deleted, 0) = 0 AND employment_status = "active"
             ORDER BY id LOCK IN SHARE MODE',
            $params
        );
        if (count($rows) !== count($ids)) {
            throw new InvalidArgumentException('Mindestens ein ausgewählter Mitarbeiter ist für die Zuordnung nicht verfügbar.');
        }
    }

    private function syncParticipants(int $id, array $old, array $new): void
    {
        $removed = array_diff($old, $new);
        if ($removed !== []) {
            $params = ['appointment' => $id];
            $this->write(
                'DELETE FROM appointment_participants WHERE appointment_id = :appointment AND user_id IN ('
                    . $this->ids(array_values($removed), 'removed_user', $params) . ')',
                $params
            );
        }
        foreach (array_diff($new, $old) as $user) {
            $this->write(
                'INSERT INTO appointment_participants (appointment_id, user_id, created_at) VALUES (:appointment, :user, UTC_TIMESTAMP())',
                ['appointment' => $id, 'user' => $user]
            );
        }
    }

    private function write(string $sql, array $params): void
    {
        if (!$this->connection->execute($sql, $params)) {
            throw new RuntimeException('Der Termin konnte nicht gespeichert werden.');
        }
    }

    private function requireRight(array $actor, bool $manage = false): int
    {
        $id = $this->activeActorId($actor);
        $permissions = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
        $global = in_array('*', $permissions, true);
        if (!$global && (!in_array('appointments.view', $permissions, true)
            || ($manage && !in_array('appointments.manage', $permissions, true)))) {
            throw new AppointmentAccessDeniedException('Für diese Aktion fehlt die Terminberechtigung.');
        }

        return $id;
    }

    private function activeActorId(array $actor): int
    {
        $id = (int) ($actor['id'] ?? 0);
        if ($id < 1 || (int) ($actor['is_deleted'] ?? 0) !== 0 || ($actor['employment_status'] ?? 'active') !== 'active') {
            throw new AppointmentAccessDeniedException('Für diese Aktion fehlt die Terminberechtigung.');
        }

        return $id;
    }

    private function projectScope(array $actor, string $column, array &$params): string
    {
        if ($this->projectAccess->hasGlobalAccess($actor)) {
            return '1 = 1';
        }
        $projectIds = $this->projectAccess->activeProjectIdsForUser($actor);

        return $projectIds === [] ? $column . ' IS NULL'
            : '(' . $column . ' IS NULL OR ' . $column . ' IN (' . $this->ids($projectIds, 'scope_project', $params) . '))';
    }

    private function ids(array $ids, string $prefix, array &$params): string
    {
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return implode(', ', $placeholders);
    }

    private function hydrate(array $rows): array
    {
        $participants = $this->participants(array_map('intval', array_column($rows, 'id')));
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['version'] = (int) $row['version'];
            $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];
            $row['starts_at'] = $this->time->fromUtc($row['starts_at_utc']);
            $row['ends_at'] = $this->time->fromUtc($row['ends_at_utc']);
            $row['participants'] = $participants[$row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    private function hydrateOwn(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['version'] = (int) $row['version'];
        $row['starts_at'] = $this->time->fromUtc($row['starts_at_utc']);
        $row['ends_at'] = $this->time->fromUtc($row['ends_at_utc']);
        $row['timezone'] = $this->time->timezone->getName();

        return [...$row, ...$this->acknowledgementState($row)];
    }

    private function acknowledgementState(array $row): array
    {
        $version = $row['acknowledged_version'] === null ? null : (int) $row['acknowledged_version'];

        return [
            'acknowledged' => $version === (int) $row['version'] && $row['acknowledged_at_utc'] !== null,
            'acknowledged_at_utc' => $row['acknowledged_at_utc'],
            'acknowledged_version' => $version,
        ];
    }

    private function hydrateOption(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['archived'] = (bool) $row['archived'];

        return $row;
    }

    private function participants(array $appointmentIds): array
    {
        $participants = [];
        foreach (array_chunk($appointmentIds, 500) as $chunk) {
            $params = [];
            $in = $this->ids($chunk, 'appointment', $params);
            $rows = $this->connection->fetchAll(
                "SELECT ap.appointment_id, u.id, CONCAT_WS(' ', u.first_name, u.last_name) AS name
                 FROM appointment_participants ap INNER JOIN users u ON u.id = ap.user_id
                 WHERE ap.appointment_id IN (" . $in . ') ORDER BY u.last_name, u.first_name, u.id',
                $params
            );
            foreach ($rows as $row) {
                $participants[(int) $row['appointment_id']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }

        return $participants;
    }

    private function notFound(): AppointmentNotFoundException
    {
        return new AppointmentNotFoundException('Der angeforderte Termin ist nicht verfügbar.');
    }
}
