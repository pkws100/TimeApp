<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Personnel\Compliance\PersonnelAccessDenied;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelConflict;
use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Personnel\Compliance\PersonnelFileRuntime;
use App\Domain\Personnel\Compliance\PersonnelFileUnavailable;
use App\Domain\Personnel\Compliance\PersonnelStorageRecoveryService;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Domain\Personnel\Storage\PersonnelStorageException;
use App\Infrastructure\Database\DatabaseConnection;
use FilesystemIterator;
use InvalidArgumentException;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\MariaDbTestCase;
use Tests\Support\PersonnelFileTestFixture as Fixture;
use Throwable;

final class PersonnelFileDatabaseTest extends MariaDbTestCase
{
    private string $root;
    private array $initialEnvironment;
    private ?array $initialSession;
    private Fixture $fixture;
    private PersonnelFileRuntime $runtime;
    private PersonnelDocumentService $documents;
    private int $actor;
    private int $employee;
    private int $type;
    private int $document;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initialEnvironment = $_ENV;
        $this->initialSession = $_SESSION ?? null;
        $_ENV['APP_ENV'] = 'test';
        $_ENV['PERSONNEL_FILE_MODE'] = 'synthetic';
        $this->root = '/var/tmp/timeapp-personnel-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->root, 0700));
        $this->fixture = new Fixture();
        $this->actor = $this->createUser();
        $this->employee = $this->createUser();
        $this->giveRights($this->actor, [PersonnelAccessService::VIEW, PersonnelAccessService::MANAGE, PersonnelAccessService::DELEGATE], true);
        Fixture::login($this->connection(), $this->actor);
        $this->runtime = $this->fixture->runtime($this->connection(), $this->root);
        $this->documents = Fixture::documentService($this->connection(), $this->runtime);

        $this->connection()->execute('INSERT INTO personnel_document_types (code, label, category) VALUES ("file_fixture", "Synthetic", "test")');
        $this->type = $this->connection()->lastInsertId();
        $this->document = $this->documents->create($this->employee, ['document_type_id' => $this->type], 'file-fixture-document')['document_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->initialEnvironment)) {
            $_ENV = $this->initialEnvironment;
        }
        if (isset($this->initialSession)) {
            $_SESSION = $this->initialSession;
        } else {
            unset($_SESSION);
        }
        if (isset($this->root) && is_dir($this->root)) {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testFileVersionsPreserveOriginalsAndOneAuditAcrossReplays(): void
    {
        $bytes = Fixture::png();
        $empty = $this->documents->addVersion($this->document, 0, 'metadata_recorded', 'file-metaversion');
        $first = Fixture::upload($this->documents, $this->document, 1, $bytes, 'file-upload-first');
        $this->fixture->scanFails = true;
        self::assertSame($first, Fixture::upload($this->documents, $this->document, 1, $bytes, 'file-upload-first'));
        $this->fixture->scanFails = false;
        $second = Fixture::upload($this->documents, $this->document, 2, Fixture::png(150), 'file-upload-second');
        $this->failure(PersonnelConflict::class, fn () => Fixture::upload($this->documents, $this->document, 1, Fixture::png(90), 'file-upload-first'));

        self::assertSame([1, 2, 3], [$empty['version_number'], $first['version_number'], $second['version_number']]);
        $metadata = $this->documents->versions($this->document);
        self::assertSame([0, 1, 1], array_map('intval', array_column($metadata, 'file_stored')));
        self::assertSame(['unknown', 'unknown', 'unknown'], array_column($metadata, 'signature_status'));
        self::assertSame(['unknown', 'unknown', 'unknown'], array_column($metadata, 'original_status'));
        self::assertSame([false, false, false], array_column($metadata, 'evidence_verified'));
        self::assertArrayNotHasKey('storage_key', $metadata[1]);
        self::assertSame('pending', $this->documents->get($this->document)['review_status']);
        $stored = $this->versionRow($first['version_id']);
        self::assertSame(hash('sha256', $bytes), $stored['sha256']);
        self::assertSame('image/png', $stored['mime_type']);
        self::assertSame(strlen($bytes), (int) $stored['size_bytes']);
        self::assertNotNull($stored['format_verified_at']);
        self::assertNotNull($stored['malware_scanned_at']);
        $cipherPath = $this->testPathFor($stored['storage_key']);
        self::assertStringNotContainsString($bytes, file_get_contents($cipherPath));

        $this->failure(PersonnelFileUnavailable::class, fn () => $this->documents->downloadFile($this->document, $empty['version_id']));
        $file = $this->documents->downloadFile($this->document, $first['version_id']);
        self::assertSame('image/png', $file->mimeType);
        self::assertSame($bytes, stream_get_contents($file->stream()));
        $file->close();
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_document_audit WHERE action = "file_version_created"'));
        self::assertSame(1, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM employee_document_access_log WHERE action = "document.download_file" AND outcome = "allowed"
             AND employee_user_id = :employee AND document_id = :document AND document_version_id = :version',
            ['employee' => $this->employee, 'document' => $this->document, 'version' => $first['version_id']]
        ));
        $this->documents->archive($this->document, 4, 'duplicate', 'file-archive');
        $archivedFile = $this->documents->downloadFile($this->document, $first['version_id']);
        self::assertSame($bytes, stream_get_contents($archivedFile->stream()));
        $archivedFile->close();
        $this->failure(PersonnelConflict::class, fn () => Fixture::upload($this->documents, $this->document, 3, $bytes, 'archived-upload'));
    }

