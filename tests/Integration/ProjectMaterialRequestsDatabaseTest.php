<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Projects\ProjectAccessDeniedException;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectMaterialConflictException;
use App\Domain\Projects\ProjectMaterialService;
use App\Domain\Users\PermissionMatrix;
use App\Http\Controllers\AppProjectMaterialController;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;
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

    public function testConcurrentRequestsAreSerializedByTheDatabaseRequestKey(): void
    {
        [$user, $project] = $this->fixture();
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
            self::assertSame($results[0]['entry']['id'], $results[1]['entry']['id']);
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
        $adapter = new \Phinx\Db\Adapter\MysqlAdapter(['name' => $this->connectionConfig()['database']], null, new \Symfony\Component\Console\Output\NullOutput());
        $adapter->setConnection($this->connection()->pdo());
        $migration = new \ProjectMaterialRequestIds('test', 20261111220000, null, new \Symfony\Component\Console\Output\NullOutput());
        $migration->setAdapter($adapter);
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

    private function payload(array $overrides = []): array
    {
        return $overrides + ['client_request_id' => '12345678-1234-4234-8234-123456789abc', 'work_date' => date('Y-m-d'), 'description' => 'Rohr', 'quantity' => '2', 'unit' => 'm', 'note' => 'Entwurf'];
    }

    private function json(Response $response): array
    {
        ob_start(); $response->send();
        return json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    }
}
