<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Projects\ProjectAccessDeniedException;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectMaterialConflictException;
use App\Domain\Projects\ProjectMaterialService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Http\Controllers\AppProjectMaterialController;
use App\Http\Request;
use App\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Phinx\Db\Adapter\MysqlAdapter;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\MariaDbTestCase;

final class ProjectMaterialRequestsDatabaseTest extends MariaDbTestCase
{
    public function testRepeatedAndArchivedRequestsKeepExactlyOneImmutableBooking(): void
    {
        [$user, $project, $service] = $this->fixture();
        $first = $service->createWithResult($this->actor($user), $project, $this->payload());
        $before = $this->connection()->fetchAll('SELECT * FROM project_material_entries');
        $repeat = $service->createWithResult($this->actor($user), $project, $this->payload(['quantity' => '02.000']));
        self::assertFalse($first['replayed']);
        self::assertTrue($repeat['replayed']);
        self::assertSame($first['entry'], $repeat['entry']);
        self::assertSame($before, $this->connection()->fetchAll('SELECT * FROM project_material_entries'));
        $service->archive($this->actor($user), $first['entry']['id']);
        $archived = $service->createWithResult($this->actor($user), $project, $this->payload());
        self::assertTrue($archived['replayed']);
        self::assertTrue($archived['entry']['is_deleted']);
        self::assertSame($first['entry']['id'], $archived['entry']['id']);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_material_entries'));
    }

    public function testChangedPayloadOrProjectCannotReuseAnAuthorsRequest(): void
    {
        [$user, $project, $service] = $this->fixture();
        $service->create($this->actor($user), $project, $this->payload());
        $otherProject = $this->createProject();
        $this->assign($otherProject, $user);
        foreach ([[$project, $this->payload(['note' => 'Andere Notiz'])], [$otherProject, $this->payload()]] as [$target, $payload]) {
            try {
                $service->create($this->actor($user), $target, $payload);
                self::fail('A stored request cannot be changed or moved to another project.');
            } catch (ProjectMaterialConflictException) {
                self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_material_entries'));
            }
        }
        $secondUser = $this->createUser();
        $this->assign($project, $secondUser);
        $own = $service->createWithResult($this->actor($secondUser), $project, $this->payload());
        self::assertFalse($own['replayed']);
        self::assertSame($secondUser, $own['entry']['created_by_user_id']);
    }

    public function testRevokedProjectAccessIsCheckedBeforeReturningAnyReplay(): void
    {
        [$user, $project, $service] = $this->fixture();
        $service->create($this->actor($user), $project, $this->payload());
        $this->connection()->execute('UPDATE project_memberships SET assigned_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE project_id = :project AND user_id = :user', ['project' => $project, 'user' => $user]);
        $this->expectException(ProjectAccessDeniedException::class);
        $service->createWithResult($this->actor($user), $project, $this->payload());
    }

    public function testLegacyRequestsAndInvalidKeysKeepTheirExistingValidation(): void
    {
        [$user, $project, $service] = $this->fixture();
        $legacy = $this->payload();
        unset($legacy['client_request_id']);
        $one = $service->create($this->actor($user), $project, $legacy);
        $two = $service->create($this->actor($user), $project, $legacy);
        self::assertNotSame($one['id'], $two['id']);
        foreach (['', null, [], 'not-a-uuid'] as $key) {
            try {
                $service->create($this->actor($user), $project, $this->payload(['client_request_id' => $key]));
                self::fail('Malformed request identifiers must not create bookings.');
            } catch (InvalidArgumentException) {
                self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_material_entries'));
            }
        }
    }

    public function testHttpResponseDistinguishesNewReplayConflictAndDeniedAccess(): void
    {
        [$user, $project, $service] = $this->fixture();
        $_SESSION['auth']['user_id'] = $user;
        $controller = new AppProjectMaterialController($service, new AuthService($this->connection(), new PermissionMatrix([], [])));
        $request = fn (array $payload): Request => new Request('POST', '/api/v1/app/projects/' . $project . '/materials', [], $payload, [], [], []);
        $first = $controller->store($request($this->payload()), ['id' => $project]);
        self::assertSame(201, $first->status());
        self::assertFalse($this->json($first)['replayed']);
        $replay = $controller->store($request($this->payload()), ['id' => $project]);
        self::assertSame(200, $replay->status());
        self::assertTrue($this->json($replay)['replayed']);
        $conflict = $controller->store($request($this->payload(['quantity' => '3'])), ['id' => $project]);
        self::assertSame(409, $conflict->status());
        self::assertSame('request_id_conflict', $this->json($conflict)['error']);
        $this->connection()->execute('UPDATE project_memberships SET assigned_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE project_id = :project AND user_id = :user', ['project' => $project, 'user' => $user]);
        self::assertSame(403, $controller->store($request($this->payload()), ['id' => $project])->status());
        $_SESSION = [];
        self::assertSame(401, $controller->store($request($this->payload()), ['id' => $project])->status());
    }

