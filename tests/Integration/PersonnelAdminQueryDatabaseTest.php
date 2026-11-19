<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Personnel\Compliance\EmploymentContextService;
use App\Domain\Personnel\Compliance\PersonnelAccessDenied;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelAdminQueryService;
use App\Domain\Personnel\Compliance\PersonnelComplianceCatalogService;
use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Users\PermissionMatrix;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class PersonnelAdminQueryDatabaseTest extends MariaDbTestCase
{
    private int $admin;
    private int $employee;
    private int $foreignEmployee;
    private int $type;
    private PersonnelAccessService $access;
    private PersonnelAdminQueryService $queries;
    private PersonnelDocumentService $documents;
    private EmploymentContextService $employment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(['first_name' => 'Synthetischer', 'last_name' => 'Akteur']);
        $this->employee = $this->createUser(['first_name' => 'Mira', 'last_name' => 'Test%feld', 'employee_number' => 'ROSTER-001']);
        $this->foreignEmployee = $this->createUser(['first_name' => 'Mira', 'last_name' => 'Fremdfeld', 'employee_number' => 'ROSTER-002']);
        $this->giveRights($this->admin, [PersonnelAccessService::VIEW, PersonnelAccessService::MANAGE, PersonnelAccessService::DELEGATE], true);
        $this->login($this->admin);
        $this->type = $this->newType('synthetic-admin-type', 'Synthetischer Typ');

        $config = require base_path('config/permissions.php');
        $connection = $this->connection();
        $access = new PersonnelAccessService($connection, new AuthService($connection, new PermissionMatrix($config['roles'], $config['available'])));
        $this->access = $access;
        $this->documents = new PersonnelDocumentService($connection, $access);
        $this->employment = new EmploymentContextService($connection, $access);
        $this->queries = new PersonnelAdminQueryService(
            $connection, $access, $this->employment, $this->documents,
            new PersonnelComplianceCatalogService($connection, $access),
        );
    }

    public function testOverviewUsesTheLatestEmployeeScopeBeforeFilteringAndDeniesGeneralRights(): void
    {
        $archived = $this->createUser(['first_name' => 'Mira', 'last_name' => 'Archivfeld']);
        $noPercent = $this->createUser(['first_name' => 'Mira', 'last_name' => 'Testfeld']);
        $this->connection()->execute('UPDATE users SET is_deleted = 1 WHERE id = :employee', ['employee' => $archived]);
        $this->documents->create($this->employee, ['document_type_id' => $this->type], 'visible');
        $this->documents->create($this->foreignEmployee, ['document_type_id' => $this->type], 'foreign');

        $actor = $this->createUser();
        $this->giveRights($actor, ['personnel.view', 'files.view', PersonnelAccessService::EXPORT]);
        $this->scope($actor, $this->employee, true);
        $this->scope($actor, $this->foreignEmployee, true);
        $this->scope($actor, $this->foreignEmployee, false);
        $this->scope($actor, $archived, true);
        $this->scope($actor, $noPercent, true);
        $this->login($actor);
        $this->denied(fn () => $this->queries->overview());
        $this->giveRights($actor, [PersonnelAccessService::VIEW]);

        $result = $this->queries->overview('Mira');
        self::assertSame([$this->employee, $noPercent], array_map('intval', array_column($result['employees'], 'id')));
        self::assertSame(1, $result['employees'][0]['document_count']);
        self::assertSame(1, $result['employees'][0]['pending_count']);
        self::assertFalse($result['employees'][0]['ambiguous']);
        self::assertFalse($result['has_more']);
        self::assertArrayNotHasKey('email', $result['employees'][0]);
        self::assertArrayNotHasKey('password_hash', $result['employees'][0]);
        self::assertSame([$this->employee], array_column($this->queries->overview('Test%')['employees'], 'id'));
        self::assertSame([$this->employee], array_column($this->queries->overview('ROSTER-001')['employees'], 'id'));
        self::assertSame([$archived], array_column($this->queries->overview('', 'archived')['employees'], 'id'));
        self::assertSame([], $this->queries->overview('Fremdfeld', 'all')['employees']);

        $this->scope($actor, $this->employee, false);
        self::assertSame([], $this->queries->overview('Test%')['employees']);
        $this->denied(fn () => $this->queries->employee($this->employee));
    }

    public function testComposedEmployeeAuditCommitsWithTheHistoricalViewAndFailsAtomically(): void
    {
        $former = $this->employment->append($this->employee, 'job', 0, null, [
            'effective_from' => '2026-04-01', 'employment_type' => 'marginal',
        ], 'initial_record', 'earlier');
        $this->employment->changeFrom($this->employee, 'job', (int) $former['revision'], (int) $former['context_id'], [
            'effective_from' => '2026-10-01', 'employment_type' => 'regular',
        ], 'later');
        $viewer = $this->createUser();
        $this->giveRights($viewer, [PersonnelAccessService::VIEW]);
        $this->scope($viewer, $this->employee, true);
        $this->login($viewer);

        $before = $this->queries->employee($this->employee, '2026-09-30');
        self::assertSame('marginal', $before['employment_current'][0]['employment_type']);
        self::assertSame('unknown', $before['employment_current'][0]['student_status']);
        self::assertSame('carried_forward', $before['employment_current'][0]['revision_reason']);
        self::assertTrue($before['employment_history'][0]['superseded']);
        self::assertFalse($before['can_manage']);
        $after = $this->queries->employee($this->employee, '2026-10-01');
        self::assertSame('regular', $after['employment_current'][0]['employment_type']);
        self::assertSame($this->employee, (int) $after['employee']['id']);
        self::assertSame(8, $this->accessCount());

        $this->connection()->execute(<<<'SQL'
            CREATE TRIGGER phase2c_fixture_fail_audit BEFORE INSERT ON employee_document_access_log FOR EACH ROW
            BEGIN
                IF NEW.action = 'admin.personnel.employee' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic audit failure';
                END IF;
            END
            SQL);
        try {
            try {
                $this->queries->employee($this->employee, '2026-10-01');
                self::fail('Expected the employee read to fail closed.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('protokolliert', $exception->getMessage());
                self::assertStringNotContainsString('synthetic audit failure', $exception->getMessage());
            }
            self::assertSame(8, $this->accessCount(), 'All nested audit inserts rolled back with the failed outer audit.');
        } finally {
            $this->connection()->execute('DROP TRIGGER phase2c_fixture_fail_audit');
        }

        self::assertSame($this->denied(fn () => $this->queries->employee($this->foreignEmployee)),
            $this->denied(fn () => $this->queries->employee(999999)));
        $denials = $this->connection()->fetchAll('SELECT employee_user_id, document_id, document_version_id
            FROM employee_document_access_log WHERE outcome = "denied"');
        self::assertCount(2, $denials);
        foreach ($denials as $denial) {
            self::assertSame(['employee_user_id' => null, 'document_id' => null, 'document_version_id' => null], $denial);
        }
        $audit = $this->queries->employeeAudit($this->employee);
        self::assertSame(['change_of_circumstances', 'carried_forward', 'initial_record'], array_column($audit['employment_history'], 'revision_reason'));
        self::assertSame('Synthetischer Akteur', $audit['employment_history'][0]['created_by_name']);
    }

    public function testDocumentAndEmployeeAuditExposeOnlyAuthorizedTargetsAndSafeVersionMetadata(): void
    {
        $owned = (int) $this->documents->create($this->employee, ['document_type_id' => $this->type], 'own')['document_id'];
        $foreign = (int) $this->documents->create($this->foreignEmployee, ['document_type_id' => $this->type], 'foreign')['document_id'];
        $fileVersion = $this->insertFileMetadata($owned, 1);
        $this->documents->addVersion($owned, 1, 'metadata_revised', 'metadata-only');
        $this->connection()->execute('INSERT INTO employee_document_access_log
            (document_id, document_version_id, action, actor_user_id, outcome) VALUES
            (:own_file, :version, "document.download_file", :actor_id, "allowed"),
            (:foreign_doc, NULL, "private-legacy-technical-string", :actor_foreign, "allowed"),
            (:denied_legacy_doc, NULL, "document.read", :denied_actor, "denied")', [
            'own_file' => $owned, 'version' => $fileVersion, 'actor_id' => $this->admin,
            'foreign_doc' => $foreign, 'actor_foreign' => $this->admin, 'denied_legacy_doc' => $owned, 'denied_actor' => $this->admin,
        ]);
        $this->connection()->execute('INSERT INTO employee_document_audit (document_id, related_document_id, action, actor_user_id, reason)
            VALUES (:document, :related, "document_assignment_corrected", :actor, "incorrect_assignment")',
            ['document' => $owned, 'related' => $foreign, 'actor' => $this->admin]);

        $viewer = $this->createUser();
        $other = $this->createUser();
        $this->giveRights($viewer, [PersonnelAccessService::VIEW]);
        $this->scope($viewer, $this->employee, true);
        $this->scope($other, $this->employee, true);
        $this->login($viewer);
        $detail = $this->queries->document($owned);
        self::assertSame('Synthetischer Typ', $detail['document']['type_label']);
        self::assertSame(['application/pdf', null], array_column($detail['versions'], 'mime_type'));
        self::assertSame([42, null], array_column($detail['versions'], 'size_bytes'));
        self::assertSame('Synthetischer Akteur', $detail['versions'][0]['created_by_name']);
        self::assertArrayNotHasKey('storage_key', $detail['versions'][0]);
        self::assertArrayNotHasKey('sha256', $detail['versions'][0]);
        self::assertNull($detail['change_audit'][2]['related_document_id']);
        self::assertContains($fileVersion, array_column($detail['access_audit'], 'document_version_id'));
        self::assertSame($this->denied(fn () => $this->queries->document($foreign)), $this->denied(fn () => $this->queries->document(999999)));

        $audit = $this->queries->employeeAudit($this->employee);
        self::assertSame([$viewer], array_column($audit['scope_history'], 'grantee_user_id'));
        self::assertNotContains($foreign, array_column($audit['access_audit'], 'document_id'));
        self::assertNotContains('denied', array_column($audit['access_audit'], 'outcome'));
        self::assertNotContains('private-legacy-technical-string', array_column($audit['access_audit'], 'action'));
        self::assertSame([$this->employee], array_values(array_unique(array_column($audit['access_audit'], 'employee_user_id'))));
        $this->login($this->admin);
        self::assertSame([$other, $viewer], array_column($this->queries->employeeAudit($this->employee)['scope_history'], 'grantee_user_id'));
    }

    public function testAssignmentOptionsRequireScopeAndTrackingLatestFileVersionDoesNotTrustLegacyHeadStatus(): void
    {
        $metadata = ['document_type_id' => $this->type];
        $existing = (int) $this->documents->create($this->employee, $metadata, 'file-to-review')['document_id'];
        $this->connection()->execute('INSERT INTO employee_documents (user_id, document_type_id, review_status, created_by_user_id)
            VALUES (:employee, :type, "reviewed", :reviewer)', ['employee' => $this->employee, 'type' => $this->type, 'reviewer' => $this->admin]);
        $firstVersion = $this->insertFileMetadata($existing, 1);
        $this->connection()->execute('INSERT INTO employee_document_evidence_reviews
            (document_id, document_version_id, actor_user_id, `procedure`, procedure_version, outcome, original_kind, paper_status,
             signature_status, reason, legible, matches_record, created_at)
            VALUES (:document, :version, :reviewer, "manual_visual", 1, "confirmed_internal", "digital_original", "not_applicable",
                "unresolved", "visual_checks_confirmed", 1, 1, UTC_TIMESTAMP())',
            ['document' => $existing, 'version' => $firstVersion, 'reviewer' => $this->admin]);

        $actor = $this->createUser();
        $this->giveRights($actor, [PersonnelAccessService::VIEW]);
        $this->giveRights($actor, [PersonnelAccessService::MANAGE]);
        $this->scope($actor, $this->employee, true);
        $this->login($actor);
        $overview = $this->queries->overview();
        self::assertSame(1, $overview['employees'][0]['reviewed_count']);
        self::assertSame(1, $overview['employees'][0]['legacy_reviewed_count']);
        self::assertSame(0, $overview['employees'][0]['pending_count']);
        $detail = $this->queries->document($existing);
        self::assertSame('confirmed_internal', $detail['evidence_history'][0]['outcome']);
        self::assertSame('Synthetischer Akteur', $detail['evidence_history'][0]['actor_name']);
        $this->documents->addVersion($existing, 1, 'metadata_revised', 'superseding-metadata');
        $overview = $this->queries->overview();
        self::assertSame(0, $overview['employees'][0]['reviewed_count']);
        self::assertSame(1, $overview['employees'][0]['pending_count']);
        self::assertSame([$this->type], array_column($this->queries->assignmentOptions($this->employee)['types'], 'id'));
        $selection = $this->queries->correctionOptions($existing, $this->employee);
        self::assertSame([$this->employee], array_column($selection['eligible_employees'], 'id'));
        self::assertSame($this->employee, $selection['employee']['id']);
        self::assertSame([], $selection['employment_history']);
        $this->denied(fn () => $this->queries->assignmentOptions($this->foreignEmployee));
        $this->denied(fn () => $this->queries->correctionOptions($existing, $this->foreignEmployee));
    }

    public function testFileReadCannotReturnAStreamInsideAnUncommittedMetadataAudit(): void
    {
        $id = (int) $this->documents->create($this->employee, ['document_type_id' => $this->type], 'nested-download')['document_id'];
        $version = $this->insertFileMetadata($id, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ein protokollierter Personalaktenzugriff ist momentan nicht möglich.');
        $this->access->auditedRead('fixture.nested_download', fn (): array => [
            $this->documents->downloadFile($id, $version), $id, $version, $this->employee,
        ]);
    }

    private function newType(string $code, string $label): int
    {
        $this->connection()->execute('INSERT INTO personnel_document_types (code, label, category) VALUES (:code, :label, "test")',
            ['code' => $code, 'label' => $label]);

        return $this->connection()->lastInsertId();
    }

    private function insertFileMetadata(int $documentId, int $version): int
    {
        $this->connection()->execute('INSERT INTO employee_document_versions
            (document_id, version_number, supersedes_version_number, storage_key, sha256, mime_type, size_bytes, storage_key_id,
             storage_format, format_verified_at, malware_scanned_at, created_by_user_id)
            VALUES (:document, :version, :preceding, :object_key, :sha256, "application/pdf", 42, "fixture", 1,
                UTC_TIMESTAMP(), UTC_TIMESTAMP(), :actor)',
            ['document' => $documentId, 'version' => $version, 'preceding' => $version > 1 ? $version - 1 : null,
                'object_key' => bin2hex(random_bytes(32)), 'sha256' => str_repeat('b', 64), 'actor' => $this->admin]);

        return $this->connection()->lastInsertId();
    }

    private function giveRights(int $actorId, array $rights, bool $systemAdmin = false): void
    {
        $this->connection()->execute('INSERT INTO roles (slug, name, is_system_role) VALUES (:slug, "Synthetic role", :system_role)',
            ['slug' => $systemAdmin ? 'administrator' : 'synthetic-admin-read-' . bin2hex(random_bytes(5)), 'system_role' => (int) $systemAdmin]);
        $roleId = $this->connection()->lastInsertId();
        foreach ($rights as $right) {
            $this->connection()->execute('INSERT INTO permissions (code, label, scope) VALUES (:permission, "Synthetic right", "global")
                ON DUPLICATE KEY UPDATE code = VALUES(code)', ['permission' => $right]);
            $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id)
                SELECT :role, id FROM permissions WHERE code = :permission', ['role' => $roleId, 'permission' => $right]);
        }
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $actorId, 'role_id' => $roleId]);
    }

    private function scope(int $grantee, int $employee, bool $grant): void
    {
        $this->connection()->execute('INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code)
            VALUES (:employee, :grantee, :actor, :is_granted, :reason)', ['employee' => $employee, 'grantee' => $grantee, 'actor' => $this->admin,
            'is_granted' => (int) $grant, 'reason' => $grant ? 'assignment' : 'revocation']);
    }

    private function login(int $actorId): void
    {
        $_SESSION = ['auth' => ['user_id' => $actorId, 'logged_in_at' => time(), 'database_realm' => $this->connection()->authenticationRealm()]];
    }

    private function accessCount(): int
    {
        return (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_document_access_log');
    }

    private function denied(callable $call): string
    {
        try {
            $call();
            self::fail('Expected the same scoped denial as an unknown target.');
        } catch (PersonnelAccessDenied $exception) {
            return $exception->getMessage();
        }
    }
}
