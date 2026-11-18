<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;

final class PersonnelDocumentService
{
    private const HEAD_COLUMNS = 'id, user_id, employment_context_id, document_type_id, document_requirement_id,
        period_from, period_until, issued_on, received_on, source, review_status, retention_anchor_on,
        retain_until, retention_hold, created_by_user_id, updated_by_user_id, archived_at,
        archived_by_user_id, created_at, updated_at, lock_version';

    private const VERSION_COLUMNS = 'id, document_id, version_number, supersedes_version_number,
        original_status, signature_status, created_by_user_id, created_at';

    private const DATE_FIELDS = [
        'period_from', 'period_until', 'issued_on', 'received_on', 'retention_anchor_on', 'retain_until',
    ];

    private const AUDIT_REASONS = [
        'document_created' => ['metadata_registered', 'incorrect_assignment'],
        'document_archived' => ['duplicate', 'superseded', 'withdrawn', 'incorrect_assignment'],
        'document_assignment_corrected' => ['incorrect_assignment'],
        'review_pending_to_rejected' => ['metadata_invalid', 'incorrect_assignment', 'insufficient_evidence'],
        'review_rejected_to_pending' => ['metadata_corrected', 'reassessment_requested'],
        'version_created' => ['metadata_recorded', 'metadata_revised'],
        'retention_hold_set' => ['legal_hold', 'retention_review'],
        'retention_hold_released' => ['hold_resolved'],
    ];

    private PersonnelCommandStore $commands;

    public function __construct(
        private DatabaseConnection $connection,
        private PersonnelAccessService $access
    ) {
        if (!$access->usesConnection($connection)) {
            throw new LogicException('Die Personalaktendienste müssen dieselbe Datenbankverbindung verwenden.');
        }
        $this->commands = new PersonnelCommandStore($connection);
    }

    /**
     * Register a manual metadata record. Context and rule IDs explicitly select immutable revisions.
     *
     * @return array{document_id: int, lock_version: int}
     */
    public function create(int $employeeId, array $input, string $requestId): array
    {
        $data = $this->normalizeMetadata($input);

        return $this->write(
            'document.create',
            $requestId,
            ['employee_id' => $employeeId, ...$data],
            fn (int $actorId) => $this->requireScope(PersonnelAccessService::MANAGE, $employeeId, $actorId),
            function (int $actorId) use ($employeeId, $data): array {
                $employee = $this->lockEmployee($employeeId);
                $this->requireScope(PersonnelAccessService::MANAGE, $employeeId, $actorId, true);
                $this->assertActiveEmployee($employee);
                $id = $this->insertDocument($employeeId, $data, $actorId);
                $this->auditChange($id, $actorId, 'document_created', 'metadata_registered');

                return ['document_id' => $id, 'lock_version' => 1];
            }
        );
    }

    public function listForEmployee(int $employeeId, bool $includeArchived = false): array
    {
        return $this->read('document.list', function () use ($employeeId, $includeArchived): array {
            $this->access->requireEmployee(PersonnelAccessService::VIEW, $employeeId);
            $rows = $this->connection->fetchAll(
                'SELECT ' . self::HEAD_COLUMNS . ' FROM employee_documents
                 WHERE user_id = :user_id' . ($includeArchived ? '' : ' AND archived_at IS NULL') . '
                 ORDER BY id DESC',
                ['user_id' => $employeeId]
            );

            return [array_map(self::unverified(...), $rows), null, null];
        });
    }

    public function get(int $documentId): array
    {
        return $this->read('document.read', function () use ($documentId): array {
            $head = $this->authorizedDocument($documentId, PersonnelAccessService::VIEW);

            return [self::unverified($head), $documentId, null];
        });
    }

    public function versions(int $documentId): array
    {
        return $this->read('document.versions', function () use ($documentId): array {
            $this->authorizedDocument($documentId, PersonnelAccessService::VIEW);
            $rows = $this->connection->fetchAll(
                'SELECT ' . self::VERSION_COLUMNS . ' FROM employee_document_versions
                 WHERE document_id = :document_id ORDER BY version_number',
                ['document_id' => $documentId]
            );

            return [array_map(self::unverified(...), $rows), $documentId, null];
        });
    }

