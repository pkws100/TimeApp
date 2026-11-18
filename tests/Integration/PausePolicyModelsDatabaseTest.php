<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Timesheets\PausePolicyService;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Http\Controllers\AdminPausePolicyController;
use App\Http\Request;
use App\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class PausePolicyModelsDatabaseTest extends MariaDbTestCase
{
    public function testDefaultsAndEmployeeOverridesUseImmutableVersionsAndDatedFallback(): void
    {
        $actor = $this->createUser();
        $first = $this->createUser();
        $second = $this->createUser();
        $policies = new PausePolicyService($this->connection());
        self::assertNull($policies->policyFor($first, $this->date(0)), 'Legacy behavior is the initial default.');
        $base = $policies->createTemplate(['name' => 'Gesetzlicher Betriebsstandard'], $actor);
        $custom = $policies->createTemplate(['name' => 'Längere Pause', 'policy' => [
            'minimum_over_six_minutes' => 45,
            'minimum_over_nine_minutes' => 60,
            'capture_mode' => 'manual',
            'planned_windows' => [['start' => '12:00', 'end' => '13:00']],
        ]], $actor);
        $policies->scheduleDefault($this->schedule($base['version']['id'], 2), $actor);
        $assignment = $policies->assign($this->schedule($custom['version']['id'], 3, 5) + [
            'user_id' => $first, 'overrides' => ['additional_minutes' => 10],
        ], $actor);

        self::assertNull($policies->policyFor($first, $this->date(1)));
        self::assertSame('stamped', $policies->policyFor($first, $this->date(2))['capture_mode']);
        $personal = $policies->policyFor($first, $this->date(3));
        self::assertSame($assignment['id'], $personal['assignment_id']);
        self::assertSame('manual', $personal['capture_mode']);
        self::assertSame(10, $personal['additional_minutes']);
        self::assertSame([['start' => '12:00', 'end' => '13:00']], $personal['planned_windows']);
        self::assertSame(30, $policies->policyFor($second, $this->date(3))['minimum_over_six_minutes']);
        self::assertSame($base['version']['id'], $policies->policyFor($first, $this->date(6))['version_id']);

        $version = $policies->addVersion($custom['id'], ['minimum_over_nine_minutes' => 75], $actor);
        self::assertSame(2, $version['revision']);
        self::assertSame(60, $policies->policyForVersion($custom['version']['id'])['minimum_over_nine_minutes']);
        $policies->archiveTemplate($custom['id'], $actor);
        self::assertSame($personal, $policies->policyFor($first, $this->date(3)));
        self::assertCount(6, $policies->listAudit());
        $this->expectException(InvalidArgumentException::class);
        $policies->assign($this->schedule($version['id'], 6) + ['user_id' => $first], $actor);
    }

    public function testReplacementAndEndingRequireExplicitChoicesAndKeepAudit(): void
    {
        $actor = $this->createUser();
        $user = $this->createUser();
        $policies = new PausePolicyService($this->connection());
        $version = $policies->createTemplate(['name' => 'Standard'], $actor)['version'];
        $first = $policies->assign($this->schedule($version['id'], 2) + ['user_id' => $user], $actor);
        foreach ([0, 2, 3] as $start) {
            try {
                $policies->assign($this->schedule($version['id'], $start) + ['user_id' => $user], $actor);
                self::fail('A same-day or overlapping schedule must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertCount(1, $policies->listAssignments($user));
            }
        }
        $second = $policies->assign($this->schedule($version['id'], 4) + ['user_id' => $user, 'replace_previous' => true], $actor);
        self::assertSame($this->date(3), $policies->policyFor($user, $this->date(3))['effective_to']);
        self::assertSame($first['id'], $policies->policyFor($user, $this->date(3))['assignment_id']);
        $third = $policies->assign($this->schedule($version['id'], 4) + [
            'user_id' => $user, 'replace_previous' => true, 'overrides' => ['capture_mode' => 'manual'],
        ], $actor);
        self::assertSame($third['id'], $policies->policyFor($user, $this->date(4))['assignment_id']);
        foreach ($policies->listAssignments($user) as $row) {
            if ((int) $row['id'] === $second['id']) {
                self::assertNotNull($row['superseded_at']);
            }
        }
        self::assertSame($user, $policies->endAssignment($third['id'], false, $this->date(7), 'Planung beendet', $actor));
        self::assertSame('manual', $policies->policyFor($user, $this->date(7))['capture_mode']);
        self::assertNull($policies->policyFor($user, $this->date(8)));
        $audit = $policies->listAudit($user);
        self::assertSame('assignment_ended', $audit[0]['action']);
        self::assertSame('Planung beendet', $audit[0]['reason']);
        self::assertSame($this->date(7), json_decode((string) $audit[0]['after_json'], true, flags: JSON_THROW_ON_ERROR)['effective_to']);
        self::assertContains('assignment_superseded', array_column($audit, 'action'));
    }

    public function testStatutoryFloorsAndOvernightPlannedWindowsAreValidated(): void
    {
        $actor = $this->createUser();
        $policies = new PausePolicyService($this->connection());
        $invalidPolicies = [
            ['minimum_over_six_minutes' => 29],
            ['minimum_over_nine_minutes' => 44],
            ['minimum_over_six_minutes' => 60, 'minimum_over_nine_minutes' => 59],
            ['additional_minutes' => -1],
            ['capture_mode' => 'auto'],
            ['planned_windows' => [['start' => '23:45', 'end' => '00:15'], ['start' => '00:00', 'end' => '00:30']]],
        ];
        foreach ($invalidPolicies as $rule) {
            try {
                $policies->createTemplate(['name' => 'Ungültig', 'policy' => $rule], $actor);
                self::fail('Illegal pause rule was saved.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $policies->listTemplates());
            }
        }
        $version = $policies->createTemplate(['name' => 'Nachtschicht', 'policy' => [
            'planned_windows' => [['start' => '23:45', 'end' => '00:15'], ['start' => '00:15', 'end' => '00:30']],
        ]], $actor)['version'];
        self::assertCount(2, $version['policy']['planned_windows']);
    }

    public function testReadPermissionMayPreviewButCannotWriteAndPlannedBreaksAreNotEvidence(): void
    {
        $actor = $this->createUser();
        $_SESSION['auth']['user_id'] = $actor;
        $connection = $this->connection();
        $policies = new PausePolicyService($connection);
        $version = $policies->createTemplate(['name' => 'Planfenster', 'policy' => [
            'planned_windows' => [['start' => '12:00', 'end' => '12:30']],
        ]], $actor)['version'];
        $csrf = new CsrfService();
        $auth = new AuthService($connection, new PermissionMatrix([], []));
        $controller = new AdminPausePolicyController($policies, $auth, $csrf, new UserService($connection));
        $example = [
            'policy_version_id' => $version['id'], 'example_date' => $this->date(0), 'example_start' => '08:00', 'example_end' => '14:30',
            'csrf_token' => $csrf->token(),
        ];
        self::assertSame('', $controller->section($this->request([])));
        self::assertSame(403, $controller->preview($this->request($example))->status());
        $this->grant($actor, PausePolicyService::VIEW);
        self::assertStringContainsString('Pausenmodelle', $controller->section($this->request([])));
        self::assertSame(403, $controller->saveTemplate($this->request($example + ['name' => 'Unzulässig']))->status());
        self::assertSame(403, $controller->preview($this->request(array_replace($example, ['csrf_token' => 'invalid'])))->status());
        $result = $this->json($controller->preview($this->request($example)))['data'];
        self::assertSame(390, $result['actual_work_minutes']);
        self::assertSame(0, $result['documented_break_minutes']);
        self::assertSame(30, $result['required_break_minutes']);
        self::assertSame(30, $result['missing_break_minutes']);
        self::assertSame('needs_clarification', $result['calculation_status']);
    }

    public function testGlobalActivationRequiresAnExplicitConfirmationInTheAdminController(): void
    {
        $actor = $this->createUser();
        $_SESSION['auth']['user_id'] = $actor;
        $this->grant($actor, PausePolicyService::MANAGE);
        $connection = $this->connection();
        $policies = new PausePolicyService($connection);
        $version = $policies->createTemplate(['name' => 'Standard'], $actor)['version'];
        $auth = new AuthService($connection, new PermissionMatrix([], []));
        $csrf = new CsrfService();
        $controller = new AdminPausePolicyController($policies, $auth, $csrf, new UserService($connection));
        $payload = $this->schedule($version['id'], 2) + ['csrf_token' => $csrf->token()];
        self::assertSame(422, $controller->scheduleDefault($this->request($payload))->status());
        self::assertSame([], $policies->listDefaults());
        self::assertSame(200, $controller->scheduleDefault($this->request($payload + ['confirm_global' => '1']))->status());
        self::assertCount(1, $policies->listDefaults());
    }

    private function grant(int $actor, string $permission): void
    {
        $connection = $this->connection();
        $connection->execute('INSERT INTO roles (slug, name) VALUES ("pause-reviewer", "Pausenprüfung")');
        $role = $connection->lastInsertId();
        $connection->execute('INSERT INTO user_roles (user_id, role_id) VALUES (:actor, :role)', ['actor' => $actor, 'role' => $role]);
        $connection->execute('INSERT INTO permissions (code, label, scope) VALUES (:code, "Pausenprüfung", "timesheets")', ['code' => $permission]);
        $connection->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)', ['role' => $role, 'permission' => $connection->lastInsertId()]);
    }

    private function request(array $payload): Request
    {
        return new Request('POST', '/admin/work-time/pause-models/preview', [], $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function json(Response $response): array
    {
        ob_start();
        $response->send();
        return json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function date(int $offset): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    private function schedule(int $version, int $from, ?int $until = null): array
    {
        return ['policy_version_id' => $version, 'effective_from' => $this->date($from), 'effective_to' => $until === null ? '' : $this->date($until), 'reason' => 'Synthetische Planung'];
    }
}
