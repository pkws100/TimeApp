<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class EmploymentContextService
{
    private const TRAIT_VALUES = [
        'employment_type' => ['unknown', 'regular', 'marginal', 'short_term', 'vocational_training', 'other'],
        'student_status' => ['unknown', 'not_applicable', 'working_student', 'other'],
        'pension_insurance_status' => ['unknown', 'mandatory', 'exempt', 'other'],
        'cross_border_status' => ['unknown', 'not_applicable', 'coordination_required', 'other'],
        'work_authorization_status' => ['unknown', 'not_required', 'evidence_required'],
    ];

    private const REASONS = ['initial_record', 'change_of_circumstances', 'correction', 'employment_end'];

    private const CONTEXT_COLUMNS = 'ctx.id, ctx.user_id, ctx.employment_key, ctx.revision, ctx.supersedes_context_id,
        ctx.effective_from, ctx.effective_until, ctx.employment_type, ctx.student_status, ctx.pension_insurance_status,
        ctx.cross_border_status, ctx.work_authorization_status, ctx.source, ctx.revision_reason,
        ctx.created_by_user_id, ctx.created_at';

    private readonly PersonnelCommandStore $commands;

    public function __construct(
        private DatabaseConnection $connection,
        private PersonnelAccessService $access,
    ) {
        if (!$access->usesConnection($connection)) {
            throw new LogicException('Die Personalakten-Dienste muessen dieselbe Datenbankverbindung verwenden.');
        }
        $this->commands = new PersonnelCommandStore($connection);
    }

    /**
     * With no predecessor this adds an independent effective interval for the employment key.
     * A predecessor replaces one currently effective slot while keeping its historical row.
     *
     * @return array{context_id:int, revision:int}
     */
    public function append(
        int $employeeId,
        string $employmentKey,
        int $expectedLatestRevision,
        ?int $supersedesContextId,
        array $metadata,
        string $reasonCode,
        string $requestId,
    ): array {
        try {
            $actorId = $this->access->requireEmployee(PersonnelAccessService::MANAGE, $employeeId);
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $employmentKey)
                || $expectedLatestRevision < 0 || ($supersedesContextId !== null && $supersedesContextId <= 0)
                || !in_array($reasonCode, self::REASONS, true)) {
                throw new InvalidArgumentException('Die Angaben zur Beschaeftigungsrevision sind ungueltig.');
            }

            $record = $this->normalizeMetadata($metadata);
            $payload = [
                'employee_id' => $employeeId,
                'employment_key' => $employmentKey,
                'expected_latest_revision' => $expectedLatestRevision,
                'supersedes_context_id' => $supersedesContextId,
                'reason_code' => $reasonCode,
                'metadata' => $record,
            ];

            return $this->commands->execute(
                $actorId,
                $requestId,
                'employment.append',
                $payload,
                function () use ($employeeId, $actorId): void {
                    if ($this->access->requireEmployee(PersonnelAccessService::MANAGE, $employeeId) !== $actorId) {
                        throw new PersonnelAccessDenied();
                    }
                },
                fn (): array => $this->insertRevision($employeeId, $employmentKey, $expectedLatestRevision,
                    $supersedesContextId, $record, $reasonCode, $actorId),
            );
        } catch (PersonnelAccessDenied $exception) {
            $this->access->auditAccess('employment.append', false);
            throw $exception;
        }
    }

    public function history(int $employeeId): array
    {
        return $this->access->auditedRead('employment.history', function () use ($employeeId): array {
            $this->access->requireEmployee(PersonnelAccessService::VIEW, $employeeId);
            $rows = $this->connection->fetchAll(
                'SELECT ' . self::CONTEXT_COLUMNS . '
                 FROM employee_employment_contexts ctx
                 WHERE ctx.user_id = :employee_id
                 ORDER BY ctx.employment_key, ctx.revision',
                ['employee_id' => $employeeId],
            );

            return [array_map($this->contextForRead(...), $rows), null, null];
        });
    }

    /** @return array{contexts:list<array>, ambiguous_employment_keys:list<string>} */
    public function contextsOn(int $employeeId, string $date): array
    {
        return $this->access->auditedRead('employment.as_of', function () use ($employeeId, $date): array {
            $this->access->requireEmployee(PersonnelAccessService::VIEW, $employeeId);

            return [$this->effectiveContexts($employeeId, PersonnelDate::require($date)), null, null];
        });
    }

    private function effectiveContexts(int $employeeId, string $date): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::CONTEXT_COLUMNS . ', COUNT(*) OVER (PARTITION BY ctx.employment_key) AS effective_count
             FROM employee_employment_contexts ctx
             LEFT JOIN employee_employment_contexts replacement ON replacement.supersedes_context_id = ctx.id
             WHERE ctx.user_id = :employee_id AND replacement.id IS NULL
               AND ctx.effective_from <= :starts_on AND (ctx.effective_until IS NULL OR ctx.effective_until >= :ends_on)
             ORDER BY ctx.employment_key, ctx.revision',
            ['employee_id' => $employeeId, 'starts_on' => $date, 'ends_on' => $date],
        );

        // Legacy rows may overlap without a replacement link. Preserve their history and require a choice.
        $contexts = [];
        $ambiguous = [];
        foreach ($rows as $row) {
            if ((int) $row['effective_count'] > 1) {
                $ambiguous[] = $row['employment_key'];
                continue;
            }
            unset($row['effective_count']);
            $contexts[] = $this->contextForRead($row);
        }
        return ['contexts' => $contexts, 'ambiguous_employment_keys' => array_values(array_unique($ambiguous))];
    }

    private function contextForRead(array $row): array
    {
        if (!in_array($row['revision_reason'], self::REASONS, true)) {
            $row['revision_reason'] = 'legacy_unclassified';
        }

        return $row;
    }

    private function insertRevision(
        int $employeeId,
        string $employmentKey,
        int $expectedLatestRevision,
        ?int $supersedesContextId,
        array $record,
        string $reasonCode,
        int $actorId,
    ): array {
        // Lock even before the first revision exists so independent connections serialize the whole history.
        $employee = $this->connection->fetchOne(
            'SELECT employment_status, is_deleted FROM users WHERE id = :id FOR UPDATE',
            ['id' => $employeeId],
        );
        if ($this->access->requireEmployee(PersonnelAccessService::MANAGE, $employeeId, true) !== $actorId) {
            throw new PersonnelAccessDenied();
        }
        if ($employee === null || (int) $employee['is_deleted'] !== 0 || $employee['employment_status'] !== 'active') {
            throw new PersonnelConflict('Der Beschaeftigungskontext kann derzeit nicht bearbeitet werden.');
        }

        $keyScope = ['employee_id' => $employeeId, 'employment_key' => $employmentKey];
        $latestRevision = (int) $this->connection->fetchColumn(
            'SELECT revision FROM employee_employment_contexts
             WHERE user_id = :employee_id AND employment_key = :employment_key
             ORDER BY revision DESC LIMIT 1 FOR UPDATE',
            $keyScope,
        );
        if ($latestRevision !== $expectedLatestRevision || $latestRevision >= 4294967295) {
            throw new PersonnelConflict('Der Revisionsstand des Beschaeftigungskontexts hat sich geaendert.');
        }

        $previous = $supersedesContextId === null ? null : $this->currentPredecessor($keyScope, $supersedesContextId);
        $overlap = $this->connection->fetchColumn(
            'SELECT ctx.id FROM employee_employment_contexts ctx
             LEFT JOIN employee_employment_contexts replacement ON replacement.supersedes_context_id = ctx.id
             WHERE ctx.user_id = :employee_id AND ctx.employment_key = :employment_key AND replacement.id IS NULL
               AND ctx.id <> :superseded_id AND ctx.effective_from <= :new_end
               AND (ctx.effective_until IS NULL OR ctx.effective_until >= :new_start)
             LIMIT 1 FOR UPDATE',
            $keyScope + [
                'superseded_id' => $supersedesContextId ?? 0,
                'new_end' => $record['effective_until'] ?? '9999-12-31',
                'new_start' => $record['effective_from'],
            ],
        );
        if ($overlap !== false) {
            throw new PersonnelConflict('Der Zeitraum ueberschneidet einen Abschnitt desselben Beschaeftigungsverhaeltnisses.');
        }

        $traitDefaults = $previous === null
            ? array_fill_keys(array_keys(self::TRAIT_VALUES), 'unknown')
            : array_intersect_key($previous, self::TRAIT_VALUES);
        $nextRevision = $latestRevision + 1;
        $stored = $this->connection->execute(
            'INSERT INTO employee_employment_contexts (
                user_id, employment_key, revision, supersedes_context_id, effective_from, effective_until,
                employment_type, student_status, pension_insurance_status, cross_border_status, work_authorization_status,
                source, revision_reason, created_by_user_id, created_at
             ) VALUES (
                :employee_id, :employment_key, :revision, :supersedes_id, :effective_from, :effective_until,
                :employment_type, :student_status, :pension_insurance_status, :cross_border_status, :work_authorization_status,
                :source, :reason, :actor_id, UTC_TIMESTAMP()
             )',
            $keyScope + $record + $traitDefaults + [
                'revision' => $nextRevision,
                'supersedes_id' => $supersedesContextId,
                'source' => 'manual',
                'reason' => $reasonCode,
                'actor_id' => $actorId,
            ],
        );
        if (!$stored) {
            throw new RuntimeException('Die Beschaeftigungsrevision konnte nicht sicher gespeichert werden.');
        }

        return ['context_id' => $this->connection->lastInsertId(), 'revision' => $nextRevision];
    }

    private function currentPredecessor(array $keyScope, int $contextId): array
    {
        $previous = $this->connection->fetchOne(
            'SELECT ' . self::CONTEXT_COLUMNS . '
             FROM employee_employment_contexts ctx
             LEFT JOIN employee_employment_contexts replacement ON replacement.supersedes_context_id = ctx.id
             WHERE ctx.id = :context_id AND ctx.user_id = :employee_id AND ctx.employment_key = :employment_key
               AND replacement.id IS NULL FOR UPDATE',
            $keyScope + ['context_id' => $contextId],
        );
        if ($previous === null) {
            throw new PersonnelConflict('Der gewaehlte Abschnitt kann nicht ersetzt werden.');
        }

        return $previous;
    }

    private function normalizeMetadata(array $metadata): array
    {
        $accepted = ['effective_from', 'effective_until', ...array_keys(self::TRAIT_VALUES)];
        if (array_diff(array_keys($metadata), $accepted) !== []) {
            throw new InvalidArgumentException('Die Angaben zur Beschaeftigungsrevision sind ungueltig.');
        }

        $from = PersonnelDate::require($metadata['effective_from'] ?? null);
        $until = isset($metadata['effective_until']) ? PersonnelDate::require($metadata['effective_until']) : null;
        if ($until !== null && $until < $from) {
            throw new InvalidArgumentException('Der Zeitraum hat ein Ende vor seinem Beginn.');
        }

        $record = ['effective_from' => $from, 'effective_until' => $until];
        foreach (self::TRAIT_VALUES as $name => $allowed) {
            if (!array_key_exists($name, $metadata)) {
                continue;
            }
            $value = $metadata[$name] ?? 'unknown';
            if (!in_array($value, $allowed, true)) {
                throw new InvalidArgumentException('Ein Beschaeftigungsmerkmal ist ungueltig.');
            }
            $record[$name] = $value;
        }

        return $record;
    }
}
