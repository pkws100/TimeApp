<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\WorkTime\WorkTimeDeviationService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Domain\WorkTime\WorkTimeReconciliationQueue;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\MariaDbTestCase;
use PHPUnit\Framework\Attributes\Group;

final class WorkTimeReconciliationQueueDatabaseTest extends MariaDbTestCase
{
    public function testDisabledWorkerAndDryRunNeverWriteSourcesQueueOrHistory(): void
    {
        [$user, $policies, $preview, $queue, $date] = $this->fixture();
        $id = $this->timesheet($user, $date);
        $before = $this->connection()->fetchAll('SELECT * FROM work_time_reconciliation_queue');
        $disabled = new WorkTimeReconciliationQueue($this->connection(), new WorkTimePreviewService($this->connection(), new WorkTimePolicyService($this->connection())));
        self::assertFalse($disabled->processBatch()['enabled']);
        self::assertSame(1, $queue->processBatch(true)['ready']);
        self::assertSame($before, $this->connection()->fetchAll('SELECT * FROM work_time_reconciliation_queue'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_runs'));
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations'));
        self::assertSame(503, (int) $this->connection()->fetchColumn('SELECT net_minutes FROM timesheets WHERE id = :id', ['id' => $id]));
    }

    public function testNewSourceIsProcessedOnceAndAnUnchangedRunDoesNoHistoricalReconciliation(): void
    {
        [$user, , , $queue, $date] = $this->fixture();
        $id = $this->timesheet($user, $date);
        $before = $this->connection()->fetchOne('SELECT *, row_start AS source_revision FROM timesheets WHERE id = :id', ['id' => $id]);
        $first = $queue->processBatch();
        self::assertSame(1, $first['processed']);
        self::assertSame(0, $first['failed']);
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations WHERE superseded_at IS NULL'));
        self::assertSame('done', $this->connection()->fetchColumn('SELECT status FROM work_time_reconciliation_queue'));
        $second = $queue->processBatch();
        self::assertSame(0, $second['claimed']);
        self::assertSame(0, $second['processed']);
        self::assertSame(0, $second['remaining']);
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_runs'));
        self::assertSame($before, $this->connection()->fetchOne('SELECT *, row_start AS source_revision FROM timesheets WHERE id = :id', ['id' => $id]));
    }

    public function testPauseEditsInvalidatePriorApprovalsWithoutChangingActualTimesheetData(): void
    {
        [$user, $policies, $preview, $queue, $date] = $this->fixture();
        $id = $this->timesheet($user, $date);
        $queue->processBatch();
        $decisions = new WorkTimeDeviationService($this->connection(), $policies);
        foreach ($this->connection()->fetchAll('SELECT id FROM work_time_deviations') as $row) {
            $decisions->decide((int) $row['id'], 'approved', $user, 'Tatsaechliche Arbeit bestaetigt', 'approval-' . $row['id']);
        }
        self::assertSame(1, $queue->processBatch()['processed']);
        self::assertSame(53, $preview->forUserPeriod($user, $date, $date)['outside_approved_minutes']);
        $before = $this->connection()->fetchOne('SELECT *, row_start AS source_revision FROM timesheets WHERE id = :id', ['id' => $id]);
        $this->connection()->execute('UPDATE timesheet_breaks SET break_ended_at = :end WHERE timesheet_id = :id', ['end' => $date . ' 12:45:00', 'id' => $id]);
        self::assertSame('pending', $this->connection()->fetchColumn('SELECT status FROM work_time_reconciliation_queue'));
        self::assertSame(0, $preview->forUserPeriod($user, $date, $date)['outside_approved_minutes']);
        self::assertSame(1, $queue->processBatch()['processed']);
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations WHERE superseded_at IS NOT NULL'));
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviation_decisions'));
        self::assertSame($before, $this->connection()->fetchOne('SELECT *, row_start AS source_revision FROM timesheets WHERE id = :id', ['id' => $id]));
        $result = json_decode((string) $this->connection()->fetchColumn('SELECT result_json FROM work_time_reconciliation_runs ORDER BY id DESC LIMIT 1'), true);
        self::assertSame(488, $result['preview']['actual_work_minutes']);
        self::assertSame(53, $result['preview']['outside_pending_minutes']);
    }

    public function testArchiveRestoreAndPauseDeletionAreAllTracked(): void
    {
        [$user, , , $queue, $date] = $this->fixture();
        $id = $this->timesheet($user, $date);
        $queue->processBatch();
        $this->connection()->execute('UPDATE timesheets SET is_deleted = 1 WHERE id = :id', ['id' => $id]);
        self::assertSame(1, $queue->processBatch()['processed']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations WHERE superseded_at IS NULL'));
        $this->connection()->execute('UPDATE timesheets SET is_deleted = 0 WHERE id = :id', ['id' => $id]);
        self::assertSame(1, $queue->processBatch()['processed']);
        self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations WHERE superseded_at IS NULL'));
        $this->connection()->execute('DELETE FROM timesheet_breaks WHERE timesheet_id = :id', ['id' => $id]);
        self::assertSame(1, $queue->processBatch()['processed']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations WHERE superseded_at IS NULL'));
        self::assertSame(503, (int) $this->connection()->fetchColumn('SELECT net_minutes FROM timesheets WHERE id = :id', ['id' => $id]));
    }

    public function testConcurrentChangesDuringPreparationAreRequeuedAndPolicyIsReadFresh(): void
    {
        [$user, $policies, $preview, , $date] = $this->fixture();
        $id = $this->timesheet($user, $date);
        $other = new DatabaseConnection($this->connectionConfig());
        $changed = false;
        $queue = new WorkTimeReconciliationQueue($this->connection(), $preview, $policies, function () use ($other, $user, $id, &$changed): void {
            if ($changed) { return; }
            $changed = true;
            $other->execute('UPDATE timesheets SET note = "Concurrent correction" WHERE id = :id', ['id' => $id]);
            $other->execute('UPDATE employee_work_time_assignments SET overrides_json = :json WHERE user_id = :user_id', ['json' => json_encode(['windows' => array_fill(1, 7, [['start' => '09:00', 'end' => '17:00']])], JSON_THROW_ON_ERROR), 'user_id' => $user]);
        });
        self::assertSame(1, $queue->processBatch()['requeued']);
        self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations'));
        self::assertSame(1, $queue->processBatch()['processed']);
        $run = json_decode((string) $this->connection()->fetchColumn('SELECT result_json FROM work_time_reconciliation_runs ORDER BY id DESC LIMIT 1'), true);
        self::assertSame(78, $run['preview']['outside_before_minutes']);
        self::assertSame(425, $run['preview']['inside_window_minutes']);
        self::assertSame(['requeued', 'done'], array_column($this->connection()->fetchAll('SELECT status FROM work_time_reconciliation_runs ORDER BY id'), 'status'));
    }

    public function testBoundedBatchAndExplicitMaintenanceBackfill(): void
    {
        [$user, , , $queue, $date] = $this->fixture();
        for ($i = 0; $i < 5; ++$i) { $this->timesheet($user, $date); }
        self::assertSame(2, $queue->processBatch(false, null, 2)['processed']);
        self::assertSame(3, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_queue WHERE status = "pending"'));
        self::assertSame(3, $queue->processBatch()['processed']);
        $before = $this->connection()->fetchAll('SELECT * FROM work_time_reconciliation_queue ORDER BY timesheet_id');
        self::assertSame(5, $queue->enqueueFullReconciliation($user, true));
        self::assertSame($before, $this->connection()->fetchAll('SELECT * FROM work_time_reconciliation_queue ORDER BY timesheet_id'));
        self::assertSame(5, $queue->enqueueFullReconciliation($user));
        self::assertSame(5, $queue->processBatch()['processed']);
        self::assertSame(10, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_deviations'), 'Maintenance must not duplicate existing segments.');
    }

    public function testFailureIsAuditedWithoutSensitiveMessageAndRetriedWithBackoff(): void
    {
        [$user, $policies, $preview, $queue, $date] = $this->fixture();
        $this->timesheet($user, $date);
        $now = new DateTimeImmutable('now');
        $failing = new WorkTimeReconciliationQueue($this->connection(), $preview, $policies, static function (): void { throw new RuntimeException('sensitive-payload-must-not-be-logged'); });
        self::assertSame(1, $failing->processBatch(false, $now)['failed']);
        self::assertSame('RuntimeException', $this->connection()->fetchColumn('SELECT last_error FROM work_time_reconciliation_queue'));
        self::assertSame(0, $queue->processBatch(false, $now->modify('+1 second'))['claimed']);
        self::assertSame(1, $queue->processBatch(false, $now->modify('+31 seconds'))['processed']);
        self::assertSame(['failed', 'done'], array_column($this->connection()->fetchAll('SELECT status FROM work_time_reconciliation_runs ORDER BY id'), 'status'));
    }

    public function testClosedAccountingPeriodsKeepExistingDecisionsUnchanged(): void
    {
        [$user, , , $queue, $date] = $this->fixture();
        $id = $this->timesheet($user, $date);
        $queue->processBatch();
        $before = $this->connection()->fetchAll('SELECT * FROM work_time_deviations ORDER BY id');
        $this->connection()->execute('INSERT INTO accounting_closures (closure_number, closure_type, status, period_start, period_end, user_id, snapshot_hash, item_count, total_net_minutes) VALUES ("QUEUE-CLOSED", "month", "final", :date_from, :date_to, :user_id, "test", 1, 503)', ['date_from' => $date, 'date_to' => $date, 'user_id' => $user]);
        // Simulate a source revision imported by a separate maintenance process.
        $this->connection()->execute('UPDATE timesheets SET note = "Imported revision" WHERE id = :id', ['id' => $id]);
        $result = $queue->processBatch();
        self::assertSame(1, $result['frozen']);
        self::assertSame($before, $this->connection()->fetchAll('SELECT * FROM work_time_deviations ORDER BY id'));
    }

    public function testKilledWorkerIsRecoveredAndParallelWorkerCannotTakeItsClaim(): void
    {
        [$user, , , $queue, $date] = $this->fixture();
        $this->timesheet($user, $date);
        $file = tempnam(sys_get_temp_dir(), 'timeapp-queue-worker-');
        $barrier = $file . '.ready';
        file_put_contents($file, json_encode($this->connectionConfig(), JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $process = proc_open([PHP_BINARY, base_path('tests/Support/work-time-queue-worker.php'), $file, $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        self::assertIsResource($process);
        try {
            for ($i = 0; $i < 500 && !is_file($barrier); ++$i) { usleep(10000); }
            self::assertFileExists($barrier);
            self::assertTrue($queue->processBatch()['locked']);
            self::assertSame('processing', $this->connection()->fetchColumn('SELECT status FROM work_time_reconciliation_queue'));
            proc_terminate($process, 9);
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($process); $process = null;
            $recovered = $queue->processBatch();
            self::assertSame(1, $recovered['recovered']);
            self::assertSame(1, $recovered['processed']);
            self::assertSame(['abandoned', 'done'], array_column($this->connection()->fetchAll('SELECT status FROM work_time_reconciliation_runs ORDER BY id'), 'status'));
        } finally {
            if (is_resource($process)) { proc_terminate($process, 9); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
            @unlink($file); @unlink($barrier);
        }
    }

    public function testAssignmentWriterWaitsForAccountingBeforeStartingItsTransaction(): void
    {
        [$user, $policies, , $queue, $date] = $this->fixture();
        $this->timesheet($user, $date);
        $file = tempnam(sys_get_temp_dir(), 'timeapp-queue-assignment-');
        $barrier = $file . '.ready';
        file_put_contents($file, json_encode($this->connectionConfig(), JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $payload = ['user_id' => $user, 'policy_version_id' => $policies->listAssignments($user)[0]['policy_version_id'], 'effective_from' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d')];
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT GET_LOCK("accounting-timesheet-write", 10)'));
        $process = proc_open([PHP_BINARY, base_path('tests/Support/work-time-queue-worker.php'), $file, $barrier, 'assign', base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        self::assertIsResource($process);
        $accountingHeld = true;
        try {
            $writerId = null;
            for ($i = 0; $i < 300; ++$i) {
                $writerId = $this->connection()->fetchColumn('SELECT IS_USED_LOCK(:name)', ['name' => 'work-time-assignment-user-' . $user]);
                if ($writerId !== null) { break; }
                usleep(10000);
            }
            self::assertNotNull($writerId, 'The assignment writer should wait behind the accounting lock.');
            self::assertSame(0, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = :writer', ['writer' => $writerId]), 'A waiting assignment writer must not hold assignment/queue row locks.');
            self::assertSame(1, $queue->processBatch()['processed'], 'The queue can materialize FK references while the assignment writer waits.');
            $this->connection()->fetchColumn('SELECT RELEASE_LOCK("accounting-timesheet-write")');
            $accountingHeld = false;
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $err ?: $out); $process = null;
            self::assertTrue(json_decode($out, true, 512, JSON_THROW_ON_ERROR)['ok']);
            self::assertSame(2, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM employee_work_time_assignments WHERE user_id = :id', ['id' => $user]));
            self::assertSame('done', $this->connection()->fetchColumn('SELECT status FROM work_time_reconciliation_queue'), 'Ending a rule only for the future must not dirty old historical work again.');
        } finally {
            if ($accountingHeld) { $this->connection()->fetchColumn('SELECT RELEASE_LOCK("accounting-timesheet-write")'); }
            if (is_resource($process)) { proc_terminate($process, 9); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
            @unlink($file); @unlink($barrier);
        }
    }

    public function testMigrationBackfillsExistingSourcesAndKeepsSystemVersionedHistory(): void
    {
        [$user, , , , $date] = $this->fixture();
        $id = $this->timesheet($user, $date);
        require_once base_path('migrations/20261111130000_work_time_reconciliation_queue.php');
        $adapter = new \Phinx\Db\Adapter\MysqlAdapter(['name' => $this->connectionConfig()['database']], null, new \Symfony\Component\Console\Output\NullOutput());
        $adapter->setConnection($this->connection()->pdo());
        $migration = new \WorkTimeReconciliationQueue('test', 20261111130000, null, new \Symfony\Component\Console\Output\NullOutput());
        $migration->setAdapter($adapter);
        $migration->down();
        self::assertFalse($this->connection()->tableExists('work_time_reconciliation_queue'));
        $this->connection()->execute('UPDATE timesheets SET end_time = "16:45:00", gross_minutes = 543, net_minutes = 513 WHERE id = :id', ['id' => $id]);
        $before = $this->connection()->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id ORDER BY row_start', ['id' => $id]);
        self::assertCount(2, $before);
        $migration->up();
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_queue WHERE timesheet_id = :id', ['id' => $id]));
        self::assertSame('pending', $this->connection()->fetchColumn('SELECT status FROM work_time_reconciliation_queue WHERE timesheet_id = :id', ['id' => $id]));
        self::assertSame($before, $this->connection()->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id ORDER BY row_start', ['id' => $id]));
        $migration->up();
        self::assertSame(1, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_queue WHERE timesheet_id = :id', ['id' => $id]));
        self::assertSame(12, (int) $this->connection()->fetchColumn('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE "wt_queue_%"'));
        self::assertSame($before, $this->connection()->fetchAll('SELECT * FROM timesheets FOR SYSTEM_TIME ALL WHERE id = :id ORDER BY row_start', ['id' => $id]));
    }

    #[Group('benchmark')]
    public function testHundredEmployeeHistoryUsesBoundedDirtyWork(): void
    {
        $connection = $this->connection();
        $policies = new WorkTimePolicyService($connection, true);
        $users = [];
        for ($index = 0; $index < 100; ++$index) {
            $connection->execute('INSERT INTO users (employee_number, first_name, last_name, email, password_hash, employment_status, time_tracking_required) VALUES (:number, "Synthetic", "Benchmark", :email, "not-a-login-hash", "active", 1)', ['number' => 'QUEUE-BENCH-' . $index, 'email' => 'queue-benchmark-' . $index . '@example.test']);
            $users[] = $connection->lastInsertId();
        }
        $template = $policies->createTemplate(['name' => 'Synthetic benchmark', 'policy' => ['windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']]), 'checkout_proposals_enabled' => false, 'outside_window_notifications_enabled' => false]], $users[0]);
        $firstYear = (int) date('Y') - 3;
        $dateFrom = $firstYear . '-01-01';
        $dateTo = ($firstYear + 2) . '-12-31';
        $dates = [];
        for ($year = $firstYear; $year < $firstYear + 3; ++$year) {
            $day = new DateTimeImmutable($year . '-01-01');
            $count = 0;
            while ($count < 240) {
                if ((int) $day->format('N') <= 5) { $dates[] = $day->format('Y-m-d'); ++$count; }
                $day = $day->modify('+1 day');
            }
        }
        foreach ($users as $user) {
            $connection->execute('INSERT INTO employee_work_time_assignments (user_id, policy_version_id, effective_from, overrides_json, created_by_user_id) VALUES (:user_id, :version_id, :date_from, "{}", :actor_id)', ['user_id' => $user, 'version_id' => $template['version']['id'], 'date_from' => $dateFrom, 'actor_id' => $users[0]]);
            $values = []; $params = [];
            foreach ($dates as $index => $date) {
                $values[] = '(:user_' . $index . ', :date_' . $index . ', "08:00:00", "12:00:00", 240, 0, 240, "work", "app")';
                $params['user_' . $index] = $user;
                $params['date_' . $index] = $date;
            }
            $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source) VALUES ' . implode(',', $values), $params);
        }
        foreach (array_slice($users, 0, 10) as $user) {
            $connection->execute('INSERT INTO timesheets (user_id, work_date, start_time, entry_type, source) VALUES (:user_id, :today, "08:00:00", "work", "app")', ['user_id' => $user, 'today' => date('Y-m-d')]);
        }
        // Synthetic fixture represents a history already reconciled once. No
        // production state is modified to obtain the idle-worker measurement.
        $connection->execute('UPDATE work_time_reconciliation_queue SET status = "done", processed_revision = revision, processed_at = NOW()');
        $connection->fetchAll('ANALYZE TABLE timesheets, work_time_reconciliation_queue');
        $historicalRows = count($users) * count($dates);
        self::assertSame(72000, $historicalRows);
        self::assertSame(72010, (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheets'));
        $legacyRangeSql = 'SELECT t.user_id, MIN(t.work_date) AS date_from, MAX(t.work_date) AS date_to FROM timesheets t JOIN users u ON u.id = t.user_id WHERE t.entry_type = "work" AND t.end_time IS NOT NULL AND COALESCE(t.is_deleted, 0) = 0 AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0 AND t.work_date <= :date_to AND EXISTS (SELECT 1 FROM employee_work_time_assignments a WHERE a.user_id = t.user_id AND a.effective_from <= t.work_date AND (a.effective_to IS NULL OR a.effective_to >= t.work_date)) GROUP BY t.user_id';
        $oldOpenSql = 'SELECT t.* FROM timesheets t JOIN users u ON u.id = t.user_id WHERE t.entry_type = "work" AND t.start_time IS NOT NULL AND t.end_time IS NULL AND COALESCE(t.is_deleted, 0) = 0 AND u.employment_status = "active" AND COALESCE(u.is_deleted, 0) = 0 AND t.work_date <= :today ORDER BY t.id';
        $newOpenSql = str_replace('COALESCE(t.is_deleted, 0) = 0', 't.is_deleted = 0', $oldOpenSql);
        $claimSql = 'SELECT * FROM work_time_reconciliation_queue WHERE status = "pending" OR (status = "failed" AND attempts < 5 AND available_at <= :now) ORDER BY available_at, timesheet_id LIMIT 100';
        $preview = new WorkTimePreviewService($connection, $policies);
        $started = microtime(true);
        $legacyRows = 0;
        foreach ($connection->fetchAll($legacyRangeSql, ['date_to' => date('Y-m-d')]) as $range) {
            // Bound the benchmark's request cache per employee so the regression
            // remains executable with ordinary CI PHP memory limits. It still
            // performs every historical interval calculation of the old algorithm.
            $legacyPreview = new WorkTimePreviewService($connection, new WorkTimePolicyService($connection, true));
            $legacyRows += count($legacyPreview->forUserPeriod((int) $range['user_id'], $range['date_from'], $range['date_to'])['rows']);
            unset($legacyPreview);
        }
        $legacySeconds = microtime(true) - $started;
        self::assertSame($historicalRows, $legacyRows);
        $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
        $worker = new WorkTimeEventService($connection, $policies, $sync, null, null, $preview);
        $started = microtime(true);
        $idle = $worker->process(false, new DateTimeImmutable('today 12:00'));
        $idleSeconds = microtime(true) - $started;
        self::assertSame(0, $idle['errors']);
        self::assertSame(0, $idle['reconciliation']['claimed']);
        self::assertSame(0, $idle['reconciliation']['processed']);
        self::assertSame(0, (int) $connection->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_runs'));
        $changedId = (int) $connection->fetchColumn('SELECT MIN(id) FROM timesheets');
        $connection->execute('UPDATE timesheets SET end_time = "12:15:00", gross_minutes = 255, net_minutes = 255 WHERE id = :id', ['id' => $changedId]);
        $started = microtime(true);
        $changed = $worker->process(false, new DateTimeImmutable('today 12:00'));
        $changedSeconds = microtime(true) - $started;
        self::assertSame(1, $changed['reconciliation']['processed']);
        self::assertSame(1, $changed['reconciliation']['claimed']);
        self::assertSame(0, $changed['reconciliation']['remaining']);
        self::assertSame(1, (int) $connection->fetchColumn('SELECT COUNT(*) FROM work_time_reconciliation_runs'));
        $report = [
            'employees' => 100, 'history_years' => 3, 'historical_timesheets' => $historicalRows, 'active_timesheets' => 10,
            'legacy_unchanged_rows_recomputed' => $legacyRows, 'incremental_unchanged_rows_recomputed' => 0, 'incremental_changed_rows_recomputed' => 1,
            'legacy_unchanged_seconds' => round($legacySeconds, 6), 'incremental_unchanged_seconds' => round($idleSeconds, 6), 'incremental_single_change_seconds' => round($changedSeconds, 6),
            'database_version' => $connection->fetchColumn('SELECT VERSION()'), 'php_version' => PHP_VERSION,
            'explain_legacy_history' => $connection->fetchAll('EXPLAIN ' . $legacyRangeSql, ['date_to' => date('Y-m-d')]),
            'explain_legacy_open' => $connection->fetchAll('EXPLAIN ' . $oldOpenSql, ['today' => date('Y-m-d')]),
            'explain_incremental_open' => $connection->fetchAll('EXPLAIN ' . $newOpenSql, ['today' => date('Y-m-d')]),
            'explain_incremental_queue' => $connection->fetchAll('EXPLAIN ' . $claimSql, ['now' => date('Y-m-d H:i:s')]),
        ];
        $path = getenv('WORK_TIME_QUEUE_BENCHMARK_REPORT') ?: sys_get_temp_dir() . '/timeapp-worktime-queue-benchmark.json';
        $temp = tempnam(dirname($path), 'timeapp-queue-report-');
        file_put_contents($temp, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($temp, 0600);
        rename($temp, $path);
        self::assertFileExists($path);
    }

    private function fixture(): array
    {
        $user = $this->createUser();
        $policies = new WorkTimePolicyService($this->connection(), true);
        $template = $policies->createTemplate(['name' => 'Queue', 'policy' => ['windows' => array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']])]], $user);
        $assignment = $policies->assign(['user_id' => $user, 'policy_version_id' => $template['version']['id'], 'effective_from' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d')], $user);
        $date = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date WHERE id = :id', ['date' => $date, 'id' => $assignment['id']]);
        $preview = new WorkTimePreviewService($this->connection(), $policies);
        $queue = new WorkTimeReconciliationQueue($this->connection(), $preview, $policies);
        return [$user, $policies, $preview, $queue, $date];
    }

    private function timesheet(int $user, string $date): int
    {
        $this->connection()->execute('INSERT INTO timesheets (user_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source) VALUES (:user_id, :date, "07:42:00", "16:35:00", 533, 30, 503, "work", "app")', ['user_id' => $user, 'date' => $date]);
        $id = $this->connection()->lastInsertId();
        $this->connection()->execute('INSERT INTO timesheet_breaks (timesheet_id, user_id, break_started_at, break_ended_at, source) VALUES (:id, :user_id, :start, :end, "app")', ['id' => $id, 'user_id' => $user, 'start' => $date . ' 12:00:00', 'end' => $date . ' 12:30:00']);
        return $id;
    }
}