    public function version(int $documentId, int $versionId): array
    {
        return $this->read('document.version', function () use ($documentId, $versionId): array {
            $this->authorizedDocument($documentId, PersonnelAccessService::VIEW);
            $row = $this->connection->fetchOne(
                'SELECT ' . self::VERSION_COLUMNS . ' FROM employee_document_versions
                 WHERE document_id = :document_id AND id = :id',
                ['document_id' => $documentId, 'id' => $versionId]
            );
            if ($row === null) {
                throw new PersonnelAccessDenied();
            }

            return [self::unverified($row), $documentId, $versionId];
        });
    }

    public function history(int $documentId): array
    {
        return $this->read('document.history', function () use ($documentId): array {
            $this->authorizedDocument($documentId, PersonnelAccessService::VIEW);
            $rows = $this->connection->fetchAll(
                'SELECT a.id, a.document_id, a.document_version_id, a.related_document_id,
                        a.action, a.actor_user_id, a.reason, a.created_at, related.user_id AS related_employee_id
                 FROM employee_document_audit a
                 LEFT JOIN employee_documents related ON related.id = a.related_document_id
                 WHERE a.document_id = :document_id ORDER BY a.id',
                ['document_id' => $documentId]
            );

            foreach ($rows as &$row) {
                $knownAction = isset(self::AUDIT_REASONS[$row['action']]);
                $row['reason'] = $knownAction && in_array($row['reason'], self::AUDIT_REASONS[$row['action']], true)
                    ? $row['reason'] : 'legacy_unclassified';
                $row['action'] = $knownAction ? $row['action'] : 'legacy_event';

                if ($row['related_document_id'] !== null) {
                    try {
                        $this->access->requireEmployee(PersonnelAccessService::VIEW, (int) $row['related_employee_id']);
                    } catch (PersonnelAccessDenied) {
                        $row['related_document_id'] = null;
                    }
                }
                unset($row['related_employee_id']);
            }
            unset($row);

            return [$rows, $documentId, null];
        });
    }

    /** @return array{document_id: int, lock_version: int, review_status: string} */
    public function changeStatus(
        int $documentId,
        string $status,
        int $expectedRevision,
        string $reason,
        string $requestId
    ): array {
        $action = match ($status) {
            'rejected' => 'review_pending_to_rejected',
            'pending' => 'review_rejected_to_pending',
            default => throw new InvalidArgumentException('Dieser Prüfstatus erfordert eine nachweisgebundene Prüfung.'),
        };
        $this->validateReason($action, $reason);

        return $this->writeDocument(
            'document.status',
            $documentId,
            $requestId,
            ['status' => $status, 'expected_revision' => $expectedRevision, 'reason' => $reason],
            function (int $actorId, array $head) use ($expectedRevision, $status, $action, $reason): array {
                $this->assertRevision($head, $expectedRevision);
                $this->assertOpen($head);
                $previous = $status === 'rejected' ? 'pending' : 'rejected';
                if ($head['review_status'] !== $previous) {
                    throw new PersonnelConflict('Der angeforderte Prüfstatusübergang ist nicht möglich.');
                }

                $id = (int) $head['id'];
                $this->persistHead($id, $actorId, 'review_status = :status', ['status' => $status]);
                $this->auditChange($id, $actorId, $action, $reason);

                return ['document_id' => $id, 'lock_version' => $expectedRevision + 1, 'review_status' => $status];
            }
        );
    }

    /**
     * A hold may be recorded on an archived document without reopening its metadata or versions.
     *
     * @return array{document_id: int, lock_version: int, retention_hold: bool}
     */
    public function setRetentionHold(
        int $documentId,
        bool $hold,
        int $expectedRevision,
        string $reason,
        string $requestId
    ): array {
        $action = $hold ? 'retention_hold_set' : 'retention_hold_released';
        $this->validateReason($action, $reason);

        return $this->writeDocument(
            'document.hold',
            $documentId,
            $requestId,
            ['hold' => $hold, 'expected_revision' => $expectedRevision, 'reason' => $reason],
            function (int $actorId, array $head) use ($expectedRevision, $hold, $action, $reason): array {
                $this->assertRevision($head, $expectedRevision);
                if ((bool) $head['retention_hold'] === $hold) {
                    throw new PersonnelConflict('Der Aufbewahrungsstatus wurde bereits so gesetzt.');
                }

                $id = (int) $head['id'];
                $this->persistHead($id, $actorId, 'retention_hold = :hold', ['hold' => (int) $hold]);
                $this->auditChange($id, $actorId, $action, $reason);

                return ['document_id' => $id, 'lock_version' => $expectedRevision + 1, 'retention_hold' => $hold];
            }
        );
    }

