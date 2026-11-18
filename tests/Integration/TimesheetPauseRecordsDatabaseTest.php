<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\PausePolicyService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetPauseService;
use App\Domain\Timesheets\TimesheetSignatureService;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\WorkTimePauseCorrectionService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\MariaDbTestCase;

final class TimesheetPauseRecordsDatabaseTest extends MariaDbTestCase
{
    public function testOptedInActualWorkStaysRecordedWithoutAFictionalDeduction(): void
    {
        [$user, $date, $sync, $pauses] = $this->fixture();
        $sync->sync($user, ['action' => 'check_in', 'client_request_id' => 'start', 'work_date' => $date, 'start_time' => '08:00']);
        $closed = $sync->sync($user, ['action' => 'check_out', 'client_request_id' => 'end', 'work_date' => $date, 'end_time' => '14:30']);
        self::assertSame(390, $closed['timesheet']['net_minutes']);
        self::assertSame(0, $closed['timesheet']['break_minutes']);
        self::assertSame(30, $closed['break_assessment']['required_break_minutes']);
        self::assertSame(30, $closed['break_assessment']['missing_break_minutes']);
        self::assertSame('needs_clarification', $closed['break_assessment']['calculation_status']);
        self::assertSame([], $pauses->breaks($closed['timesheet']['id']));
    }

    public function testStampsAndManualPausesAccumulateAndReplayExactlyOnce(): void
    {
        [$user, $date, $sync, $pauses] = $this->fixture();
        $entry = $sync->sync($user, ['action' => 'check_in', 'client_request_id' => 'start', 'work_date' => $date, 'start_time' => '08:00'])['timesheet'];
        $binding = ['work_date' => $date, 'expected_timesheet_id' => $entry['id']];
        $started = $sync->sync($user, $binding + ['action' => 'pause_start', 'client_request_id' => 'pause-on', 'break_started_at' => $date . ' 09:30']);
        $end = $binding + ['action' => 'pause_end', 'client_request_id' => 'pause-off', 'break_ended_at' => $date . ' 09:45', 'expected_break_id' => $started['current_break']['id']];
        self::assertSame($sync->sync($user, $end), $sync->sync($user, $end));
        $manual = $binding + ['action' => 'pause_record', 'client_request_id' => 'other', 'break_started_at' => $date . ' 12:00', 'break_ended_at' => $date . ' 12:30'];
        self::assertSame($sync->sync($user, $manual), $sync->sync($user, $manual));
        try {
            $sync->sync($user, $binding + ['action' => 'pause', 'client_request_id' => 'old', 'manual_break_minutes' => 15]);
            self::fail('Legacy writes cannot replace actual pauses.');
        } catch (InvalidArgumentException) {
            self::assertCount(2, $pauses->breaks($entry['id']));
        }
        $closed = $sync->sync($user, $binding + ['action' => 'check_out', 'client_request_id' => 'end', 'end_time' => '16:00']);
        self::assertSame(45, $closed['timesheet']['break_minutes']);
        self::assertSame(435, $closed['timesheet']['net_minutes']);
        self::assertSame(45, $closed['break_assessment']['documented_break_minutes']);
        self::assertSame(0, $closed['break_assessment']['missing_break_minutes']);
    }

    public function testPauseStampsRemainChronologicalAcrossTheRepeatedBerlinHour(): void
    {
        $zone = new DateTimeZone('Europe/Berlin');
        $lastYear = (int) (new DateTimeImmutable('today', $zone))->format('Y') - 1;
        $date = (new DateTimeImmutable('last sunday of october ' . $lastYear, $zone))->format('Y-m-d');
        [$user, , $sync, $pauses] = $this->fixture($date);

        $entry = $sync->sync($user, ['action' => 'check_in', 'client_request_id' => 'dst-start', 'work_date' => $date, 'start_time' => '01:00'])['timesheet'];
        $binding = ['work_date' => $date, 'expected_timesheet_id' => $entry['id']];
        $started = $sync->sync($user, $binding + ['action' => 'pause_start', 'client_request_id' => 'dst-pause-on', 'break_started_at' => $date . 'T00:50:00.125Z']);
        $startIso = $date . 'T02:50:00+02:00';
        self::assertSame($startIso, $started['current_break']['break_started_at']);
        $end = $binding + ['action' => 'pause_end', 'break_ended_at' => $date . 'T01:05:00Z', 'expected_break_id' => $started['current_break']['id']];
        try {
            $sync->sync($user, $end + ['client_request_id' => 'dst-stale', 'expected_break_started_at' => $date . 'T02:50:00+01:00']);
            self::fail('The other occurrence of the same clock time cannot confirm the open pause.');
        } catch (InvalidArgumentException) {
            self::assertNull($pauses->breaks($entry['id'])[0]['break_ended_at']);
        }
        $validEnd = $end + ['client_request_id' => 'dst-pause-off', 'expected_break_started_at' => $startIso];
        $ended = $sync->sync($user, $validEnd);
        self::assertSame($ended, $sync->sync($user, $validEnd));
        self::assertSame($date . 'T02:05:00+01:00', $ended['breaks_today'][0]['break_ended_at']);
        self::assertSame(15, $ended['breaks_today'][0]['duration_minutes']);

        $sync->sync($user, $binding + ['action' => 'pause_record', 'client_request_id' => 'dst-manual',
            'break_started_at' => $date . 'T02:20:00+01:00', 'break_ended_at' => $date . 'T02:35:00+01:00']);
        $closed = $sync->sync($user, $binding + ['action' => 'check_out', 'client_request_id' => 'dst-end', 'end_time' => '04:00']);
        self::assertSame([$startIso, $date . 'T02:20:00+01:00'], array_column($pauses->breaks($entry['id']), 'break_started_at'));
        self::assertSame(240, $closed['timesheet']['gross_minutes']);
        self::assertSame(30, $closed['timesheet']['break_minutes']);
        self::assertSame(210, $closed['timesheet']['net_minutes']);
        self::assertSame(30, $closed['break_assessment']['documented_break_minutes']);
        self::assertSame('resolved', $closed['break_assessment']['calculation_status']);
    }

