<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Appointments\AppointmentAccessDeniedException;
use App\Domain\Appointments\AppointmentConflictException;
use App\Domain\Appointments\AppointmentNotFoundException;
use App\Domain\Appointments\AppointmentService;
use App\Domain\Projects\ProjectAccessService;
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

        foreach (['project_id' => $project, 'participant_ids' => [$employee]] as $field => $value) {
            try {
                $this->appointments->create($this->admin, $this->data([$field => $value]));
                self::fail('An inactive reference cannot be assigned anew.');
            } catch (InvalidArgumentException) {
                self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM appointments'));
            }
        }
        $this->appointments->update($this->admin, $id, ['version' => 2, 'project_id' => null, 'participant_ids' => []]);
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

    private function data(array $overrides = []): array
    {
        return $overrides + ['title' => 'Baustellenbesprechung', 'starts_at' => '2099-07-16T09:15', 'ends_at' => '2099-07-16T10:00'];
    }
}