    /** @return array{document_id: int, lock_version: int} */
    public function archive(int $documentId, int $expectedRevision, string $reason, string $requestId): array
    {
        $this->validateReason('document_archived', $reason);

        return $this->writeDocument(
            'document.archive',
            $documentId,
            $requestId,
            ['expected_revision' => $expectedRevision, 'reason' => $reason],
            function (int $actorId, array $head) use ($expectedRevision, $reason): array {
                $this->assertArchivable($head, $expectedRevision);
                $id = (int) $head['id'];
                $this->archiveHead($id, $actorId);
                $this->auditChange($id, $actorId, 'document_archived', $reason);

                return ['document_id' => $id, 'lock_version' => $expectedRevision + 1];
            }
        );
    }

    /**
     * Preserve the original document with its version chain and create a fresh pending metadata record.
     *
     * @return array{document_id: int, replacement_document_id: int, lock_version: int, replacement_lock_version: int}
     */
    public function correctAssignment(
        int $documentId,
        int $replacementEmployeeId,
        array $input,
        int $expectedRevision,
        string $reason,
        string $requestId
    ): array {
        $this->validateReason('document_assignment_corrected', $reason);
        $data = $this->normalizeMetadata($input);

        return $this->writeDocument(
            'document.correct_assignment',
            $documentId,
            $requestId,
            ['replacement_employee_id' => $replacementEmployeeId, 'expected_revision' => $expectedRevision, 'reason' => $reason, ...$data],
            function (int $actorId, array $head) use ($replacementEmployeeId, $data, $expectedRevision, $reason): array {
                $this->assertArchivable($head, $expectedRevision);
                if ((int) $head['user_id'] === $replacementEmployeeId
                    && (int) $head['document_type_id'] === $data['document_type_id']
                    && $this->nullableId($head['employment_context_id']) === $data['employment_context_id']
                    && $this->nullableId($head['document_requirement_id']) === $data['document_requirement_id']) {
                    throw new PersonnelConflict('Die Dokumentzuordnung hat sich nicht verändert.');
                }

                $id = (int) $head['id'];
                $replacementId = $this->insertDocument($replacementEmployeeId, $data, $actorId);
                $this->archiveHead($id, $actorId);
                $this->auditChange($id, $actorId, 'document_assignment_corrected', $reason, relatedDocumentId: $replacementId);
                $this->auditChange($replacementId, $actorId, 'document_created', $reason, relatedDocumentId: $id);

                return [
                    'document_id' => $id,
                    'replacement_document_id' => $replacementId,
                    'lock_version' => $expectedRevision + 1,
                    'replacement_lock_version' => 1,
                ];
            },
            replacementEmployeeId: $replacementEmployeeId
        );
    }