    public function testUnplacedEvidenceCanBeLocatedWithoutChangingBookingOrLosingOriginalRecord(): void
    {
        [$user, $date, $sync, $pauses] = $this->fixture();
        $entry = $sync->sync($user, ['action' => 'check_in', 'client_request_id' => 'start', 'work_date' => $date, 'start_time' => '08:00'])['timesheet'];
        $binding = ['work_date' => $date, 'expected_timesheet_id' => $entry['id']];
        $sync->sync($user, $binding + ['action' => 'check_out', 'client_request_id' => 'end', 'end_time' => '16:00']);
        $duration = $binding + ['action' => 'pause_record', 'manual_break_minutes' => 30, 'expected_documented_break_minutes' => 0];
        $result = $sync->sync($user, $duration + ['client_request_id' => 'duration']);
        self::assertNull($result['breaks_today'][0]['break_started_at']);
        self::assertSame(30, $result['break_assessment']['unplaced_break_minutes']);
        try {
            $sync->sync($user, $duration + ['client_request_id' => 'parallel-duration']);
            self::fail('Another device with the same stale evidence base must not append the duration again.');
        } catch (InvalidArgumentException) {
            self::assertCount(1, $pauses->breaks($entry['id']));
        }
        $connection = $this->connection();
        $windows = new WorkTimePolicyService($connection, false);
        $corrections = new WorkTimePauseCorrectionService($connection, $windows, new WorkTimePreviewService($connection, $windows), pauses: $pauses);
        $before = $connection->fetchOne('SELECT *, row_start FROM timesheets WHERE id = :id', ['id' => $entry['id']]);
        $details = $corrections->details($entry['id']);
        self::assertTrue($details['can_document']);
        $corrections->document($entry['id'], [['start' => $date . 'T12:00', 'end' => $date . 'T12:30']], $user, 'Tatsächliche Pause bestätigt.', 'place-break', $details['source_token']);
        self::assertSame($before, $connection->fetchOne('SELECT *, row_start FROM timesheets WHERE id = :id', ['id' => $entry['id']]));
        self::assertNotNull($connection->fetchColumn('SELECT placed_at FROM timesheet_pause_durations'));
        self::assertSame(30, $pauses->assessmentForDay($user, $date)['documented_break_minutes']);
        self::assertSame(0, $pauses->assessmentForDay($user, $date)['unplaced_break_minutes']);
        self::assertCount(1, $pauses->breaks($entry['id']));
    }

    public function testAdminAndLegacyBookingsUseTheSameSnapshotActivationBoundary(): void
    {
        [$user, $date, $sync, $pauses] = $this->fixture();
        $legacyUser = $this->createUser();
        $admin = new AdminBookingService($this->connection(), new TimesheetCalculator());
        $admin->setPauseService($pauses);
        $payload = ['work_date' => $date, 'start_time' => '08:00', 'end_time' => '14:30', 'change_reason' => 'Geprüfte Arbeit', 'break_minutes' => 0, 'note' => 'Originalbuchung'];
        $new = $admin->createManual($payload + ['user_id' => $user], $user);
        $legacy = $admin->createManual($payload + ['user_id' => $legacyUser], $user);
        self::assertSame(390, $new['net_minutes']);
        self::assertSame(360, $legacy['net_minutes']);
        self::assertSame(0, $pauses->assessmentForDay($legacyUser, $date)['documented_break_minutes']);
        self::assertSame(30, $pauses->assessmentForDay($legacyUser, $date)['legacy_deduction_minutes']);
        self::assertNotNull($pauses->snapshot($new['id']));
        self::assertNull($pauses->snapshot($legacy['id']));
        $result = $sync->sync($user, [
            'work_date' => $date, 'expected_timesheet_id' => $new['id'], 'action' => 'pause_record',
            'client_request_id' => 'metadata-guard', 'manual_break_minutes' => 15, 'expected_documented_break_minutes' => 0,
            'source' => 'terminal', 'project_id' => $this->createProject(), 'note' => 'Getrennte Pausennotiz',
        ]);
        $saved = $this->connection()->fetchOne('SELECT source, note, project_id FROM timesheets WHERE id = :id', ['id' => $new['id']]);
        self::assertSame(['source' => 'admin', 'note' => 'Originalbuchung', 'project_id' => null], $saved);
        self::assertSame('Getrennte Pausennotiz', $result['breaks_today'][0]['note']);
    }

