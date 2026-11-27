<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\Timesheets\WorkSessionConflict;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use PDOException;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MariaDbScratchConfig;

final class AppTimesheetSyncDatabaseTest extends TestCase
{
    public function testFinalCutoverLetsTheEmployeeStartWithoutChangingTheLockedOpenBooking(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId] = $this->seedAssignedUserAndProject($connection, 'live-cutover');
            $connection->execute('ALTER TABLE timesheets ADD SYSTEM VERSIONING');
            $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time, note)
                VALUES (:user, "2026-06-01", "08:00", "Altbuchung")', ['user' => $userId]);
            $oldId = $connection->lastInsertId();
            $connection->execute('UPDATE timesheets SET note = "Historischer Nachweis" WHERE id = :id', ['id' => $oldId]);
            $connection->execute('INSERT INTO employee_account_cutovers (user_id, active_final_user_id, status, effective_from)
                VALUES (:user, :active_user, "final", "2026-06-02")', ['user' => $userId, 'active_user' => $userId]);
            $connection->execute('INSERT INTO accounting_closures (closure_number, closure_type, status, period_start, period_end, user_id)
                VALUES ("TEST-CUTOVER", "month", "final", "2026-01-01", "2026-06-01", :user)', ['user' => $userId]);
            $history = static fn (): array => $connection->fetchAll(
                'SELECT *, ROW_START, ROW_END FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id ORDER BY ROW_START',
                ['id' => $oldId]
            );
            $before = $history();
            $cutovers = $connection->fetchAll('SELECT * FROM employee_account_cutovers');
            self::assertCount(2, $before);

            $service = $this->createSyncService($connection);
            $checkIn = [
                'client_request_id' => 'valid-after-cutover',
                'action' => 'check_in',
                'work_date' => '2026-06-02',
                'project_id' => $projectId,
                'start_time' => '07:00',
            ];
            $result = $service->sync($userId, $checkIn);
            self::assertSame($projectId, $result['timesheet']['project_id']);
            self::assertSame($result, $service->sync($userId, $checkIn));
            self::assertSame($before, $history());
            self::assertSame($cutovers, $connection->fetchAll('SELECT * FROM employee_account_cutovers'));
            self::assertSame(2, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheets'));

            $this->expectException(WorkSessionConflict::class);
            $service->sync($userId, array_replace($checkIn, ['client_request_id' => 'another-in', 'work_date' => '2026-06-03']));
        });
    }

    public function testDraftRevisedFutureAndForeignCutoversDoNotHideLiveWork(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            $service = $this->createSyncService($connection);
            $futureDate = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
            [$otherUserId] = $this->seedAssignedUserAndProject($connection, 'other-cutover');
            foreach (['draft', 'revised', 'future', 'foreign', 'inactive', 'inconsistent', 'notyet'] as $case) {
                [$userId] = $this->seedAssignedUserAndProject($connection, $case);
                $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time)
                    VALUES (:user, "2026-06-01", "08:00")', ['user' => $userId]);
                $connection->execute('INSERT INTO employee_account_cutovers (user_id, active_final_user_id, status, effective_from)
                    VALUES (:user, :active_user, :status, :effective_from)', [
                    'user' => $case === 'foreign' ? $otherUserId : $userId,
                    'active_user' => match ($case) {
                        'inactive' => null,
                        'foreign' => $otherUserId,
                        'inconsistent' => $otherUserId,
                        default => $userId,
                    },
                    'status' => in_array($case, ['draft', 'revised'], true) ? $case : 'final',
                    'effective_from' => match ($case) {
                        'future' => $futureDate,
                        'notyet' => '2026-06-03',
                        default => '2026-06-02',
                    },
                ]);

                try {
                    $service->sync($userId, [
                        'client_request_id' => 'blocked-' . $case,
                        'action' => 'check_in',
                        'work_date' => $case === 'future' ? $futureDate : '2026-06-02',
                        'start_time' => '07:00',
                    ]);
                    self::fail('Expected an open-session conflict for ' . $case);
                } catch (WorkSessionConflict $exception) {
                    self::assertSame('active_work_session', $exception->reason, $case);
                    self::assertNull($exception->visibleProject);
                }
            }
        });
    }

    public function testDuplicateMessagesRespectCurrentProjectAccessAndStaleOfflineStartCannotOverlap(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId] = $this->seedAssignedUserAndProject($connection, 'visible');
            [$otherUserId, $otherProjectId] = $this->seedAssignedUserAndProject($connection, 'other-project');
            $projectNumber = $connection->fetchColumn('SELECT project_number FROM projects WHERE id = :id', ['id' => $projectId]);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create']];
            $service = $this->createSyncService($connection);
            $checkIn = [
                'client_request_id' => 'night-work',
                'action' => 'check_in',
                'work_date' => '2026-06-01',
                'project_id' => $projectId,
                'start_time' => '23:00',
            ];
            $result = $service->sync($user, $checkIn);

            foreach ([$projectId, $otherProjectId, null] as $index => $requestedProject) {
                if ($requestedProject === null) {
                    $connection->execute('UPDATE projects SET status = "archived" WHERE id = :id', ['id' => $projectId]);
                }
                try {
                    $service->sync($user, array_replace($checkIn, [
                        'client_request_id' => 'duplicate-' . $index,
                        'work_date' => '2026-06-02',
                        'start_time' => '00:30',
                        'project_id' => $requestedProject ?? $otherProjectId,
                    ]));
                    self::fail('Duplicate live check-in was accepted.');
                } catch (WorkSessionConflict $exception) {
                    self::assertSame('active_work_session', $exception->reason);
                    if ($requestedProject === $projectId) {
                        self::assertSame('Sie sind bereits in diesem Projekt eingecheckt.', $exception->getMessage());
                    } elseif ($requestedProject !== null) {
                        self::assertStringContainsString('Projekt ' . $projectNumber, $exception->getMessage());
                    } else {
                        self::assertNull($exception->visibleProject);
                        self::assertStringNotContainsString($projectNumber, $exception->getMessage());
                    }
                }
            }
            self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheets'));
            $checkout = $service->sync($user, [
                'client_request_id' => 'end-night',
                'action' => 'check_out',
                'work_date' => '2026-06-01',
                'expected_timesheet_id' => $result['timesheet']['id'],
                'end_time' => '01:00',
                'actual_end_at' => '2026-06-02T01:00',
            ]);
            self::assertSame(120, $checkout['timesheet']['net_minutes']);
            self::assertSame($projectId, $checkout['timesheet']['project_id']);

            try {
                $service->sync($user, [
                    'client_request_id' => 'late-start',
                    'action' => 'check_in',
                    'work_date' => '2026-06-02',
                    'start_time' => '00:30',
                ]);
                self::fail('An offline start would overlap the completed night shift.');
            } catch (WorkSessionConflict $exception) {
                self::assertSame('overlapping_work_session', $exception->reason);
            }
            try {
                $service->sync($otherUserId, $checkIn);
                self::fail('An operation ID owned by another account must not appear synced.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('Anfrage-ID', $exception->getMessage());
            }
            self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheets'));
        });
    }

    public function testCheckInAfterArchivedLatestBookingsPreservesEarlierWorkHistoryAndReplayReceipts(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            $connection->execute('ALTER TABLE timesheets ADD SYSTEM VERSIONING');
            $service = $this->createSyncService($connection);
            foreach ([true, false] as $keepEarlierWork) {
                [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, $keepEarlierWork ? 'archived-latest' : 'archived-all');
                $workDate = '2026-06-03';
                $oldIds = [];
                $originalCheckIn = [];
                $originalReceipt = [];
                foreach ([['07:27', '09:00'], ['09:01', '12:15'], ['12:39', '12:40'], ['12:40', '12:41']] as $index => [$start, $end]) {
                    $originalCheckIn = [
                        'client_request_id' => 'before-archive-in-' . $index . '-' . $suffix,
                        'action' => 'check_in', 'work_date' => $workDate,
                        'project_id' => $projectId, 'start_time' => $start,
                    ];
                    $originalReceipt = $service->sync($userId, $originalCheckIn);
                    $oldIds[] = $originalReceipt['timesheet']['id'];
                    $service->sync($userId, [
                        'client_request_id' => 'before-archive-out-' . $index . '-' . $suffix,
                        'action' => 'check_out', 'work_date' => $workDate,
                        'expected_timesheet_id' => $originalReceipt['timesheet']['id'], 'end_time' => $end,
                    ]);
                }
                $connection->execute('UPDATE timesheets
                    SET is_deleted = 1, deleted_at = NOW(), deleted_by_user_id = :actor, updated_at = NOW()
                    WHERE user_id = :user AND id >= :first_archived', [
                    'actor' => $userId, 'user' => $userId, 'first_archived' => $oldIds[$keepEarlierWork ? 2 : 0],
                ]);
                $lastOldId = $oldIds[array_key_last($oldIds)];
                $history = static fn (): array => $connection->fetchAll(
                    'SELECT *, ROW_START, ROW_END FROM timesheets FOR SYSTEM_TIME ALL
                     WHERE user_id = :user AND id <= :last_old ORDER BY id, ROW_START',
                    ['user' => $userId, 'last_old' => $lastOldId]
                );
                $beforeHistory = $history();
                $beforeReceipts = $connection->fetchAll('SELECT * FROM app_sync_operations WHERE user_id = :user ORDER BY id', ['user' => $userId]);
                self::assertSame($keepEarlierWork ? 2 : 4, (int) $connection->fetchColumn(
                    'SELECT COUNT(*) FROM timesheets WHERE user_id = :user AND is_deleted = 1', ['user' => $userId]
                ));

                // A previously accepted offline request remains a receipt for the
                // archived booking; replay must not reopen or replace that row.
                self::assertSame($originalReceipt, $service->sync($userId, $originalCheckIn));
                $freshCheckIn = [
                    'client_request_id' => 'after-archive-in-' . $suffix,
                    'action' => 'check_in', 'work_date' => $workDate,
                    'project_id' => $projectId, 'start_time' => '12:45',
                ];
                $fresh = $service->sync($userId, $freshCheckIn);
                self::assertGreaterThan($lastOldId, $fresh['timesheet']['id']);
                self::assertSame('12:45:00', $fresh['timesheet']['start_time']);
                self::assertNull($fresh['timesheet']['end_time']);
                self::assertSame($projectId, $fresh['timesheet']['project_id']);
                self::assertSame($fresh, $service->sync($userId, $freshCheckIn));
                self::assertSame($originalReceipt, $service->sync($userId, $originalCheckIn));

                try {
                    $service->sync($userId, [
                        'client_request_id' => 'stale-archive-out-' . $suffix,
                        'action' => 'check_out', 'work_date' => $workDate,
                        'expected_timesheet_id' => $lastOldId, 'expected_start_time' => '12:40', 'end_time' => '13:00',
                    ]);
                    self::fail('A delayed check-out for an archived booking must not close the new session.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('inzwischen geaendert', $exception->getMessage());
                }

                self::assertSame($beforeHistory, $history());
                self::assertSame($beforeReceipts, $connection->fetchAll(
                    'SELECT * FROM app_sync_operations WHERE user_id = :user AND client_request_id <> :fresh_request ORDER BY id',
                    ['user' => $userId, 'fresh_request' => $freshCheckIn['client_request_id']]
                ));
                self::assertSame(5, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheets WHERE user_id = :user', ['user' => $userId]));
                self::assertSame(1, (int) $connection->fetchColumn(
                    'SELECT COUNT(*) FROM timesheets WHERE user_id = :user AND is_deleted = 0 AND start_time IS NOT NULL AND end_time IS NULL', ['user' => $userId]
                ));
                self::assertSame($keepEarlierWork ? 3 : 1, (int) $connection->fetchColumn(
                    'SELECT COUNT(*) FROM timesheets WHERE user_id = :user AND is_deleted = 0', ['user' => $userId]
                ));
            }
        });
    }

    public function testCheckInAfterArchivedBookingsStillRejectsOverlapAndAnotherLiveSession(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'archive-conflicts');
            $connection->execute('INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, gross_minutes, net_minutes)
                VALUES (:user, :project, "2026-06-03", "09:01", "12:15", 194, 194)', ['user' => $userId, 'project' => $projectId]);
            $connection->execute('INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, is_deleted)
                VALUES (:user, :project, "2026-06-03", "12:40", "12:41", 1)', ['user' => $userId, 'project' => $projectId]);
            $before = $connection->fetchAll('SELECT * FROM timesheets ORDER BY id');
            $service = $this->createSyncService($connection);
            $checkIn = [
                'client_request_id' => 'overlapping-after-archive-' . $suffix,
                'action' => 'check_in', 'work_date' => '2026-06-03',
                'project_id' => $projectId, 'start_time' => '10:00',
            ];

            try {
                $service->sync($userId, $checkIn);
                self::fail('The retained working interval must still block an overlapping check-in.');
            } catch (WorkSessionConflict $exception) {
                self::assertSame('overlapping_work_session', $exception->reason);
            }
            self::assertSame($before, $connection->fetchAll('SELECT * FROM timesheets ORDER BY id'));
            self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM app_sync_operations'));

            $fresh = $service->sync($userId, array_replace($checkIn, [
                'client_request_id' => 'valid-after-archive-' . $suffix, 'start_time' => '12:45',
            ]));
            try {
                $service->sync($userId, array_replace($checkIn, [
                    'client_request_id' => 'duplicate-after-archive-' . $suffix, 'start_time' => '12:46',
                ]));
                self::fail('An archived predecessor must not permit duplicate active check-ins.');
            } catch (WorkSessionConflict $exception) {
                self::assertSame('active_work_session', $exception->reason);
            }
            self::assertNull($connection->fetchColumn('SELECT end_time FROM timesheets WHERE id = :id', ['id' => $fresh['timesheet']['id']]));
            self::assertSame(3, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheets'));
            self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM app_sync_operations'));
        });
    }

    public function testManualFortyFiveMinuteBreakSurvivesCheckout(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-break');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-03';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $pauseResult = $service->sync($user, [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ]);

            self::assertSame(45, $pauseResult['timesheet']['break_minutes'] ?? null);
            self::assertSame(45, $pauseResult['tracked_minutes_live_basis']['completed_break_minutes'] ?? null);

            $checkoutResult = $service->sync($user, [
                'client_request_id' => 'check-out-' . $suffix,
                'action' => 'check_out',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'end_time' => '16:00',
            ]);

            self::assertSame(480, $checkoutResult['timesheet']['gross_minutes'] ?? null);
            self::assertSame(45, $checkoutResult['timesheet']['break_minutes'] ?? null);
            self::assertSame(435, $checkoutResult['timesheet']['net_minutes'] ?? null);
            self::assertSame(45, $checkoutResult['tracked_minutes_live_basis']['completed_break_minutes'] ?? null);
            self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testManualBreakSurvivesUpsert(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-upsert');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-04';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ]);
            $result = $service->sync($user, [
                'client_request_id' => 'upsert-' . $suffix,
                'action' => 'upsert',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
                'end_time' => '16:00',
            ]);

            self::assertSame(480, $result['timesheet']['gross_minutes'] ?? null);
            self::assertSame(45, $result['timesheet']['break_minutes'] ?? null);
            self::assertSame(435, $result['timesheet']['net_minutes'] ?? null);
        });
    }

    public function testManualBreakSurvivesProjectSelectionAndNoteUpdate(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-project');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-05';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => null,
                'work_date' => $workDate,
                'start_time' => '08:00',
                'note' => 'Noch ohne Projekt',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => null,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ]);
            $projectResult = $service->sync($user, [
                'client_request_id' => 'project-' . $suffix,
                'action' => 'select_project',
                'project_id' => $projectId,
                'work_date' => $workDate,
            ]);
            $noteResult = $service->sync($user, [
                'client_request_id' => 'note-' . $suffix,
                'action' => 'upsert',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'note' => 'Projekt nachgetragen',
            ]);

            self::assertSame($projectId, $projectResult['timesheet']['project_id'] ?? null);
            self::assertSame(45, $projectResult['timesheet']['break_minutes'] ?? null);
            self::assertSame('Projekt nachgetragen', $noteResult['timesheet']['note'] ?? null);
            self::assertSame(45, $noteResult['timesheet']['break_minutes'] ?? null);
        });
    }

    public function testConfirmedProjectAssignmentCannotMoveAChangedWorkEntry(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'guarded-assignment');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-05';
            $entry = $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'work_date' => $workDate,
                'start_time' => '08:00',
                'note' => 'Aktuelle Notiz',
            ])['timesheet'];
            $assignment = [
                'client_request_id' => 'guarded-move-' . $suffix,
                'action' => 'select_project',
                'work_date' => $workDate,
                'project_id' => $projectId,
                'expected_timesheet_id' => $entry['id'],
                'expected_user_id' => $userId,
                'expected_project_id' => null,
                'expected_running' => true,
                'expected_start_time' => '08:00',
                'note' => 'Veraltete Notiz',
            ];

            $result = $service->sync($user, $assignment);
            self::assertSame($projectId, $result['timesheet']['project_id']);
            self::assertSame('Aktuelle Notiz', $result['timesheet']['note']);
            self::assertSame($result, $service->sync($user, $assignment));

            foreach ([
                ['expected_project_id' => null],
                ['expected_project_id' => $projectId, 'expected_running' => false],
                ['expected_project_id' => $projectId, 'expected_user_id' => $userId + 1],
                ['expected_project_id' => $projectId, 'expected_timesheet_id' => null],
            ] as $index => $staleExpectation) {
                try {
                    $service->sync($user, array_replace($assignment, $staleExpectation, ['client_request_id' => 'stale-move-' . $index . '-' . $suffix]));
                    self::fail('Stale project assignment should have been rejected.');
                } catch (RuntimeException $exception) {
                    self::assertSame('Der Arbeitseinsatz wurde inzwischen geaendert. Bitte neu laden.', $exception->getMessage());
                }
            }

            self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheets'));
            self::assertSame($projectId, (int) $connection->fetchColumn('SELECT project_id FROM timesheets WHERE id = :id', ['id' => $entry['id']]));
        });
    }

    public function testLegalMinimumOverridesLowerManualBreak(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-minimum');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-08';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 15,
            ]);
            $result = $service->sync($user, [
                'client_request_id' => 'check-out-' . $suffix,
                'action' => 'check_out',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'end_time' => '18:00',
            ]);

            self::assertSame(600, $result['timesheet']['gross_minutes'] ?? null);
            self::assertSame(45, $result['timesheet']['break_minutes'] ?? null);
            self::assertSame(555, $result['timesheet']['net_minutes'] ?? null);
        });
    }

    public function testCompletedStructuredBreakSurvivesCheckout(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'structured-break');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-09';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-start-' . $suffix,
                'action' => 'pause_start',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'break_started_at' => '2026-06-09 12:00:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'pause-end-' . $suffix,
                'action' => 'pause_end',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'break_ended_at' => '2026-06-09 12:45:00',
            ]);
            $result = $service->sync($user, [
                'client_request_id' => 'check-out-' . $suffix,
                'action' => 'check_out',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'end_time' => '16:00',
            ]);

            self::assertSame(45, $result['timesheet']['break_minutes'] ?? null);
            self::assertSame(435, $result['timesheet']['net_minutes'] ?? null);
            self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testOpenStructuredBreakDoesNotDiscardStoredManualBreak(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'mixed-break');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-12';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $service->sync($user, [
                'client_request_id' => 'manual-pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ]);
            $startedResult = $service->sync($user, [
                'client_request_id' => 'pause-start-' . $suffix,
                'action' => 'pause_start',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'break_started_at' => '2026-06-12 12:00:00',
            ]);

            self::assertSame(45, $startedResult['timesheet']['break_minutes'] ?? null);

            $endedResult = $service->sync($user, [
                'client_request_id' => 'pause-end-' . $suffix,
                'action' => 'pause_end',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'break_ended_at' => '2026-06-12 12:15:00',
            ]);

            self::assertSame(15, $endedResult['timesheet']['break_minutes'] ?? null);
            self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testMultipleStructuredBreaksAreAddedWithoutStoredBreakDoubleCounting(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'multiple-breaks');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-10';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);

            foreach ([
                ['10:00:00', '10:15:00'],
                ['12:00:00', '12:30:00'],
            ] as $index => [$startedAt, $endedAt]) {
                $service->sync($user, [
                    'client_request_id' => 'pause-start-' . $index . '-' . $suffix,
                    'action' => 'pause_start',
                    'project_id' => $projectId,
                    'work_date' => $workDate,
                    'break_started_at' => $workDate . ' ' . $startedAt,
                ]);
                $service->sync($user, [
                    'client_request_id' => 'pause-end-' . $index . '-' . $suffix,
                    'action' => 'pause_end',
                    'project_id' => $projectId,
                    'work_date' => $workDate,
                    'break_ended_at' => $workDate . ' ' . $endedAt,
                ]);
            }

            $result = $service->sync($user, [
                'client_request_id' => 'check-out-' . $suffix,
                'action' => 'check_out',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'end_time' => '16:00',
            ]);

            self::assertSame(45, $result['timesheet']['break_minutes'] ?? null);
            self::assertSame(435, $result['timesheet']['net_minutes'] ?? null);
            self::assertSame(2, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testManualPauseRequestRemainsIdempotent(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'manual-idempotent');
            $service = $this->createSyncService($connection);
            $user = ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']];
            $workDate = '2026-06-11';

            $service->sync($user, [
                'client_request_id' => 'check-in-' . $suffix,
                'action' => 'check_in',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'start_time' => '08:00',
            ]);
            $payload = [
                'client_request_id' => 'pause-' . $suffix,
                'action' => 'pause',
                'project_id' => $projectId,
                'work_date' => $workDate,
                'manual_break_minutes' => 45,
            ];
            $firstResult = $service->sync($user, $payload);
            $replayedResult = $service->sync($user, $payload);

            self::assertSame($firstResult, $replayedResult);
            self::assertSame(45, $replayedResult['timesheet']['break_minutes'] ?? null);
            self::assertSame(2, (int) $connection->fetchColumn('SELECT COUNT(*) FROM app_sync_operations'));
            self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheet_breaks'));
        });
    }

    public function testEmployeeCanBookAssignedProjectWithNativePdoPlaceholders(): void
    {
        $pdo = MariaDbScratchConfig::connectServer();
        self::assertInstanceOf(PDO::class, $pdo);

        $databaseName = 'timeapp_test_hy093_' . bin2hex(random_bytes(4));
        $quotedDatabaseName = MariaDbScratchConfig::quoteIdentifier($databaseName);
        $databaseCreated = false;

        try {
            try {
                $pdo->exec('CREATE DATABASE ' . $quotedDatabaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $databaseCreated = true;
            } catch (PDOException $exception) {
                self::fail('Datenbankbenutzer darf keine Scratch-Datenbank anlegen: ' . $exception->getMessage());
            }

            $connection = new DatabaseConnection(MariaDbScratchConfig::connection($databaseName));
            $this->createSchema($connection);

            $suffix = bin2hex(random_bytes(4));
            $userEmail = 'phpunit-hy093-' . $suffix . '@example.test';
            $projectNumber = 'PHP-HY093-' . $suffix;

            $connection->execute(
                'INSERT INTO users (
                    employee_number, first_name, last_name, email, password_hash, employment_status, time_tracking_required, created_at, updated_at, is_deleted
                 ) VALUES (
                    :employee_number, "PHPUnit", "Mitarbeiter", :email, "", "active", 1, NOW(), NOW(), 0
                 )',
                [
                    'employee_number' => 'HY093-' . $suffix,
                    'email' => $userEmail,
                ]
            );
            $userId = $connection->lastInsertId();

            $connection->execute(
                'INSERT INTO projects (
                    project_number, name, status, created_at, updated_at, is_deleted
                 ) VALUES (
                    :project_number, "PHPUnit HY093 Projekt", "active", NOW(), NOW(), 0
                 )',
                ['project_number' => $projectNumber]
            );
            $projectId = $connection->lastInsertId();

            $connection->execute(
                'INSERT INTO project_memberships (project_id, user_id, assignment_role, assigned_from, assigned_until)
                 VALUES (:project_id, :user_id, NULL, :assigned_from, :assigned_until)',
                [
                    'project_id' => $projectId,
                    'user_id' => $userId,
                    'assigned_from' => '2026-01-01',
                    'assigned_until' => '2026-12-31',
                ]
            );

            $service = new AppTimesheetSyncService(
                $connection,
                new TimesheetCalculator(),
                new CompanySettingsService($connection, []),
                new WorkdayStateCalculator()
            );

            $result = $service->sync(
                ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']],
                [
                    'client_request_id' => 'phpunit-hy093-' . $suffix,
                    'action' => 'check_in',
                    'project_id' => $projectId,
                    'work_date' => '2026-06-01',
                    'start_time' => '07:00',
                ]
            );

            self::assertSame('synced', $result['status'] ?? null);
            self::assertSame($projectId, $result['timesheet']['project_id'] ?? null);
        } finally {
            if ($databaseCreated) {
                try {
                    $pdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabaseName);
                } catch (PDOException) {
                    // Best-effort cleanup for scratch database.
                }
            }
        }
    }

    public function testEmployeeCannotSyncIntoFinalizedAccountingPeriod(): void
    {
        $this->withScratchDatabase(function (DatabaseConnection $connection): void {
            [$userId, $projectId, $suffix] = $this->seedAssignedUserAndProject($connection, 'locked');

            $connection->execute(
                'INSERT INTO accounting_closures (
                    closure_number, closure_type, status, period_start, period_end, project_id, user_id,
                    snapshot_hash, item_count, total_net_minutes, created_at, finalized_at
                 ) VALUES (
                    "ABR-MONTH-2026-06-LOCKED", "month", "final", "2026-06-01", "2026-06-30", NULL, NULL,
                    "hash", 0, 0, NOW(), NOW()
                 )'
            );

            $service = new AppTimesheetSyncService(
                $connection,
                new TimesheetCalculator(),
                new CompanySettingsService($connection, []),
                new WorkdayStateCalculator()
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('festgeschrieben');

            $service->sync(
                ['id' => $userId, 'permissions' => ['timesheets.create', 'timesheets.view_own']],
                [
                    'client_request_id' => 'phpunit-locked-' . $suffix,
                    'action' => 'check_in',
                    'project_id' => $projectId,
                    'work_date' => '2026-06-02',
                    'start_time' => '07:00',
                ]
            );
        });
    }

    /**
     * @param callable(DatabaseConnection): void $callback
     */
    private function withScratchDatabase(callable $callback): void
    {
        $pdo = MariaDbScratchConfig::connectServer();
        self::assertInstanceOf(PDO::class, $pdo);

        $databaseName = 'timeapp_test_appsync_' . bin2hex(random_bytes(4));
        $quotedDatabaseName = MariaDbScratchConfig::quoteIdentifier($databaseName);
        $databaseCreated = false;

        try {
            try {
                $pdo->exec('CREATE DATABASE ' . $quotedDatabaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $databaseCreated = true;
            } catch (PDOException $exception) {
                self::fail('Datenbankbenutzer darf keine Scratch-Datenbank anlegen: ' . $exception->getMessage());
            }

            $connection = new DatabaseConnection(MariaDbScratchConfig::connection($databaseName));
            $this->createSchema($connection);

            $callback($connection);
        } finally {
            if ($databaseCreated) {
                try {
                    $pdo->exec('DROP DATABASE IF EXISTS ' . $quotedDatabaseName);
                } catch (PDOException) {
                    // Best-effort cleanup for scratch database.
                }
            }
        }
    }

    private function seedAssignedUserAndProject(DatabaseConnection $connection, string $prefix): array
    {
        $suffix = bin2hex(random_bytes(4));
        $userEmail = 'phpunit-' . $prefix . '-' . $suffix . '@example.test';
        $projectNumber = 'PHP-' . strtoupper($prefix) . '-' . $suffix;

        $connection->execute(
            'INSERT INTO users (
                employee_number, first_name, last_name, email, password_hash, employment_status, time_tracking_required, created_at, updated_at, is_deleted
             ) VALUES (
                :employee_number, "PHPUnit", "Mitarbeiter", :email, "", "active", 1, NOW(), NOW(), 0
             )',
            [
                'employee_number' => strtoupper($prefix) . '-' . $suffix,
                'email' => $userEmail,
            ]
        );
        $userId = $connection->lastInsertId();

        $connection->execute(
            'INSERT INTO projects (
                project_number, name, status, created_at, updated_at, is_deleted
             ) VALUES (
                :project_number, "PHPUnit Projekt", "active", NOW(), NOW(), 0
             )',
            ['project_number' => $projectNumber]
        );
        $projectId = $connection->lastInsertId();

        $connection->execute(
            'INSERT INTO project_memberships (project_id, user_id, assignment_role, assigned_from, assigned_until)
             VALUES (:project_id, :user_id, NULL, :assigned_from, :assigned_until)',
            [
                'project_id' => $projectId,
                'user_id' => $userId,
                'assigned_from' => '2026-01-01',
                'assigned_until' => null,
            ]
        );

        return [$userId, $projectId, $suffix];
    }

    private function createSyncService(DatabaseConnection $connection): AppTimesheetSyncService
    {
        return new AppTimesheetSyncService(
            $connection,
            new TimesheetCalculator(),
            new CompanySettingsService($connection, []),
            new WorkdayStateCalculator()
        );
    }

    private function createSchema(DatabaseConnection $connection): void
    {
        $connection->execute(
            'CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                employee_number VARCHAR(30) NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                email VARCHAR(150) NULL,
                password_hash VARCHAR(255) NULL,
                employment_status ENUM("active","inactive","terminated") DEFAULT "active",
                time_tracking_required TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_deleted TINYINT(1) DEFAULT 0
            )'
        );

        $connection->execute(
            'CREATE TABLE projects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_number VARCHAR(40) NULL,
                name VARCHAR(150) NULL,
                customer_name VARCHAR(150) NULL,
                customer_signature_required TINYINT(1) DEFAULT 0,
                customer_signature_name VARCHAR(190) NULL,
                status ENUM("planning","active","paused","completed","archived") DEFAULT "planning",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_deleted TINYINT(1) DEFAULT 0
            )'
        );

        $connection->execute(
            'CREATE TABLE project_memberships (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                assignment_role VARCHAR(80) NULL,
                assigned_from DATE NULL,
                assigned_until DATE NULL
            )'
        );

        $connection->execute(
            'CREATE TABLE timesheets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                project_id INT UNSIGNED NULL,
                created_by_user_id INT UNSIGNED NULL,
                work_date DATE NULL,
                start_time TIME NULL,
                end_time TIME NULL,
                gross_minutes INT DEFAULT 0,
                break_minutes INT DEFAULT 0,
                net_minutes INT DEFAULT 0,
                expenses_amount DECIMAL(10,2) DEFAULT 0.00,
                entry_type ENUM("work","sick","vacation","holiday","absent") DEFAULT "work",
                source VARCHAR(40) DEFAULT "app",
                note TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_deleted TINYINT(1) DEFAULT 0,
                deleted_at DATETIME NULL,
                deleted_by_user_id INT UNSIGNED NULL
            )'
        );

        $connection->execute(
            'CREATE TABLE employee_account_cutovers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                active_final_user_id INT UNSIGNED NULL,
                status ENUM("draft","final","revised") NOT NULL,
                effective_from DATE NOT NULL
            )'
        );

        $connection->execute(
            'CREATE TABLE app_sync_operations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                client_request_id VARCHAR(120) NULL,
                operation_type VARCHAR(50) NULL,
                response_json TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_client_request_id (client_request_id)
            )'
        );

        $connection->execute(
            'CREATE TABLE timesheet_breaks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                timesheet_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                break_started_at DATETIME NOT NULL,
                break_ended_at DATETIME NULL,
                source VARCHAR(40) DEFAULT "app",
                note TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $connection->execute(
            'CREATE TABLE accounting_closures (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                closure_number VARCHAR(80) NOT NULL UNIQUE,
                closure_type ENUM("month","project") NOT NULL,
                status ENUM("draft","final","correction") NOT NULL DEFAULT "final",
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                project_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                original_closure_id INT UNSIGNED NULL,
                snapshot_hash VARCHAR(64) NOT NULL DEFAULT "",
                item_count INT NOT NULL DEFAULT 0,
                total_net_minutes INT NOT NULL DEFAULT 0,
                created_by_user_id INT UNSIGNED NULL,
                finalized_by_user_id INT UNSIGNED NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                finalized_at DATETIME NULL,
                note TEXT NULL
            )'
        );

        $connection->execute(
            'CREATE TABLE accounting_closure_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                closure_id INT UNSIGNED NOT NULL,
                timesheet_id INT UNSIGNED NOT NULL,
                UNIQUE KEY uniq_accounting_closure_items_timesheet (timesheet_id)
            )'
        );
    }
}
