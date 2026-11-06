<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Projects\ProjectService;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class ProjectNumberSuggestionDatabaseTest extends MariaDbTestCase
{
    public function testEmptyDatabaseStartsAtFiveDigitsWithoutReservingTheSuggestion(): void
    {
        $service = $this->service();

        self::assertSame('00001', $service->suggestNextProjectNumber());
        self::assertSame('00001', $service->suggestNextProjectNumber());
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM projects'));

        $this->insertProject('00001');

        self::assertSame('00002', $service->suggestNextProjectNumber());
    }

    #[DataProvider('numberSeriesCases')]
    public function testSuggestionContinuesTheLatestNumberedSeriesUsingItsHighestSuffix(array $numbers, ?string $expected): void
    {
        foreach ($numbers as $number) {
            $this->insertProject($number);
        }

        $before = $this->connection()->fetchAll('SELECT * FROM projects ORDER BY id');
        self::assertSame($expected, $this->service()->suggestNextProjectNumber());
        self::assertSame($before, $this->connection()->fetchAll('SELECT * FROM projects ORDER BY id'));
    }

    public static function numberSeriesCases(): iterable
    {
        yield 'MA series and leading zeros' => [['MA00009'], 'MA00010'];
        yield 'pure numeric maximum instead of latest or gaps' => [['00009', '00100', '00042'], '00101'];
        yield 'year prefix is an independent series' => [['2025-99999', '2026-00004', '2026-00002'], '2026-00005'];
        yield 'latest numbered prefix wins despite larger older series' => [['ALT-99999', 'NEU-00007', 'Freie Nummer'], 'NEU-00008'];
        yield 'prefix comparison ignores ASCII case but preserves latest spelling' => [['MA00099', 'ma00002'], 'ma00100'];
        yield 'prefix comparison ignores Unicode case' => [['BÜRO-00099', 'büro-00002'], 'büro-00100'];
        yield 'latest width replaces older padding' => [['MA00127', 'MA7'], 'MA128'];
        yield 'latest width is retained when highest value has fewer digits' => [['MA999', 'MA000001'], 'MA001000'];
        yield 'only final digit run is incremented' => [['BAU42-X005'], 'BAU42-X006'];
        yield 'newer nonnumeric identifiers do not change the series' => [['MA00009', 'Projekt X', 'MA12X', 'NUM-１２', 'NUM-٢'], 'MA00010'];
        yield 'only nonnumeric identifiers use the initial fallback' => [['Projekt X', 'NUM-１２', 'NUM-٢'], '00001'];
        yield 'integers beyond floating point precision stay exact' => [['MA9007199254740993', 'MA9007199254740992'], 'MA9007199254740994'];
        yield 'integers beyond PHP integer range stay exact' => [
            ['MA123456789012345678901234567890', 'MA000000000000000000000000000001'],
            'MA123456789012345678901234567891',
        ];
        yield 'large carry uses string arithmetic' => [['MA199999999999999999999999999999'], 'MA200000000000000000000000000000'];
        yield 'zero suffix retains padding' => [['MA00000'], 'MA00001'];
        yield 'forty digit zero number fits the existing width' => [[str_repeat('0', 40)], str_repeat('0', 39) . '1'];
        yield 'exact forty character result remains valid' => [[str_repeat('A', 39) . '8'], str_repeat('A', 39) . '9'];
        yield 'Unicode prefixes count characters instead of bytes' => [[str_repeat('Ä', 39) . '8'], str_repeat('Ä', 39) . '9'];
        yield 'prefix overflow returns no suggestion' => [[str_repeat('A', 39) . '9'], null];
        yield 'numeric overflow returns no suggestion' => [[str_repeat('9', 40)], null];
        yield 'database collation collisions are not suggested' => [['Ä-00002', 'A-00001'], 'A-00003'];
    }

    public function testArchivedProjectsContributeToTheMaximumAndCanDefineTheLatestSeries(): void
    {
        $this->insertProject('MA00010');
        $this->insertProject('MA00030', true);
        $this->insertProject('MA00040', false, 'archived');
        $this->insertProject('MA00002');

        self::assertSame('MA00041', $this->service()->suggestNextProjectNumber());

        $this->insertProject('ALT00007', true, 'archived');

        self::assertSame('ALT00008', $this->service()->suggestNextProjectNumber());
    }

    public function testLatestSeriesUsesCreationIdRatherThanEditableTimestamps(): void
    {
        $olderId = $this->insertProject('ALT00099');
        $newerId = $this->insertProject('NEU00001');
        $this->connection()->execute('UPDATE projects SET created_at = :created_at, updated_at = :updated_at WHERE id = :id', [
            'created_at' => '2030-01-01 12:00:00', 'updated_at' => '2030-01-01 12:00:00', 'id' => $olderId,
        ]);
        $this->connection()->execute('UPDATE projects SET created_at = :created_at, updated_at = :updated_at WHERE id = :id', [
            'created_at' => '2020-01-01 12:00:00', 'updated_at' => '2020-01-01 12:00:00', 'id' => $newerId,
        ]);

        self::assertSame('NEU00002', $this->service()->suggestNextProjectNumber());
    }

    public function testAdminCreateRequiresAccessAndShowsAnEditableUnreservedSuggestion(): void
    {
        $this->insertProject('MA00009');
        $managerId = $this->createManager();
        $employeeId = $this->createUser();

        $this->withRouter(function (Router $router) use ($managerId, $employeeId): void {
            $anonymous = $router->dispatch($this->request('GET', '/admin/projects/create'));
            self::assertSame(302, $anonymous->status());
            self::assertStringStartsWith('/admin/login', $anonymous->headers()['Location']);
            $_SESSION['auth']['user_id'] = $employeeId;
            self::assertSame(403, $router->dispatch($this->request('GET', '/admin/projects/create'))->status());
            $_SESSION['auth']['user_id'] = $managerId;

            foreach ([1, 2] as $attempt) {
                $response = $router->dispatch($this->request('GET', '/admin/projects/create'));
                self::assertSame(200, $response->status(), 'Create attempt ' . $attempt);
                $input = $this->projectNumberInput($response);
                self::assertSame('MA00010', $input->getAttribute('value'));
                self::assertFalse($input->hasAttribute('readonly'));
                self::assertFalse($input->hasAttribute('disabled'));
                self::assertSame('40', $input->getAttribute('maxlength'));
            }

            self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM projects'));
        });
    }

    public function testAdminCreateAllowsAFreeManualNumberInsteadOfTheSuggestion(): void
    {
        $this->insertProject('MA00009');
        $managerId = $this->createManager();

        $this->withRouter(function (Router $router) use ($managerId): void {
            $_SESSION['auth']['user_id'] = $managerId;
            $router->dispatch($this->request('GET', '/admin/projects/create'));
            $response = $router->dispatch($this->request('POST', '/admin/projects', [
                'csrf_token' => $_SESSION['_csrf_token'], 'project_number' => 'WUNSCH-42', 'name' => 'Manuelles Projekt', 'status' => 'active',
            ]));

            self::assertSame(302, $response->status());
            self::assertStringContainsString('/edit?notice=created', $response->headers()['Location']);
            self::assertSame('Manuelles Projekt', $this->connection()->fetchColumn(
                'SELECT name FROM projects WHERE project_number = :project_number', ['project_number' => 'WUNSCH-42']
            ));
        });
    }

    public function testCreateValidationKeepsAStaleDuplicateOrManuallyEnteredNumber(): void
    {
        $this->insertProject('MA00009');
        $managerId = $this->createManager();

        $this->withRouter(function (Router $router) use ($managerId): void {
            $_SESSION['auth']['user_id'] = $managerId;
            $form = $router->dispatch($this->request('GET', '/admin/projects/create'));
            self::assertSame('MA00010', $this->projectNumberInput($form)->getAttribute('value'));
            $this->insertProject('MA00010'); // Another administrator used the displayed suggestion.
            $csrf = (string) $_SESSION['_csrf_token'];

            $duplicate = $router->dispatch($this->request('POST', '/admin/projects', [
                'csrf_token' => $csrf, 'project_number' => 'MA00010', 'name' => 'Noch nicht angelegtes Projekt', 'status' => 'active',
            ]));
            self::assertSame(422, $duplicate->status());
            self::assertSame('MA00010', $this->projectNumberInput($duplicate)->getAttribute('value'));
            self::assertSame('field-error-project_number', $this->projectNumberInput($duplicate)->getAttribute('aria-describedby'));
            self::assertSame(1, preg_match('/<input\b[^>]*\bname="project_number"[^>]*>/u', $this->content($duplicate), $numberTag));
            self::assertSame(1, substr_count($numberTag[0], 'aria-describedby='));
            self::assertStringContainsString('bereits vergeben', $this->content($duplicate));
            self::assertSame('MA00011', $this->service()->suggestNextProjectNumber());

            foreach (['WUNSCH-007', ''] as $number) {
                $invalid = $router->dispatch($this->request('POST', '/admin/projects', [
                    'csrf_token' => $csrf, 'project_number' => $number, 'name' => '', 'status' => 'active',
                ]));
                self::assertSame(422, $invalid->status());
                self::assertSame($number, $this->projectNumberInput($invalid)->getAttribute('value'));
            }

            self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM projects'));
        });
    }

    public function testEditAndItsValidationErrorsKeepTheirOwnNumberInsteadOfTheSuggestion(): void
    {
        $projectId = $this->insertProject('MA00009');
        $this->insertProject('MA00007');
        $managerId = $this->createManager();

        $this->withRouter(function (Router $router) use ($managerId, $projectId): void {
            $_SESSION['auth']['user_id'] = $managerId;
            $path = '/admin/projects/' . $projectId;
            $edit = $router->dispatch($this->request('GET', $path . '/edit'));
            self::assertSame(200, $edit->status());
            self::assertSame('MA00009', $this->projectNumberInput($edit)->getAttribute('value'));
            $invalid = $router->dispatch($this->request('PUT', $path, [
                'csrf_token' => $_SESSION['_csrf_token'], 'project_number' => 'MA00007', 'name' => 'Bearbeitet', 'status' => 'active',
            ]));
            self::assertSame(422, $invalid->status());
            self::assertSame('MA00007', $this->projectNumberInput($invalid)->getAttribute('value'));
            self::assertSame('MA00009', $this->connection()->fetchColumn('SELECT project_number FROM projects WHERE id = :id', ['id' => $projectId]));
        });
    }

    public function testOverflowLeavesTheCreateNumberEmptyAndEditable(): void
    {
        $this->insertProject(str_repeat('9', 40));
        $managerId = $this->createManager();

        $this->withRouter(function (Router $router) use ($managerId): void {
            $_SESSION['auth']['user_id'] = $managerId;
            $response = $router->dispatch($this->request('GET', '/admin/projects/create'));

            self::assertSame(200, $response->status());
            $input = $this->projectNumberInput($response);
            self::assertSame('', $input->getAttribute('value'));
            self::assertFalse($input->hasAttribute('readonly'));
            self::assertFalse($input->hasAttribute('disabled'));
        });
    }

    private function service(): ProjectService
    {
        return new ProjectService($this->connection());
    }

    private function insertProject(string $number, bool $archived = false, string $status = 'active'): int
    {
        $this->connection()->execute(
            'INSERT INTO projects (project_number, name, status, is_deleted, created_at, updated_at)
             VALUES (:project_number, :name, :status, :is_deleted, NOW(), NOW())',
            ['project_number' => $number, 'name' => 'Testprojekt', 'status' => $status, 'is_deleted' => $archived ? 1 : 0]
        );

        return $this->connection()->lastInsertId();
    }

    private function createManager(): int
    {
        $userId = $this->createUser();
        $this->connection()->execute('INSERT INTO roles (slug, name, is_system_role, created_at, updated_at, is_deleted) VALUES ("project-number-office", "Buero", 0, NOW(), NOW(), 0)');
        $roleId = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO permissions (code, label, scope, created_at) VALUES ("projects.manage", "Projekte verwalten", "backend", NOW())');
        $permissionId = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)', ['role_id' => $roleId, 'permission_id' => $permissionId]);
        $this->connection()->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', ['user_id' => $userId, 'role_id' => $roleId]);

        return $userId;
    }

    private function projectNumberInput(Response $response): DOMElement
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $this->content($response), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $inputs = (new DOMXPath($document))->query('//input[@name="project_number"]');
        self::assertNotFalse($inputs);
        self::assertSame(1, $inputs->length);
        $input = $inputs->item(0);
        self::assertInstanceOf(DOMElement::class, $input);

        return $input;
    }

    private function request(string $method, string $path, array $body = []): Request
    {
        return new Request($method, $path, [], $body, [], [], []);
    }

    private function content(Response $response): string
    {
        return (string) (new ReflectionProperty(Response::class, 'content'))->getValue($response);
    }

    private function withRouter(callable $callback): void
    {
        $override = tempnam(sys_get_temp_dir(), 'timeapp-project-number-routes-');

        if ($override === false || !str_starts_with((string) $this->connectionConfig()['database'], 'timeapp_test_')) {
            throw new RuntimeException('Routentest benoetigt einen isolierten Scratch-Override.');
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
}