    public function testConnectedBookingsAcrossMidnightAreOneStatutoryWorkPeriod(): void
    {
        [$user, $date, , $pauses] = $this->fixture();
        $previous = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $this->connection()->execute('UPDATE employee_pause_policy_assignments SET effective_from = :date WHERE user_id = :user_id', ['date' => $previous, 'user_id' => $user]);
        $admin = new AdminBookingService($this->connection(), new TimesheetCalculator());
        $admin->setPauseService($pauses);
        $payload = ['user_id' => $user, 'change_reason' => 'Synthetischer Nachteinsatz'];
        $admin->createManual($payload + ['work_date' => $previous, 'start_time' => '21:00', 'end_time' => '00:30'], $user);
        $admin->createManual($payload + ['work_date' => $date, 'start_time' => '00:30', 'end_time' => '04:30'], $user);
        $assessment = $pauses->assessmentForDay($user, $date);
        self::assertSame([$previous, $date], $assessment['linked_work_dates']);
        self::assertSame(450, $assessment['actual_work_minutes']);
        self::assertSame(30, $assessment['required_break_minutes']);
        self::assertTrue($assessment['continuous_work_limit_exceeded']);

        $admin->createManual($payload + ['work_date' => $date, 'start_time' => '17:00', 'end_time' => '19:00'], $user);
        $overnight = $pauses->assessmentForDay($user, $previous);
        self::assertSame(450, $overnight['actual_work_minutes']);
        self::assertSame(30, $overnight['required_break_minutes']);
        $bothPeriods = $pauses->assessmentForDay($user, $date);
        self::assertSame(570, $bothPeriods['actual_work_minutes']);
        self::assertSame(30, $bothPeriods['required_break_minutes']);
    }

    public function testDailyAssessmentUsesOneContinuousPauseAcrossProjectBookings(): void
    {
        [$user, $date, $sync, $pauses] = $this->fixture();
        $admin = new AdminBookingService($this->connection(), new TimesheetCalculator());
        $admin->setPauseService($pauses);
        $payload = ['user_id' => $user, 'work_date' => $date, 'change_reason' => 'Synthetischer Projektwechsel'];
        $firstProject = $this->createProject();
        $secondProject = $this->createProject();
        foreach ([$firstProject, $secondProject] as $project) {
            $this->connection()->execute(
                'INSERT INTO project_memberships (project_id, user_id, assigned_from) VALUES (:project, :user, :assigned_from)',
                ['project' => $project, 'user' => $user, 'assigned_from' => $date]
            );
        }
        $first = $admin->createManual($payload + ['project_id' => $firstProject, 'start_time' => '08:00', 'end_time' => '12:00'], $user);
        $second = $admin->createManual($payload + ['project_id' => $secondProject, 'start_time' => '12:00', 'end_time' => '15:00'], $user);
        $pause = ['action' => 'pause_record', 'work_date' => $date];
        $sync->sync($user, $pause + [
            'expected_timesheet_id' => $first['id'], 'client_request_id' => 'first-section',
            'break_started_at' => $date . ' 11:53', 'break_ended_at' => $date . ' 12:00',
        ]);
        $sync->sync($user, $pause + [
            'expected_timesheet_id' => $second['id'], 'client_request_id' => 'second-section',
            'break_started_at' => $date . ' 12:00', 'break_ended_at' => $date . ' 12:08',
        ]);
        $last = $sync->sync($user, $pause + [
            'expected_timesheet_id' => $second['id'], 'client_request_id' => 'another-pause',
            'break_started_at' => $date . ' 14:00', 'break_ended_at' => $date . ' 14:15',
        ]);
        $assessment = $pauses->assessmentForDay($user, $date);
        self::assertSame($assessment, $last['break_assessment']);
        self::assertSame(30, $assessment['documented_break_minutes']);
        self::assertSame(30, $assessment['qualifying_break_minutes']);
        self::assertSame(0, $assessment['missing_break_minutes']);
        self::assertSame('resolved', $assessment['calculation_status']);
    }