    public function testFailuresLeaveNoVersionAndTheDownloadFailsClosedOnAuditOrObjectErrors(): void
    {
        $bytes = Fixture::png();
        $this->fixture->scanFails = true;
        $this->failure(PersonnelFileUnavailable::class, fn () => Fixture::upload($this->documents, $this->document, 0, $bytes, 'retry'));
        $this->fixture->scanFails = false;
        $this->failure(PersonnelFileUnavailable::class, fn () => Fixture::upload($this->documents, $this->document, 0, $bytes, 'mime', 'image/jpeg'));
        $this->failure(PersonnelFileUnavailable::class, fn () => Fixture::upload($this->documents, $this->document, 0, $bytes, 'extension', 'image/png', 'txt'));
        $this->failure(PDOException::class, fn () => $this->connection()->execute(
            'INSERT INTO employee_document_versions (document_id, version_number, storage_key, sha256, mime_type, size_bytes, created_by_user_id)
             VALUES (:document, 1, :key, :hash, "image/png", 42, :actor)',
            ['document' => $this->document, 'key' => str_repeat('a', 64), 'hash' => str_repeat('b', 64), 'actor' => $this->actor]
        ));
        self::assertSame([], $this->documents->versions($this->document));
        self::assertSame([], $this->runtime->storage->withLock(true, fn (): array => $this->runtime->storage->listQuarantineKeys()));

        $this->connection()->execute('CREATE TRIGGER test_reject_file_change BEFORE INSERT ON employee_document_audit FOR EACH ROW
            SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "synthetic confidential failure"');
        try {
            $message = $this->failure(PersonnelFileUnavailable::class, fn () => Fixture::upload($this->documents, $this->document, 0, $bytes, 'retry'));
            self::assertStringNotContainsString('synthetic confidential', $message);
            self::assertSame([], $this->documents->versions($this->document));
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM personnel_command_receipts WHERE operation = "document.upload_file"'));
        } finally {
            $this->connection()->execute('DROP TRIGGER test_reject_file_change');
        }
        $result = Fixture::upload($this->documents, $this->document, 0, $bytes, 'retry');
        self::assertSame($result, Fixture::upload($this->documents, $this->document, 0, $bytes, 'retry'));
        $recovery = new PersonnelStorageRecoveryService($this->connection(), Fixture::auth($this->connection()), $this->runtime);
        self::assertSame(1, $recovery->reconcile()['orphaned_objects']);
        self::assertSame(1, $recovery->reconcile()['verified']);

        $this->connection()->execute('CREATE TRIGGER test_reject_file_read BEFORE INSERT ON employee_document_access_log FOR EACH ROW
            SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "synthetic confidential read failure"');
        try {
            $message = $this->failure(RuntimeException::class, fn () => $this->documents->downloadFile($this->document, $result['version_id']));
            self::assertStringNotContainsString('synthetic confidential', $message);
        } finally {
            $this->connection()->execute('DROP TRIGGER test_reject_file_read');
        }
        $object = $this->testPathFor($this->versionRow($result['version_id'])['storage_key']);
        $corrupted = file_get_contents($object);
        $last = strlen($corrupted) - 1;
        $corrupted[$last] = chr(ord($corrupted[$last]) ^ 1);
        self::assertSame(strlen($corrupted), file_put_contents($object, $corrupted));
        $this->failure(PersonnelFileUnavailable::class, fn () => $this->documents->downloadFile($this->document, $result['version_id']));
        self::assertSame(1, $recovery->reconcile()['invalid']);
        self::assertTrue(unlink($object));
        self::assertSame(1, $recovery->reconcile()['missing']);
        $this->failure(PersonnelFileUnavailable::class, fn () => $this->documents->downloadFile($this->document, $result['version_id']));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_document_access_log WHERE action = "document.download_file" AND outcome = "allowed"'));
    }

    public function testExactRightScopeAndSessionGuardTheFileOperations(): void
    {
        $bytes = Fixture::png();
        $version = Fixture::upload($this->documents, $this->document, 0, $bytes, 'protected-file')['version_id'];
        $foreign = $this->documents->create($this->createUser(), ['document_type_id' => $this->type], 'foreign-document')['document_id'];
        $foreignVersion = Fixture::upload($this->documents, $foreign, 0, $bytes, 'foreign-file')['version_id'];

        foreach ([PersonnelAccessService::MANAGE, PersonnelAccessService::EXPORT, 'files.view', 'personnel.view', 'users.manage', 'roles.manage'] as $right) {
            $user = $this->createUser();
            $this->giveRights($user, [$right]);
            $this->scope($user, true);
            Fixture::login($this->connection(), $user);
            $denied = $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->downloadFile($this->document, $version));
            self::assertStringNotContainsString((string) $version, $denied);
        }
        $viewer = $this->createUser();
        $role = $this->giveRights($viewer, [PersonnelAccessService::VIEW]);
        $this->scope($viewer, true);
        Fixture::login($this->connection(), $viewer);
        $download = $this->documents->downloadFile($this->document, $version);
        self::assertSame($bytes, stream_get_contents($download->stream()));
        $unknown = $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->downloadFile(999999, $version));
        self::assertSame($unknown, $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->downloadFile($foreign, $foreignVersion)));
        self::assertSame($unknown, $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->downloadFile($this->document, $foreignVersion)));
        $this->failure(PersonnelAccessDenied::class, fn () => Fixture::upload($this->documents, $this->document, 1, $bytes, 'read-only'));
        $this->scope($viewer, false);
        $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->downloadFile($this->document, $version));
        $this->scope($viewer, true);
        $this->connection()->execute('DELETE FROM role_permissions WHERE role_id = :id', ['id' => $role]);
        $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->downloadFile($this->document, $version));
        Fixture::login($this->connection(), $this->actor);
        $_SESSION['auth']['database_realm'] = hash('sha256', 'wrong realm');
        $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->downloadFile($this->document, $version));
        Fixture::login($this->connection(), $this->actor);
        $_ENV['PERSONNEL_FILE_MODE'] = 'disabled';
        $this->failure(PersonnelFileUnavailable::class, fn () => $this->documents->downloadFile($this->document, $version));
        $_ENV['PERSONNEL_FILE_MODE'] = 'synthetic';
        $_ENV['APP_ENV'] = 'production';
        $this->failure(PersonnelFileUnavailable::class, fn () => $this->fixture->runtime($this->connection(), $this->root));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_document_access_log
            WHERE outcome = "denied" AND (employee_user_id IS NOT NULL OR document_id IS NOT NULL OR document_version_id IS NOT NULL)'));
    }

    public function testParallelFileUploadsCannotUseTheSamePredecessor(): void
    {
        $blocker = new DatabaseConnection($this->connectionConfig());
        $pdo = $blocker->pdo();
        self::assertNotNull($pdo);
        $pdo->beginTransaction();
        $blocker->fetchColumn('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $this->employee]);
        $workers = [];
        try {
            $workers[] = $this->startUploadWorker('parallel-one');
            $workers[] = $this->startUploadWorker('parallel-two');
            foreach ($workers as [, $output]) {
                self::assertSame("ready\n", fgets($output));
            }
            $pdo->commit();
            $outcomes = [];
            foreach ($workers as [, $output]) {
                $outcomes[] = trim((string) fgets($output));
            }
            sort($outcomes);
            self::assertSame(['conflict', 'created'], $outcomes);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($workers as [$process, $output, $errors]) {
                fclose($output);
                fclose($errors);
                proc_close($process);
            }
        }
        self::assertCount(1, $this->documents->versions($this->document));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_document_audit WHERE action = "file_version_created"'));
        self::assertCount(1, $this->runtime->storage->withLock(true, fn () => $this->runtime->storage->listObjectKeys()));
        self::assertSame([], $this->runtime->storage->withLock(true, fn () => $this->runtime->storage->listStagingKeys()));
    }

    public function testRecoveryOnlyRemovesAgedTechnicalStaging(): void
    {
        $storage = $this->runtime->storage;
        $staged = $storage->withLock(false, function () use ($storage): string {
            $key = $storage->newStorageKey();
            $file = $storage->beginObject($key);
            fwrite($file, 'synthetic partial ciphertext');
            fclose($file);

            return $key;
        });
        $path = $this->testPathFor($staged);
        self::assertTrue(touch($path, time() - 7200));
        $quarantine = $this->root . '/quarantine/' . $storage->newStorageKey();
        fclose(PersonnelPrivatePath::createExclusive($quarantine));
        self::assertTrue(touch($quarantine, time() - 7200));
        $recovery = new PersonnelStorageRecoveryService($this->connection(), Fixture::auth($this->connection()), $this->runtime);
        $report = $recovery->reconcile(false, 3600);
        self::assertSame(1, $report['expired_staging']);
        self::assertSame(1, $report['expired_quarantine']);
        self::assertFileExists($path);
        self::assertFileExists($quarantine);
        $report = $recovery->reconcile(true, 3600);
        self::assertSame(1, $report['removed_staging']);
        self::assertSame(1, $report['removed_quarantine']);
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($quarantine);
    }

    public function testConfiguredKeyringCannotBePublishedFromAnOrdinaryPath(): void
    {
        $_ENV['PERSONNEL_FILE_ROOT'] = $this->root;
        $_ENV['PERSONNEL_FILE_KEYRING'] = base_path('public/synthetic-keyring.json');
        $this->failure(PersonnelStorageException::class, fn () => PersonnelFileRuntime::fromConfiguration($this->connection()));
    }

    public function testConfiguredRuntimeRequiresScannerAndStoresWithTheIsolatedRealDaemon(): void
    {
        $keyDirectory = '/var/tmp/timeapp-personnel-keyfixture-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($keyDirectory, 0700));
        $keyFile = $keyDirectory . '/files.json';
        $handle = PersonnelPrivatePath::createExclusive($keyFile);
        fwrite($handle, json_encode(['active' => 'fixture.1', 'keys' => ['fixture.1' => base64_encode(random_bytes(32))]], JSON_THROW_ON_ERROR));
        fclose($handle);
        try {
            $_ENV['PERSONNEL_FILE_ROOT'] = $this->root;
            $_ENV['PERSONNEL_FILE_KEYRING'] = $keyFile;
            $_ENV['PERSONNEL_QPDF'] = getenv('PERSONNEL_TEST_QPDF') ?: '/usr/bin/qpdf';
            $_ENV['PERSONNEL_CLAMD_SOCKET'] = $keyDirectory . '/unavailable.sock';
            $this->failure(PersonnelStorageException::class, fn () => PersonnelFileRuntime::fromConfiguration($this->connection()));
            $scanner = getenv('PERSONNEL_TEST_CLAMD_SOCKET');
            if ($scanner === false) {
                return;
            }

            $_ENV['PERSONNEL_CLAMD_SOCKET'] = $scanner;
            $runtime = PersonnelFileRuntime::fromConfiguration($this->connection());
            $documents = Fixture::documentService($this->connection(), $runtime);
            $bytes = Fixture::png();
            $result = Fixture::upload($documents, $this->document, 0, $bytes, 'real-synthetic-scanner');
            $download = $documents->downloadFile($this->document, $result['version_id']);
            self::assertSame($bytes, stream_get_contents($download->stream()));
            self::assertSame('fixture.1', $this->versionRow($result['version_id'])['storage_key_id']);
        } finally {
            unlink($keyFile);
            rmdir($keyDirectory);
        }
    }

    public function testEvidenceIsAnImmutableInternalReviewOfOneVerifiedVersion(): void
    {
        $first = Fixture::upload($this->documents, $this->document, 0, Fixture::png(), 'evidence-file');
        $input = [
            'outcome' => 'confirmed_internal', 'reason' => 'visual_checks_confirmed',
            'original_kind' => 'digital_copy', 'paper_status' => 'retained',
            'legible' => true, 'matches_record' => true,
        ];
        $revision = $first['lock_version'];
        $version = $first['version_id'];
        $this->failure(InvalidArgumentException::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, $version, $revision, [...$input, 'signature_status' => 'verified'], 'manufactured-signature'
        ));
        $this->failure(InvalidArgumentException::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, $version, $revision, [...$input, 'paper_status' => 'unknown'], 'unknown-original'
        ));
        $this->failure(InvalidArgumentException::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, $version, $revision, [...$input, 'matches_record' => false], 'unchecked-evidence'
        ));
        $confirmed = $this->documents->recordEvidenceReview($this->document, $version, $revision, $input, 'evidence-review');
        self::assertSame($confirmed, $this->documents->recordEvidenceReview($this->document, $version, $revision, $input, 'evidence-review'));
        $this->failure(PersonnelConflict::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, $version, $revision, [...$input, 'paper_status' => 'seen'], 'evidence-review'
        ));
        self::assertSame($revision + 1, $confirmed['lock_version']);
        self::assertSame('pending', $this->documents->get($this->document)['review_status']);
        self::assertFalse($this->documents->get($this->document)['evidence_verified']);
        self::assertFalse($this->documents->version($this->document, $version)['evidence_verified']);
        $this->failure(InvalidArgumentException::class, fn () => $this->documents->changeStatus(
            $this->document, 'reviewed', $confirmed['lock_version'], 'visual_checks_confirmed', 'unsafe-head-review'
        ));
        $events = $this->documents->history($this->document);
        self::assertSame('document_evidence_confirmed', end($events)['action']);
        self::assertSame($version, (int) end($events)['document_version_id']);

        $history = $this->documents->evidenceHistory($this->document);
        self::assertCount(1, $history);
        self::assertSame($this->actor, (int) $history[0]['actor_user_id']);
        self::assertSame('manual_visual', $history[0]['procedure']);
        self::assertSame(1, (int) $history[0]['procedure_version']);
        self::assertSame('unresolved', $history[0]['signature_status']);
        self::assertSame('retained', $history[0]['paper_status']);
        self::assertArrayNotHasKey('storage_key', $history[0]);
        self::assertArrayNotHasKey('sha256', $history[0]);
        self::assertSame(1, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM employee_document_access_log WHERE employee_user_id = :id AND action = "document.evidence_history"',
            ['id' => $this->employee]
        ));

        $this->failure(PDOException::class, fn () => $this->connection()->execute('UPDATE employee_document_evidence_reviews SET outcome = "objected"'));
        $this->failure(PDOException::class, fn () => $this->connection()->execute('DELETE FROM employee_document_evidence_reviews'));
        $next = Fixture::upload($this->documents, $this->document, 1, Fixture::png(100), 'evidence-replacement');
        $this->failure(PersonnelConflict::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, $version, $next['lock_version'], $input, 'review-old-version'
        ));
        $this->documents->recordEvidenceReview($this->document, $next['version_id'], $next['lock_version'], [
            'outcome' => 'objected', 'reason' => 'illegible', 'original_kind' => 'unknown', 'paper_status' => 'unknown',
        ], 'evidence-objection');
        $history = $this->documents->evidenceHistory($this->document);
        self::assertSame(['objected', 'confirmed_internal'], array_column($history, 'outcome'));
        self::assertSame([$next['version_id'], $version], array_map('intval', array_column($history, 'document_version_id')));
        $this->documents->archive($this->document, $next['lock_version'] + 1, 'duplicate', 'archive-evidence-document');
        self::assertSame($history, $this->documents->evidenceHistory($this->document));
    }

    public function testEvidenceRequiresScopeAndAuthenticatedStoredBytesBeforePublishingReview(): void
    {
        $open = ['outcome' => 'open', 'reason' => 'form_unresolved', 'original_kind' => 'unknown', 'paper_status' => 'unknown'];
        $empty = $this->documents->addVersion($this->document, 0, 'metadata_recorded', 'empty-evidence-source');
        $this->failure(PersonnelFileUnavailable::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, $empty['version_id'], $empty['lock_version'], $open, 'empty-evidence-review'
        ));
        $this->failure(PDOException::class, fn () => $this->connection()->execute(
            'INSERT INTO employee_document_evidence_reviews (document_id, document_version_id, actor_user_id, `procedure`,
                procedure_version, outcome, original_kind, paper_status, signature_status, reason, legible, matches_record, created_at)
             VALUES (:document, :version, :actor, "manual_visual", 1, "open", "unknown", "unknown", "unresolved", "form_unresolved", 0, 0, UTC_TIMESTAMP())',
            ['document' => $this->document, 'version' => $empty['version_id'], 'actor' => $this->actor]
        ));
        $stored = Fixture::upload($this->documents, $this->document, 1, Fixture::png(), 'scope-evidence-source');
        $viewer = $this->createUser();
        $this->giveRights($viewer, [PersonnelAccessService::VIEW]);
        $this->scope($viewer, true);
        Fixture::login($this->connection(), $viewer);
        $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, $stored['version_id'], $stored['lock_version'], $open, 'view-only-review'
        ));
        self::assertSame([], $this->documents->evidenceHistory($this->document));
        $this->scope($viewer, false);
        $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->evidenceHistory($this->document));
        self::assertSame(0, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(*) FROM employee_document_access_log WHERE outcome = "denied" AND (employee_user_id IS NOT NULL OR document_id IS NOT NULL OR document_version_id IS NOT NULL)'
        ));

        Fixture::login($this->connection(), $this->actor);
        $this->failure(PersonnelAccessDenied::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, 999999, $stored['lock_version'], $open, 'wrong-evidence-version'
        ));
        $cipherPath = $this->testPathFor($this->versionRow($stored['version_id'])['storage_key']);
        self::assertNotFalse(file_put_contents($cipherPath, 'synthetic corrupted object'));
        $this->failure(PersonnelFileUnavailable::class, fn () => $this->documents->recordEvidenceReview(
            $this->document, $stored['version_id'], $stored['lock_version'], $open, 'corrupt-evidence-review'
        ));
        self::assertSame([], $this->documents->evidenceHistory($this->document));
        self::assertSame($stored['lock_version'], (int) $this->documents->get($this->document)['lock_version']);
    }

    private function versionRow(int $id): array
    {
        return $this->connection()->fetchOne('SELECT * FROM employee_document_versions WHERE id = :id', ['id' => $id]);
    }

    private function testPathFor(string $key): string
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if ($entry->isFile() && str_starts_with($entry->getFilename(), $key)) {
                return $entry->getPathname();
            }
        }
        self::fail('Expected generated file in disposable storage.');
    }

    private function giveRights(int $user, array $rights, bool $isAdministrator = false): int
    {
        $this->connection()->execute('INSERT INTO roles (slug, name, is_system_role) VALUES (:slug, "Synthetic role", :system)',
            ['slug' => $isAdministrator ? 'administrator' : 'file-test-' . $user, 'system' => (int) $isAdministrator]);
        $role = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)', ['user' => $user, 'role' => $role]);
        foreach ($rights as $right) {
            $this->connection()->execute('INSERT INTO permissions (code, label, scope) VALUES (:code, "Synthetic", "global") ON DUPLICATE KEY UPDATE code = VALUES(code)', ['code' => $right]);
            $this->connection()->execute('INSERT INTO role_permissions (permission_id, role_id) SELECT id, :role FROM permissions WHERE code = :code', ['role' => $role, 'code' => $right]);
        }

        return $role;
    }

    private function scope(int $user, bool $isGranted): void
    {
        $this->connection()->execute('INSERT INTO employee_personnel_scope_events (employee_user_id, grantee_user_id, changed_by_user_id, is_granted, reason_code)
            VALUES (:employee, :grantee, :actor, :grant, "test")', ['employee' => $this->employee, 'grantee' => $user, 'actor' => $this->actor, 'grant' => (int) $isGranted]);
    }

    private function failure(string $exceptionClass, callable $action): string
    {
        try {
            $action();
        } catch (Throwable $exception) {
            self::assertInstanceOf($exceptionClass, $exception);

            return $exception->getMessage();
        }
        self::fail('Expected ' . $exceptionClass);
    }

    private function startUploadWorker(string $request): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/personnel-file-upload-worker.php')],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode(['connection' => $this->connectionConfig(), 'actor' => $this->actor,
            'document' => $this->document, 'root' => $this->root, 'request' => $request], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 12);

        return [$process, $pipes[1], $pipes[2]];
    }
}
