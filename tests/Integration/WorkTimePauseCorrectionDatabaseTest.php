<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\WorkTime\WorkTimePauseCorrectionService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class WorkTimePauseCorrectionDatabaseTest extends MariaDbTestCase
{
    public function testActualPauseEvidenceResolvesTheWindowWithoutChangingWorkOrAccountingValues(): void
    {
        [$actor, $user, $id, $date, $service, $preview] = $this->fixture();
        $before = $this->source($id);
        $details = $service->details($id);
        self::assertSame('needs_clarification', $details['calculation']['calculation_status']);
        self::assertSame(30, $details['stored_break_minutes']);
        self::assertSame(30.0, $details['missing_break_minutes']);
        $result = $service->document($id, $this->interval($date), $actor, 'Nach Rueckfrage tatsaechliche Mittagspause belegt', 'evidence-1', $details['source_token']);
        self::assertFalse($result['replayed']);
        self::assertSame($before, $this->source($id), 'Neither net values nor the system-versioned source revision may change.');
        $calculation = $preview->forUserPeriod($user, $date, $date);
        self::assertSame(503, $calculation['actual_work_minutes']);
        self::assertSame(450, $calculation['inside_window_minutes']);
        self::assertSame(53, $calculation['outside_pending_minutes']);
        $audit = $this->connection()->fetchOne('SELECT * FROM work_time_pause_corrections WHERE timesheet_id = :id', ['id' => $id]);
        self::assertSame($actor, (int) $audit['actor_user_id']);
        self::assertSame('needs_clarification', json_decode($audit['before_json'], true)['calculation']['calculation_status']);
        self::assertSame('pending', json_decode($audit['after_json'], true)['calculation']['calculation_status']);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheet_change_log WHERE timesheet_id = :id', ['id' => $id]));
        self::assertCount(1, $service->listForUser($user, $date, $date));
    }

    public function testRepeatIsIdempotentAndChangedReplayCannotReuseAnotherEvidenceRequest(): void
    {
        [$actor, , $id, $date, $service] = $this->fixture();
        $token = $service->details($id)['source_token'];
        $first = $service->document($id, $this->interval($date), $actor, 'Tatsaechlich genommene Pause bestaetigt', 'same-request', $token);
        $second = $service->document($id, $this->interval($date), $actor, 'Tatsaechlich genommene Pause bestaetigt', 'same-request', $token);
        self::assertTrue($second['replayed']);
        self::assertSame($first['audit_id'], $second['audit_id']);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]));
        $this->expectException(InvalidArgumentException::class);
        $service->document($id, $this->interval($date, '13:00', '13:30'), $actor, 'Tatsaechlich genommene Pause bestaetigt', 'same-request', $token);
    }

    public function testIncorrectDurationOutsideWorkAndOverlapNeverCreateEvidence(): void
    {
        [$actor, , $id, $date, $service] = $this->fixture();
        $before = $this->source($id);
        $token = $service->details($id)['source_token'];
        foreach ([$this->interval($date, '12:00', '12:15'), $this->interval($date, '07:00', '07:30'), [['start' => $date . ' 12:00', 'end' => $date . ' 12:20'], ['start' => $date . ' 12:10', 'end' => $date . ' 12:20']]] as $index => $intervals) {
            try {
                $service->document($id, $intervals, $actor, 'Tatsaechliche Pausen nachgeprueft', 'invalid-' . $index, $token);
                self::fail('Unsupported break evidence must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame($before, $this->source($id));
                self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]));
                self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_pause_corrections'));
            }
        }
    }

    public function testExistingBreaksRemainImmutableWhenMissingEvidenceIsAppended(): void
    {
        [$actor, $user, $id, $date, $service] = $this->fixture();
        $this->break($id, $user, $date . ' 12:00:00', $date . ' 12:15:00');
        $existing = $this->connection()->fetchOne('SELECT * FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]);
        $details = $service->details($id);
        self::assertSame(15.0, $details['missing_break_minutes']);
        $service->document($id, $this->interval($date, '12:30', '12:45'), $actor, 'Zweite tatsaechliche Pause belegt', 'append-only', $details['source_token']);
        self::assertSame($existing, $this->connection()->fetchOne('SELECT * FROM timesheet_breaks WHERE id = :id', ['id' => $existing['id']]));
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]));
    }

    public function testStaleSourceAndOpenBreakNeedFreshReviewWithoutChanges(): void
    {
        [$actor, $user, $id, $date, $service] = $this->fixture();
        $oldToken = $service->details($id)['source_token'];
        $this->break($id, $user, $date . ' 12:00:00', $date . ' 12:15:00');
        try {
            $service->document($id, $this->interval($date, '12:30', '12:45'), $actor, 'Weitere tatsaechliche Pause belegt', 'stale', $oldToken);
            self::fail('A changed source requires another review.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('inzwischen', $exception->getMessage());
        }
        $this->connection()->execute('UPDATE timesheet_breaks SET break_ended_at = NULL WHERE timesheet_id = :id', ['id' => $id]);
        $details = $service->details($id);
        self::assertFalse($details['can_document']);
        self::assertStringContainsString('noch offen', $details['blocked_reason']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_pause_corrections'));
    }

    public function testCustomerSignatureBlocksDocumentationAndRemainsUntouched(): void
    {
        [$actor, $user, $id, $date, $service] = $this->fixture();
        $this->connection()->execute('INSERT INTO timesheet_customer_signatures (timesheet_id, user_id, customer_name, confirmation_text, signature_storage_path, signature_sha256, signed_at, created_by_user_id) VALUES (:timesheet, :user_id, "Testkunde", "Zeiten bestaetigt", "protected/test-only.png", :hash, NOW(), :actor)', ['timesheet' => $id, 'user_id' => $user, 'hash' => str_repeat('a', 64), 'actor' => $user]);
        $signature = $this->connection()->fetchOne('SELECT * FROM timesheet_customer_signatures WHERE timesheet_id = :id', ['id' => $id]);
        $details = $service->details($id);
        self::assertFalse($details['can_document']);
        self::assertStringContainsString('Kundenbestaetigung', $details['blocked_reason']);
        try {
            $service->document($id, $this->interval($date), $actor, 'Pause trotz Unterschrift nachtragen', 'signed', $details['source_token']);
            self::fail('Signed work is protected.');
        } catch (InvalidArgumentException) {
            self::assertSame($signature, $this->connection()->fetchOne('SELECT * FROM timesheet_customer_signatures WHERE timesheet_id = :id', ['id' => $id]));
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]));
        }
    }

    public function testAccountingAndCutoverClosuresBlockAdditionalPauseEvidence(): void
    {
        [$actor, $user, $id, $date, $service] = $this->fixture();
        $token = $service->details($id)['source_token'];
        foreach (['manual', 'employee_account_cutover'] as $source) {
            $this->connection()->execute('INSERT INTO accounting_closures (closure_number, closure_type, status, period_start, period_end, user_id, source_type, source_id) VALUES (:number, "month", "final", :date_from, :date_to, :user_id, :source_type, 1)', ['number' => 'PAUSE-' . $source, 'date_from' => $date, 'date_to' => $date, 'user_id' => $user, 'source_type' => $source]);
            try {
                $service->document($id, $this->interval($date), $actor, 'Pause im geschlossenen Zeitraum', 'closure-' . $source, $token);
                self::fail('Closed work must not change.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($source === 'manual' ? 'festgeschrieben' : 'Stichtag', $exception->getMessage());
            }
            $this->connection()->execute('DELETE FROM accounting_closures WHERE closure_number = :number', ['number' => 'PAUSE-' . $source]);
        }
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_pause_corrections'));
    }

    public function testWriteReviewSeesNewSignatureEvenWhenTheTransactionHasAnOlderReadSnapshot(): void
    {
        [, $user, $id, , $service] = $this->fixture();
        $pdo = $this->connection()->pdo();
        $pdo->beginTransaction();
        try {
            self::assertTrue($service->details($id)['can_document']);
            $other = new \App\Infrastructure\Database\DatabaseConnection($this->connectionConfig());
            $other->transaction(function () use ($other, $id, $user): void {
                $other->fetchOne('SELECT id FROM timesheets WHERE id = :id FOR UPDATE', ['id' => $id]);
                $other->execute('INSERT INTO timesheet_customer_signatures (timesheet_id, user_id, customer_name, confirmation_text, signature_storage_path, signature_sha256, signed_at, created_by_user_id) VALUES (:timesheet, :user_id, "Testkunde", "Zwischenzeitlich bestaetigt", "protected/race-test-only.png", :hash, NOW(), :actor)', ['timesheet' => $id, 'user_id' => $user, 'hash' => str_repeat('b', 64), 'actor' => $user]);
            });
            self::assertFalse($service->details($id)['has_active_signature'], 'An ordinary repeatable read still has its earlier snapshot.');
            $review = $service->details($id, true);
            self::assertTrue($review['has_active_signature']);
            self::assertFalse($review['can_document']);
            self::assertStringContainsString('Kundenbestaetigung', $review['blocked_reason']);
        } finally {
            $pdo->rollBack();
        }
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]));
    }

    public function testFeatureOffAndUnassignedEmployeesHaveNoPauseCorrectionPath(): void
    {
        [$actor, , $id, $date] = $this->fixture();
        $policies = new WorkTimePolicyService($this->connection(), false);
        $service = new WorkTimePauseCorrectionService($this->connection(), $policies, new WorkTimePreviewService($this->connection(), $policies));
        self::assertSame([], $service->listForUser($actor, $date, $date));
        self::assertFalse($service->details($id)['can_document']);
        $this->expectException(InvalidArgumentException::class);
        $service->document($id, $this->interval($date), $actor, 'Tatsaechliche Pause dokumentiert', 'disabled', str_repeat('a', 64));
    }

    public function testAdminEndpointRequiresBothPermissionsEmployeeScopeCsrfAndActualAttestation(): void
    {
        [$actor, $user, $id, $date, $service, $preview, $policies] = $this->fixture();
        $auth = new \App\Domain\Auth\AuthService($this->connection(), new \App\Domain\Users\PermissionMatrix([], []));
        $csrf = new \App\Domain\Auth\CsrfService();
        $sync = new \App\Domain\Timesheets\AppTimesheetSyncService($this->connection(), new \App\Domain\Timesheets\TimesheetCalculator(), new \App\Domain\Settings\CompanySettingsService($this->connection(), []), new \App\Domain\Timesheets\WorkdayStateCalculator());
        $controller = new \App\Http\Controllers\AdminWorkTimeController(new \App\Presentation\Admin\AdminView('Test', 'http://localhost'), $auth, $csrf, new \App\Domain\Users\UserService($this->connection()), $policies, $preview, new \App\Domain\WorkTime\WorkTimeDeviationService($this->connection(), $policies), new \App\Domain\WorkTime\WorkTimeAccessService($policies), new \App\Domain\WorkTime\WorkTimeEventService($this->connection(), $policies, $sync), $service);
        $body = ['pause_start' => [$date . 'T12:00'], 'pause_end' => [$date . 'T12:30'], 'reason' => 'Tatsaechliche Mittagspause nach Rueckfrage', 'request_id' => 'http-evidence', 'source_token' => $service->details($id)['source_token'], 'actual_break_attestation' => '1', 'csrf_token' => $csrf->token()];
        $request = static fn (array $body): \App\Http\Request => new \App\Http\Request('POST', '/admin/work-time/timesheets/' . $id . '/pauses', [], $body, [], [], []);
        self::assertSame(403, $controller->documentPauses($request($body), ['id' => $id])->status());
        $_SESSION['auth']['user_id'] = $actor;
        $this->grant($actor, ['work_time_deviations.manage']);
        self::assertSame(403, $controller->documentPauses($request($body), ['id' => $id])->status());
        $this->grant($actor, ['timesheets.manage']);
        self::assertSame(403, $controller->documentPauses($request($body), ['id' => $id])->status(), 'A permission alone does not establish employee scope.');
        $assignment = $policies->listAssignments($user)[0];
        $this->connection()->execute('INSERT INTO employee_work_time_supervisors (assignment_id, user_id) VALUES (:assignment, :actor)', ['assignment' => $assignment['id'], 'actor' => $actor]);
        $withoutAttestation = $body;
        unset($withoutAttestation['actual_break_attestation']);
        self::assertStringContainsString('error=', $controller->documentPauses($request($withoutAttestation), ['id' => $id])->headers()['Location']);
        $invalidCsrf = $body;
        $invalidCsrf['csrf_token'] = 'invalid';
        self::assertStringContainsString('error=', $controller->documentPauses($request($invalidCsrf), ['id' => $id])->headers()['Location']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_pause_corrections'));
        $saved = $controller->documentPauses($request($body), ['id' => $id]);
        self::assertStringContainsString('saved=1', $saved->headers()['Location']);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_pause_corrections'));
    }

    private function fixture(): array
    {
        $actor = $this->createUser();
        $user = $this->createUser();
        $date = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $policies = new WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => 'Pausenfenster', 'policy' => ['windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']])]], $actor);
        $assignment = $policies->assign(['user_id' => $user, 'policy_version_id' => $template['version']['id'], 'effective_from' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d')], $actor);
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date_from WHERE id = :id', ['date_from' => $date, 'id' => $assignment['id']]);
        $this->connection()->execute('INSERT INTO timesheets (user_id, work_date, entry_type, source, start_time, end_time, gross_minutes, break_minutes, net_minutes) VALUES (:user_id, :work_date, "work", "app", "07:42:00", "16:35:00", 533, 30, 503)', ['user_id' => $user, 'work_date' => $date]);
        $id = $this->connection()->lastInsertId();
        $preview = new WorkTimePreviewService($this->connection(), $policies);
        return [$actor, $user, $id, $date, new WorkTimePauseCorrectionService($this->connection(), $policies, $preview), $preview, $policies];
    }

    private function interval(string $date, string $start = '12:00', string $end = '12:30'): array
    {
        return [['start' => $date . 'T' . $start, 'end' => $date . 'T' . $end]];
    }

    private function break(int $id, int $user, string $start, ?string $end): void
    {
        $this->connection()->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source) VALUES (:timesheet, :user_id, :starts_at, :ends_at, "app")', ['timesheet' => $id, 'user_id' => $user, 'starts_at' => $start, 'ends_at' => $end]);
    }

    private function source(int $id): array
    {
        return $this->connection()->fetchOne('SELECT *, row_start AS source_revision FROM timesheets WHERE id = :id', ['id' => $id]);
    }

    private function grant(int $user, array $permissions): void
    {
        $slug = 'pause-' . bin2hex(random_bytes(4));
        $this->connection()->execute('INSERT INTO roles (slug, name) VALUES (:slug, :name)', ['slug' => $slug, 'name' => $slug]);
        $role = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', ['user_id' => $user, 'role_id' => $role]);
        foreach ($permissions as $permission) {
            $this->connection()->execute('INSERT INTO permissions (code, label, scope) VALUES (:code, :label, "timesheets") ON DUPLICATE KEY UPDATE code = VALUES(code)', ['code' => $permission, 'label' => $permission]);
            $permissionId = $this->connection()->fetchColumn('SELECT id FROM permissions WHERE code = :code', ['code' => $permission]);
            $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)', ['role_id' => $role, 'permission_id' => $permissionId]);
        }
    }
}
