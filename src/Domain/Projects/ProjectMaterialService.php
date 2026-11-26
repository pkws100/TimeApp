<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Timesheets\LiveWorkSession;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

final class ProjectMaterialService
{
    private const MAX_QUANTITY = '999999999.999';
    public const CAPTURE_STANDARD = 'standard';
    public const CAPTURE_CROSS_PROJECT = 'cross_project';
    public const CAPTURE_BACKDATED = 'backdated';
    public const CAPTURE_CROSS_PROJECT_BACKDATED = 'cross_project_backdated';

    public function __construct(
        private DatabaseConnection $connection,
        private ProjectAccessService $projectAccessService,
        private ?WorkTimeEventService $workTimeEvents = null
    ) {
    }

    public function list(array $user, int $projectId, string $scope = 'active'): array
    {
        $includeArchived = $scope === 'all' && $this->projectAccessService->canManage($user);
        if (!$this->projectAccessService->canAccess($user, $projectId, $includeArchived)) {
            throw new ProjectAccessDeniedException('Keine Berechtigung fuer dieses Projekt.');
        }

        if (!$this->connection->tableExists('project_material_entries')) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT
                project_material_entries.*,
                users.first_name AS creator_first_name,
                users.last_name AS creator_last_name
             FROM project_material_entries
             LEFT JOIN users ON users.id = project_material_entries.created_by_user_id
             WHERE project_material_entries.project_id = :project_id
               ' . ($includeArchived ? '' : 'AND COALESCE(project_material_entries.is_deleted, 0) = 0') . '
             ORDER BY project_material_entries.is_deleted ASC,
                      project_material_entries.work_date DESC,
                      project_material_entries.created_at DESC,
                      project_material_entries.id DESC',
            ['project_id' => $projectId]
        );

