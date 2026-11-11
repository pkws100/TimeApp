<?php

declare(strict_types=1);
namespace Tests\Integration;

use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDOException;
use Tests\Support\MariaDbTestCase;

final class WorkTimePolicyDatabaseTest extends MariaDbTestCase
{
    public function testDisabledFeatureUsesNoRulesAndDoesNotTouchExistingData(): void
    {
        [$actor, $employee, $policy, $assignment] = $this->fixture();
        $id = $this->timesheet($employee, $assignment['effective_from']);
        $before = $this->connection()->fetchOne('SELECT * FROM timesheets WHERE id = :id', ['id' => $id]);
        $disabled = new WorkTimePolicyService($this->connection());
        self::assertNull($disabled->policyFor($employee, $assignment['effective_from']));
        $preview = new WorkTimePreviewService($this->connection(), $disabled);
        self::assertFalse($preview->forUserPeriod($employee, $assignment['effective_from'], $assignment['effective_from'])['enabled']);
        self::assertSame(['created' => 0, 'superseded' => 0], $preview->reconcileUser($employee, $assignment['effective_from'], $assignment['effective_from']));
        self::assertSame($before, $this->connection()->fetchOne('SELECT * FROM timesheets WHERE id = :id', ['id' => $id]));
    }

    public function testVersionedAssignmentAndIndividualOverridesRespectFutureBoundary(): void
    {
        [$actor, $employee, $policy, $assignment, $template] = $this->fixture();
        self::assertNull($policy->policyFor($employee, $this->date('-1 day', $assignment['effective_from'])));
        $first = $policy->policyFor($employee, $assignment['effective_from']);
        self::assertSame('work_started', $first['early_check_in_mode']);
        $version = $policy->addVersion($template['id'], ['windows' => $this->windows('09:00', '17:00')], $actor);
        $nextDate = $this->date('+10 days', $assignment['effective_from']);
        $second = $policy->assign(['user_id' => $employee, 'policy_version_id' => $version['id'], 'effective_from' => $nextDate, 'overrides' => ['early_check_in_mode' => 'presence_only'], 'supervisor_user_ids' => [$actor]], $actor);
        self::assertSame($first['policy_version_id'], $policy->policyFor($employee, $this->date('-1 day', $nextDate))['policy_version_id']);
        self::assertSame('presence_only', $policy->policyFor($employee, $nextDate)['early_check_in_mode']);
        self::assertSame([$actor], $policy->listAssignments($employee)[0]['supervisor_user_ids']);
        self::assertSame($second['id'], $policy->policyFor($employee, $nextDate)['assignment_id']);
        self::assertSame($this->date('-1 day', $nextDate), $policy->listAssignments($employee)[1]['effective_to']);
        self::assertSame(5, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_policy_audit'));
    }

    public function testTodayAndHistoricalActivationAreRejected(): void
    {
        [$actor, $employee, $policy, , $template] = $this->fixture();
        $this->expectException(InvalidArgumentException::class);
        $policy->assign(['user_id' => $employee, 'policy_version_id' => $template['version']['id'], 'effective_from' => $this->date('today')], $actor);
    }

    public function testSameDayAndFiniteOverlapsCannotContradictAssignments(): void
    {
        [$actor, $employee, $policy, $assignment, $template] = $this->fixture();
        $this->expectException(InvalidArgumentException::class);
        $policy->assign(['user_id' => $employee, 'policy_version_id' => $template['version']['id'], 'effective_from' => $assignment['effective_from']], $actor);
    }

    public function testAssignmentEndIsAuditedWithoutRemovingHistory(): void
    {
        [$actor, $employee, $policy, $assignment] = $this->fixture();
        $policy->endAssignment($assignment['id'], $assignment['effective_from'], $actor, 'Ab morgen ohne Arbeitszeitfenster');
        self::assertNotNull($policy->policyFor($employee, $assignment['effective_from']));
        self::assertNull($policy->policyFor($employee, $this->date('+1 day', $assignment['effective_from'])));
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_work_time_assignments'));
        self::assertStringContainsString('Ab morgen', (string) $this->connection()->fetchColumn('SELECT after_json FROM work_time_policy_audit WHERE action = "assignment_ended"'));
    }

    public function testPreviewAndReconciliationKeepActualTimesheetImmutableAndDecisionsInvalidateOnSourceChange(): void
    {
        [$actor, $employee, $policy, $assignment] = $this->fixture();
        $date = $assignment['effective_from'];
        $id = $this->timesheet($employee, $date);
        $preview = new WorkTimePreviewService($this->connection(), $policy);
        $before = $this->connection()->fetchOne('SELECT * FROM timesheets WHERE id = :id', ['id' => $id]);
        $result = $preview->forUserPeriod($employee, $date, $date);
        self::assertSame(503, $result['actual_work_minutes']);
        self::assertSame(503, $result['recorded_net_minutes']);
        self::assertSame(450, $result['accountable_work_minutes']);
        self::assertSame(53, $result['outside_pending_minutes']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations'));
        self::assertSame(['created' => 2, 'superseded' => 0], $preview->reconcileUser($employee, $date, $date));
        self::assertSame(['created' => 0, 'superseded' => 0], $preview->reconcileUser($employee, $date, $date));
        $this->connection()->execute('UPDATE work_time_deviations SET status = "approved" WHERE timesheet_id = :id', ['id' => $id]);
        self::assertSame(503, $preview->forUserPeriod($employee, $date, $date)['accountable_work_minutes']);
        self::assertFalse($preview->forUserPeriod($employee, $date, $date)['unresolved']);
        $policy->assign(['user_id' => $employee, 'policy_version_id' => $assignment['policy_version_id'], 'effective_from' => $this->date('+10 days', $date)], $actor);
        self::assertSame(503, $preview->forUserPeriod($employee, $date, $date)['accountable_work_minutes'], 'A future assignment end must not invalidate historical decisions.');
        self::assertFalse($preview->forUserPeriod($employee, $date, $date)['unresolved']);
        self::assertSame($before, $this->connection()->fetchOne('SELECT * FROM timesheets WHERE id = :id', ['id' => $id]));
        $this->connection()->execute('UPDATE timesheets SET end_time = "16:45:00", net_minutes = 513, gross_minutes = 543 WHERE id = :id', ['id' => $id]);
        $changed = $preview->forUserPeriod($employee, $date, $date);
        self::assertSame(63, $changed['outside_pending_minutes']);
        self::assertSame(0, $changed['outside_approved_minutes']);
        self::assertSame(['created' => 2, 'superseded' => 2], $preview->reconcileUser($employee, $date, $date));
        self::assertSame(4, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations'));
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations WHERE superseded_at IS NOT NULL'));
    }

    public function testUnplacedAutomaticPauseCreatesNoInventedDeviations(): void
    {
        [$actor, $employee, $policy, $assignment] = $this->fixture();
        $id = $this->timesheet($employee, $assignment['effective_from']);
        $this->connection()->execute('DELETE FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]);
        $preview = new WorkTimePreviewService($this->connection(), $policy);
        $summary = $preview->forUserPeriod($employee, $assignment['effective_from'], $assignment['effective_from']);
        self::assertSame('needs_clarification', $summary['calculation_status']);
        self::assertNull($summary['actual_work_minutes']);
        self::assertSame(503, $summary['recorded_net_minutes']);
        self::assertNull($summary['accountable_work_minutes']);
        self::assertNull($summary['outside_pending_minutes']);
        self::assertSame(['created' => 0, 'superseded' => 0], $preview->reconcileUser($employee, $assignment['effective_from'], $assignment['effective_from']));
    }

    public function testArchivedTemplatesKeepHistoricalRulesAndReferencesPreventPhysicalDeletion(): void
    {
        [$actor, $employee, $policy, $assignment, $template] = $this->fixture();
        $policy->archiveTemplate($template['id'], $actor);
        self::assertNotNull($policy->policyFor($employee, $assignment['effective_from']));
        self::assertFalse((bool) $policy->listTemplates()[0]['is_active']);
        $this->expectException(PDOException::class);
        $this->connection()->execute('DELETE FROM users WHERE id = :id', ['id' => $employee]);
    }

    public function testSourceTimesheetsRemainSystemVersioned(): void
    {
        $create = $this->connection()->fetchOne('SHOW CREATE TABLE timesheets');
        self::assertStringContainsString('WITH SYSTEM VERSIONING', implode(' ', $create));
    }

    private function fixture(): array
    {
        $actor = $this->createUser(); $employee = $this->createUser();
        $policy = new WorkTimePolicyService($this->connection(), true);
        $template = $policy->createTemplate(['name' => 'Fenster', 'policy' => ['windows' => $this->windows()]], $actor);
        $assignment = $policy->assign(['user_id' => $employee, 'policy_version_id' => $template['version']['id'], 'effective_from' => $this->date('+2 days')], $actor);
        return [$actor, $employee, $policy, $assignment, $template];
    }
    private function windows(string $start = '08:00', string $end = '16:00'): array { return array_fill(1, 7, [['start' => $start, 'end' => $end]]); }
    private function date(string $modifier, ?string $base = null): string { return (new DateTimeImmutable($base ?? 'now', new DateTimeZone('Europe/Berlin')))->modify($modifier)->format('Y-m-d'); }
    private function timesheet(int $employee, string $date): int
    {
        $this->connection()->execute('INSERT INTO timesheets (user_id, created_by_user_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source, created_at, updated_at) VALUES (:user, :actor, :date, "07:42:00", "16:35:00", 533, 30, 503, "work", "app", NOW(), NOW())', ['user' => $employee, 'actor' => $employee, 'date' => $date]);
        $id = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source, created_at, updated_at) VALUES (:timesheet, :user, :start, :end, "app", NOW(), NOW())', ['timesheet' => $id, 'user' => $employee, 'start' => $date . ' 12:00:00', 'end' => $date . ' 12:30:00']);
        return $id;
    }
}
