<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Personnel\Compliance\EmploymentContextService;
use App\Domain\Personnel\Compliance\InitialPersonnelComplianceCatalog;
use App\Domain\Personnel\Compliance\PersonnelAccessDenied;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelComplianceCatalogService;
use App\Domain\Personnel\Compliance\PersonnelConflict;
use App\Domain\Users\PermissionMatrix;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class PersonnelEmploymentCatalogTest extends MariaDbTestCase
{
    public function testRevisionPreservesHistoryAndUsesOnlyTrustedProvenance(): void
    {
        [$service, $actor, $employee] = $this->employmentFixture();
        $first = $service->append($employee, 'job', 0, null, [
            'effective_from' => '2026-11-01', 'student_status' => 'working_student',
        ], 'initial_record', 'employment.initial');
        $original = $service->history($employee)[0];
        self::assertSame(1, $first['revision']);
        self::assertSame($actor, (int) $original['created_by_user_id']);
        self::assertSame('manual', $original['source']);
        self::assertSame('initial_record', $original['revision_reason']);
        self::assertSame('unknown', $original['employment_type']);
        self::assertSame('unknown', $original['work_authorization_status']);

        $second = $service->append($employee, 'job', 1, $first['context_id'], [
            'effective_from' => '2026-11-02', 'effective_until' => '2026-11-30', 'employment_type' => 'regular',
        ], 'correction', 'employment.correct');
        $history = $service->history($employee);
        self::assertSame($original, $history[0]);
        self::assertSame(2, $second['revision']);
        self::assertSame($first['context_id'], (int) $history[1]['supersedes_context_id']);
        self::assertSame('working_student', $history[1]['student_status']);
        self::assertSame('correction', $history[1]['revision_reason']);
        self::assertSame([], $service->contextsOn($employee, '2026-11-01')['contexts']);
        self::assertSame($second['context_id'], (int) $service->contextsOn($employee, '2026-11-30')['contexts'][0]['id']);
        self::assertSame([], $service->contextsOn($employee, '2026-12-01')['contexts']);

        foreach (['UPDATE employee_employment_contexts SET employment_type = "regular" WHERE id = :id',
            'DELETE FROM employee_employment_contexts WHERE id = :id'] as $statement) {
            $error = $this->assertRejected(PDOException::class, fn () => $this->connection()->execute($statement, ['id' => $first['context_id']]));
            self::assertSame('45000', $error->getCode());
        }
    }

    public function testSameEmploymentRejectsOverlapButParallelEmploymentAndAdjacentSlotsAreAllowed(): void
    {
        [$service, , $employee] = $this->employmentFixture();
        $first = $service->append($employee, 'job', 0, null, [
            'effective_from' => '2026-11-01', 'effective_until' => '2026-11-15',
        ], 'initial_record', 'employment.interval1');
        $this->assertRejected(PersonnelConflict::class, fn () => $service->append($employee, 'job', 1, null, [
            'effective_from' => '2026-11-15',
        ], 'change_of_circumstances', 'employment.overlap'));
        $second = $service->append($employee, 'job', 1, null, [
            'effective_from' => '2026-11-16',
        ], 'change_of_circumstances', 'employment.interval2');
        $parallel = $service->append($employee, 'other-job', 0, null, [
            'effective_from' => '2026-11-07',
        ], 'initial_record', 'employment.parallel');
        $this->assertRejected(PersonnelConflict::class, fn () => $service->append($employee, 'job', 2, $first['context_id'], [
            'effective_from' => '2026-11-01',
        ], 'correction', 'employment.overlap.replacement'));
        $this->assertRejected(PersonnelConflict::class, fn () => $service->append($employee, 'job', 2, $parallel['context_id'], [
            'effective_from' => '2026-12-01',
        ], 'correction', 'employment.cross-key'));

        self::assertSame([$first['context_id'], $parallel['context_id']], array_column($service->contextsOn($employee, '2026-11-15')['contexts'], 'id'));
        self::assertSame([$second['context_id'], $parallel['context_id']], array_column($service->contextsOn($employee, '2026-11-16')['contexts'], 'id'));
        self::assertCount(3, $service->history($employee));
    }

    public function testInvalidMetadataLeavesNoReceiptOrHistoryAndLegacyOverlapIsUnresolved(): void
    {
        [$service, $actor, $employee] = $this->employmentFixture();
        foreach ([
            ['effective_from' => '2026-02-29'],
            ['effective_from' => '2026-11-02', 'effective_until' => '2026-11-01'],
            ['effective_from' => '2026-11-01', 'created_by_user_id' => $employee],
            ['effective_from' => '2026-11-01', 'employment_type' => 'invalid-test-value'],
        ] as $payload) {
            $error = $this->assertRejected(InvalidArgumentException::class, fn () => $service->append(
                $employee, 'job', 0, null, $payload, 'initial_record', 'employment.invalid',
            ));
            self::assertStringNotContainsString('invalid-test-value', $error->getMessage());
        }
        $this->assertRejected(InvalidArgumentException::class, fn () => $service->append(
            $employee, 'job', 0, null, ['effective_from' => '2026-11-01'], 'private unstructured text', 'employment.invalid.reason',
        ));
        self::assertSame([], $service->history($employee));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM personnel_command_receipts'));

        $legacyReason = 'synthetic private legacy text';
        foreach ([1 => '2026-10-01', 2 => '2026-11-01'] as $revision => $startsOn) {
            $this->connection()->execute(
                'INSERT INTO employee_employment_contexts (user_id, employment_key, revision, effective_from, revision_reason, created_by_user_id)
                 VALUES (:employee, "legacy", :revision, :starts_on, :reason, :actor)',
                ['employee' => $employee, 'revision' => $revision, 'starts_on' => $startsOn, 'reason' => $legacyReason, 'actor' => $actor],
            );
        }
        self::assertSame(['contexts' => [], 'ambiguous_employment_keys' => ['legacy']], $service->contextsOn($employee, '2026-11-03'));
        self::assertSame(['legacy_unclassified', 'legacy_unclassified'], array_column($service->history($employee), 'revision_reason'));
        self::assertSame(['legacy_unclassified'], array_column($service->contextsOn($employee, '2026-10-03')['contexts'], 'revision_reason'));
        self::assertSame($legacyReason, $this->connection()->fetchColumn(
            'SELECT revision_reason FROM employee_employment_contexts WHERE user_id = :employee AND revision = 1',
            ['employee' => $employee],
        ));
    }

    public function testReplayDoesNotDuplicateAndArchivedEmployeeRemainsReadable(): void
    {
        [$service, , $employee] = $this->employmentFixture();
        $metadata = ['effective_from' => '2026-11-01'];
        $first = $service->append($employee, 'job', 0, null, $metadata, 'initial_record', 'employment.replay');
        self::assertSame($first, $service->append($employee, 'job', 0, null, $metadata, 'initial_record', 'employment.replay'));
        $this->assertRejected(PersonnelConflict::class, fn () => $service->append(
            $employee, 'job', 0, null, ['effective_from' => '2026-11-02'], 'initial_record', 'employment.replay',
        ));

        $this->connection()->execute('UPDATE users SET is_deleted = 1 WHERE id = :id', ['id' => $employee]);
        self::assertSame($first, $service->append($employee, 'job', 0, null, $metadata, 'initial_record', 'employment.replay'));
        $this->assertRejected(PersonnelConflict::class, fn () => $service->append(
            $employee, 'job', 1, $first['context_id'], $metadata, 'correction', 'employment.archived',
        ));
        self::assertCount(1, $service->history($employee));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM personnel_command_receipts'));
    }

    public function testTwoSessionsWritingTheSameRevisionGiveOneSuccessAndOneConflict(): void
    {
        [$service, $firstActor, $employee] = $this->employmentFixture();
        $secondActor = $this->createActor([PersonnelAccessService::MANAGE]);
        $this->grantScope($employee, $secondActor, $firstActor);
        $first = $service->append($employee, 'job', 0, null, ['effective_from' => '2026-11-01'], 'initial_record', 'employment.first');

        $source = <<<'PHP'
            require 'bootstrap/autoload.php';
            $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            $_SESSION = ['auth' => ['user_id' => $input['actor']]];
            $db = new App\Infrastructure\Database\DatabaseConnection($input['config']);
            $auth = new App\Domain\Auth\AuthService($db, new App\Domain\Users\PermissionMatrix([], []));
            $access = new App\Domain\Personnel\Compliance\PersonnelAccessService($db, $auth);
            $service = new App\Domain\Personnel\Compliance\EmploymentContextService($db, $access);
            echo "ready\n";
            flush();
            try {
                $result = $service->append($input['employee'], 'job', 1, $input['predecessor'],
                    ['effective_from' => '2026-11-01', 'effective_until' => $input['end']], 'employment_end', 'employment.race');
                echo 'success:' . $result['revision'];
            } catch (App\Domain\Personnel\Compliance\PersonnelConflict) {
                echo 'conflict';
            }
            PHP;

        $pdo = $this->connection()->pdo();
        $workers = [];
        $results = [];
        $pdo->beginTransaction();
        try {
            $this->connection()->fetchOne('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $employee]);
            foreach ([[$firstActor, '2026-11-29'], [$secondActor, '2026-11-30']] as [$actor, $end]) {
                $process = proc_open([PHP_BINARY, '-r', $source], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode([
                    'config' => $this->connectionConfig(), 'actor' => $actor, 'employee' => $employee,
                    'end' => $end, 'predecessor' => $first['context_id'],
                ], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                self::assertSame("ready\n", fgets($pipes[1]));
            }
            $pdo->commit();
            foreach ($workers as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $stderr);
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }

        self::assertEqualsCanonicalizing(['success:2', 'conflict'], $results);
        self::assertCount(2, $service->history($employee));
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM personnel_command_receipts'));
    }

    public function testContextAccessIsScopedAndAuditsDenialsWithoutReferences(): void
    {
        [$service, $actor, $employee] = $this->employmentFixture();
        $other = $this->createUser();
        $pdo = $this->connection()->pdo();
        $pdo->beginTransaction();
        try {
            $error = $this->assertRejected(RuntimeException::class, fn () => $service->history($employee));
            self::assertSame('Ein protokollierter Personalaktenzugriff ist momentan nicht möglich.', $error->getMessage());
        } finally {
            $pdo->rollBack();
        }
        $service->history($employee);
        $denied = $this->assertRejected(PersonnelAccessDenied::class, fn () => $service->history($other));
        $missing = $this->assertRejected(PersonnelAccessDenied::class, fn () => $service->history(999999));
        self::assertSame($denied->getMessage(), $missing->getMessage());
        $this->connection()->execute(
            'INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code)
             VALUES (:employee, :grantee, :actor, 0, "revocation")',
            ['employee' => $employee, 'grantee' => $actor, 'actor' => $actor],
        );
        $this->assertRejected(PersonnelAccessDenied::class, fn () => $service->contextsOn($employee, '2026-11-01'));
        $logs = $this->connection()->fetchAll('SELECT outcome, document_id, document_version_id FROM employee_document_access_log WHERE actor_user_id = :id ORDER BY id', ['id' => $actor]);
        self::assertSame(['allowed', 'denied', 'denied', 'denied'], array_column($logs, 'outcome'));
        self::assertSame([null, null, null, null], array_column($logs, 'document_id'));
        self::assertSame([null, null, null, null], array_column($logs, 'document_version_id'));
    }

    public function testCatalogueSupportsExactHistoricalRevisionsAndOnlyManualReview(): void
    {
        $actor = $this->createActor([PersonnelAccessService::CATALOG]);
        $_SESSION['auth']['user_id'] = $actor;
        InitialPersonnelComplianceCatalog::seed($this->connection()->pdo());
        $access = $this->access();
        $catalog = new PersonnelComplianceCatalogService($this->connection(), $access);
        self::assertContains('BVV-8', array_column($catalog->sources(), 'code'));
        self::assertContains('employment_identity', array_column($catalog->documentTypes(), 'code'));
        $rule = $catalog->ruleHistory('BVV-8-1-01')[0];
        $typeId = (int) $rule['document_type_id'];
        self::assertSame($rule, $catalog->ruleRevision((int) $rule['id'], $typeId));
        self::assertSame('manual_review', $rule['applicability_mode']);
        $this->assertRejected(InvalidArgumentException::class, fn () => $catalog->ruleRevision((int) $rule['id'], $typeId + 1));
        $this->assertRejected(InvalidArgumentException::class, fn () => $catalog->rulesForTypeOn($typeId, '2026-02-29'));
        self::assertSame(['manual_review' => true, 'candidates' => [], 'ambiguous_rule_codes' => []], $catalog->rulesForTypeOn($typeId, '2026-06-30'));
        self::assertSame([$rule], $catalog->rulesForTypeOn($typeId, '2026-07-01')['candidates']);

        $otherType = (int) $this->connection()->fetchColumn('SELECT id FROM personnel_document_types WHERE id <> :id ORDER BY id LIMIT 1', ['id' => $typeId]);
        $this->connection()->execute(
            'INSERT INTO personnel_document_requirements (rule_code, revision, document_type_id, source_id, citation, valid_from, valid_until,
                record_kind, record_frequency, employment_scope, evidence_rule, signature_rule, retention_rule)
             SELECT rule_code, 2, :other_type, source_id, citation, "2026-11-01", "2026-11-30",
                record_kind, record_frequency, employment_scope, evidence_rule, signature_rule, retention_rule
             FROM personnel_document_requirements WHERE id = :original',
            ['other_type' => $otherType, 'original' => $rule['id']],
        );
        self::assertSame(['manual_review' => true, 'candidates' => [], 'ambiguous_rule_codes' => ['BVV-8-1-01']], $catalog->rulesForTypeOn($typeId, '2026-11-30'));
        self::assertSame([$rule], $catalog->rulesForTypeOn($typeId, '2026-12-01')['candidates']);
        self::assertSame([1, 2], array_column($catalog->ruleHistory('BVV-8-1-01'), 'revision'));
        self::assertSame($rule, $catalog->ruleRevision((int) $rule['id'], $typeId));

        $employee = $this->createUser();
        $this->grantScope($employee, $actor, $actor);
        $service = new EmploymentContextService($this->connection(), $access);
        $this->assertRejected(PersonnelAccessDenied::class, fn () => $service->history($employee));
        $_SESSION['auth']['user_id'] = $this->createActor([PersonnelAccessService::VIEW]);
        $this->assertRejected(PersonnelAccessDenied::class, fn () => $catalog->sources());
    }

    public function testServicesCannotBeWiredWithDifferentConnections(): void
    {
        $access = $this->access();
        $other = new DatabaseConnection($this->connectionConfig());
        $this->assertRejected(LogicException::class, fn () => new EmploymentContextService($other, $access));
        $this->assertRejected(LogicException::class, fn () => new PersonnelComplianceCatalogService($other, $access));
    }

    private function access(): PersonnelAccessService
    {
        return new PersonnelAccessService($this->connection(), new AuthService($this->connection(), new PermissionMatrix([], [])));
    }

    private function employmentFixture(): array
    {
        $actor = $this->createActor([PersonnelAccessService::MANAGE, PersonnelAccessService::VIEW]);
        $employee = $this->createUser();
        $this->grantScope($employee, $actor, $actor);
        $_SESSION['auth']['user_id'] = $actor;

        return [new EmploymentContextService($this->connection(), $this->access()), $actor, $employee];
    }

    private function createActor(array $permissions): int
    {
        $actor = $this->createUser();
        $this->connection()->execute('INSERT INTO roles (slug, name) VALUES (:slug, "Synthetic role")', ['slug' => 'personnel-test-'.$actor]);
        $role = $this->connection()->lastInsertId();
        foreach ($permissions as $permission) {
            $this->connection()->execute(
                'INSERT INTO permissions (code, label, scope) VALUES (:code, "Synthetic privilege", "personnel") ON DUPLICATE KEY UPDATE code = VALUES(code)',
                ['code' => $permission],
            );
            $this->connection()->execute(
                'INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE code = :code',
                ['role' => $role, 'code' => $permission],
            );
        }
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:actor, :role)', ['actor' => $actor, 'role' => $role]);

        return $actor;
    }

    private function grantScope(int $employee, int $grantee, int $actor): void
    {
        $this->connection()->execute(
            'INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code)
             VALUES (:employee, :grantee, :actor, 1, "assignment")',
            ['employee' => $employee, 'grantee' => $grantee, 'actor' => $actor],
        );
    }

    private function assertRejected(string $type, callable $operation): \Throwable
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($type, $exception, $exception->getMessage());

            return $exception;
        }
        self::fail('The personnel operation was expected to be rejected.');
    }
}