    public function testSignedAndClosedBookingsCannotReceiveAppPauseRecords(): void
    {
        [$user, $date, , $pauses] = $this->fixture();
        $connection = $this->connection();
        $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator(), new TimesheetSignatureService($connection, []));
        $sync->setPauseService($pauses);
        $entry = $sync->sync($user, ['action' => 'check_in', 'client_request_id' => 'first', 'work_date' => $date, 'start_time' => '08:00'])['timesheet'];
        $binding = ['work_date' => $date, 'expected_timesheet_id' => $entry['id']];
        $sync->sync($user, $binding + ['action' => 'check_out', 'client_request_id' => 'last', 'end_time' => '16:00']);
        $payload = $binding + ['action' => 'pause_record', 'manual_break_minutes' => 15, 'expected_documented_break_minutes' => 0];
        $connection->execute('INSERT INTO timesheet_customer_signatures (timesheet_id, user_id, customer_name, confirmation_text, signature_storage_path, signature_sha256, signed_at, created_by_user_id)
            VALUES (:entry, :user_id, "Testkunde", "Zeiten bestätigt", "protected/test-only.png", :hash, NOW(), :actor)',
            ['entry' => $entry['id'], 'user_id' => $user, 'hash' => str_repeat('a', 64), 'actor' => $user]);
        try {
            $sync->sync($user, $payload + ['client_request_id' => 'signed']);
            self::fail('The signed booking must reject a pause write.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Kundenbestaetigung', $error->getMessage());
        }
        $connection->execute('UPDATE timesheet_customer_signatures SET archived_at = NOW() WHERE timesheet_id = :id', ['id' => $entry['id']]);
        $connection->execute('INSERT INTO accounting_closures (closure_number, closure_type, status, period_start, period_end, user_id)
            VALUES ("PAUSE-APP-LOCK", "month", "final", :from_date, :to_date, :user_id)', ['from_date' => $date, 'to_date' => $date, 'user_id' => $user]);
        try {
            $sync->sync($user, $payload + ['client_request_id' => 'closed']);
            self::fail('The closed period must reject a pause write.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('festgeschrieben', $error->getMessage());
        }
        self::assertSame([], $pauses->breaks($entry['id']));
    }

    public function testLegacyPauseActionKeepsTheSignedBookingAndConfirmationIntact(): void
    {
        $connection = $this->connection();
        $user = $this->createUser();
        $signatures = new TimesheetSignatureService($connection, []);
        $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator(), $signatures);
        $date = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $entry = $sync->sync($user, ['action' => 'upsert', 'client_request_id' => 'legacy-entry', 'work_date' => $date, 'start_time' => '08:00', 'end_time' => '16:00'])['timesheet'];
        $connection->execute('INSERT INTO timesheet_customer_signatures (timesheet_id, user_id, customer_name, confirmation_text, signature_storage_path, signature_sha256, signed_at, created_by_user_id)
            VALUES (:entry, :user_id, "Testkunde", "Zeiten bestätigt", "protected/test-only.png", :hash, NOW(), :actor)',
            ['entry' => $entry['id'], 'user_id' => $user, 'hash' => str_repeat('a', 64), 'actor' => $user]);
        try {
            $sync->sync($user, ['action' => 'pause', 'client_request_id' => 'legacy-signed-pause', 'work_date' => $date, 'manual_break_minutes' => 60]);
            self::fail('The signed legacy booking must reject the old aggregate pause action.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Kundenbestaetigung', $error->getMessage());
        }
        self::assertNotNull($signatures->findActiveForTimesheet($entry['id']));
        self::assertSame(30, (int) $connection->fetchColumn('SELECT break_minutes FROM timesheets WHERE id = :id', ['id' => $entry['id']]));
    }

    private function fixture(?string $date = null): array
    {
        $connection = $this->connection();
        $user = $this->createUser();
        $policies = new PausePolicyService($connection);
        $version = $policies->createTemplate(['name' => 'Test Pausenmodell'], $user)['version'];
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
        $date ??= $today->modify('-1 day')->format('Y-m-d');
        $assignment = $policies->assign(['user_id' => $user, 'policy_version_id' => $version['id'], 'effective_from' => $today->modify('+2 days')->format('Y-m-d'), 'reason' => 'Synthetischer Prüfstand'], $user);
        $connection->execute('UPDATE employee_pause_policy_assignments SET effective_from = :date WHERE id = :id', ['date' => $date, 'id' => $assignment['id']]);
        $pauses = new TimesheetPauseService($connection, $policies);
        $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
        $sync->setPauseService($pauses);
        return [$user, $date, $sync, $pauses];
    }
}
