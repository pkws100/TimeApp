<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Backup\PdoBackupDatabaseSource;
use App\Domain\Projects\ProjectAccessDeniedException;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectMaterialService;
use App\Domain\Projects\ProjectWorkReportConflictException;
use App\Domain\Projects\ProjectWorkReportService;
use App\Domain\Users\PermissionMatrix;
use App\Http\Controllers\AppProjectWorkReportController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\MariaDbScratchConfig;
use Tests\Support\MariaDbTestCase;

final class ProjectWorkReportsDatabaseTest extends MariaDbTestCase
{
    public function testMigrationIsAdditiveAndBackupDiscoversReports(): void
    {
        $this->runPhinx('rollback', '20260724120000');
        self::assertFalse($this->connection()->tableExists('project_work_reports'));
        $userId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $userId);
        $material = (new ProjectMaterialService($this->connection(), new ProjectAccessService($this->connection())))
            ->create($this->actor($userId), $projectId, ['description' => 'Bestandsmaterial', 'quantity' => '2']);
        $projectBefore = $this->connection()->fetchOne('SELECT * FROM projects WHERE id = :id', ['id' => $projectId]);

        $this->runPhinx('migrate');
        $report = $this->service()->create($this->actor($userId), $projectId, $this->payload());
        $this->runPhinx('migrate');

