<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

final class PersonnelAdminQueryService
{
    private const EMPLOYEE_COLUMNS = 'u.id, u.employee_number, u.first_name, u.last_name, u.employment_status, u.is_deleted';
    private const PAGE_SIZE = 30;
    private const AUDIT_PAGE_SIZE = 50;
    private const ACCESS_ACTIONS = [
        'admin.personnel.overview', 'admin.personnel.employee', 'admin.personnel.document',
        'admin.personnel.employee_audit', 'admin.personnel.correction_options', 'admin.personnel.assignment_options',
        'document.read', 'document.list', 'document.version', 'document.versions', 'document.history',
        'document.employee_history', 'document.download_file', 'document.evidence.history',
        'document.evidence_history', 'employment.history', 'employment.as_of',
    ];

    private const LATEST_EVIDENCE = 'LEFT JOIN employee_document_versions v ON v.id = (
        SELECT previous_version.id FROM employee_document_versions previous_version
        WHERE previous_version.document_id = d.id ORDER BY previous_version.version_number DESC LIMIT 1
    ) LEFT JOIN employee_document_evidence_reviews e ON e.id = (
        SELECT previous_review.id FROM employee_document_evidence_reviews previous_review
        WHERE previous_review.document_id = d.id AND previous_review.document_version_id = v.id
        ORDER BY previous_review.id DESC LIMIT 1
    )';

    public function __construct(
        private readonly DatabaseConnection $connection,
        private readonly PersonnelAccessService $access,
        private readonly EmploymentContextService $employment,
        private readonly PersonnelDocumentService $documents,
        private readonly PersonnelComplianceCatalogService $catalog,
    ) {
        if (!$access->usesConnection($connection)) {
            throw new LogicException('Personalaktenansicht und Zugriffsdienst benötigen dieselbe Datenbankverbindung.');
        }
    }

    public function overview(string $search = '', string $filter = 'active', int $page = 1, ?string $asOf = null): array
    {
        $search = trim($search);
        if (strlen($search) > 240 || !in_array($filter, ['active', 'archived', 'all'], true)) {
            throw new InvalidArgumentException('Die Such- oder Filterangaben sind ungültig.');
        }
        $date = $this->date($asOf);
        $offset = $this->offset($page, self::PAGE_SIZE);

        return $this->access->auditedRead('admin.personnel.overview', function () use ($search, $filter, $page, $date, $offset): array {
            $scopeActorId = $this->access->employeeDirectoryScope(PersonnelAccessService::VIEW);
            $rows = $this->scopedEmployees($scopeActorId, $search, $filter, self::PAGE_SIZE + 1, $offset);
            $hasMore = count($rows) > self::PAGE_SIZE;
            $employees = array_slice($rows, 0, self::PAGE_SIZE);
            $counts = $this->documentCounts(array_map('intval', array_column($employees, 'id')));
            foreach ($employees as &$employee) {
                $id = (int) $employee['id'];
                $assessment = $this->employment->contextsOn($id, $date);
                $employee += $counts[$id] ?? $this->emptyDocumentCounts();
                $employee['ambiguous'] = $assessment['ambiguous_employment_keys'] !== [];
            }
            unset($employee);

            return [[
                'employees' => $employees, 'search' => $search, 'filter' => $filter, 'page' => $page,
                'has_more' => $hasMore, 'as_of' => $date,
            ], null, null];
        });
    }

    public function employee(int $employeeId, ?string $asOf = null): array
    {
        $date = $this->date($asOf);

        return $this->access->auditedRead('admin.personnel.employee', function () use ($employeeId, $date): array {
            $this->access->requireEmployee(PersonnelAccessService::VIEW, $employeeId);
            $employee = $this->employeeRecord($employeeId);
            $assessment = $this->employment->contextsOn($employeeId, $date);
            $history = $this->contextHistory($employeeId);
            $documents = $this->decorateDocuments($this->documents->listForEmployee($employeeId, true), $employeeId);

            return [[
                'employee' => $employee, 'as_of' => $date, 'employment_history' => $history,
                'employment_current' => $this->publicContexts($assessment['contexts']),
                'ambiguous_employment_keys' => $assessment['ambiguous_employment_keys'],
                'documents' => $documents, 'can_manage' => $this->canManage($employeeId),
            ], null, null, $employeeId];
        });
    }

    public function document(int $documentId): array
    {
        return $this->access->auditedRead('admin.personnel.document', function () use ($documentId): array {
            $document = $this->documents->get($documentId);
            $employeeId = (int) $document['user_id'];
            $employee = $this->employeeRecord($employeeId);
            $document['type_label'] = (string) $this->connection->fetchColumn(
                'SELECT label FROM personnel_document_types WHERE id = :type_id', ['type_id' => $document['document_type_id']]
            );
            $document['source'] = $document['source'] === 'manual' ? 'manual' : 'legacy_unclassified';
            $document['rule'] = $document['document_requirement_id'] === null ? null : $this->connection->fetchOne(
                'SELECT id, rule_code, revision, document_type_id, citation, valid_from, valid_until, applicability_mode
                 FROM personnel_document_requirements WHERE id = :rule_id AND document_type_id = :type_id',
                ['rule_id' => $document['document_requirement_id'], 'type_id' => $document['document_type_id']],
            );
            $versions = $this->withActorNames($this->documents->versions($documentId), 'created_by_user_id', 'created_by_name');
            $history = $this->withActorNames($this->documents->history($documentId));
            $evidence = $this->withActorNames($this->documents->evidenceHistory($documentId));
            $accessRows = $this->accessRows($employeeId, $documentId, 0);

            return [[
                'document' => $document, 'employee' => $employee, 'versions' => $versions, 'change_audit' => $history,
                'evidence_history' => $evidence,
                'access_audit' => array_slice($accessRows, 0, self::AUDIT_PAGE_SIZE),
                'access_has_more' => count($accessRows) > self::AUDIT_PAGE_SIZE,
                'can_manage' => $this->canManage($employeeId),
            ], $documentId, null, $employeeId];
        });
    }

    public function employeeAudit(int $employeeId, int $page = 1): array
    {
        $offset = $this->offset($page, self::AUDIT_PAGE_SIZE);

        return $this->access->auditedRead('admin.personnel.employee_audit', function () use ($employeeId, $page, $offset): array {
            $actorId = $this->access->requireEmployee(PersonnelAccessService::VIEW, $employeeId);
            $limit = self::AUDIT_PAGE_SIZE + 1;
            $access = $this->accessRows($employeeId, null, $offset);
            $changes = $this->withActorNames($this->documents->historyForEmployee($employeeId, $limit, $offset));
            $scope = $this->scopeHistory($employeeId, $actorId, $offset);
            $employment = $this->contextHistory($employeeId);
            usort($employment, static fn (array $left, array $right): int => (int) $right['id'] <=> (int) $left['id']);

            return [[
                'employee' => $this->employeeRecord($employeeId), 'page' => $page,
                'access_audit' => array_slice($access, 0, self::AUDIT_PAGE_SIZE),
                'change_audit' => array_slice($changes, 0, self::AUDIT_PAGE_SIZE),
                'scope_history' => array_slice($scope, 0, self::AUDIT_PAGE_SIZE),
                'employment_history' => array_slice($employment, $offset, self::AUDIT_PAGE_SIZE),
                'access_has_more' => count($access) > self::AUDIT_PAGE_SIZE,
                'change_has_more' => count($changes) > self::AUDIT_PAGE_SIZE,
                'scope_has_more' => count($scope) > self::AUDIT_PAGE_SIZE,
                'employment_has_more' => count($employment) > $offset + self::AUDIT_PAGE_SIZE,
            ], null, null, $employeeId];
        });
    }

    public function assignmentOptions(int $employeeId): array
    {
        return $this->access->auditedRead('admin.personnel.assignment_options', function () use ($employeeId): array {
            $this->access->requireEmployee(PersonnelAccessService::VIEW, $employeeId);

            return [$this->catalog->assignmentOptions($employeeId), null, null, $employeeId];
        });
    }

    public function correctionOptions(int $documentId, ?int $targetEmployeeId = null, string $search = '', int $page = 1): array
    {
        $search = trim($search);
        if (strlen($search) > 240) {
            throw new InvalidArgumentException('Die Suche nach Mitarbeitenden ist ungültig.');
        }
        $offset = $this->offset($page, self::PAGE_SIZE);

        return $this->access->auditedRead('admin.personnel.correction_options', function () use ($documentId, $targetEmployeeId, $search, $page, $offset): array {
            $document = $this->documents->get($documentId);
            $sourceEmployeeId = (int) $document['user_id'];
            $this->access->requireEmployee(PersonnelAccessService::MANAGE, $sourceEmployeeId);
            $scopeActorId = $this->access->employeeDirectoryScope(PersonnelAccessService::MANAGE);
            $eligible = $this->scopedEmployees($scopeActorId, $search, 'active', self::PAGE_SIZE + 1, $offset);
            $target = null;
            if ($targetEmployeeId !== null) {
                $this->access->requireEmployee(PersonnelAccessService::VIEW, $targetEmployeeId);
                $this->access->requireEmployee(PersonnelAccessService::MANAGE, $targetEmployeeId);
                $target = $this->employeeRecord($targetEmployeeId);
                if ($target['employment_status'] !== 'active' || (bool) $target['is_deleted']) {
                    throw new PersonnelAccessDenied();
                }
            }

            return [[
                ...$this->catalog->assignmentOptions($targetEmployeeId ?? $sourceEmployeeId),
                'eligible_employees' => array_slice($eligible, 0, self::PAGE_SIZE),
                'employee' => $target,
                'employment_history' => $targetEmployeeId === null ? [] : $this->contextHistory($targetEmployeeId),
                'search' => $search, 'page' => $page, 'has_more' => count($eligible) > self::PAGE_SIZE,
            ], $documentId, null, $sourceEmployeeId];
        });
    }

    private function scopedEmployees(?int $scopeActorId, string $search, string $filter, int $limit, int $offset): array
    {
        $params = [];
        $clauses = ['1 = 1'];
        if ($scopeActorId !== null) {
            $clauses[] = '1 = (SELECT s.is_granted FROM employee_personnel_scope_events s
                WHERE s.employee_user_id = u.id AND s.grantee_user_id = :scope_actor ORDER BY s.id DESC LIMIT 1)';
            $params['scope_actor'] = $scopeActorId;
        }
        if ($filter === 'active') {
            $clauses[] = "u.is_deleted = 0 AND u.employment_status = 'active'";
        } elseif ($filter === 'archived') {
            $clauses[] = 'u.is_deleted = 1';
        }
        if ($search !== '') {
            $literal = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $clauses[] = "(CONCAT_WS(' ', u.first_name, u.last_name) LIKE :full_name ESCAPE '!'
                OR CONCAT_WS(' ', u.last_name, u.first_name) LIKE :reversed_name ESCAPE '!'
                OR u.employee_number LIKE :employee_number ESCAPE '!')";
            $params += ['full_name' => $literal, 'reversed_name' => $literal, 'employee_number' => $literal];
        }

        return $this->connection->fetchAll(
            'SELECT ' . self::EMPLOYEE_COLUMNS . ' FROM users u WHERE ' . implode(' AND ', $clauses)
            . ' ORDER BY u.last_name, u.first_name, u.id LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );
    }

    private function documentCounts(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAll(
            'SELECT d.user_id, COUNT(*) AS document_count,
                SUM(d.review_status = "pending" AND COALESCE(e.outcome, "open") = "open") AS pending_count,
                SUM(d.review_status = "rejected" OR (d.review_status = "pending" AND e.outcome = "objected")) AS rejected_count,
                SUM(d.review_status = "pending" AND e.outcome = "confirmed_internal") AS reviewed_count,
                SUM(d.review_status = "reviewed") AS legacy_reviewed_count
             FROM employee_documents d ' . self::LATEST_EVIDENCE . '
             WHERE d.archived_at IS NULL AND d.user_id IN (' . $this->placeholders($employeeIds) . ') GROUP BY d.user_id',
            $employeeIds,
        );
        $result = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row['user_id'];
            unset($row['user_id']);
            $result[$employeeId] = array_map('intval', $row);
        }

        return $result;
    }

    private function emptyDocumentCounts(): array
    {
        return ['document_count' => 0, 'pending_count' => 0, 'rejected_count' => 0, 'reviewed_count' => 0, 'legacy_reviewed_count' => 0];
    }

    private function decorateDocuments(array $documents, int $employeeId): array
    {
        if ($documents === []) {
            return [];
        }
        $metadata = array_column($this->connection->fetchAll(
            'SELECT d.id, t.label AS type_label, v.id AS latest_version_id, e.outcome AS latest_evidence_outcome,
                (SELECT COUNT(*) FROM employee_document_versions all_versions WHERE all_versions.document_id = d.id) AS version_count,
                (SELECT COUNT(*) FROM employee_document_versions stored_versions
                    WHERE stored_versions.document_id = d.id AND stored_versions.storage_format IS NOT NULL) AS file_version_count
             FROM employee_documents d JOIN personnel_document_types t ON t.id = d.document_type_id '
            . self::LATEST_EVIDENCE . ' WHERE d.user_id = :employee_id',
            ['employee_id' => $employeeId],
        ), null, 'id');

        foreach ($documents as &$document) {
            $document += $metadata[$document['id']] ?? [];
            $document['source'] = $document['source'] === 'manual' ? 'manual' : 'legacy_unclassified';
        }
        unset($document);

        return $documents;
    }

    private function accessRows(int $employeeId, ?int $documentId, int $offset): array
    {
        $params = ['employee_id' => $employeeId, 'legacy_employee_id' => $employeeId];
        $documentFilter = '';
        if ($documentId !== null) {
            $documentFilter = ' AND a.document_id = :document_id';
            $params['document_id'] = $documentId;
        }
        $limit = self::AUDIT_PAGE_SIZE + 1;
        $rows = $this->connection->fetchAll(
            'SELECT a.id, a.actor_user_id, COALESCE(a.employee_user_id, d.user_id) AS employee_user_id,
                    a.document_id, a.document_version_id, a.action, a.outcome, a.created_at
             FROM employee_document_access_log a LEFT JOIN employee_documents d ON d.id = a.document_id
             WHERE a.outcome = "allowed" AND (a.employee_user_id = :employee_id
                OR (a.employee_user_id IS NULL AND d.user_id = :legacy_employee_id))' . $documentFilter
            . ' ORDER BY a.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );
        foreach ($rows as &$row) {
            if (!in_array($row['action'], self::ACCESS_ACTIONS, true)) {
                $row['action'] = 'legacy_event';
            }
        }
        unset($row);

        return $this->withActorNames($rows);
    }

    private function scopeHistory(int $employeeId, int $actorId, int $offset): array
    {
        $allGrantees = false;
        if ($this->access->employeeDirectoryScope(PersonnelAccessService::VIEW) === null) {
            try {
                $this->access->requirePermission(PersonnelAccessService::DELEGATE);
                $allGrantees = true;
            } catch (PersonnelAccessDenied) {
                // An actor without delegation rights gets only their own scope history.
            }
        }
        $params = ['employee_id' => $employeeId];
        $filter = '';
        if (!$allGrantees) {
            $filter = ' AND grantee_user_id = :grantee_id';
            $params['grantee_id'] = $actorId;
        }
        $limit = self::AUDIT_PAGE_SIZE + 1;
        $rows = $this->connection->fetchAll(
            'SELECT id, employee_user_id, grantee_user_id, changed_by_user_id AS actor_user_id, is_granted, reason_code, created_at
             FROM employee_personnel_scope_events WHERE employee_user_id = :employee_id' . $filter
            . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );
        foreach ($rows as &$row) {
            if (!in_array($row['reason_code'], ['assignment', 'revocation', 'correction'], true)) {
                $row['reason_code'] = 'legacy_unclassified';
            }
        }
        unset($row);

        return $this->withActorNames($this->withActorNames($rows), 'grantee_user_id', 'grantee_name');
    }

    private function withActorNames(array $rows, string $actorColumn = 'actor_user_id', string $nameColumn = 'actor_name'): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', array_column($rows, $actorColumn))));
        $names = array_column($this->connection->fetchAll(
            "SELECT id, CONCAT_WS(' ', first_name, last_name) AS name FROM users WHERE id IN (" . $this->placeholders($ids) . ')',
            $ids,
        ), 'name', 'id');
        foreach ($rows as &$row) {
            $row[$nameColumn] = $names[(int) $row[$actorColumn]] ?? '';
        }
        unset($row);

        return $rows;
    }

    private function contextHistory(int $employeeId): array
    {
        $history = $this->publicContexts($this->employment->history($employeeId));
        $superseded = array_fill_keys(array_filter(array_column($history, 'supersedes_context_id')), true);
        foreach ($history as &$context) {
            $context['superseded'] = isset($superseded[$context['id']]);
        }
        unset($context);

        return $this->withActorNames($history, 'created_by_user_id', 'created_by_name');
    }

    private function publicContexts(array $contexts): array
    {
        foreach ($contexts as &$context) {
            $context['source'] = $context['source'] === 'manual' ? 'manual' : 'legacy_unclassified';
        }
        unset($context);

        return $contexts;
    }

    private function employeeRecord(int $employeeId): array
    {
        return $this->connection->fetchOne(
            'SELECT ' . self::EMPLOYEE_COLUMNS . ' FROM users u WHERE u.id = :employee_id',
            ['employee_id' => $employeeId],
        ) ?? throw new PersonnelAccessDenied();
    }

    private function canManage(int $employeeId): bool
    {
        try {
            $this->access->requireEmployee(PersonnelAccessService::MANAGE, $employeeId);

            return true;
        } catch (PersonnelAccessDenied) {
            return false;
        }
    }

    private function date(?string $asOf): string
    {
        return PersonnelDate::require($asOf ?? (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'));
    }

    private function offset(int $page, int $size): int
    {
        if ($page < 1 || $page > 100000) {
            throw new InvalidArgumentException('Die angeforderte Seite ist ungültig.');
        }

        return ($page - 1) * $size;
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