    public function testMaterialDateAndProjectCaptureAreIndependentAndLeaveTimeRecordsUntouched(): void
    {
        [$user, $sourceProject, $service] = $this->fixture();
        $targetProject = $this->createProject();
        $this->assign($targetProject, $user);
        $now = $this->berlinToday();
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $cutovers = new EmployeeAccountCutoverService($this->connection(), new AccountJournalService($this->connection()));
        $cutovers->finalize([
            'user_id' => $user,
            'effective_from' => $today,
            'opening_time_balance' => '+02:00',
            'leave_year' => (int) $now->format('Y'),
            'annual_leave_entitlement_days' => 30,
            'opening_remaining_leave_days' => 30,
        ], $this->createUser());
        $timesheet = $this->workEntry($user, $sourceProject, $today);
        $this->connection()->execute(
            'INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at) VALUES (:timesheet, :user, :started_at)',
            ['timesheet' => $timesheet, 'user' => $user, 'started_at' => $today . ' 10:00:00']
        );
        $account = new TimeAccountService($this->connection(), new CalendarPolicyService($this->connection()));
        $beforeAccount = $account->monthlyAccount($user, (int) $now->format('Y'), (int) $now->format('n'), $today, true);
        self::assertIsInt($beforeAccount['closing_balance_minutes']);
        self::assertSame(105, $beforeAccount['actual_work_minutes']);
        $beforeRows = $this->timeSnapshot($user);

        $cases = [
            [$sourceProject, $today, 'standard'],
            [$targetProject, $today, 'cross_project'],
            [$sourceProject, $yesterday, 'backdated'],
            [$targetProject, $yesterday, 'cross_project_backdated'],
        ];
        foreach ($cases as $index => [$projectId, $workDate, $type]) {
            $entry = $service->create($this->actor($user), $projectId, $this->payload([
                'client_request_id' => sprintf('12345678-1234-4234-8234-123456789ab%d', $index),
                'work_date' => $workDate,
                'capture_type' => 'standard',
                'captured_work_project_id' => $targetProject,
                'captured_timesheet_id' => $timesheet + 1,
                'created_by_user_id' => $user + 1,
                'created_at' => '2000-01-01 00:00:00',
            ]));
            self::assertSame($projectId, $entry['project_id']);
            self::assertSame($workDate, $entry['work_date']);
            self::assertSame($today, substr($entry['created_at'], 0, 10));
            self::assertSame($user, $entry['created_by_user_id']);
            self::assertSame('Test Mitarbeiter', $entry['created_by_name']);
            self::assertSame($type, $entry['capture_type']);
            self::assertSame($sourceProject, $entry['captured_work_project_id']);
            self::assertArrayNotHasKey('captured_timesheet_id', $entry);
            self::assertSame($timesheet, (int) $this->connection()->fetchColumn(
                'SELECT captured_timesheet_id FROM project_material_entries WHERE id = :id', ['id' => $entry['id']]
            ));
        }

        self::assertCount(2, $service->list($this->actor($user), $sourceProject));
        self::assertCount(2, $service->list($this->actor($user), $targetProject));
        self::assertSame($beforeRows, $this->timeSnapshot($user));
        self::assertSame($beforeAccount, $account->monthlyAccount($user, (int) $now->format('Y'), (int) $now->format('n'), $today, true));
    }

