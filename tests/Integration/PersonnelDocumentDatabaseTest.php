<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Personnel\Compliance\PersonnelAccessDenied;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelConflict;
use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Users\PermissionMatrix;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Support\MariaDbTestCase;
use Throwable;

final class PersonnelDocumentDatabaseTest extends MariaDbTestCase
{
    private int $actor;
    private int $employee;
    private int $otherEmployee;
    private int $type;
    private PersonnelDocumentService $documents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->createUser();
        $this->employee = $this->createUser();
        $this->otherEmployee = $this->createUser();
        $this->type = $this->createType('synthetic-type');
        $this->grant($this->actor, [PersonnelAccessService::VIEW, PersonnelAccessService::MANAGE], true);
        $this->login($this->actor);

        $connection = $this->connection();
        $config = require base_path('config/permissions.php');
        $auth = new AuthService($connection, new PermissionMatrix($config['roles'], $config['available']));
        $this->documents = new PersonnelDocumentService(
            $connection,
            new PersonnelAccessService($connection, $auth)
        );
    }

    public function testCreatesPendingMetadataUsingSessionAndReplaysOnlyIdenticalRequests(): void
    {
        $input = ['document_type_id' => $this->type, 'issued_on' => '2026-11-16', 'received_on' => '2026-11-17'];
        $receipt = $this->documents->create($this->employee, $input, 'meta-1');
        self::assertSame($receipt, $this->documents->create($this->employee, array_reverse($input, true), 'meta-1'));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->create($this->employee, [
            ...$input, 'received_on' => '2026-11-18',
        ], 'meta-1'));

        $head = $this->documents->get($receipt['document_id']);
        self::assertSame($this->employee, (int) $head['user_id']);
        self::assertSame($this->actor, (int) $head['created_by_user_id']);
        self::assertSame('manual', $head['source']);
        self::assertSame('pending', $head['review_status']);
        self::assertFalse($head['evidence_verified']);
        self::assertSame(1, (int) $head['lock_version']);
        self::assertSame([], $this->documents->versions($receipt['document_id']));
        self::assertCount(1, $this->documents->listForEmployee($this->employee));
        self::assertCount(1, $this->documents->history($receipt['document_id']));
        self::assertSame(1, $this->countRows('employee_document_audit'));
        self::assertSame(1, $this->countRows('personnel_command_receipts'));
        self::assertSame(4, $this->countRows('employee_document_access_log'));
        self::assertSame('allowed', $this->connection()->fetchColumn(
            "SELECT outcome FROM employee_document_access_log WHERE action = 'document.read' AND document_id = :id",
            ['id' => $receipt['document_id']]
        ));

        foreach (['actor_id', 'review_status', 'storage_key', 'sha256', 'retention_hold', 'signature_status'] as $attribute) {
            $message = $this->assertFailure(InvalidArgumentException::class, fn () => $this->documents->create(
                $this->employee, [...$input, $attribute => 'synthetic-sensitive-input'], 'meta-bad-' . $attribute
            ));
            self::assertStringNotContainsString('synthetic-sensitive-input', $message);
        }
        self::assertSame(1, $this->countRows('employee_documents'));
    }

    public function testLegacyReviewedLabelCannotBeMistakenForVerifiedEvidence(): void
    {
        $this->connection()->execute(
            'INSERT INTO employee_documents (user_id, document_type_id, review_status, created_by_user_id)
             VALUES (:user, :type, "reviewed", :actor)',
            ['user' => $this->employee, 'type' => $this->type, 'actor' => $this->actor]
        );
        $id = $this->connection()->lastInsertId();
        $this->connection()->execute(
            'INSERT INTO employee_document_versions (document_id, version_number, signature_status, created_by_user_id)
             VALUES (:id, 1, "verified", :actor)',
            ['id' => $id, 'actor' => $this->actor]
        );
        $legacyVersionId = $this->connection()->lastInsertId();
        $head = $this->documents->get($id);
        self::assertSame('reviewed', $head['review_status']);
        self::assertFalse($head['evidence_verified']);
        self::assertFalse($this->documents->listForEmployee($this->employee)[0]['evidence_verified']);
        self::assertSame('verified', $this->documents->version($id, $legacyVersionId)['signature_status']);
        self::assertFalse($this->documents->version($id, $legacyVersionId)['evidence_verified']);
        self::assertFalse($this->documents->versions($id)[0]['evidence_verified']);
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->addVersion(
            $id, 0, 'metadata_recorded', 'legacy-reviewed-version'
        ));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->changeStatus(
            $id, 'pending', 1, 'reassessment_requested', 'legacy-reviewed-reset'
        ));
    }

    public function testValidatesTypesDatesRuleAndEmployeeReferencesAndKeepsHistoricalContext(): void
    {
        $context = $this->context($this->employee, '2026-10-01', '2026-12-31');
        $otherContext = $this->context($this->otherEmployee, '2026-10-01', null);
        $rule = $this->rule($this->type);
        $otherRule = $this->rule($this->createType('synthetic-other-type'));
        $data = [
            'document_type_id' => $this->type,
            'employment_context_id' => $context,
            'document_requirement_id' => $rule,
            'period_from' => '2026-11-01',
            'period_until' => '2026-11-30',
        ];
        foreach ([
            ['document_type_id' => 999999],
            ['employment_context_id' => $otherContext],
            ['document_requirement_id' => $otherRule],
            ['document_requirement_id' => 999999],
            ['period_until' => '2026-10-01'],
            ['period_until' => '2026-11-31'],
            ['period_from' => '2026-09-30'],
            ['period_until' => '2027-01-01'],
            ['period_from' => null],
            ['issued_on' => '2026-11-17', 'received_on' => '2026-11-16'],
            ['retain_until' => '2027-01-01'],
            ['retention_anchor_on' => '2028-01-01', 'retain_until' => '2027-01-01'],
            ['source' => 'external-input'],
        ] as $index => $bad) {
            $this->assertFailure(InvalidArgumentException::class, fn () => $this->documents->create(
                $this->employee, [...$data, ...$bad], 'reference-bad-' . $index
            ));
        }
        self::assertSame(0, $this->countRows('employee_documents'));
        $receipt = $this->documents->create($this->employee, $data, 'reference-valid');
        $this->connection()->execute(
            'INSERT INTO employee_employment_contexts
             (user_id, employment_key, revision, supersedes_context_id, effective_from, effective_until, revision_reason, created_by_user_id)
             VALUES (:employee, "test-context", 2, :previous, "2026-12-01", "2026-12-31", "correction", :actor)',
            ['employee' => $this->employee, 'previous' => $context, 'actor' => $this->actor]
        );
        $contextId = $this->documents->get($receipt['document_id'])['employment_context_id'];
        self::assertSame($context, (int) $contextId);
        self::assertSame($rule, (int) $this->documents->get($receipt['document_id'])['document_requirement_id']);
        $this->assertFailure(PDOException::class, fn () => $this->connection()->execute(
            'UPDATE employee_documents SET source = "external" WHERE id = :id',
            ['id' => $receipt['document_id']]
        ));

        $this->connection()->execute('UPDATE users SET is_deleted = 1 WHERE id = :id', ['id' => $this->employee]);
        self::assertCount(1, $this->documents->listForEmployee($this->employee));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->create($this->employee, $data, 'after-user-archive'));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->addVersion($receipt['document_id'], 0, 'metadata_recorded', 'after-user-archive-version'));
        self::assertSame($receipt, $this->documents->create($this->employee, $data, 'reference-valid'));
    }

    public function testExactPermissionsAndEmployeeScopeConstrainAccessAndDenialsRevealNoExistence(): void
    {
        $document = $this->newDocument($this->employee, 'employee-doc');
        $foreign = $this->newDocument($this->otherEmployee, 'foreign-doc');
        $ownVersion = $this->documents->addVersion($document, 0, 'metadata_recorded', 'employee-version');
        $foreignVersion = $this->documents->addVersion($foreign, 0, 'metadata_recorded', 'foreign-version');

        $viewer = $this->createUser();
        $this->grant($viewer, [PersonnelAccessService::VIEW]);
        $this->grant($viewer, ['personnel.view']);
        $this->scope($viewer, $this->employee);
        $this->login($viewer);
        self::assertCount(1, $this->documents->listForEmployee($this->employee));
        self::assertSame($ownVersion['version_id'], (int) $this->documents->version($document, $ownVersion['version_id'])['id']);
        $unknown = $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->get(999999));
        self::assertSame($unknown, $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->get($foreign)));
        self::assertSame($unknown, $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->version($document, $foreignVersion['version_id'])));
        self::assertSame($unknown, $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->listForEmployee($this->otherEmployee)));
        self::assertSame($unknown, $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->archive($document, 2, 'duplicate', 'viewer-archive')));
        self::assertSame(5, (int) $this->connection()->fetchColumn(
            "SELECT COUNT(*) FROM employee_document_access_log WHERE actor_user_id = :actor
               AND outcome = 'denied' AND document_id IS NULL AND document_version_id IS NULL",
            ['actor' => $viewer]
        ));
        $this->scope($viewer, $this->employee, false);
        self::assertSame($unknown, $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->get($document)));

        $manager = $this->createUser();
        $this->grant($manager, [PersonnelAccessService::MANAGE]);
        $this->scope($manager, $this->employee);
        $this->login($manager);
        self::assertSame('rejected', $this->documents->changeStatus($document, 'rejected', 2, 'metadata_invalid', 'manager-change')['review_status']);
        $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->get($document));
        $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->archive($foreign, 2, 'duplicate', 'manager-foreign'));

        foreach ([[], ['personnel.view'], ['personnel.manage'], ['files.view'], [PersonnelAccessService::CATALOG], [PersonnelAccessService::EXPORT]] as $rights) {
            $actor = $this->createUser();
            if ($rights !== []) {
                $this->grant($actor, $rights);
            }
            $this->scope($actor, $this->employee);
            $this->login($actor);
            $_SESSION['auth']['permissions'] = [PersonnelAccessService::VIEW, PersonnelAccessService::MANAGE];
            self::assertSame($unknown, $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->get($document)));
            $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->archive($document, 3, 'duplicate', 'out-of-area'));
        }

        $this->login($this->actor);
        $this->connection()->execute("UPDATE users SET employment_status = 'inactive' WHERE id = :id", ['id' => $this->actor]);
        self::assertSame($unknown, $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->get($document)));
    }

    public function testVersionsHaveServerNumbersAndNoFileClaimsAndUnchangedHistory(): void
    {
        $id = $this->newDocument($this->employee, 'version-doc');
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->addVersion($id, 1, 'metadata_recorded', 'missing-predecessor'));
        $first = $this->documents->addVersion($id, 0, 'metadata_recorded', 'version-one');
        self::assertSame($first, $this->documents->addVersion($id, 0, 'metadata_recorded', 'version-one'));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->addVersion($id, 1, 'metadata_revised', 'version-one'));
        $second = $this->documents->addVersion($id, 1, 'metadata_revised', 'version-two');
        $third = $this->documents->addVersion($id, 2, 'metadata_revised', 'version-three');
        self::assertSame([1, 2, 3], [$first['version_number'], $second['version_number'], $third['version_number']]);
        self::assertSame(4, $third['lock_version']);
        $versions = $this->documents->versions($id);
        self::assertSame([1, 2, 3], array_map('intval', array_column($versions, 'version_number')));
        self::assertSame([null, 1, 2], array_column($versions, 'supersedes_version_number'));
        foreach ($versions as $version) {
            self::assertSame('unknown', $version['original_status']);
            self::assertSame('unknown', $version['signature_status']);
            self::assertArrayNotHasKey('storage_key', $version);
        }
        self::assertSame(0, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM employee_document_versions
             WHERE storage_key IS NOT NULL OR sha256 IS NOT NULL OR mime_type IS NOT NULL OR size_bytes IS NOT NULL'
        ));
        self::assertSame(4, $this->countRows('employee_document_audit'));
        $this->assertFailure(PDOException::class, fn () => $this->connection()->execute(
            'UPDATE employee_document_versions SET version_number = 5 WHERE id = :id', ['id' => $first['version_id']]
        ));
        $this->assertFailure(PDOException::class, fn () => $this->connection()->execute(
            'DELETE FROM employee_document_versions WHERE id = :id', ['id' => $third['version_id']]
        ));
        $this->assertFailure(PDOException::class, fn () => $this->connection()->execute(
            'INSERT INTO employee_document_versions (document_id, version_number, created_by_user_id)
             VALUES (:document_id, 1, :actor_id)', ['document_id' => $id, 'actor_id' => $this->actor]
        ));
        self::assertCount(3, $this->documents->versions($id));
    }

    public function testConcurrentVersionWritersCannotConsumeTheSamePredecessor(): void
    {
        $id = $this->newDocument($this->employee, 'parallel-doc');
        $anotherManager = $this->createUser();
        $this->grant($anotherManager, [PersonnelAccessService::MANAGE]);
        $this->scope($anotherManager, $this->employee);
        $workers = [];
        try {
            $this->connection()->transaction(function () use (&$workers, $id, $anotherManager): void {
                $this->connection()->fetchColumn('SELECT id FROM employee_documents WHERE id = :id FOR UPDATE', ['id' => $id]);
                foreach ([$this->actor, $anotherManager] as $actor) {
                    $workers[] = $this->startVersionWorker($actor, $id);
                }
                foreach ($workers as [, $output]) {
                    self::assertSame("ready\n", fgets($output));
                }
            });

            $outcomes = [];
            foreach ($workers as [, $output]) {
                $outcomes[] = trim((string) fgets($output));
            }
            sort($outcomes);
            self::assertSame(['conflict', 'created'], $outcomes);
        } finally {
            foreach ($workers as $worker) {
                $this->closeVersionWorker($worker);
            }
        }

        self::assertSame([1], array_map('intval', array_column($this->documents->versions($id), 'version_number')));
        self::assertSame(2, $this->countRows('personnel_command_receipts'));
        self::assertSame(2, $this->countRows('employee_document_audit'));
    }

    #[TestWith([false], 'Mitarbeiterbereich')]
    #[TestWith([true], 'Rollenrecht')]
    public function testVersionWriteRechecksAuthorizationAfterWaitingForAnEmployeeLock(bool $revokePermission): void
    {
        $id = $this->newDocument($this->employee, 'revocation-doc');
        $manager = $this->createUser();
        $roleId = $this->grant($manager, [PersonnelAccessService::MANAGE]);
        $this->scope($manager, $this->employee);
        $worker = null;
        try {
            $this->connection()->transaction(function () use (&$worker, $id, $manager, $roleId, $revokePermission): void {
                $this->connection()->fetchColumn('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $this->employee]);
                $worker = $this->startVersionWorker($manager, $id);
                self::assertSame("ready\n", fgets($worker[1]));
                // This lock is reached only after the worker's initial permission check has passed.
                $deadline = microtime(true) + 5;
                do {
                    $waiting = (bool) $this->connection()->fetchColumn(
                        'SELECT COUNT(*) FROM information_schema.PROCESSLIST
                         WHERE DB = DATABASE() AND ID <> CONNECTION_ID() AND INFO LIKE :lock_query',
                        ['lock_query' => '%SELECT employment_status, is_deleted FROM users%LOCK IN SHARE MODE%']
                    );
                    if (!$waiting) {
                        usleep(10000);
                    }
                } while (!$waiting && microtime(true) < $deadline);
                self::assertTrue($waiting, 'Der konkurrierende Auftrag wartet auf den Mitarbeiterschreibschutz.');
                if ($revokePermission) {
                    $this->connection()->execute('DELETE FROM role_permissions WHERE role_id = :role_id', ['role_id' => $roleId]);
                } else {
                    $this->scope($manager, $this->employee, false);
                }
            });
            self::assertSame("denied\n", fgets($worker[1]));
        } finally {
            if ($worker !== null) {
                $this->closeVersionWorker($worker);
            }
        }

        self::assertSame([], $this->documents->versions($id));
        self::assertSame(1, $this->countRows('personnel_command_receipts'));
        self::assertSame(1, $this->countRows('employee_document_audit'));
    }

    public function testStatusArchiveHoldAndReasonCodesProduceAtomicImmutableHistory(): void
    {
        $id = $this->newDocument($this->employee, 'status-doc');
        $this->assertFailure(InvalidArgumentException::class, fn () => $this->documents->changeStatus($id, 'reviewed', 1, 'metadata_corrected', 'reviewed'));
        $this->assertFailure(InvalidArgumentException::class, fn () => $this->documents->changeStatus($id, 'rejected', 1, 'synthetic-sensitive-diagnosis', 'bad-reason'));
        $rejected = $this->documents->changeStatus($id, 'rejected', 1, 'insufficient_evidence', 'reject');
        self::assertSame('rejected', $rejected['review_status']);
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->changeStatus($id, 'pending', 1, 'metadata_corrected', 'stale-open'));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->addVersion($id, 0, 'metadata_recorded', 'rejected-add'));
        self::assertSame('pending', $this->documents->changeStatus($id, 'pending', 2, 'metadata_corrected', 'reopen')['review_status']);
        $this->documents->setRetentionHold($id, true, 3, 'legal_hold', 'hold');
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->archive($id, 4, 'withdrawn', 'active-hold-archive'));
        $this->documents->setRetentionHold($id, false, 4, 'hold_resolved', 'release');
        $archived = $this->documents->archive($id, 5, 'withdrawn', 'archive');
        self::assertSame($archived, $this->documents->archive($id, 5, 'withdrawn', 'archive'));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->archive($id, 6, 'withdrawn', 'repeat-archive'));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->changeStatus($id, 'rejected', 6, 'metadata_invalid', 'archive-reject'));
        $this->assertFailure(PersonnelConflict::class, fn () => $this->documents->addVersion($id, 0, 'metadata_recorded', 'archive-add'));
        self::assertSame([], $this->documents->listForEmployee($this->employee));
        $archivedHead = $this->documents->listForEmployee($this->employee, true)[0];
        self::assertNotNull($archivedHead['archived_at']);
        self::assertSame($this->actor, (int) $archivedHead['archived_by_user_id']);
        self::assertTrue($this->documents->setRetentionHold($id, true, 6, 'legal_hold', 'archived-hold')['retention_hold']);
        $history = $this->documents->history($id);
        self::assertSame([
            'document_created', 'review_pending_to_rejected', 'review_rejected_to_pending',
            'retention_hold_set', 'retention_hold_released', 'document_archived', 'retention_hold_set',
        ], array_column($history, 'action'));
        self::assertSame('withdrawn', $history[5]['reason']);
        $auditId = (int) $history[5]['id'];
        $this->assertFailure(PDOException::class, fn () => $this->connection()->execute(
            'UPDATE employee_document_audit SET reason = "x" WHERE id = :id', ['id' => $auditId]
        ));
        $this->assertFailure(PDOException::class, fn () => $this->connection()->execute(
            'DELETE FROM employee_document_audit WHERE id = :id', ['id' => $auditId]
        ));
    }

    public function testCorrectionArchivesOriginalAndRedactsUnscopedLinksAndLegacyAuditText(): void
    {
        $id = $this->newDocument($this->employee, 'correction-doc');
        $this->documents->addVersion($id, 0, 'metadata_recorded', 'correction-version');
        $metadata = ['document_type_id' => $this->type];
        $manager = $this->createUser();
        $this->grant($manager, [PersonnelAccessService::MANAGE]);
        $this->scope($manager, $this->employee);
        $this->login($manager);
        $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->correctAssignment(
            $id, $this->otherEmployee, $metadata, 2, 'incorrect_assignment', 'correction-denied'
        ));
        self::assertSame(1, $this->countRows('employee_documents'));

        $this->login($this->actor);
        $correction = $this->documents->correctAssignment($id, $this->otherEmployee, $metadata, 2, 'incorrect_assignment', 'correction');
        self::assertSame($correction, $this->documents->correctAssignment($id, $this->otherEmployee, $metadata, 2, 'incorrect_assignment', 'correction'));
        $replacement = $correction['replacement_document_id'];
        self::assertNotSame($id, $replacement);
        self::assertSame($this->employee, (int) $this->documents->get($id)['user_id']);
        self::assertNotNull($this->documents->get($id)['archived_at']);
        self::assertSame($this->otherEmployee, (int) $this->documents->get($replacement)['user_id']);
        self::assertSame('pending', $this->documents->get($replacement)['review_status']);
        self::assertCount(1, $this->documents->versions($id));
        self::assertSame([], $this->documents->versions($replacement));
        self::assertSame($replacement, (int) $this->documents->history($id)[2]['related_document_id']);
        $this->connection()->execute(
            'INSERT INTO employee_document_audit (document_id, action, actor_user_id, reason)
             VALUES (:document_id, "synthetic-sensitive-action", :actor, "synthetic-sensitive-diagnosis")',
            ['document_id' => $id, 'actor' => $this->actor]
        );

        $viewer = $this->createUser();
        $this->grant($viewer, [PersonnelAccessService::VIEW]);
        $this->scope($viewer, $this->employee);
        $this->login($viewer);
        $history = $this->documents->history($id);
        self::assertNull($history[2]['related_document_id']);
        self::assertSame('legacy_event', $history[3]['action']);
        self::assertSame('legacy_unclassified', $history[3]['reason']);
        self::assertStringNotContainsString('synthetic-sensitive', json_encode($history, JSON_THROW_ON_ERROR));
    }

    public function testAuditFailureRollsBackWritesAndPreventsDataDisclosure(): void
    {
        $id = $this->newDocument($this->employee, 'audit-doc');
        $connection = $this->connection();
        $connection->execute(
            "CREATE TRIGGER test_reject_document_audit BEFORE INSERT ON employee_document_audit FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic-sensitive-write-error'"
        );
        try {
            $message = $this->assertFailure(RuntimeException::class, fn () => $this->documents->addVersion($id, 0, 'metadata_recorded', 'version-audit-fail'));
            self::assertStringNotContainsString('synthetic-sensitive', $message);
            $this->assertFailure(RuntimeException::class, fn () => $this->documents->create($this->employee, ['document_type_id' => $this->type], 'create-audit-fail'));
            self::assertSame(1, $this->countRows('employee_documents'));
            self::assertSame(0, $this->countRows('employee_document_versions'));
            self::assertSame(1, (int) $this->documents->get($id)['lock_version']);
            self::assertSame(1, $this->countRows('personnel_command_receipts'));
        } finally {
            $connection->execute('DROP TRIGGER test_reject_document_audit');
        }
        self::assertSame(1, $this->documents->addVersion($id, 0, 'metadata_recorded', 'version-audit-fail')['version_number']);
        self::assertSame(2, $this->countRows('employee_document_audit'));

        $connection->execute(
            "CREATE TRIGGER test_reject_access_audit BEFORE INSERT ON employee_document_access_log FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic-sensitive-read-error'"
        );
        try {
            $message = $this->assertFailure(RuntimeException::class, fn () => $this->documents->get($id));
            self::assertStringNotContainsString('synthetic-sensitive', $message);
            $this->assertFailure(PersonnelAccessDenied::class, fn () => $this->documents->get(999999));
        } finally {
            $connection->execute('DROP TRIGGER test_reject_access_audit');
        }
        self::assertSame($id, (int) $this->documents->get($id)['id']);
        $connection->transaction(function () use ($id): void {
            $this->assertFailure(RuntimeException::class, fn () => $this->documents->get($id));
        });
    }

    private function login(int $actor): void
    {
        $_SESSION = ['auth' => [
            'user_id' => $actor,
            'logged_in_at' => time(),
            'database_realm' => $this->connection()->authenticationRealm(),
        ]];
    }

    private function startVersionWorker(int $actor, int $documentId): array
    {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/personnel-document-version-worker.php')],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode([
            'connection' => $this->connectionConfig(),
            'actor' => $actor,
            'document_id' => $documentId,
        ], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 8);

        return [$process, $pipes[1], $pipes[2]];
    }

    private function closeVersionWorker(array $worker): void
    {
        [$process, $output, $errors] = $worker;
        fclose($output);
        fclose($errors);
        proc_close($process);
    }

    private function createType(string $code): int
    {
        $this->connection()->execute(
            'INSERT INTO personnel_document_types (code, label, category) VALUES (:code, "Synthetic test type", "test")',
            ['code' => $code]
        );

        return $this->connection()->lastInsertId();
    }

    private function newDocument(int $employee, string $requestId): int
    {
        return $this->documents->create($employee, ['document_type_id' => $this->type], $requestId)['document_id'];
    }

    private function context(int $employee, string $from, ?string $until): int
    {
        $this->connection()->execute(
            'INSERT INTO employee_employment_contexts (user_id, employment_key, effective_from, effective_until, created_by_user_id)
             VALUES (:user_id, "test-context", :date_from, :date_until, :actor)',
            ['user_id' => $employee, 'date_from' => $from, 'date_until' => $until, 'actor' => $this->actor]
        );

        return $this->connection()->lastInsertId();
    }

    private function rule(int $type): int
    {
        $source = $this->connection()->fetchColumn(
            'SELECT id FROM personnel_compliance_sources WHERE code = "synthetic-source" AND version = "1"'
        );
        if (!$source) {
            $this->connection()->execute(
                'INSERT INTO personnel_compliance_sources (code, version, title, url, checked_on)
                 VALUES ("synthetic-source", "1", "Synthetic test source", "https://example.test", "2026-11-18")'
            );
            $source = $this->connection()->lastInsertId();
        }
        $this->connection()->execute(
            'INSERT INTO personnel_document_requirements
             (rule_code, document_type_id, source_id, citation, valid_from, valid_until, record_kind, record_frequency,
              employment_scope, evidence_rule, signature_rule, retention_rule)
             VALUES (:code, :type, :source, "Synthetic test citation", "2026-11-01", "2026-11-30", "test", "test",
                     "Synthetic scope", "Synthetic evidence", "Synthetic signature", "Synthetic retention")',
            ['code' => 'synthetic-rule-' . $type, 'type' => $type, 'source' => $source]
        );

        return $this->connection()->lastInsertId();
    }

    private function grant(int $actor, array $permissions, bool $systemAdmin = false): int
    {
        $this->connection()->execute(
            'INSERT INTO roles (slug, name, is_system_role) VALUES (:slug, "Synthetic test role", :system)',
            ['slug' => $systemAdmin ? 'administrator' : 'personnel-test-' . bin2hex(random_bytes(5)), 'system' => (int) $systemAdmin]
        );
        $role = $this->connection()->lastInsertId();
        foreach ($permissions as $permission) {
            $this->connection()->execute(
                'INSERT INTO permissions (code, label, scope) VALUES (:code, "Synthetic test permission", "global")
                 ON DUPLICATE KEY UPDATE code = VALUES(code)',
                ['code' => $permission]
            );
            $this->connection()->execute(
                'INSERT INTO role_permissions (role_id, permission_id) SELECT :role, id FROM permissions WHERE code = :code',
                ['role' => $role, 'code' => $permission]
            );
        }
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', ['user' => $actor, 'role' => $role]);

        return $role;
    }

    private function scope(int $grantee, int $employee, bool $grant = true): void
    {
        $this->connection()->execute(
            'INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code)
             VALUES (:employee, :grantee, :actor, :granted, :reason)',
            ['employee' => $employee, 'grantee' => $grantee, 'actor' => $this->actor, 'granted' => (int) $grant,
                'reason' => $grant ? 'assignment' : 'revocation']
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM ' . $table);
    }

    private function assertFailure(string $class, callable $operation): string
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            self::assertInstanceOf($class, $exception);

            return $exception->getMessage();
        }
        self::fail('Der erwartete Fehler ist ausgeblieben.');
    }
}