    /**
     * Zero denotes the absence of a predecessor. Clients supply no file, signature or version attributes.
     *
     * @return array{document_id: int, version_id: int, version_number: int, lock_version: int}
     */
    public function addVersion(
        int $documentId,
        int $expectedPredecessorNumber,
        string $reason,
        string $requestId
    ): array {
        if ($expectedPredecessorNumber < 0) {
            throw new InvalidArgumentException('Die erwartete Vorgängerversion ist ungültig.');
        }
        $this->validateReason('version_created', $reason);

        return $this->writeDocument(
            'document.add_version',
            $documentId,
            $requestId,
            ['expected_predecessor' => $expectedPredecessorNumber, 'reason' => $reason],
            function (int $actorId, array $head) use ($expectedPredecessorNumber, $reason): array {
                $this->assertOpen($head);
                if ($head['review_status'] !== 'pending') {
                    throw new PersonnelConflict('Neue Metadatenversionen benötigen einen offenen Prüfstatus.');
                }

                $id = (int) $head['id'];
                $latest = (int) $this->connection->fetchColumn(
                    'SELECT version_number FROM employee_document_versions WHERE document_id = :document_id
                     ORDER BY version_number DESC LIMIT 1 FOR UPDATE',
                    ['document_id' => $id]
                );
                if ($latest !== $expectedPredecessorNumber) {
                    throw new PersonnelConflict('Die Dokumentversion wurde zwischenzeitlich geändert.');
                }

                $this->persist(
                    'INSERT INTO employee_document_versions
                     (document_id, version_number, supersedes_version_number, created_by_user_id, created_at)
                     VALUES (:document_id, :version_number, :predecessor, :actor_id, UTC_TIMESTAMP())',
                    ['document_id' => $id, 'version_number' => $latest + 1, 'predecessor' => $latest ?: null, 'actor_id' => $actorId]
                );
                $versionId = $this->connection->lastInsertId();
                $this->persistHead($id, $actorId);
                $this->auditChange($id, $actorId, 'version_created', $reason, $versionId);

                return [
                    'document_id' => $id,
                    'version_id' => $versionId,
                    'version_number' => $latest + 1,
                    'lock_version' => (int) $head['lock_version'] + 1,
                ];
            },
            requiresActiveOwner: true
        );
    }

    private function normalizeMetadata(array $input): array
    {
        $keys = ['document_type_id', 'employment_context_id', 'document_requirement_id', 'source', ...self::DATE_FIELDS];
        if (array_diff_key($input, array_flip($keys)) !== []) {
            throw new InvalidArgumentException('Die Dokumentmetadaten enthalten nicht unterstützte Angaben.');
        }

        $data = [];
        foreach (['document_type_id', 'employment_context_id', 'document_requirement_id'] as $key) {
            $value = $input[$key] ?? null;
            if (($value !== null || $key === 'document_type_id') && (!is_int($value) || $value < 1)) {
                throw new InvalidArgumentException('Eine Dokumentzuordnung ist ungültig.');
            }
            $data[$key] = $value;
        }
        $source = array_key_exists('source', $input) ? $input['source'] : 'manual';
        if ($source !== 'manual') {
            throw new InvalidArgumentException('Die Erfassungsquelle ist in diesem Arbeitsablauf unzulässig.');
        }
        $data['source'] = $source;

        foreach (self::DATE_FIELDS as $key) {
            $date = $input[$key] ?? null;
            $data[$key] = $date === null ? null : PersonnelDate::require($date);
        }

        if (($data['period_until'] !== null && ($data['period_from'] === null || $data['period_until'] < $data['period_from']))
            || ($data['retain_until'] !== null && ($data['retention_anchor_on'] === null || $data['retain_until'] < $data['retention_anchor_on']))
            || ($data['received_on'] !== null && $data['issued_on'] !== null && $data['received_on'] < $data['issued_on'])) {
            throw new InvalidArgumentException('Die Dokumentdaten bilden keinen gültigen Zeitraum.');
        }

        return $data;
    }

    private function assertReferences(int $employeeId, array $data): void
    {
        if (!$this->connection->fetchColumn(
            'SELECT id FROM personnel_document_types WHERE id = :id',
            ['id' => $data['document_type_id']]
        )) {
            throw new InvalidArgumentException('Der Dokumenttyp ist ungültig.');
        }

        $from = $data['period_from'] ?? $data['issued_on'] ?? $data['received_on'];
        $until = $data['period_until'] ?? $from;
        if ($data['document_requirement_id'] !== null) {
            $rule = $this->connection->fetchOne(
                'SELECT valid_from, valid_until FROM personnel_document_requirements
                 WHERE id = :id AND document_type_id = :type_id AND applicability_mode = "manual_review"',
                ['id' => $data['document_requirement_id'], 'type_id' => $data['document_type_id']]
            );
            if ($rule === null || !$this->matchesPeriod($from, $until, $rule['valid_from'], $rule['valid_until'])) {
                throw new InvalidArgumentException('Die Regelrevision passt nicht zum Dokumenttyp oder Zeitraum.');
            }
        }
        if ($data['employment_context_id'] !== null) {
            $context = $this->connection->fetchOne(
                'SELECT effective_from, effective_until FROM employee_employment_contexts
                 WHERE id = :id AND user_id = :user_id',
                ['id' => $data['employment_context_id'], 'user_id' => $employeeId]
            );
            if ($context === null || !$this->matchesPeriod($from, $until, $context['effective_from'], $context['effective_until'])) {
                throw new InvalidArgumentException('Die Beschäftigungsrevision passt nicht zum Mitarbeiter oder Zeitraum.');
            }
        }
    }