    public function testCapturedSourceProjectDetailsRequireCurrentAccessToThatProject(): void
    {
        [$user, $sourceProject, $service] = $this->fixture();
        $targetProject = $this->createProject();
        $colleague = $this->createUser();
        $this->assign($targetProject, $user);
        $this->assign($targetProject, $colleague);
        $this->workEntry($user, $sourceProject, $this->berlinToday()->format('Y-m-d'));
        $entry = $service->create($this->actor($user), $targetProject, $this->payload());
        self::assertSame($sourceProject, $entry['captured_work_project_id']);
        self::assertNotEmpty($entry['captured_work_project_name']);

        $_SESSION['auth']['user_id'] = $colleague;
        $controller = new AppProjectMaterialController($service, new AuthService($this->connection(), new PermissionMatrix([], [])));
        $response = $controller->index(new Request('GET', '/api/v1/app/projects/' . $targetProject . '/materials', [], [], [], [], []), ['id' => $targetProject]);
        $visible = $this->json($response)['data'][0];
        self::assertSame('cross_project', $visible['capture_type']);
        self::assertNull($visible['captured_work_project_id']);
        self::assertNull($visible['captured_work_project_name']);
        self::assertSame($entry, $service->list(['id' => $colleague, 'permissions' => ['projects.manage']], $targetProject)[0]);

        $this->connection()->execute('DELETE FROM project_memberships WHERE project_id = :project AND user_id = :user', ['project' => $sourceProject, 'user' => $user]);
        $replay = $service->createWithResult($this->actor($user), $targetProject, $this->payload());
        self::assertTrue($replay['replayed']);
        self::assertSame('cross_project', $replay['entry']['capture_type']);
        self::assertNull($replay['entry']['captured_work_project_id']);
        self::assertNull($replay['entry']['captured_work_project_name']);
    }

    public function testRetryAfterCheckOutAndNewCheckInKeepsFirstCaptureSnapshot(): void
    {
        [$user, $targetProject, $service] = $this->fixture();
        $sourceProject = $this->createProject();
        $this->assign($sourceProject, $user);
        $today = $this->berlinToday()->format('Y-m-d');
        $timesheet = $this->workEntry($user, $sourceProject, $today);
        $_SESSION['auth']['user_id'] = $user;
        $controller = new AppProjectMaterialController($service, new AuthService($this->connection(), new PermissionMatrix([], [])));
        $request = new Request('POST', '/api/v1/app/projects/' . $targetProject . '/materials', [], $this->payload(), [], [], []);

        // The first response is discarded to simulate a lost response.
        self::assertSame(201, $controller->store($request, ['id' => $targetProject])->status());
        $before = $this->connection()->fetchAll('SELECT * FROM project_material_entries');
        $this->connection()->execute('UPDATE timesheets SET end_time = "11:00:00" WHERE id = :id', ['id' => $timesheet]);
        $this->workEntry($user, $targetProject, $today);

        $replayed = $controller->store($request, ['id' => $targetProject]);
        self::assertSame(200, $replayed->status());
        $body = $this->json($replayed);
        self::assertTrue($body['replayed']);
        self::assertSame('cross_project', $body['data']['capture_type']);
        self::assertSame($sourceProject, $body['data']['captured_work_project_id']);
        self::assertSame($timesheet, (int) $before[0]['captured_timesheet_id']);
        self::assertSame($before, $this->connection()->fetchAll('SELECT * FROM project_material_entries'));
    }

