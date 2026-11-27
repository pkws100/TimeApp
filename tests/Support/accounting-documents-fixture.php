<?php

declare(strict_types=1);

use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Infrastructure\Database\DatabaseConnection;

// Loaded only by the isolated UI runner after it creates a random scratch schema.
return static function (DatabaseConnection $connection, int $userId, int $adminId, WorkTimePolicyService $policies): array {
    $month = new DateTimeImmutable('first day of last month');
    $firstDay = $month;
    while ((int) $firstDay->format('N') !== 1) {
        $firstDay = $firstDay->modify('+1 day');
    }
    $year = (int) $month->format('Y');
    (new EmployeeAccountCutoverService($connection, new AccountJournalService($connection)))->finalize([
        'user_id' => $userId, 'effective_from' => $year . '-01-01', 'opening_time_balance' => '0:00',
        'leave_year' => $year, 'annual_leave_entitlement_days' => 30, 'opening_remaining_leave_days' => 30,
    ], $adminId);
    $template = $policies->createTemplate(['name' => 'UI Abrechnung Kernzeit', 'policy' => [
        'windows' => array_fill(1, 5, [['start' => '07:00', 'end' => '16:00']]),
    ]], $adminId);
    $assignment = $policies->assign([
        'user_id' => $userId, 'policy_version_id' => $template['version']['id'],
        'effective_from' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d'),
    ], $adminId);
    $connection->execute('UPDATE employee_work_time_assignments SET effective_from = :fixture_date WHERE id = :id', [
        'fixture_date' => $month->format('Y-m-d'), 'id' => $assignment['id'],
    ]);
    $connection->execute('INSERT INTO projects (project_number, name, status, created_at, updated_at, is_deleted)
        VALUES ("UI-BILLING", "UI Abrechnungsprojekt", "active", NOW(), NOW(), 0)');
    $projectId = $connection->lastInsertId();
    foreach ([['vacation', 'vacation_paid', 480], ['sick', 'sick_paid', 480], ['absent', 'unpaid_leave', 0]] as $offset => [$type, $reason, $credit]) {
        $connection->execute('INSERT INTO timesheets (user_id, work_date, entry_type, absence_reason_code, credited_minutes,
            gross_minutes, break_minutes, net_minutes, source)
            VALUES (:user_id, :work_date, :entry_type, :reason, :credit, 0, 0, 0, "admin")', [
            'user_id' => $userId, 'work_date' => $firstDay->modify('+' . $offset . ' days')->format('Y-m-d'),
            'entry_type' => $type, 'reason' => $reason, 'credit' => $credit,
        ]);
    }
    $connection->execute('INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, entry_type, source,
        gross_minutes, break_minutes, net_minutes) VALUES (:user_id, :project_id, :work_date, "08:00:00", "16:00:00", "work", "app", 480, 30, 450)', [
        'user_id' => $userId, 'project_id' => $projectId, 'work_date' => $firstDay->modify('+3 days')->format('Y-m-d'),
    ]);
    $connection->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source)
        VALUES (:booking, :user, :started, :ended, "app")', [
        'booking' => $connection->lastInsertId(), 'user' => $userId,
        'started' => $firstDay->modify('+3 days')->format('Y-m-d') . ' 12:00:00',
        'ended' => $firstDay->modify('+3 days')->format('Y-m-d') . ' 12:30:00',
    ]);

    return ['period' => $month->format('Y-m'), 'project_id' => $projectId];
};