        self::assertSame($projectBefore, $this->connection()->fetchOne('SELECT * FROM projects WHERE id = :id', ['id' => $projectId]));
        self::assertSame('Bestandsmaterial', $this->connection()->fetchColumn(
            'SELECT description FROM project_material_entries WHERE id = :id', ['id' => $material['id']]
        ));
        self::assertSame($report['entry']['id'], $this->service()->list($this->actor($userId), $projectId)[0]['id']);
        self::assertContains('project_work_reports', (new PdoBackupDatabaseSource($this->connection()))->applicationTables());
        self::assertSame(1, (int) $this->connection()->fetchColumn(
            'SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = "project_work_reports"
               AND index_name = "uniq_project_report_author_request" AND non_unique = 0'
        ));
        self::assertSame('RESTRICT', $this->connection()->fetchColumn(
            'SELECT delete_rule FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE() AND table_name = "project_work_reports"
               AND referenced_table_name = "projects"'
        ));
        self::assertSame('ERROR', strtoupper((string) $this->connection()->fetchColumn('SELECT @@SESSION.system_versioning_alter_history')));
    }

    public function testTeamCanReadMultipleReportsAndOnlyOwnerOrManagerCanArchive(): void
    {
        $ownerId = $this->createUser(['first_name' => 'Anna', 'last_name' => 'Bau']);
        $teamId = $this->createUser();
        $managerId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $ownerId);
        $this->assign($projectId, $teamId);
        $service = $this->service();
        $first = $service->create($this->actor($ownerId), $projectId, $this->payload());
        $second = $service->create($this->actor($ownerId), $projectId, $this->payload(['description' => 'Zweiter Bericht']));
        $items = $service->list($this->actor($teamId), $projectId);

        self::assertCount(2, $items);
        self::assertSame([$second['entry']['id'], $first['entry']['id']], array_column($items, 'id'));
        self::assertSame('Anna Bau', $items[0]['created_by_name']);
        self::assertSame($ownerId, $items[0]['created_by_user_id']);

        try {
            $service->archive($this->actor($teamId), $first['entry']['id']);
            self::fail('Andere Projektmitglieder duerfen fremde Arbeitsberichte nicht archivieren.');
        } catch (ProjectAccessDeniedException) {
            self::assertFalse($service->list($this->actor($teamId), $projectId)[0]['is_deleted']);
        }

        $service->archive($this->actor($ownerId), $first['entry']['id']);
        $service->archive($this->actor($managerId, ['projects.manage']), $second['entry']['id']);
        self::assertSame([], $service->list($this->actor($teamId), $projectId, 'all'));
        $history = $service->list($this->actor($managerId, ['projects.manage']), $projectId, 'all');
        self::assertCount(2, $history);
        self::assertTrue($history[0]['is_deleted']);
        self::assertNotNull($history[0]['deleted_at']);
        self::assertSame($managerId, $history[0]['deleted_by_user_id']);
        self::assertSame($ownerId, $history[1]['deleted_by_user_id']);
        self::assertSame('Leitungen montiert.', $history[1]['description']);
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_work_reports'));
    }

    public function testNormalizationAndIdempotencyKeepArchivedReportsAndSeparateAuthors(): void
    {
        $userId = $this->createUser();
        $otherId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $userId);
        $this->assign($projectId, $otherId);
        $payload = $this->payload(['description' => "  Leitungen\r\nmontiert.\r\n  "]);
        $service = $this->service();
        $created = $service->create($this->actor($userId), $projectId, $payload);
        $replayed = $service->create($this->actor($userId), $projectId, [
            ...$payload, 'description' => "Leitungen\nmontiert.", 'request_id' => strtoupper($payload['request_id']),
        ]);

        self::assertFalse($created['replayed']);
        self::assertTrue($replayed['replayed']);
        self::assertSame($created['entry'], $replayed['entry']);
        self::assertSame("Leitungen\nmontiert.", $created['entry']['description']);
        $service->archive($this->actor($userId), $created['entry']['id']);
        $archivedReplay = $service->create($this->actor($userId), $projectId, $payload);
        self::assertTrue($archivedReplay['replayed']);
        self::assertTrue($archivedReplay['entry']['is_deleted']);
        self::assertSame($created['entry']['id'], $archivedReplay['entry']['id']);
        $other = $service->create($this->actor($otherId), $projectId, $payload);
        self::assertFalse($other['replayed']);
        self::assertNotSame($created['entry']['id'], $other['entry']['id']);
    }

    public function testIdempotencyRejectsChangedDescriptionDateOrProjectWithoutChangingStoredReport(): void
    {
        $userId = $this->createUser();
        $projectId = $this->createProject();
        $otherProjectId = $this->createProject();
        $this->assign($projectId, $userId);
        $this->assign($otherProjectId, $userId);
        $payload = $this->payload();
        $service = $this->service();
        $service->create($this->actor($userId), $projectId, $payload);

        foreach ([
            [$projectId, [...$payload, 'description' => 'Geaenderter Entwurf']],
            [$projectId, [...$payload, 'work_date' => '2025-01-01']],
            [$otherProjectId, $payload],
        ] as [$target, $changed]) {
            try {
                $service->create($this->actor($userId), $target, $changed);
                self::fail('Abweichende Angaben unter derselben Anfrage-ID muessen abgewiesen werden.');
            } catch (ProjectWorkReportConflictException) {
                self::assertSame('Leitungen montiert.', $service->list($this->actor($userId), $projectId)[0]['description']);
            }
        }

        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_work_reports'));
    }

    public function testCurrentAccessIsRequiredBeforeIdempotencyReplayAndForEveryOperation(): void
    {
        $userId = $this->createUser();
        $outsiderId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $userId);
        $payload = $this->payload();
        $service = $this->service();
        $created = $service->create($this->actor($userId), $projectId, $payload);
        $this->connection()->execute(
            'UPDATE project_memberships SET assigned_until = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        foreach ([$userId, $outsiderId] as $actorId) {
            foreach ([
                fn () => $service->list($this->actor($actorId), $projectId),
                fn () => $service->create($this->actor($actorId), $projectId, $payload),
                fn () => $service->archive($this->actor($actorId), $created['entry']['id']),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Fehlende aktuelle Projektberechtigung muss jede Operation sperren.');
                } catch (ProjectAccessDeniedException) {
                    self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_work_reports WHERE is_deleted = 0'));
                }
            }
        }
    }

    public function testValidationUsesBerlinDateAndAcceptsTenThousandUnicodeCharacters(): void
    {
        $userId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $userId);
        $timezoneBefore = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');

        try {
            $payload = $this->payload(['description' => str_repeat('🔧', 10000)]);
            unset($payload['work_date']);
            $created = $this->service()->create($this->actor($userId), $projectId, $payload);
            self::assertSame($this->berlinToday(), $created['entry']['work_date']);
            self::assertSame(str_repeat('🔧', 10000), $created['entry']['description']);
        } finally {
            date_default_timezone_set($timezoneBefore);
        }

        $invalid = [
            ['description' => ' '], ['description' => []], ['description' => 123], ['description' => true],
            ['description' => str_repeat('ä', 10001)], ['description' => "\xFF"],
            ['work_date' => '2026-02-30'], ['work_date' => '2026-1-01'], ['work_date' => []], ['work_date' => '0000-01-01'],
            ['work_date' => "2026-01-01\0"], ['work_date' => ''],
            ['work_date' => (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')],
            ['request_id' => 'invalid'], ['request_id' => []], ['request_id' => null],
        ];

        foreach ($invalid as $override) {
            try {
                $this->service()->create($this->actor($userId), $projectId, $this->payload($override));
                self::fail('Ungueltige Arbeitsbericht-Daten wurden akzeptiert.');
            } catch (InvalidArgumentException) {
                self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_work_reports'));
            }
        }
    }

    public function testApiAndAdminShareEmployeeReportWithSessionAuthorAndEnforceGuardsAndCsrf(): void
    {
        $employeeId = $this->createUser(['first_name' => 'Lena', 'last_name' => 'Montage']);
        $teammateId = $this->createUser();
        $outsiderId = $this->createUser();
        $adminId = $this->createUser();
        $this->grantProjectManagement($adminId);
        $projectId = $this->createProject();
        $this->assign($projectId, $employeeId);
        $this->assign($projectId, $teammateId);
        $path = '/api/v1/app/projects/' . $projectId . '/work-reports';
        $payload = $this->payload(['description' => "<script>alert(1)</script>\nLeitungen montiert.", 'created_by_user_id' => $adminId]);

        $this->withRouter(function (Router $router) use ($employeeId, $teammateId, $outsiderId, $adminId, $projectId, $path, $payload): void {
            self::assertSame(401, $router->dispatch($this->request('POST', $path, $payload))->status());
            $_SESSION['auth']['user_id'] = $employeeId;
            $createdResponse = $router->dispatch($this->request('POST', $path, $payload));
            self::assertSame(201, $createdResponse->status());
            $created = $this->json($createdResponse);
            self::assertSame($employeeId, $created['data']['created_by_user_id']);
            $id = $created['data']['id'];
            $archivePath = '/admin/project-work-reports/' . $id . '/archive';
            self::assertSame(200, $router->dispatch($this->request('POST', $path, $payload))->status());
            $conflict = $router->dispatch($this->request('POST', $path, [...$payload, 'description' => 'Neuer Inhalt']));
            self::assertSame(409, $conflict->status());
            self::assertSame('request_id_conflict', $this->json($conflict)['code']);
            self::assertSame(422, $router->dispatch($this->request('POST', $path, $this->payload(['description' => ''])))->status());
            self::assertSame(403, $router->dispatch($this->request('POST', $archivePath))->status());

            $_SESSION['auth']['user_id'] = $teammateId;
            self::assertSame($id, $this->json($router->dispatch($this->request('GET', $path)))['data'][0]['id']);
            self::assertSame(403, $router->dispatch($this->request('DELETE', '/api/v1/app/project-work-reports/' . $id))->status());
            $_SESSION['auth']['user_id'] = $outsiderId;
            self::assertSame(403, $router->dispatch($this->request('GET', $path))->status());
            self::assertSame(403, $router->dispatch($this->request('POST', $path, $payload))->status());

            $_SESSION['auth']['user_id'] = $adminId;
            $adminPage = $router->dispatch($this->request('GET', '/admin/projects/' . $projectId . '/edit'));
            self::assertSame(200, $adminPage->status());
            $html = $this->content($adminPage);
            self::assertStringContainsString('id="project-work-reports"', $html);
            self::assertStringContainsString('<h2>Ausgeführte Arbeiten</h2>', $html);
            self::assertStringContainsString('Lena Montage', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<br', $html);
            self::assertStringNotContainsString('<script>alert(1)</script>', $html);
            self::assertStringContainsString('action="' . $archivePath . '"', $html);
            self::assertStringContainsString($payload['work_date'], $html);

            $csrf = (string) $_SESSION['_csrf_token'];
            $rejected = $router->dispatch($this->request('POST', $archivePath, ['csrf_token' => 'expired']));
            self::assertSame('/admin/projects?error=csrf', $rejected->headers()['Location']);
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT is_deleted FROM project_work_reports WHERE id = :id', ['id' => $id]));
            $archived = $router->dispatch($this->request('POST', $archivePath, ['csrf_token' => $csrf]));
            self::assertStringContainsString('notice=work-report-archived#project-work-reports', $archived->headers()['Location']);
            self::assertSame($adminId, (int) $this->connection()->fetchColumn('SELECT deleted_by_user_id FROM project_work_reports WHERE id = :id', ['id' => $id]));
            $history = $this->content($router->dispatch($this->request('GET', '/admin/projects/' . $projectId . '/edit')));
            self::assertStringContainsString('Lena Montage', $history);
            self::assertStringNotContainsString('action="' . $archivePath . '"', $history);

            $_SESSION['auth']['user_id'] = $employeeId;
            self::assertSame([], $this->json($router->dispatch($this->request('GET', $path)))['data']);
            $replayed = $this->json($router->dispatch($this->request('POST', $path, $payload)));
            self::assertTrue($replayed['replayed']);
            self::assertTrue($replayed['data']['is_deleted']);
            $own = $this->json($router->dispatch($this->request('POST', $path, $this->payload())));
            self::assertSame(200, $router->dispatch($this->request('DELETE', '/api/v1/app/project-work-reports/' . $own['data']['id']))->status());
        });
    }

    public function testControllerRequiresSessionForAllOperations(): void
    {
        $controller = new AppProjectWorkReportController($this->service(), new AuthService($this->connection(), new PermissionMatrix([], [])));

        foreach (['index', 'store', 'archive'] as $method) {
            self::assertSame(401, $controller->$method($this->request('GET', '/'), ['id' => 1])->status());
        }
    }

    public function testConcurrentIdenticalRequestsCreateOnlyOneReport(): void
    {
        $userId = $this->createUser();
        $projectId = $this->createProject();
        $this->assign($projectId, $userId);
        $input = ['config' => $this->connectionConfig(), 'actor' => $this->actor($userId), 'project_id' => $projectId, 'payload' => $this->payload()];
        $script = <<<'PHP'
require 'bootstrap/autoload.php';
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$connection = new App\Infrastructure\Database\DatabaseConnection($input['config']);
if (!$connection->isAvailable()) { throw new RuntimeException('Scratch-Verbindung fehlgeschlagen.'); }
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);
$service = new App\Domain\Projects\ProjectWorkReportService($connection, new App\Domain\Projects\ProjectAccessService($connection));
fwrite(STDOUT, json_encode($service->create($input['actor'], $input['project_id'], $input['payload']), JSON_THROW_ON_ERROR));
PHP;
        $workers = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());

                if (!is_resource($process)) {
                    throw new RuntimeException('Paralleler Scratch-Testprozess konnte nicht gestartet werden.');
                }

                $workers[] = ['process' => $process, 'pipes' => $pipes];
                stream_set_timeout($pipes[1], 15);
                fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR) . "\n");
                fflush($pipes[0]);
            }

            foreach ($workers as $worker) {
                self::assertSame("READY\n", fgets($worker['pipes'][1]));
            }

            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "GO\n");
                fflush($worker['pipes'][0]);
            }

            $results = [];

            foreach ($workers as $worker) {
                $output = stream_get_contents($worker['pipes'][1]);
                self::assertSame('', stream_get_contents($worker['pipes'][2]));
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }

            self::assertSame($results[0]['entry']['id'], $results[1]['entry']['id']);
            self::assertNotSame($results[0]['replayed'], $results[1]['replayed']);
            self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_work_reports'));
        } finally {
            foreach ($workers as $worker) {
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }

                if (proc_get_status($worker['process'])['running']) {
                    proc_terminate($worker['process']);
                }

                proc_close($worker['process']);
            }
        }
    }

    private function service(): ProjectWorkReportService
    {
        return new ProjectWorkReportService($this->connection(), new ProjectAccessService($this->connection()));
    }

    private function actor(int $userId, array $permissions = []): array
    {
        return ['id' => $userId, 'employment_status' => 'active', 'is_deleted' => 0, 'permissions' => $permissions];
    }

    private function assign(int $projectId, int $userId): void
    {
        $this->connection()->execute('INSERT INTO project_memberships (project_id, user_id) VALUES (:project_id, :user_id)', [
            'project_id' => $projectId, 'user_id' => $userId,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        $hex = bin2hex(random_bytes(16));
        $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);

        return $overrides + ['request_id' => $uuid, 'work_date' => $this->berlinToday(), 'description' => 'Leitungen montiert.'];
    }

    private function berlinToday(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }

    private function request(string $method, string $path, array $body = []): Request
    {
        return new Request($method, $path, [], $body, [], [], []);
    }

    private function content(Response $response): string
    {
        return (string) (new ReflectionProperty(Response::class, 'content'))->getValue($response);
    }

    private function json(Response $response): array
    {
        return json_decode($this->content($response), true, 512, JSON_THROW_ON_ERROR);
    }

    private function grantProjectManagement(int $userId): void
    {
        $this->connection()->execute('INSERT INTO roles (slug, name, is_system_role, created_at, updated_at, is_deleted) VALUES ("report-office", "Buero", 0, NOW(), NOW(), 0)');
        $roleId = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO permissions (code, label, scope, created_at) VALUES ("projects.manage", "Projekte verwalten", "backend", NOW())');
        $permissionId = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)', ['role_id' => $roleId, 'permission_id' => $permissionId]);
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', ['user_id' => $userId, 'role_id' => $roleId]);
    }

    private function withRouter(callable $callback): void
    {
        $override = tempnam(sys_get_temp_dir(), 'timeapp-report-routes-');

        if ($override === false) {
            throw new RuntimeException('Scratch-Override konnte nicht angelegt werden.');
        }

        chmod($override, 0600);
        file_put_contents($override, "<?php\nreturn " . var_export($this->connectionConfig(), true) . ";\n");
        $previous = getenv('DB_OVERRIDE_FILE');
        putenv('DB_OVERRIDE_FILE=' . $override);

        try {
            [, $router] = require base_path('bootstrap/app.php');
            $callback($router);
        } finally {
            $previous === false ? putenv('DB_OVERRIDE_FILE') : putenv('DB_OVERRIDE_FILE=' . $previous);
            unlink($override);
        }
    }

    private function runPhinx(string $command, ?string $target = null): void
    {
        $database = (string) $this->connectionConfig()['database'];
        $path = tempnam(sys_get_temp_dir(), 'timeapp-report-migration-');

        if ($path === false || !str_starts_with($database, 'timeapp_test_')) {
            throw new RuntimeException('Migrationstest benoetigt eine isolierte Scratch-Datenbank.');
        }

        chmod($path, 0600);
        file_put_contents($path, "<?php\nreturn " . var_export([
            'paths' => ['migrations' => base_path('migrations')],
            'environments' => ['default_migration_table' => 'phinxlog', 'default_environment' => 'test', 'test' => MariaDbScratchConfig::phinxEnvironment($database)],
            'version_order' => 'creation',
        ], true) . ";\n");

        try {
            $arguments = [PHP_BINARY, base_path('vendor/bin/phinx'), $command, '-c', $path, '-e', 'test'];

            if ($target !== null) {
                array_push($arguments, '-t', $target);
            }

            $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());

            if (!is_resource($process)) {
                throw new RuntimeException('Phinx-Testprozess konnte nicht gestartet werden.');
            }

            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $output);
        } finally {
            unlink($path);
        }
    }
}
