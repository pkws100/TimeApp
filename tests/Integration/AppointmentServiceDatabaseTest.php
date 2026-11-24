<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Appointments\AppointmentAccessDeniedException;
use App\Domain\Appointments\AppointmentConflictException;
use App\Domain\Appointments\AppointmentNotFoundException;
use App\Domain\Appointments\AppointmentService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Users\PermissionMatrix;
use App\Http\Controllers\AppAppointmentsController;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use Tests\Support\MariaDbTestCase;

final class AppointmentServiceDatabaseTest extends MariaDbTestCase
{
    private AppointmentService $appointments;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appointments = new AppointmentService($this->connection(), new ProjectAccessService($this->connection()));
        $this->admin = ['id' => $this->createUser(), 'permissions' => ['*']];
    }

    public function testLifecyclePreservesRelationsAndKeepsAppointmentsOutOfBookings(): void
    {
        $participant = $this->createUser(['first_name' => 'Lina', 'last_name' => 'A Test']);
        $other = $this->createUser(['first_name' => 'Sina', 'last_name' => 'B Test']);
        $project = $this->createProject();
        $unlinked = $this->appointments->create($this->admin, $this->data());
        self::assertNull($this->appointments->find($this->admin, $unlinked)['project_id']);
        self::assertSame([], $this->appointments->find($this->admin, $unlinked)['participants']);

        $id = $this->appointments->create($this->admin, $this->data([
            'project_id' => $project, 'participant_ids' => [$participant, $participant, $other],
        ]));
        $created = $this->appointments->find($this->admin, $id);
        self::assertSame($project, $created['project_id']);
        self::assertSame('2099-07-16 07:15:00', $created['starts_at_utc']);
        self::assertSame('2099-07-16T09:15', $created['starts_at']);
        self::assertSame('Europe/Berlin', $created['timezone']);
        self::assertSame(1, $created['version']);
        self::assertSame([
            ['id' => $participant, 'name' => 'Lina A Test'],
            ['id' => $other, 'name' => 'Sina B Test'],
        ], $created['participants']);
        $originalLink = $this->connection()->fetchColumn(
            'SELECT id FROM appointment_participants WHERE appointment_id = :appointment AND user_id = :second',
            ['appointment' => $id, 'second' => $other]
        );

        $this->appointments->update($this->admin, $id, [
            'version' => 1, 'title' => 'Neuer Titel', 'participant_ids' => [$other], 'status' => 'completed',
        ]);
        $changed = $this->appointments->find($this->admin, $id);
        self::assertSame('Neuer Titel', $changed['title']);
        self::assertSame('completed', $changed['status']);
        self::assertSame(2, $changed['version']);
        self::assertSame([$other], array_column($changed['participants'], 'id'));
        self::assertSame((int) $originalLink, (int) $this->connection()->fetchColumn(
            'SELECT id FROM appointment_participants WHERE appointment_id = :appointment',
            ['appointment' => $id]
        ));
        $this->appointments->update($this->admin, $unlinked, ['version' => 1, 'status' => 'cancelled']);
        self::assertSame('cancelled', $this->appointments->find($this->admin, $unlinked)['status']);
        try {
            $this->appointments->update($this->admin, $id, ['version' => 2, 'status' => 'planned']);
            self::fail('An ended appointment cannot be reopened.');
        } catch (InvalidArgumentException) {
            self::assertSame(2, $this->appointments->find($this->admin, $id)['version']);
        }

        $this->appointments->archive($this->admin, $id, 2);
        self::assertNotNull($this->appointments->find($this->admin, $id)['archived_at']);
        self::assertSame([$unlinked], array_column($this->appointments->calendarBetween($this->admin, '2099-07-16', '2099-07-16'), 'id'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_memberships'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets'));
    }

    public function testListAndCalendarUseProjectScopeBeforeFilteringAndCounting(): void
    {
        $visible = $this->createProject();
        $hidden = $this->createProject();
        $actor = ['id' => $this->createUser(), 'permissions' => ['appointments.view', 'appointments.manage']];
        $participant = $this->createUser();
        $this->connection()->execute(
            'INSERT INTO project_memberships (project_id, user_id) VALUES (:project, :operator)',
            ['project' => $visible, 'operator' => $actor['id']]
        );
        $unlinked = $this->appointments->create($this->admin, $this->data(['title' => 'Allgemeiner Termin']));
        $visibleId = $this->appointments->create($actor, $this->data([
            'project_id' => $visible, 'title' => 'Termin mit 100%', 'participant_ids' => [$participant],
            'starts_at' => '2099-07-16T23:50', 'ends_at' => '2099-07-17T01:00',
        ]));
        $hiddenId = $this->appointments->create($this->admin, $this->data([
            'project_id' => $hidden, 'title' => 'Nicht sichtbarer Termin', 'participant_ids' => [$participant],
        ]));

        $result = $this->appointments->list($actor, ['period' => 'custom', 'from' => '2099-07-16', 'to' => '2099-07-16']);
        self::assertSame([$unlinked, $visibleId], array_column($result['items'], 'id'));
        self::assertSame(2, $result['total']);
        self::assertSame(0, $this->appointments->list($actor, ['project_id' => $hidden])['total']);
        self::assertSame([$visibleId], array_column($this->appointments->list($actor, ['user_id' => $participant])['items'], 'id'));
        self::assertSame([$visibleId], array_column($this->appointments->list($actor, ['q' => '%'])['items'], 'id'));
        self::assertSame(0, $this->appointments->list($actor, ['status' => 'completed'])['total']);
        self::assertSame(0, $this->appointments->list($actor, ['q' => 'Nicht sichtbarer Termin'])['total']);
        self::assertSame([$visible], array_column($this->appointments->formOptions($actor)['projects'], 'id'));
        self::assertSame([$visibleId], array_column($this->appointments->calendarBetween($actor, '2099-07-17', '2099-07-17'), 'id'));
        self::assertNull($this->appointments->find($actor, $hiddenId));
        try {
            $this->appointments->update($actor, $hiddenId, ['version' => 1, 'project_id' => null]);
            self::fail('An inaccessible appointment cannot be moved into visible scope.');
        } catch (AppointmentNotFoundException) {
            self::assertSame($hidden, $this->appointments->find($this->admin, $hiddenId)['project_id']);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->appointments->create($actor, $this->data(['project_id' => $hidden]));
    }

    public function testExistingArchivedLinksRemainReadableButCannotBeAddedAnew(): void
    {
        $project = $this->createProject();
        $employee = $this->createUser();
        $id = $this->appointments->create($this->admin, $this->data(['project_id' => $project, 'participant_ids' => [$employee]]));
        $this->connection()->execute('UPDATE projects SET is_deleted = 1, status = "archived" WHERE id = :id', ['id' => $project]);
        $this->connection()->execute('UPDATE users SET employment_status = "inactive" WHERE id = :id', ['id' => $employee]);

        $existing = $this->appointments->find($this->admin, $id);
        $options = $this->appointments->formOptions($this->admin, $existing);
        self::assertTrue($options['projects'][0]['archived']);
        $participants = array_column($options['participants'], 'archived', 'id');
        self::assertTrue($participants[$employee]);
        $this->appointments->update($this->admin, $id, ['version' => 1, 'project_id' => $project, 'participant_ids' => [$employee]]);
        self::assertSame([$employee], array_column($this->appointments->find($this->admin, $id)['participants'], 'id'));
        self::assertSame(1, $this->appointments->find($this->admin, $id)['version']);

        foreach (['project_id' => $project, 'participant_ids' => [$employee]] as $field => $value) {
            try {
                $this->appointments->create($this->admin, $this->data([$field => $value]));
                self::fail('An inactive reference cannot be assigned anew.');
            } catch (InvalidArgumentException) {
                self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM appointments'));
            }
        }
        $this->appointments->update($this->admin, $id, ['version' => 1, 'project_id' => null, 'participant_ids' => []]);
        $updated = $this->appointments->find($this->admin, $id);
        self::assertNull($updated['project_id']);
        self::assertSame([], $updated['participants']);
    }

    public function testValidationAndOptimisticVersionPreventPartialOrLostWrites(): void
    {
        foreach ([
            ['title' => '   '],
            ['starts_at' => '2026-03-29T02:30'],
            ['ends_at' => '2099-02-30T12:00'],
            ['ends_at' => '2099-07-16T09:00'],
            ['participant_ids' => [4294967295]],
        ] as $invalid) {
            try {
                $this->appointments->create($this->admin, $this->data($invalid));
                self::fail('Invalid input unexpectedly wrote a record.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM appointments'));
                self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM appointment_participants'));
            }
        }
        $id = $this->appointments->create($this->admin, $this->data());
        $secondConnection = new DatabaseConnection($this->connectionConfig());
        $second = new AppointmentService($secondConnection, new ProjectAccessService($secondConnection));
        $staleVersion = $second->find($this->admin, $id)['version'];
        $this->appointments->update($this->admin, $id, ['version' => 1, 'title' => 'Erste Änderung']);
        try {
            $second->update($this->admin, $id, ['version' => $staleVersion, 'title' => 'Veraltete Änderung']);
            self::fail('The stale writer unexpectedly overwrote a newer version.');
        } catch (AppointmentConflictException) {
            self::assertSame('Erste Änderung', $this->appointments->find($this->admin, $id)['title']);
        }
        $this->appointments->archive($this->admin, $id, 2);
        $this->expectException(AppointmentConflictException::class);
        $second->update($this->admin, $id, ['version' => 3, 'title' => 'Archivierter Termin']);
    }

    public function testMonthlyPreviewUsesOnlyPlannedRowsAndBerlinMidnightAcrossDst(): void
    {
        $endingAtMidnight = $this->appointments->create($this->admin, $this->data([
            'title' => 'Bis Mitternacht', 'starts_at' => '2026-10-24T23:00', 'ends_at' => '2026-10-25T00:00',
        ]));
        $crossing = $this->appointments->create($this->admin, $this->data([
            'title' => 'Über den Zeitwechsel', 'starts_at' => '2026-10-24T23:30', 'ends_at' => '2026-10-25T03:30',
        ]));
        $completed = $this->appointments->create($this->admin, $this->data([
            'status' => 'completed', 'starts_at' => '2026-10-25T10:00', 'ends_at' => '2026-10-25T11:00',
        ]));

        $preview = $this->appointments->calendarBetween($this->admin, '2026-10-24', '2026-10-25', includeDetails: false);
        self::assertSame([$endingAtMidnight, $crossing], array_column($preview, 'id'));
        self::assertSame([
            'id' => $crossing, 'title' => 'Über den Zeitwechsel',
            'starts_at_utc' => '2026-10-24 21:30:00', 'ends_at_utc' => '2026-10-25 02:30:00',
        ], $preview[1]);
        self::assertSame([$crossing], array_column(
            $this->appointments->calendarBetween($this->admin, '2026-10-25', '2026-10-25', includeDetails: false), 'id'
        ));
        self::assertSame([$crossing, $completed], array_column(
            $this->appointments->calendarBetween($this->admin, '2026-10-25', '2026-10-25'), 'id'
        ));
    }

    public function testServiceKeepsRightsAndChronologicalPaginationSeparate(): void
    {
        $actor = ['id' => $this->createUser(), 'permissions' => ['appointments.view']];
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $ids[] = $this->appointments->create($this->admin, $this->data());
        }
        $result = $this->appointments->list($actor, []);
        self::assertSame(26, $result['total']);
        self::assertSame(array_slice($ids, 0, 25), array_column($result['items'], 'id'));
        self::assertSame([$ids[25]], array_column($this->appointments->list($actor, ['page' => 2])['items'], 'id'));
        try {
            $this->appointments->create($actor, $this->data());
            self::fail('Read permission alone cannot create appointments.');
        } catch (AppointmentAccessDeniedException) {
            self::assertSame(26, $this->appointments->list($actor, [])['total']);
        }
        $this->expectException(AppointmentAccessDeniedException::class);
        $this->appointments->calendarBetween([
            'id' => $actor['id'], 'permissions' => ['timesheets.view', 'personnel.view'],
        ], '2099-07-16', '2099-07-16');
    }

    public function testUpcomingListIncludesAnOngoingAppointmentUntilItsEnd(): void
    {
        $running = $this->appointments->create($this->admin, $this->data(['title' => 'Laufender Termin']));
        $finished = $this->appointments->create($this->admin, $this->data(['title' => 'Abgelaufener Termin']));
        $future = $this->appointments->create($this->admin, $this->data(['title' => 'Späterer Termin']));
        $this->connection()->execute(
            'UPDATE appointments SET starts_at_utc = UTC_TIMESTAMP() - INTERVAL 2 HOUR,
                ends_at_utc = IF(id = :running, UTC_TIMESTAMP() + INTERVAL 1 HOUR, UTC_TIMESTAMP() - INTERVAL 1 HOUR)
             WHERE id IN (:running_id, :finished_id)',
            ['running' => $running, 'running_id' => $running, 'finished_id' => $finished]
        );

        self::assertSame([$running, $future], array_column($this->appointments->list($this->admin, [])['items'], 'id'));
    }

    public function testEmployeeRoutesAuthorizeCurrentParticipantAndBindAcknowledgementToVersion(): void
    {
        $employee = $this->createUser();
        $colleague = $this->createUser();
        $project = $this->createProject();
        $own = $this->appointments->create($this->admin, $this->data([
            'title' => 'Gemeinsamer Termin', 'project_id' => $project, 'participant_ids' => [$employee, $colleague],
        ]));
        $foreign = $this->appointments->create($this->admin, $this->data(['participant_ids' => [$colleague]]));
        $cancelled = $this->appointments->create($this->admin, $this->data(['status' => 'cancelled', 'participant_ids' => [$employee]]));
        $archived = $this->appointments->create($this->admin, $this->data(['participant_ids' => [$employee]]));
        $this->appointments->archive($this->admin, $archived, 1);

        $auth = new AuthService($this->connection(), new PermissionMatrix([], []));
        $csrf = new CsrfService();
        $controller = new AppAppointmentsController($this->appointments, $auth, $csrf);
        $_SESSION['auth'] = ['user_id' => $employee, 'database_realm' => $this->connection()->authenticationRealm()];
        $response = $controller->index($this->employeeRequest(query: ['user_id' => (string) $colleague]));
        self::assertSame(200, $response->status());
        self::assertSame('private, no-store', $response->headers()['Cache-Control']);
        self::assertSame([$own], array_column($this->payload($response)['data'], 'id'));
        $past = $controller->index($this->employeeRequest(query: ['filter' => 'past']));
        self::assertSame([$cancelled], array_column($this->payload($past)['data'], 'id'));
        self::assertSame(404, $controller->show($this->employeeRequest(), ['id' => $foreign])->status());
        self::assertSame(404, $controller->show($this->employeeRequest(), ['id' => $archived])->status());

        $detail = $this->payload($controller->show($this->employeeRequest(), ['id' => $own]));
        self::assertFalse($detail['data']['acknowledged']);
        self::assertNotEmpty($detail['data']['project_number']);
        self::assertArrayNotHasKey('participants', $detail['data']);
        self::assertArrayNotHasKey('project_id', $detail['data']);
        self::assertSame(403, $controller->acknowledge($this->employeeRequest(['version' => 1]), ['id' => $own])->status());
        $request = $this->employeeRequest(['version' => 1, 'csrf_token' => $detail['csrf_token'], 'user_id' => $colleague]);
        self::assertSame(404, $controller->acknowledge($request, ['id' => $foreign])->status());
        self::assertTrue($this->payload($controller->acknowledge($request, ['id' => $own]))['data']['acknowledged']);
        $states = array_column($this->appointments->acknowledgements($this->admin, $own), 'acknowledged', 'id');
        self::assertTrue($states[$employee]);
        self::assertFalse($states[$colleague]);

        $this->connection()->execute(
            'UPDATE appointment_participants SET acknowledged_at_utc = "2026-01-01 10:00:00" WHERE appointment_id = :id AND user_id = :employee',
            ['id' => $own, 'employee' => $employee]
        );
        $replay = $this->payload($controller->acknowledge($request, ['id' => $own]));
        self::assertSame('2026-01-01 10:00:00', $replay['data']['acknowledged_at_utc']);
        $this->appointments->update($this->admin, $own, [
            'version' => 1, 'title' => 'Gemeinsamer Termin', 'project_id' => (string) $project,
            'participant_ids' => [(string) $colleague, (string) $employee],
        ]);
        $afterNoOp = $this->payload($controller->show($this->employeeRequest(), ['id' => $own]));
        self::assertSame(1, $afterNoOp['data']['version']);
        self::assertTrue($afterNoOp['data']['acknowledged']);
        $this->appointments->update($this->admin, $own, ['version' => 1, 'location' => 'Anderer Treffpunkt']);
        $stale = $this->payload($controller->show($this->employeeRequest(), ['id' => $own]));
        self::assertFalse($stale['data']['acknowledged']);
        self::assertSame(1, $stale['data']['acknowledged_version']);
        self::assertSame(409, $controller->acknowledge($request, ['id' => $own])->status());
        $newVersion = $this->employeeRequest(['version' => 2, 'csrf_token' => $detail['csrf_token']]);
        self::assertSame(200, $controller->acknowledge($newVersion, ['id' => $own])->status());
        $this->appointments->update($this->admin, $own, ['version' => 2, 'participant_ids' => [$colleague]]);
        self::assertSame(404, $controller->show($this->employeeRequest(), ['id' => $own])->status());
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM timesheets'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM project_memberships'));
    }

    private function employeeRequest(array $body = [], array $query = []): Request
    {
        return new Request($body === [] ? 'GET' : 'POST', '/api/v1/app/appointments', $query, $body, [], [], []);
    }

    private function payload(Response $response): array
    {
        ob_start();
        $response->send();
        return json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function data(array $overrides = []): array
    {
        return $overrides + ['title' => 'Baustellenbesprechung', 'starts_at' => '2099-07-16T09:15', 'ends_at' => '2099-07-16T10:00'];
    }
}
