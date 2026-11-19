<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use LogicException;

final class PersonnelComplianceCatalogService
{
    private const RULE_COLUMNS = 'r.id, r.rule_code, r.revision, r.document_type_id, r.source_id,
        r.citation, r.valid_from, r.valid_until, r.legal_force, r.record_kind, r.record_frequency,
        r.applicability_mode, r.employment_scope, r.evidence_rule, r.signature_rule, r.retention_trigger,
        r.retention_years, r.retention_rule, r.created_at,
        t.code AS document_type_code, s.code AS source_code, s.version AS source_version';

    private const TYPE_AND_SOURCE = 'INNER JOIN personnel_document_types t ON t.id = r.document_type_id
        INNER JOIN personnel_compliance_sources s ON s.id = r.source_id';

    public function __construct(private DatabaseConnection $connection, private PersonnelAccessService $access)
    {
        if (!$access->usesConnection($connection)) {
            throw new LogicException('Die Personalakten-Dienste muessen dieselbe Datenbankverbindung verwenden.');
        }
    }

    public function sources(): array
    {
        $this->access->requirePermission(PersonnelAccessService::CATALOG);

        return $this->connection->fetchAll(
            'SELECT id, code, version, title, url, published_on, effective_from, checked_on, created_at
             FROM personnel_compliance_sources ORDER BY code, effective_from, id',
        );
    }

    public function documentTypes(): array
    {
        $this->access->requirePermission(PersonnelAccessService::CATALOG);

        return $this->connection->fetchAll('SELECT id, code, label, category FROM personnel_document_types ORDER BY code');
    }

    /** @return array{types:list<array>,rules:list<array>} */
    public function assignmentOptions(int $employeeId): array
    {
        $this->access->requireEmployee(PersonnelAccessService::MANAGE, $employeeId);

        return [
            'types' => $this->connection->fetchAll('SELECT id, code, label, category FROM personnel_document_types ORDER BY label, id'),
            'rules' => $this->connection->fetchAll(
                'SELECT id, rule_code, revision, document_type_id, citation, valid_from, valid_until, applicability_mode
                 FROM personnel_document_requirements ORDER BY rule_code, revision DESC, id'
            ),
        ];
    }

    public function ruleRevision(int $ruleId, ?int $documentTypeId = null): array
    {
        $this->access->requirePermission(PersonnelAccessService::CATALOG);
        $revision = $this->connection->fetchOne(
            'SELECT ' . self::RULE_COLUMNS . ' FROM personnel_document_requirements r '
            . self::TYPE_AND_SOURCE . ' WHERE r.id = :id',
            ['id' => $ruleId],
        );
        if ($revision === null || ($documentTypeId !== null && (int) $revision['document_type_id'] !== $documentTypeId)) {
            throw new InvalidArgumentException('Die Regelrevision ist fuer diesen Bezug nicht verfuegbar.');
        }

        return $revision;
    }

    public function ruleHistory(string $ruleCode): array
    {
        $this->access->requirePermission(PersonnelAccessService::CATALOG);
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $ruleCode)) {
            throw new InvalidArgumentException('Der Regelschluessel ist ungueltig.');
        }

        return $this->connection->fetchAll(
            'SELECT ' . self::RULE_COLUMNS . ' FROM personnel_document_requirements r '
            . self::TYPE_AND_SOURCE . ' WHERE r.rule_code = :rule_code ORDER BY r.revision',
            ['rule_code' => $ruleCode],
        );
    }

    /** @return array{manual_review: true, candidates:list<array>, ambiguous_rule_codes:list<string>} */
    public function rulesForTypeOn(int $documentTypeId, string $date): array
    {
        $this->access->requirePermission(PersonnelAccessService::CATALOG);
        $date = PersonnelDate::require($date);
        if ($this->connection->fetchOne('SELECT id FROM personnel_document_types WHERE id = :id', ['id' => $documentTypeId]) === null) {
            throw new InvalidArgumentException('Der Dokumenttyp ist nicht verfuegbar.');
        }

        // Count all applicable revisions of a rule, even when it was reassigned to another type.
        $rows = $this->connection->fetchAll(
            'WITH applicable AS (
                SELECT req.*, COUNT(*) OVER (PARTITION BY req.rule_code) AS applicable_count
                FROM personnel_document_requirements req
                WHERE (req.valid_from IS NULL OR req.valid_from <= :starts_on)
                  AND (req.valid_until IS NULL OR req.valid_until >= :ends_on)
             ) SELECT ' . self::RULE_COLUMNS . ', r.applicable_count FROM applicable r '
            . self::TYPE_AND_SOURCE . ' WHERE r.document_type_id = :type_id ORDER BY r.rule_code, r.revision',
            ['type_id' => $documentTypeId, 'starts_on' => $date, 'ends_on' => $date],
        );

        $candidates = [];
        $ambiguous = [];
        foreach ($rows as $row) {
            if ((int) $row['applicable_count'] > 1) {
                $ambiguous[] = $row['rule_code'];
                continue;
            }
            unset($row['applicable_count']);
            $candidates[] = $row;
        }

        return [
            'manual_review' => true,
            'candidates' => $candidates,
            'ambiguous_rule_codes' => array_values(array_unique($ambiguous)),
        ];
    }
}
