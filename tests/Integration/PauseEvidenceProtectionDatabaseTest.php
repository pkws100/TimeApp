<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetSignatureService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\WorkTimeDeviationService;
use App\Domain\WorkTime\WorkTimePauseCorrectionService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePresenceService;
use App\Domain\WorkTime\WorkTimePreviewService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class PauseEvidenceProtectionDatabaseTest extends MariaDbTestCase
{
    public function testLegacyManualPauseCannotDeleteDocumentedEvidenceOrPartiallyWriteTheTimesheet(): void
    {
        [$actor, $user, $id, $date] = $this->evidenceFixture();
        $before = $this->snapshot($id);
        $blocked = false;
        try {
            $this->sync()->sync($user, ['client_request_id' => 'protected-legacy-pause', 'action' => 'pause', 'work_date' => $date, 'manual_break_minutes' => 30]);
        } catch (InvalidArgumentException|RuntimeException $error) {
            $blocked = true;
            self::assertStringContainsString('Pausennachweis', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($id), 'The old pause sync must preserve actual evidence, its source, audit and source revision.');
        self::assertTrue($blocked, 'A manual duration cannot replace documented actual pause intervals.');
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM app_sync_operations WHERE client_request_id = "protected-legacy-pause"'));
    }

    public function testAppAndTerminalCorrectionsCannotMoveAttestedWorkOrChangeItsDuration(): void
    {
        [, $user, $id, $date] = $this->evidenceFixture();
        $before = $this->snapshot($id);
        foreach ([
            ['action' => 'upsert', 'start_time' => '07:30'],
            ['action' => 'upsert', 'end_time' => '16:45'],
            ['action' => 'check_out', 'end_time' => '16:45'],
            ['action' => 'pause', 'manual_break_minutes' => 60],
            ['action' => 'upsert', 'source' => 'terminal'],
        ] as $index => $change) {
            $this->assertEvidenceRejected(fn () => $this->sync()->sync($user, $change + ['client_request_id' => 'protected-app-' . $index, 'work_date' => $date]));
            self::assertSame($before, $this->snapshot($id));
        }
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM app_sync_operations'));
    }

    public function testEnabledWindowWorkflowAlsoRejectsTheLegacyPauseReplacement(): void
    {
        [, $user, $id, $date, , $policies] = $this->evidenceFixture();
        $before = $this->snapshot($id);
        $sync = $this->sync();
        $sync->setWorkTimePresenceService(new WorkTimePresenceService($this->connection(), $policies));
        $this->assertEvidenceRejected(fn () => $sync->sync($user, ['client_request_id' => 'protected-enabled', 'action' => 'pause', 'work_date' => $date, 'manual_break_minutes' => 30, 'expected_timesheet_id' => $id]));
        self::assertSame($before, $this->snapshot($id));
    }

    public function testLegacyAdminWaitingForAccountingSeesEvidenceCommittedBeforeItsWrite(): void
    {
        [$actor, , $id, $date, $preview, $policies] = $this->evidenceFixture(false);
        $file = tempnam(sys_get_temp_dir(), 'timeapp-pause-evidence-');
        $ready = $file . '.ready';
        file_put_contents($file, json_encode($this->connectionConfig(), JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT GET_LOCK("accounting-timesheet-write", 10)'));
        $locked = true;
        $process = proc_open([PHP_BINARY, base_path('tests/Support/pause-evidence-worker.php'), $file, $ready, (string) $id, (string) $actor], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        self::assertIsResource($process);
        try {
            for ($index = 0; $index < 500 && !is_file($ready); ++$index) { usleep(10000); }
            self::assertFileExists($ready);
            $connectionId = (int) file_get_contents($ready);
            $state = null;
            for ($index = 0; $index < 500; ++$index) {
                $state = $this->connection()->fetchColumn('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID = :connection_id', ['connection_id' => $connectionId]);
                if ($state === 'User lock') { break; }
                usleep(10000);
            }
            self::assertSame('User lock', $state, 'The legacy admin must have read its old source and now wait for accounting.');
            $corrections = new WorkTimePauseCorrectionService($this->connection(), $policies, $preview);
            $details = $corrections->details($id);
            $corrections->document($id, [['start' => $date . 'T12:00', 'end' => $date . 'T12:30']], $actor, 'Pausennachweis vor dem wartenden alten Schreibweg', 'concurrent-evidence', $details['source_token']);
            $before = $this->snapshot($id);
            $this->connection()->fetchColumn('SELECT RELEASE_LOCK("accounting-timesheet-write")');
            $locked = false;
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error ?: $output); $process = null;
            $response = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('blocked', $response['status']);
            self::assertStringContainsString('Pausennachweis', $response['message']);
            self::assertSame($before, $this->snapshot($id));
        } finally {
            if ($locked) { $this->connection()->fetchColumn('SELECT RELEASE_LOCK("accounting-timesheet-write")'); }
            if (is_resource($process)) { proc_terminate($process, 9); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
            @unlink($file); @unlink($ready);
        }
    }

    public function testAdminChangesToPauseDateAndStampsAreRejectedWithoutAuditOrSourceWrites(): void
    {
        [$actor, , $id, $date] = $this->evidenceFixture();
        $before = $this->snapshot($id);
        $admin = new AdminBookingService($this->connection(), new TimesheetCalculator());
        foreach ([
            ['break_minutes' => 60],
            ['start_time' => '07:30'],
            ['end_time' => '16:45'],
            ['work_date' => (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d')],
        ] as $change) {
            $this->assertEvidenceRejected(fn () => $admin->update($id, $change, $actor, 'Versuch ueber alten Admin-Buchungsweg'));
            self::assertSame($before, $this->snapshot($id));
        }
    }

    public function testRejectedLegacyWriteDoesNotSupersedeApprovedOutsideWork(): void
    {
        [$actor, $user, $id, $date, $preview, $policies] = $this->evidenceFixture();
        $preview->reconcileTimesheet($id);
        $deviationId = (int) $this->connection()->fetchColumn('SELECT id FROM work_time_deviations WHERE timesheet_id = :id ORDER BY id LIMIT 1', ['id' => $id]);
        (new WorkTimeDeviationService($this->connection(), $policies))->decide($deviationId, 'approved', $actor, 'Arbeitsbeginn ausdruecklich beauftragt.', 'evidence-approved');
        $before = $this->snapshot($id);
        $this->assertEvidenceRejected(fn () => $this->sync()->sync($user, ['client_request_id' => 'protected-approved', 'action' => 'pause', 'work_date' => $date, 'manual_break_minutes' => 30]));
        self::assertSame($before, $this->snapshot($id));
        self::assertSame('approved', $this->connection()->fetchColumn('SELECT status FROM work_time_deviations WHERE id = :id', ['id' => $deviationId]));
    }

    public function testEvidenceAuditStillProtectsARecordWhoseIntervalsWereLostByAnOldVersion(): void
    {
        [, $user, $id, $date] = $this->evidenceFixture();
        // Reproduce the durable state left by the old destructive writer. The
        // immutable correction audit is retained and must still mark protection.
        $this->connection()->execute('DELETE FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]);
        $before = $this->snapshot($id);
        $this->assertEvidenceRejected(fn () => $this->sync()->sync($user, ['client_request_id' => 'protected-missing-interval', 'action' => 'pause', 'work_date' => $date, 'manual_break_minutes' => 60]));
        self::assertSame($before, $this->snapshot($id));
    }

    public function testLegacyEvidenceMarkerIsProtectedEvenWithoutANewCorrectionAudit(): void
    {
        [$actor, , $id, $date] = $this->evidenceFixture(false);
        $this->connection()->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source) SELECT id, user_id, :pause_start, :pause_end, "admin_evidence" FROM timesheets WHERE id = :id', ['pause_start' => $date . ' 12:00:00', 'pause_end' => $date . ' 12:30:00', 'id' => $id]);
        $before = $this->snapshot($id);
        $this->assertEvidenceRejected(fn () => (new AdminBookingService($this->connection(), new TimesheetCalculator()))->update($id, ['break_minutes' => 60], $actor, 'Importierter Nachweis bleibt geschuetzt'));
        self::assertSame($before, $this->snapshot($id));
    }

    public function testRejectedChangesCannotArchiveAnExistingCustomerConfirmation(): void
    {
        [$actor, $user, $id, $date] = $this->evidenceFixture();
        $this->connection()->execute('INSERT INTO timesheet_customer_signatures (timesheet_id, user_id, customer_name, confirmation_text, signature_storage_path, signature_sha256, signed_at, created_by_user_id) VALUES (:timesheet, :user_id, "Synthetic customer", "Zeiten bestaetigt", "protected/test-only.png", :hash, NOW(), :actor)', ['timesheet' => $id, 'user_id' => $user, 'hash' => str_repeat('a', 64), 'actor' => $user]);
        $signatureService = new TimesheetSignatureService($this->connection(), [], 'test-only-key');
        $before = $this->snapshot($id);
        $this->assertEvidenceRejected(fn () => (new AdminBookingService($this->connection(), new TimesheetCalculator(), $signatureService))->update($id, ['break_minutes' => 60], $actor, 'Signierter Nachweis darf nicht umgangen werden'));
        $sync = new AppTimesheetSyncService($this->connection(), new TimesheetCalculator(), new CompanySettingsService($this->connection(), []), new WorkdayStateCalculator(), $signatureService);
        $this->assertEvidenceRejected(fn () => $sync->sync($user, ['client_request_id' => 'protected-signed', 'action' => 'upsert', 'work_date' => $date, 'start_time' => '07:30']));
        self::assertSame($before, $this->snapshot($id));
    }

    public function testAccountingAndEmployeeCutoverLocksStillTakePrecedence(): void
    {
        [$actor, $user, $id, $date] = $this->evidenceFixture();
        foreach ([null, 'employee_account_cutover'] as $index => $sourceType) {
            $this->connection()->execute('INSERT INTO accounting_closures (closure_number, closure_type, status, period_start, period_end, user_id, snapshot_hash, item_count, total_net_minutes, source_type) VALUES (:number, "month", "final", :date_from, :date_to, :user_id, "test", 1, 503, :source_type)', ['number' => 'PROTECTED-CLOSURE-' . $index, 'date_from' => $date, 'date_to' => $date, 'user_id' => $user, 'source_type' => $sourceType]);
            $before = $this->snapshot($id);
            try {
                (new AdminBookingService($this->connection(), new TimesheetCalculator()))->update($id, ['break_minutes' => 60], $actor, 'Die Periodensperre bleibt massgeblich');
                self::fail('Accounting and cutover locks must still block the old writer.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString($sourceType === null ? 'festgeschrieben' : 'Stichtag', $error->getMessage());
            }
            try {
                $this->sync()->sync($user, ['client_request_id' => 'protected-closure-' . $index, 'action' => 'pause', 'work_date' => $date, 'manual_break_minutes' => 30]);
                self::fail('App cannot bypass accounting closures.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('festgeschrieben', $error->getMessage());
            }
            self::assertSame($before, $this->snapshot($id));
        }
    }

    public function testMetadataAndRevisableArchiveRestoreKeepEvidenceAndOriginalWorkIntact(): void
    {
        [$actor, , $id] = $this->evidenceFixture();
        $before = $this->snapshot($id);
        $admin = new AdminBookingService($this->connection(), new TimesheetCalculator());
        $admin->update($id, ['note' => 'Zusaetzlicher Hinweis ohne Aenderung der Arbeitszeit'], $actor, 'Nur Notiz ergaenzen');
        $admin->archive($id, $actor, 'Begruendete Archivierung mit historischem Nachweis');
        $admin->restore($id, $actor, 'Unveraenderten Nachweis wieder sichtbar machen');
        $after = $this->snapshot($id);
        self::assertSame($before['breaks'], $after['breaks']);
        self::assertSame($before['evidence'], $after['evidence']);
        foreach (['id', 'user_id', 'work_date', 'start_time', 'end_time', 'gross_minutes', 'break_minutes', 'net_minutes', 'source'] as $field) {
            self::assertSame($before['timesheet'][$field], $after['timesheet'][$field]);
        }
        self::assertSame(0, (int) $after['timesheet']['is_deleted']);
        self::assertCount(count($before['changes']) + 3, $after['changes'], json_encode(array_column($after['changes'], 'action_type')));
        self::assertGreaterThan(count($before['versions']), count($after['versions']));
    }

    public function testOrdinaryManualPauseRetainsTheExistingLegacyBehaviour(): void
    {
        [, $user, $id, $date] = $this->evidenceFixture(false);
        $this->connection()->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source) VALUES (:id, :user_id, :pause_start, :pause_end, "app")', ['id' => $id, 'user_id' => $user, 'pause_start' => $date . ' 12:00:00', 'pause_end' => $date . ' 12:30:00']);
        $result = $this->sync()->sync($user, ['client_request_id' => 'ordinary-manual-pause', 'action' => 'pause', 'work_date' => $date, 'manual_break_minutes' => 60]);
        self::assertSame('synced', $result['status']);
        self::assertSame(60, $result['timesheet']['break_minutes']);
        self::assertSame(473, $result['timesheet']['net_minutes']);
        self::assertSame('07:42:00', $result['timesheet']['start_time']);
        self::assertSame('16:35:00', $result['timesheet']['end_time']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]));
    }

    public function testAccountWriteLockExecutesAVoidConsumerOnlyOnceWhileBothLocksAreHeld(): void
    {
        $user = $this->createUser();
        $service = new EmployeeAccountCutoverService($this->connection(), new AccountJournalService($this->connection()));
        $calls = [];
        $service->withAccountWriteLocks($user, function () use ($user, &$calls): void {
            $calls[] = [
                'accounting' => (int) $this->connection()->fetchColumn('SELECT IS_USED_LOCK("accounting-timesheet-write") = CONNECTION_ID()'),
                'employee' => (int) $this->connection()->fetchColumn('SELECT IS_USED_LOCK(:lock_name) = CONNECTION_ID()', ['lock_name' => 'employee-account-cutover-' . $user]),
            ];
        });
        self::assertSame([['accounting' => 1, 'employee' => 1]], $calls, 'The void consumer must run once, never a second time after accounting was released.');
        self::assertNull($this->connection()->fetchColumn('SELECT IS_USED_LOCK("accounting-timesheet-write")'));
        self::assertNull($this->connection()->fetchColumn('SELECT IS_USED_LOCK(:lock_name)', ['lock_name' => 'employee-account-cutover-' . $user]));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM time_account_entries'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM vacation_account_entries'));
    }

    private function evidenceFixture(bool $document = true): array
    {
        $actor = $this->createUser();
        $user = $this->createUser();
        $date = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $policies = new WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => 'Protected pause evidence', 'policy' => ['windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']])]], $actor);
        $assignment = $policies->assign(['user_id' => $user, 'policy_version_id' => $template['version']['id'], 'effective_from' => (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')], $actor);
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date_from WHERE id = :id', ['date_from' => $date, 'id' => $assignment['id']]);
        $this->connection()->execute('INSERT INTO timesheets (user_id, work_date, entry_type, source, start_time, end_time, gross_minutes, break_minutes, net_minutes) VALUES (:user_id, :work_date, "work", "app", "07:42:00", "16:35:00", 533, 30, 503)', ['user_id' => $user, 'work_date' => $date]);
        $id = $this->connection()->lastInsertId();
        $preview = new WorkTimePreviewService($this->connection(), $policies);
        $corrections = new WorkTimePauseCorrectionService($this->connection(), $policies, $preview);
        $details = $corrections->details($id);
        if ($document) {
            $corrections->document($id, [['start' => $date . 'T12:00', 'end' => $date . 'T12:30']], $actor, 'Mitarbeiter bestaetigt die tatsaechliche Mittagspause.', 'evidence-' . $id, $details['source_token']);
        }
        return [$actor, $user, $id, $date, $preview, $policies];
    }

    private function sync(): AppTimesheetSyncService
    {
        // No enabled window integration: historical evidence must remain protected
        // even when the feature has subsequently been disabled.
        return new AppTimesheetSyncService($this->connection(), new TimesheetCalculator(), new CompanySettingsService($this->connection(), []), new WorkdayStateCalculator());
    }

    private function snapshot(int $id): array
    {
        return [
            'timesheet' => $this->connection()->fetchOne('SELECT *, row_start AS source_revision FROM timesheets WHERE id = :id', ['id' => $id]),
            'versions' => $this->connection()->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id ORDER BY row_start', ['id' => $id]),
            'breaks' => $this->connection()->fetchAll('SELECT * FROM timesheet_breaks WHERE timesheet_id = :id ORDER BY id', ['id' => $id]),
            'evidence' => $this->connection()->fetchAll('SELECT * FROM work_time_pause_corrections WHERE timesheet_id = :id ORDER BY id', ['id' => $id]),
            'changes' => $this->connection()->fetchAll('SELECT * FROM timesheet_change_log WHERE timesheet_id = :id ORDER BY id', ['id' => $id]),
            'signatures' => $this->connection()->fetchAll('SELECT * FROM timesheet_customer_signatures WHERE timesheet_id = :id ORDER BY id', ['id' => $id]),
            'deviations' => $this->connection()->fetchAll('SELECT * FROM work_time_deviations WHERE timesheet_id = :id ORDER BY id', ['id' => $id]),
            'decisions' => $this->connection()->fetchAll('SELECT d.* FROM work_time_deviation_decisions d JOIN work_time_deviations w ON w.id = d.deviation_id WHERE w.timesheet_id = :id ORDER BY d.id', ['id' => $id]),
            'queue' => $this->connection()->fetchAll('SELECT * FROM work_time_reconciliation_queue WHERE timesheet_id = :id', ['id' => $id]),
        ];
    }

    private function assertEvidenceRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Protected actual pause evidence must not be changed through a legacy writer.');
        } catch (InvalidArgumentException|RuntimeException $error) {
            self::assertStringContainsString('Pausennachweis', $error->getMessage());
        }
    }
}