    private function matchesPeriod(?string $from, ?string $until, ?string $validFrom, ?string $validUntil): bool
    {
        return ($validFrom === null || ($from !== null && $from >= $validFrom))
            && ($validUntil === null || ($until !== null && $until <= $validUntil));
    }

    private function insertDocument(int $employeeId, array $data, int $actorId): int
    {
        $this->assertReferences($employeeId, $data);
        $this->persist(
            'INSERT INTO employee_documents
             (user_id, document_type_id, employment_context_id, document_requirement_id, source,
              period_from, period_until, issued_on, received_on, retention_anchor_on, retain_until,
              created_by_user_id, created_at, updated_at)
             VALUES (:user_id, :document_type_id, :employment_context_id, :document_requirement_id, :source,
                     :period_from, :period_until, :issued_on, :received_on, :retention_anchor_on, :retain_until,
                     :actor_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['user_id' => $employeeId, ...$data, 'actor_id' => $actorId]
        );

        return $this->connection->lastInsertId();
    }

    private function lockEmployee(int $employeeId): array
    {
        $row = $this->connection->fetchOne(
            'SELECT employment_status, is_deleted FROM users WHERE id = :id LOCK IN SHARE MODE',
            ['id' => $employeeId]
        );
        if ($row === null) {
            throw new PersonnelAccessDenied();
        }

        return $row;
    }

    private function assertActiveEmployee(array $employee): void
    {
        if ((bool) $employee['is_deleted'] || $employee['employment_status'] !== 'active') {
            throw new PersonnelConflict('Neue Unterlagen erfordern ein aktives Mitarbeiterkonto.');
        }
    }

    private function requireScope(string $permission, int $employeeId, int $actorId, bool $locked = false): void
    {
        if ($this->access->requireEmployee($permission, $employeeId, $locked) !== $actorId) {
            throw new PersonnelAccessDenied();
        }
    }

    private function authorizedDocument(int $documentId, string $permission, ?int $actorId = null): array
    {
        $authorizedActor = $this->access->requirePermission($permission);
        $head = $this->connection->fetchOne(
            'SELECT ' . self::HEAD_COLUMNS . ' FROM employee_documents WHERE id = :id',
            ['id' => $documentId]
        );
        if ($head === null || ($actorId !== null && $authorizedActor !== $actorId)) {
            throw new PersonnelAccessDenied();
        }
        $this->requireScope($permission, (int) $head['user_id'], $authorizedActor);

        return $head;
    }

    private function read(string $action, callable $operation): array
    {
        return $this->access->auditedRead($action, $operation);
    }

    private function write(string $action, string $requestId, array $payload, callable $authorize, callable $operation): array
    {
        try {
            $actorId = $this->access->actorId();

            return $this->commands->execute(
                $actorId,
                $requestId,
                $action,
                $payload,
                fn () => $authorize($actorId),
                fn (): array => $operation($actorId)
            );
        } catch (PersonnelAccessDenied $exception) {
            $this->access->auditAccess($action, false);

            throw $exception;
        } catch (PDOException) {
            throw new RuntimeException('Die Personalaktenänderung konnte nicht gespeichert werden.');
        }
    }

    private function writeDocument(
        string $action,
        int $documentId,
        string $requestId,
        array $payload,
        callable $operation,
        bool $requiresActiveOwner = false,
        ?int $replacementEmployeeId = null
    ): array {
        return $this->write(
            $action,
            $requestId,
            ['document_id' => $documentId, ...$payload],
            function (int $actorId) use ($documentId, $replacementEmployeeId): void {
                $this->authorizedDocument($documentId, PersonnelAccessService::MANAGE, $actorId);
                if ($replacementEmployeeId !== null) {
                    $this->requireScope(PersonnelAccessService::MANAGE, $replacementEmployeeId, $actorId);
                }
            },
            function (int $actorId) use ($documentId, $operation, $requiresActiveOwner, $replacementEmployeeId): array {
                $owner = (int) $this->connection->fetchColumn('SELECT user_id FROM employee_documents WHERE id = :id', ['id' => $documentId]);
                $employees = $replacementEmployeeId === null || $replacementEmployeeId === $owner
                    ? [$owner] : [$owner, $replacementEmployeeId];
                sort($employees, SORT_NUMERIC);
                $employeesLocked = [];
                foreach ($employees as $employeeId) {
                    $employeesLocked[$employeeId] = $this->lockEmployee($employeeId);
                }

                $head = $this->connection->fetchOne(
                    'SELECT ' . self::HEAD_COLUMNS . ' FROM employee_documents WHERE id = :id FOR UPDATE',
                    ['id' => $documentId]
                );
                if ($head === null) {
                    throw new PersonnelAccessDenied();
                }
                foreach ($employees as $employeeId) {
                    $this->requireScope(PersonnelAccessService::MANAGE, $employeeId, $actorId, true);
                }
                if ($requiresActiveOwner) {
                    $this->assertActiveEmployee($employeesLocked[$owner]);
                }
                if ($replacementEmployeeId !== null) {
                    $this->assertActiveEmployee($employeesLocked[$replacementEmployeeId]);
                }

                return $operation($actorId, $head);
            }
        );
    }

    private function assertRevision(array $head, int $expectedRevision): void
    {
        if ((int) $head['lock_version'] !== $expectedRevision) {
            throw new PersonnelConflict('Das Dokument wurde zwischenzeitlich geändert.');
        }
    }

    private function assertOpen(array $head): void
    {
        if ($head['archived_at'] !== null) {
            throw new PersonnelConflict('Das Dokument ist bereits archiviert.');
        }
    }

    private function assertArchivable(array $head, int $expectedRevision): void
    {
        $this->assertRevision($head, $expectedRevision);
        $this->assertOpen($head);
        if ((bool) $head['retention_hold']) {
            throw new PersonnelConflict('Das Dokument hat eine aktive Aufbewahrungssperre.');
        }
    }

    private function validateReason(string $action, string $reason): void
    {
        if (!in_array($reason, self::AUDIT_REASONS[$action], true)) {
            throw new InvalidArgumentException('Für diese Änderung ist ein zulässiger Begründungscode erforderlich.');
        }
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function unverified(array $record): array
    {
        return [...$record, 'evidence_verified' => false];
    }

    private function archiveHead(int $documentId, int $actorId): void
    {
        $this->persistHead(
            $documentId,
            $actorId,
            'archived_at = UTC_TIMESTAMP(), archived_by_user_id = :archiver_id',
            ['archiver_id' => $actorId]
        );
    }

    /** Assignments come exclusively from the service's status and hold paths. */
    private function persistHead(int $id, int $actorId, string $assignments = '', array $parameters = []): void
    {
        $this->persist(
            'UPDATE employee_documents SET lock_version = lock_version + 1,
                    updated_at = UTC_TIMESTAMP(), updated_by_user_id = :actor_id'
                    . ($assignments === '' ? '' : ', ' . $assignments) . ' WHERE id = :id',
            ['id' => $id, 'actor_id' => $actorId, ...$parameters]
        );
    }

    private function auditChange(
        int $documentId,
        int $actorId,
        string $action,
        string $reason,
        ?int $versionId = null,
        ?int $relatedDocumentId = null
    ): void {
        $this->persist(
            'INSERT INTO employee_document_audit
             (document_id, document_version_id, related_document_id, action, actor_user_id, reason, created_at)
             VALUES (:document_id, :version_id, :related_id, :action, :actor_id, :reason, UTC_TIMESTAMP())',
            [
                'document_id' => $documentId,
                'version_id' => $versionId,
                'related_id' => $relatedDocumentId,
                'action' => $action,
                'actor_id' => $actorId,
                'reason' => $reason,
            ]
        );
    }

    private function persist(string $sql, array $parameters): void
    {
        if (!$this->connection->execute($sql, $parameters)) {
            throw new RuntimeException('Die Personalaktenänderung konnte nicht gespeichert werden.');
        }
    }
}