        return $this->publicEntries($user, $rows, $includeArchived);
    }

    public function create(array $user, int $projectId, array $input): array
    {
        return $this->createWithResult($user, $projectId, $input)['entry'];
    }

    /**
     * @param (callable(): ?array)|null $reloadActor
     * @return array{entry: array, replayed: bool}
     */
    public function createWithResult(array $user, int $projectId, array $input, ?callable $reloadActor = null): array
    {
        $this->projectAccessService->requireProjectEntryWriteAccess($user, $projectId);

        if (!$this->connection->tableExists('project_material_entries')) {
            throw new RuntimeException('Die Materialerfassung ist noch nicht migriert.');
        }

        $userId = (int) ($user['id'] ?? 0);
        $requestId = $input['client_request_id'] ?? null;
        if (array_key_exists('client_request_id', $input)) {
            if (!is_string($requestId)
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $requestId) !== 1) {
                throw new InvalidArgumentException('Bitte uebermitteln Sie eine gueltige Anfrage-ID fuer die Materialbuchung.');
            }
            $requestId = strtolower($requestId);
            if (!$this->connection->columnExists('project_material_entries', 'client_request_id')) {
                throw new RuntimeException('Die Absicherung wiederholter Materialbuchungen ist noch nicht migriert.');
            }
        }
        $captureColumns = (int) $this->connection->fetchColumn(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
             AND table_name = "project_material_entries"
             AND column_name IN ("capture_type", "captured_work_project_id", "captured_timesheet_id")'
        );
        if ($captureColumns !== 0 && $captureColumns !== 3) {
            throw new RuntimeException('Der Erfassungskontext fuer Materialbuchungen ist noch nicht migriert.');
        }
        $hasCaptureContext = $captureColumns === 3;

        if ($requestId !== null) {
            $existing = $this->replay($user, $projectId, $requestId, $input, $reloadActor);
            if ($existing !== null) {
                return $existing;
            }
        }

        $insert = function () use ($user, $userId, $projectId, $input, $requestId, $hasCaptureContext, $reloadActor): array {
            $actor = $this->currentWriteActor($user, $projectId, $reloadActor);
            $capturedAt = $this->now();
            $record = $this->normalize($input, $capturedAt);
            $capture = $hasCaptureContext ? $this->captureContext($userId, $projectId, $record['work_date'], $capturedAt) : [];
            $request = $requestId === null ? [] : [
                'client_request_id' => $requestId,
                'request_payload_hash' => $this->payloadHash($record, $input, !$hasCaptureContext),
            ];
            $params = [
                'project_id' => $projectId,
                'created_by_user_id' => $userId,
                ...$record,
                ...$capture,
                ...$request,
                'is_deleted' => 0,
                'created_at' => $capturedAt->format('Y-m-d H:i:s'),
                'updated_at' => $capturedAt->format('Y-m-d H:i:s'),
            ];
            // Spaltennamen stammen ausschließlich aus den festen Service-Feldern.
            $columns = array_keys($params);
            $this->connection->execute(
                'INSERT INTO project_material_entries (' . implode(', ', $columns) . ')
                 VALUES (:' . implode(', :', $columns) . ')',
                $params
            );

            return $this->result($actor, $this->connection->lastInsertId(), false);
        };

        try {
            // Zeitbuchung und Material-Snapshot verwenden dieselbe Schreibsperre.
            return $hasCaptureContext
                ? (new TimesheetWriteGuard($this->connection))->withAccountingWriteLock(
                    fn (): array => $this->connection->transaction($insert)
                )
                : $this->connection->transaction($insert);
        } catch (PDOException $exception) {
            if ($requestId === null || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            // Der Unique-Index bindet auch gleichzeitige Anfragen an den ersten Eintrag.
            return $this->replay($user, $projectId, $requestId, $input, $reloadActor)
                ?? throw new RuntimeException('Der bereits gespeicherte Materialeintrag konnte nicht geladen werden.');
        }
    }

    /** @return array{entry: array, replayed: bool}|null */
    private function replay(array $user, int $projectId, string $requestId, array $input, ?callable $reloadActor): ?array
    {
        $existing = $this->connection->fetchOne(
            'SELECT * FROM project_material_entries
             WHERE created_by_user_id = :author AND client_request_id = :request_id LIMIT 1',
            ['author' => (int) $user['id'], 'request_id' => $requestId]
        );
        if ($existing === null) {
            return null;
        }

        $actor = $this->currentWriteActor($user, $projectId, $reloadActor);
        $record = $this->normalize($input, $this->now());
        if ((int) $existing['project_id'] !== $projectId
            || !hash_equals((string) $existing['request_payload_hash'], $this->payloadHash($record, $input, !isset($existing['capture_type'])))) {
            throw new ProjectMaterialConflictException(
                'Diese Materialanfrage wurde bereits mit anderen Angaben gespeichert. Ihr Entwurf bleibt erhalten. '
                . 'Bitte pruefen Sie den gespeicherten Materialeintrag, bevor Sie eine neue Buchung anlegen.'
            );
        }
        return $this->result($actor, (int) $existing['id'], true);
    }

    /** @return array{entry: array, replayed: bool} */
    private function result(array $user, int $entryId, bool $replayed): array
    {
        $entry = $this->find($entryId);
        if ($entry === null) {
            throw new RuntimeException('Der Materialeintrag konnte nicht geladen werden.');
        }

        return ['entry' => $this->publicEntries($user, [$entry])[0], 'replayed' => $replayed];
    }

    private function captureContext(int $userId, int $projectId, string $workDate, DateTimeImmutable $capturedAt): array
    {
        $captureDate = $capturedAt->format('Y-m-d');
        $entry = $this->connection->fetchOne(
            'SELECT id, project_id FROM timesheets AS t
             WHERE user_id = :user_id AND work_date = :capture_date AND ' . LiveWorkSession::isOpenSql('t') . '
             ORDER BY start_time DESC, updated_at DESC, id DESC LIMIT 1',
            ['user_id' => $userId, 'capture_date' => $captureDate, 'live_reference_date' => $captureDate]
        );
        $entry ??= $this->workTimeEvents?->statusForUser($userId, $captureDate)['active_work_entry'] ?? null;

        $workProjectId = isset($entry['project_id']) ? (int) $entry['project_id'] : null;
        $crossProject = $workProjectId !== null && $workProjectId !== $projectId;
        $backdated = $workDate < $captureDate;
        $captureType = match (true) {
            $crossProject && $backdated => self::CAPTURE_CROSS_PROJECT_BACKDATED,
            $crossProject => self::CAPTURE_CROSS_PROJECT,
            $backdated => self::CAPTURE_BACKDATED,
            default => self::CAPTURE_STANDARD,
        };

        return [
            'capture_type' => $captureType,
            'captured_work_project_id' => $workProjectId,
            'captured_timesheet_id' => $entry['id'] ?? null,
        ];
    }

    public function archive(array $user, int $entryId, bool $allowArchivedProject = false): array
    {
        $entry = $this->find($entryId);

        if ($entry === null || (int) ($entry['is_deleted'] ?? 0) === 1) {
            throw new InvalidArgumentException('Der Materialeintrag wurde nicht gefunden.');
        }

        $projectId = (int) ($entry['project_id'] ?? 0);
        $this->projectAccessService->requireProjectEntryWriteAccess($user, $projectId, $allowArchivedProject);
        $userId = (int) ($user['id'] ?? 0);

        if (!$this->projectAccessService->canManage($user) && (int) ($entry['created_by_user_id'] ?? 0) !== $userId) {
            throw new ProjectAccessDeniedException('Fremde Materialeintraege duerfen nicht archiviert werden.', ProjectAccessDeniedException::WRITE_DENIED);
        }

        $archivedAt = $this->now()->format('Y-m-d H:i:s');
        $this->connection->execute(
            'UPDATE project_material_entries
             SET is_deleted = 1,
                 deleted_at = :deleted_at,
                 deleted_by_user_id = :deleted_by_user_id,
                 updated_at = :updated_at
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0',
            [
                'deleted_at' => $archivedAt,
                'updated_at' => $archivedAt,
                'deleted_by_user_id' => $userId,
                'id' => $entryId,
            ]
        );

        return [
            'id' => $entryId,
            'project_id' => $projectId,
        ];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }

    private function payloadHash(array $record, array $input, bool $legacy = false): string
    {
        // Ein implizites Datum beschreibt auch am Folgetag dieselbe Anfrage.
        if (!$legacy && trim((string) ($input['work_date'] ?? '')) === '') {
            $record['work_date'] = null;
        }

        return hash('sha256', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function normalize(array $input, DateTimeImmutable $now): array
    {
        $description = trim($this->scalarString($input['description'] ?? null, 'Material oder Artikel'));
        $unit = $this->nullableTrimmed($input['unit'] ?? null, 'Einheit');
        $note = $this->nullableTrimmed($input['note'] ?? null, 'Bemerkung');
        $quantity = $this->normalizeQuantity($input['quantity'] ?? null);
        $workDate = trim($this->scalarString($input['work_date'] ?? null, 'Materialdatum'));
        $today = $now->format('Y-m-d');
        $workDate = $workDate !== '' ? $workDate : $today;

        if ($description === '') {
            throw new InvalidArgumentException('Bitte geben Sie Material oder Artikel an.');
        }

        if (mb_strlen($description) > 255) {
            throw new InvalidArgumentException('Die Materialbeschreibung darf maximal 255 Zeichen enthalten.');
        }

        if ($unit !== null && mb_strlen($unit) > 40) {
            throw new InvalidArgumentException('Die Einheit darf maximal 40 Zeichen enthalten.');
        }

        if ($note !== null && mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Die Bemerkung darf maximal 2.000 Zeichen enthalten.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $workDate) !== 1) {
            throw new InvalidArgumentException('Bitte geben Sie ein gueltiges Materialdatum an.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, $now->getTimezone());
        $dateErrors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $workDate
            || ($dateErrors !== false && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0))) {
            throw new InvalidArgumentException('Bitte geben Sie ein gueltiges Materialdatum an.');
        }

        if ($workDate > $today) {
            throw new InvalidArgumentException('Das Materialdatum darf nicht in der Zukunft liegen.');
        }

        return [
            'work_date' => $workDate,
            'description' => $description,
            'quantity' => $quantity,
            'unit' => $unit,
            'note' => $note,
        ];
    }

    private function normalizeQuantity(mixed $value): string
    {
        $quantity = str_replace(',', '.', trim($this->scalarString($value, 'Menge')));

        if (preg_match('/^\d{1,12}(?:\.\d{1,3})?$/', $quantity) !== 1) {
            throw new InvalidArgumentException('Die Menge muss positiv sein und darf hoechstens drei Nachkommastellen enthalten.');
        }

        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $canonical = ltrim($whole, '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        $canonical .= $fraction !== '' ? '.' . rtrim($fraction, '0') : '';
        $canonical = rtrim($canonical, '.');

        if ((float) $canonical <= 0 || (float) $canonical > (float) self::MAX_QUANTITY) {
            throw new InvalidArgumentException('Die Menge muss groesser als 0 und darf maximal ' . self::MAX_QUANTITY . ' betragen.');
        }

        return $canonical;
    }

    private function find(int $entryId): ?array
    {
        if ($entryId <= 0 || !$this->connection->tableExists('project_material_entries')) {
            return null;
        }

        return $this->connection->fetchOne(
            'SELECT
                project_material_entries.*,
                users.first_name AS creator_first_name,
                users.last_name AS creator_last_name
             FROM project_material_entries
             LEFT JOIN users ON users.id = project_material_entries.created_by_user_id
             WHERE project_material_entries.id = :id
             LIMIT 1',
            ['id' => $entryId]
        );
    }

    private function publicEntries(array $user, array $rows, bool $allowArchivedProjects = false): array
    {
        $projectNames = [];

        return array_map(function (array $row) use ($user, $allowArchivedProjects, &$projectNames): array {
            $projectId = (int) ($row['captured_work_project_id'] ?? 0);
            if ($projectId > 0 && !array_key_exists($projectId, $projectNames)) {
                $name = $this->projectAccessService->canAccess($user, $projectId, $allowArchivedProjects)
                    ? $this->connection->fetchColumn('SELECT name FROM projects WHERE id = :id', ['id' => $projectId])
                    : null;
                $projectNames[$projectId] = is_string($name) ? $name : null;
            }

            return $this->publicEntry($row, $projectNames[$projectId] ?? null);
        }, $rows);
    }

    private function publicEntry(array $row, ?string $capturedProjectName): array
    {
        $creator = trim((string) ($row['creator_first_name'] ?? '') . ' ' . (string) ($row['creator_last_name'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => (int) ($row['project_id'] ?? 0),
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'created_by_name' => $creator !== '' ? $creator : null,
            'work_date' => (string) ($row['work_date'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'quantity' => (string) ($row['quantity'] ?? '0'),
            'unit' => $row['unit'] ?? null,
            'note' => $row['note'] ?? null,
            'capture_type' => $row['capture_type'] ?? null,
            'captured_work_project_id' => $capturedProjectName === null ? null : (int) $row['captured_work_project_id'],
            'captured_work_project_name' => $capturedProjectName,
            'is_deleted' => (int) ($row['is_deleted'] ?? 0) === 1,
            'deleted_at' => $row['deleted_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function currentWriteActor(array $user, int $projectId, ?callable $reloadActor): array
    {
        $current = $reloadActor === null ? $user : $reloadActor();
        if ($current === null || (int) $current['id'] !== (int) $user['id']) {
            throw new ProjectAccessDeniedException('Keine Schreibberechtigung fuer dieses Projekt.');
        }
        $this->projectAccessService->requireProjectEntryWriteAccess($current, $projectId);

        return $current;
    }

    private function nullableTrimmed(mixed $value, string $label): ?string
    {
        $value = trim($this->scalarString($value, $label));

        return $value === '' ? null : $value;
    }

    private function scalarString(mixed $value, string $label): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_scalar($value)) {
            throw new InvalidArgumentException($label . ' muss als einfacher Textwert uebergeben werden.');
        }

        return (string) $value;
    }
}
