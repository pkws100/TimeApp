<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

final class ProjectMaterialService
{
    private const MAX_QUANTITY = '999999999.999';

    public function __construct(
        private DatabaseConnection $connection,
        private ProjectAccessService $projectAccessService
    ) {
    }

    public function list(array $user, int $projectId, string $scope = 'active'): array
    {
        $this->assertProjectAccess($user, $projectId);

        if (!$this->connection->tableExists('project_material_entries')) {
            return [];
        }

        $includeArchived = $scope === 'all' && $this->projectAccessService->canManage($user);
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

        return array_map([$this, 'publicEntry'], $rows);
    }

    public function create(array $user, int $projectId, array $input): array
    {
        return $this->createWithResult($user, $projectId, $input)['entry'];
    }

    /** @return array{entry: array, replayed: bool} */
    public function createWithResult(array $user, int $projectId, array $input): array
    {
        // Replays must still be authorized after a session or membership change.
        $this->assertProjectAccess($user, $projectId);

        if (!$this->connection->tableExists('project_material_entries')) {
            throw new RuntimeException('Die Materialerfassung ist noch nicht migriert.');
        }

        $record = $this->normalize($input);
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
        $payloadHash = hash('sha256', json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            $this->connection->execute(
            'INSERT INTO project_material_entries (
                project_id, created_by_user_id, work_date, description, quantity, unit, note,
                ' . ($requestId !== null ? 'client_request_id, request_payload_hash,' : '') . '
                is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
             ) VALUES (
                :project_id, :created_by_user_id, :work_date, :description, :quantity, :unit, :note,
                ' . ($requestId !== null ? ':client_request_id, :request_payload_hash,' : '') . '
                0, NULL, NULL, NOW(), NOW()
             )',
            [
                'project_id' => $projectId,
                'created_by_user_id' => $userId,
                ...$record,
                ...($requestId !== null ? ['client_request_id' => $requestId, 'request_payload_hash' => $payloadHash] : []),
            ]
            );

            $entry = $this->find($this->connection->lastInsertId());

            if ($entry === null) {
                throw new RuntimeException('Der Materialeintrag konnte nicht geladen werden.');
            }

            return ['entry' => $this->publicEntry($entry), 'replayed' => false];
        } catch (PDOException $exception) {
            if ($requestId === null || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }

        // The author/request unique index serializes simultaneous requests. An
        // archived booking retains its key and is never recreated by a retry.
        $existing = $this->connection->fetchOne(
            'SELECT id, project_id, request_payload_hash FROM project_material_entries
             WHERE created_by_user_id = :author AND client_request_id = :request_id LIMIT 1',
            ['author' => $userId, 'request_id' => $requestId]
        );
        if ($existing === null) {
            throw new RuntimeException('Der bereits gespeicherte Materialeintrag konnte nicht geladen werden.');
        }
        if ((int) $existing['project_id'] !== $projectId
            || !hash_equals((string) $existing['request_payload_hash'], $payloadHash)) {
            throw new ProjectMaterialConflictException(
                'Diese Materialanfrage wurde bereits mit anderen Angaben gespeichert. Ihr Entwurf bleibt erhalten. '
                . 'Bitte pruefen Sie den gespeicherten Materialeintrag, bevor Sie eine neue Buchung anlegen.'
            );
        }
        $entry = $this->find((int) $existing['id']);
        if ($entry === null) {
            throw new RuntimeException('Der bereits gespeicherte Materialeintrag konnte nicht geladen werden.');
        }

        return ['entry' => $this->publicEntry($entry), 'replayed' => true];
    }

    public function archive(array $user, int $entryId): array
    {
        $entry = $this->find($entryId);

        if ($entry === null || (int) ($entry['is_deleted'] ?? 0) === 1) {
            throw new InvalidArgumentException('Der Materialeintrag wurde nicht gefunden.');
        }

        $projectId = (int) ($entry['project_id'] ?? 0);
        $this->assertProjectAccess($user, $projectId);
        $userId = (int) ($user['id'] ?? 0);

        if (!$this->projectAccessService->canManage($user) && (int) ($entry['created_by_user_id'] ?? 0) !== $userId) {
            throw new ProjectAccessDeniedException('Fremde Materialeintraege duerfen nicht archiviert werden.');
        }

        $this->connection->execute(
            'UPDATE project_material_entries
             SET is_deleted = 1,
                 deleted_at = NOW(),
                 deleted_by_user_id = :deleted_by_user_id,
                 updated_at = NOW()
             WHERE id = :id
               AND COALESCE(is_deleted, 0) = 0',
            [
                'deleted_by_user_id' => $userId,
                'id' => $entryId,
            ]
        );

        return [
            'id' => $entryId,
            'project_id' => $projectId,
        ];
    }

    private function normalize(array $input): array
    {
        $description = trim($this->scalarString($input['description'] ?? null, 'Material oder Artikel'));
        $unit = $this->nullableTrimmed($input['unit'] ?? null, 'Einheit');
        $note = $this->nullableTrimmed($input['note'] ?? null, 'Bemerkung');
        $quantity = $this->normalizeQuantity($input['quantity'] ?? null);
        $workDate = trim($this->scalarString($input['work_date'] ?? null, 'Materialdatum'));
        $workDate = $workDate !== '' ? $workDate : (new \DateTimeImmutable('today'))->format('Y-m-d');

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

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $workDate);
        $dateErrors = \DateTimeImmutable::getLastErrors();

        if (!$date instanceof \DateTimeImmutable
            || $date->format('Y-m-d') !== $workDate
            || ($dateErrors !== false && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0))) {
            throw new InvalidArgumentException('Bitte geben Sie ein gueltiges Materialdatum an.');
        }

        if ($date > new \DateTimeImmutable('today')) {
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

    private function publicEntry(array $row): array
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
            'is_deleted' => (int) ($row['is_deleted'] ?? 0) === 1,
            'deleted_at' => $row['deleted_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function assertProjectAccess(array $user, int $projectId): void
    {
        if (!$this->projectAccessService->canAccess($user, $projectId)) {
            throw new ProjectAccessDeniedException('Keine Berechtigung fuer dieses Projekt.');
        }
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