    public function testConcurrentRequestsAreSerializedByTheDatabaseRequestKey(): void
    {
        [$user, $project] = $this->fixture();
        $sourceProject = $this->createProject();
        $this->assign($sourceProject, $user);
        $timesheet = $this->workEntry($user, $sourceProject, $this->berlinToday()->format('Y-m-d'));
        $file = tempnam(sys_get_temp_dir(), 'timeapp-material-requests-');
        $gate = $file . '.go';
        file_put_contents($file, json_encode($this->connectionConfig(), JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $workers = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/project-material-request-worker.php'), $file, (string) $user, (string) $project, $gate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                self::assertIsResource($process);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }
            for ($i = 0; $i < 500 && count(glob($gate . '.ready.*') ?: []) < 2; ++$i) { usleep(10000); }
            self::assertCount(2, glob($gate . '.ready.*') ?: []);
            file_put_contents($gate, 'go');
            $results = [];
            foreach ($workers as &$worker) {
                $output = stream_get_contents($worker['pipes'][1]);
                $error = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]); fclose($worker['pipes'][2]);
                $exit = proc_close($worker['process']);
                $worker['process'] = null;
                self::assertSame(0, $exit, $error ?: $output);
                $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            unset($worker);
            self::assertSame($results[0]['entry'], $results[1]['entry']);
            self::assertSame('cross_project', $results[0]['entry']['capture_type']);
            self::assertSame($sourceProject, $results[0]['entry']['captured_work_project_id']);
            self::assertSame($timesheet, (int) $this->connection()->fetchColumn('SELECT captured_timesheet_id FROM project_material_entries'));
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['replayed'])));
            self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_material_entries'));
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    foreach ($worker['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                    proc_close($worker['process']);
                }
            }
            @unlink($file); @unlink($gate);
            foreach (glob($gate . '.ready.*') ?: [] as $marker) { @unlink($marker); }
        }
    }

    public function testAdditiveMigrationAndRetryPreserveLegacyMaterialData(): void
    {
        require_once base_path('migrations/20261111220000_project_material_request_ids.php');
        $migration = new \ProjectMaterialRequestIds('test', 20261111220000, null, new NullOutput());
        $migration->setAdapter($this->phinxAdapter());
        $migration->down();
        [$user, $project, $service] = $this->fixture();
        $legacy = $this->payload(); unset($legacy['client_request_id']);
        $entry = $service->create($this->actor($user), $project, $legacy);
        $migration->up(); $migration->up();
        self::assertSame($entry, $service->list($this->actor($user), $project)[0]);
        self::assertNull($this->connection()->fetchColumn('SELECT client_request_id FROM project_material_entries WHERE id = :id', ['id' => $entry['id']]));
        $new = $service->createWithResult($this->actor($user), $project, $this->payload());
        self::assertFalse($new['replayed']);
        self::assertTrue($service->createWithResult($this->actor($user), $project, $this->payload())['replayed']);
    }

    public function testCaptureMigrationKeepsPreMigrationEntriesAndReplaysUnclassified(): void
    {
        require_once base_path('migrations/20261118120000_project_material_capture_context.php');
        $migration = new \ProjectMaterialCaptureContext('test', 20261118120000, null, new NullOutput());
        $migration->setAdapter($this->phinxAdapter());
        $migration->down();
        [$user, $project, $service] = $this->fixture();
        $workDate = $this->berlinToday()->modify('-1 day')->format('Y-m-d');
        $payload = $this->payload(['work_date' => $workDate]);
        $entry = $service->createWithResult($this->actor($user), $project, $payload)['entry'];
        self::assertNull($entry['capture_type']);
        self::assertTrue($service->createWithResult($this->actor($user), $project, $payload)['replayed']);

        $migration->up();
        $migration->up();

        self::assertSame($entry, $service->list($this->actor($user), $project)[0]);
        self::assertSame(['entry' => $entry, 'replayed' => true], $service->createWithResult($this->actor($user), $project, $payload));
        $new = $service->create($this->actor($user), $project, ['client_request_id' => '12345678-1234-4234-8234-123456789abe'] + $payload);
        self::assertSame('backdated', $new['capture_type']);
        self::assertNull($new['captured_work_project_id']);
    }

    public function testOnlyWorkTimeCarryoverCountsAsAnActivePriorDayBooking(): void
    {
        [$user, $project, $service] = $this->fixture();
        $otherProject = $this->createProject();
        $today = $this->berlinToday()->format('Y-m-d');
        $yesterday = $this->berlinToday()->modify('-1 day')->format('Y-m-d');
        $timesheet = $this->workEntry($user, $otherProject, $yesterday);
        $this->workEntry($user, $otherProject, $today, '09:00:00', '10:00:00');
        $this->workEntry($user, $otherProject, $today, '10:00:00', null, true);
        $this->workEntry($this->createUser(), $otherProject, $today);

        $entry = $service->create($this->actor($user), $project, $this->payload());

        self::assertSame('standard', $entry['capture_type']);
        self::assertNull($entry['captured_work_project_id']);
        self::assertNull($this->connection()->fetchColumn('SELECT captured_timesheet_id FROM project_material_entries WHERE id = :id', ['id' => $entry['id']]));

        $connection = $this->connection();
        $policy = new WorkTimePolicyService($connection, true);
        $template = $policy->createTemplate(['name' => 'Test Arbeitszeitfenster', 'policy' => [
            'windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']]),
        ]], $user);
        $assignment = $policy->assign([
            'user_id' => $user, 'policy_version_id' => $template['version']['id'],
            'effective_from' => $this->berlinToday()->modify('+1 day')->format('Y-m-d'),
        ], $user);
        $connection->execute('UPDATE employee_work_time_assignments SET effective_from = :start WHERE id = :id', ['start' => $yesterday, 'id' => $assignment['id']]);
        $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
        $service = new ProjectMaterialService($connection, new ProjectAccessService($connection), new WorkTimeEventService($connection, $policy, $sync));

        $carryover = $service->create($this->actor($user), $project, $this->payload(['client_request_id' => '12345678-1234-4234-8234-123456789abd']));
        self::assertSame('cross_project', $carryover['capture_type']);
        self::assertSame($timesheet, (int) $connection->fetchColumn('SELECT captured_timesheet_id FROM project_material_entries WHERE id = :id', ['id' => $carryover['id']]));
    }

    public function testImplicitMaterialDateKeepsFirstDayWhenRequestIsRepeatedLater(): void
    {
        [$user, $project, $service] = $this->fixture();
        $payload = $this->payload();
        unset($payload['work_date']);
        $entry = $service->create($this->actor($user), $project, $payload);
        $yesterday = $this->berlinToday()->modify('-1 day')->format('Y-m-d');
        $this->connection()->execute(
            'UPDATE project_material_entries SET work_date = :previous_date, created_at = :previous_time WHERE id = :id',
            ['previous_date' => $yesterday, 'previous_time' => $yesterday . ' 12:00:00', 'id' => $entry['id']]
        );

        $replayed = $service->createWithResult($this->actor($user), $project, $payload);
        self::assertTrue($replayed['replayed']);
        self::assertSame($entry['id'], $replayed['entry']['id']);
        self::assertSame($yesterday, $replayed['entry']['work_date']);
        self::assertSame('standard', $replayed['entry']['capture_type']);

        $this->expectException(ProjectMaterialConflictException::class);
        $service->createWithResult($this->actor($user), $project, $payload + ['work_date' => $this->berlinToday()->format('Y-m-d')]);
    }

    private function phinxAdapter(): MysqlAdapter
    {
        $adapter = new MysqlAdapter(['name' => $this->connectionConfig()['database']], null, new NullOutput());
        $adapter->setConnection($this->connection()->pdo());

        return $adapter;
    }

    private function fixture(): array
    {
        $user = $this->createUser();
        $project = $this->createProject();
        $this->assign($project, $user);
        return [$user, $project, new ProjectMaterialService($this->connection(), new ProjectAccessService($this->connection()))];
    }

    private function actor(int $user): array { return ['id' => $user, 'permissions' => []]; }

    private function assign(int $project, int $user): void
    {
        $this->connection()->execute('INSERT INTO project_memberships (project_id, user_id) VALUES (:project, :user)', ['project' => $project, 'user' => $user]);
    }

    private function berlinToday(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
    }

    private function workEntry(int $user, ?int $project, string $date, string $start = '08:00:00', ?string $end = null, bool $archived = false): int
    {
        $this->connection()->execute(
            'INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, entry_type, gross_minutes, break_minutes, net_minutes, is_deleted)
             VALUES (:user, :project, :date, :start, :end, "work", 120, 15, 105, :archived)',
            ['user' => $user, 'project' => $project, 'date' => $date, 'start' => $start, 'end' => $end, 'archived' => $archived ? 1 : 0]
        );

        return $this->connection()->lastInsertId();
    }

    private function timeSnapshot(int $user): array
    {
        return [
            'timesheets' => $this->connection()->fetchAll('SELECT *, row_start, row_end FROM timesheets FOR SYSTEM_TIME ALL WHERE user_id = :user ORDER BY id, row_start', ['user' => $user]),
            'pauses' => $this->connection()->fetchAll('SELECT * FROM timesheet_breaks WHERE user_id = :user ORDER BY id', ['user' => $user]),
            'audit' => $this->connection()->fetchAll('SELECT * FROM timesheet_change_log ORDER BY id'),
            'account' => $this->connection()->fetchAll('SELECT * FROM time_account_entries WHERE user_id = :user ORDER BY id', ['user' => $user]),
        ];
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['client_request_id' => '12345678-1234-4234-8234-123456789abc', 'work_date' => $this->berlinToday()->format('Y-m-d'), 'description' => 'Rohr', 'quantity' => '2', 'unit' => 'm', 'note' => 'Entwurf'];
    }

    private function json(Response $response): array
    {
        ob_start(); $response->send();
        return json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    }
}
