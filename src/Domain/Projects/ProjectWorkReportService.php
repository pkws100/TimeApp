<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

final class ProjectWorkReportService
{
    public function __construct(
        private DatabaseConnection $connection,
        private ProjectAccessService $projectAccessService
    ) {
    }

    public function list(array $user, int $projectId, string $scope = 'active'): array
    {
        $this->assertProjectAccess($user, $projectId);

        if (!$this->connection->tableExists('project_work_reports')) {
            return [];
        }

        $includeArchived = $scope === 'all' && $this->projectAccessService->canManage($user);
        $rows = $this->connection->fetchAll(
            $this->entrySelect() . '
             WHERE project_work_reports.project_id = :project_id
               ' . ($includeArchived ? '' : 'AND COALESCE(project_work_reports.is_deleted, 0) = 0') . '
             ORDER BY project_work_reports.is_deleted ASC,
                      project_work_reports.work_date DESC,
                      project_work_reports.created_at DESC,
                      project_work_reports.id DESC',
            ['project_id' => $projectId]
        );

        return array_map([$this, 'publicEntry'], $rows);
    }

    /** @return array{entry: array, replayed: bool} */
    public function create(array $user, int $projectId, array $input): array
    {
        // Permission must be checked on every retry, before returning any stored report.
        $this->assertProjectAccess($user, $projectId);

        if (!$this->connection->tableExists('project_work_reports')) {
            throw new RuntimeException('Die Arbeitsberichte sind noch nicht migriert.');
        }

        $record = $this->normalize($input);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $this->connection->execute(
                'INSERT INTO project_work_reports (
                    project_id, created_by_user_id, request_id, work_date, description,
                    is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
                 ) VALUES (
                    :project_id, :created_by_user_id, :request_id, :work_date, :description,
                    0, NULL, NULL, NOW(), NOW()
                 )',
                ['project_id' => $projectId, 'created_by_user_id' => $userId, ...$record]
            );
            $entry = $this->find($this->connection->lastInsertId());

            if ($entry === null) {
                throw new RuntimeException('Der Arbeitsbericht konnte nicht geladen werden.');
            }

            return ['entry' => $this->publicEntry($entry), 'replayed' => false];
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }

        // The unique author/request key also serializes simultaneous submissions.
        // Archived reports retain their key and can never be recreated by a retry.
        $existing = $this->connection->fetchOne(
            $this->entrySelect() . '
             WHERE project_work_reports.created_by_user_id = :created_by_user_id
               AND project_work_reports.request_id = :request_id
             LIMIT 1',
            ['created_by_user_id' => $userId, 'request_id' => $record['request_id']]
        );

        if ($existing === null) {
            throw new RuntimeException('Der bereits gespeicherte Arbeitsbericht konnte nicht geladen werden.');
        }

        if ((int) $existing['project_id'] !== $projectId
            || (string) $existing['work_date'] !== $record['work_date']
            || (string) $existing['description'] !== $record['description']) {
            throw new ProjectWorkReportConflictException(
                'Diese Anfrage wurde bereits mit anderen Angaben gespeichert. Ihr Entwurf bleibt erhalten. '
                . 'Bitte pruefen Sie den gespeicherten Bericht, bevor Sie einen neuen Bericht anlegen.'
            );
        }

        return ['entry' => $this->publicEntry($existing), 'replayed' => true];
    }

    public function archive(array $user, int $entryId): array
    {
        $entry = $this->find($entryId);

        if ($entry === null || (int) ($entry['is_deleted'] ?? 0) === 1) {
            throw new InvalidArgumentException('Der Arbeitsbericht wurde nicht gefunden.');
        }

        $projectId = (int) $entry['project_id'];
        $this->assertProjectAccess($user, $projectId);
        $userId = (int) ($user['id'] ?? 0);

        if (!$this->projectAccessService->canManage($user) && (int) ($entry['created_by_user_id'] ?? 0) !== $userId) {
            throw new ProjectAccessDeniedException('Fremde Arbeitsberichte duerfen nicht archiviert werden.');
        }

        $this->connection->execute(
            'UPDATE project_work_reports
             SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :deleted_by_user_id, updated_at = NOW()
             WHERE id = :id AND COALESCE(is_deleted, 0) = 0',
            ['deleted_by_user_id' => $userId, 'id' => $entryId]
        );

        return ['id' => $entryId, 'project_id' => $projectId];
    }

    private function normalize(array $input): array
    {
        $description = $input['description'] ?? null;

        if (!is_string($description) || !mb_check_encoding($description, 'UTF-8')) {
            throw new InvalidArgumentException('Bitte geben Sie die ausgefuehrten Arbeiten als Text an.');
        }

        $description = trim(str_replace(["\r\n", "\r"], "\n", $description));

        if ($description === '') {
            throw new InvalidArgumentException('Bitte beschreiben Sie die ausgefuehrten Arbeiten.');
        }

        if (mb_strlen($description, 'UTF-8') > 10000) {
            throw new InvalidArgumentException('Die Beschreibung darf maximal 10.000 Zeichen enthalten.');
        }

        $requestId = $input['request_id'] ?? null;

        if (!is_string($requestId)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $requestId) !== 1) {
            throw new InvalidArgumentException('Bitte uebermitteln Sie eine gueltige Anfrage-ID fuer den Arbeitsbericht.');
        }

        $timezone = new DateTimeZone('Europe/Berlin');
        $today = new DateTimeImmutable('today', $timezone);
        $workDate = $input['work_date'] ?? $today->format('Y-m-d');

        if (!is_string($workDate)
            || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $workDate) !== 1
            || $workDate < '1000-01-01') {
            throw new InvalidArgumentException('Bitte geben Sie ein gueltiges Berichtsdatum an.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, $timezone);
        $dateErrors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $workDate
            || ($dateErrors !== false && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0))) {
            throw new InvalidArgumentException('Bitte geben Sie ein gueltiges Berichtsdatum an.');
        }

        if ($date > $today) {
            throw new InvalidArgumentException('Das Berichtsdatum darf nicht in der Zukunft liegen.');
        }

        return ['request_id' => strtolower($requestId), 'work_date' => $workDate, 'description' => $description];
    }

    private function find(int $entryId): ?array
    {
        if ($entryId <= 0 || !$this->connection->tableExists('project_work_reports')) {
            return null;
        }

        return $this->connection->fetchOne(
            $this->entrySelect() . ' WHERE project_work_reports.id = :id LIMIT 1',
            ['id' => $entryId]
        );
    }

    private function entrySelect(): string
    {
        return 'SELECT project_work_reports.*, users.first_name AS creator_first_name, users.last_name AS creator_last_name
                FROM project_work_reports
                LEFT JOIN users ON users.id = project_work_reports.created_by_user_id';
    }

    private function publicEntry(array $row): array
    {
        $creator = trim((string) ($row['creator_first_name'] ?? '') . ' ' . (string) ($row['creator_last_name'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'request_id' => (string) $row['request_id'],
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'created_by_name' => $creator !== '' ? $creator : null,
            'work_date' => (string) $row['work_date'],
            'description' => (string) $row['description'],
            'is_deleted' => (int) ($row['is_deleted'] ?? 0) === 1,
            'deleted_at' => $row['deleted_at'] ?? null,
            'deleted_by_user_id' => isset($row['deleted_by_user_id']) ? (int) $row['deleted_by_user_id'] : null,
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
}
