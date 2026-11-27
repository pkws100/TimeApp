<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Exports\AccountingClosureService;
use App\Domain\Exports\AccountingEmployeeSummaryService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\DailyTargetService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MariaDbTestCase;
use Tests\Support\MariaDbScratchConfig;

final class AccountingDocumentSummaryDatabaseTest extends MariaDbTestCase
{
    public function testPeriodAbsencesAnnualAsOfAndConfiguredSchedulesAreStoredImmutably(): void
    {
        [$userId, $projectId, $accounts, $cutovers, $policies] = $this->fixture();
        $closures = $this->closures($accounts, $policies);
        $preview = $closures->previewPackage(['type' => 'month', 'period' => '2025-05', 'user_id' => $userId]);
        $summary = $preview['employee_summaries'][0];
        self::assertSame('preview', $preview['employee_summary_status']);
        self::assertSame('2025-05-31', $summary['as_of_date']);
        self::assertSame(1, $summary['vacation_taken_days']);
        self::assertSame(1, $summary['vacation_unpaid_days']);
        self::assertSame(1, $summary['sick_days']);
        self::assertSame(960, $summary['credited_absence_minutes']);
        self::assertSame(20, $summary['required_days']);
        self::assertSame(9600, $summary['target_minutes']);
        self::assertSame(2.0, $summary['vacation_taken_year_days']);
        self::assertSame(1.0, $summary['vacation_future_days']);
        self::assertSame(28.0, $summary['vacation_remaining_days']);
        self::assertSame(27.0, $summary['vacation_booked_remaining_days']);
        self::assertFalse($summary['vacation_is_provisional']);
        self::assertCount(2, $summary['core_time_periods']);
        self::assertSame('2025-05-15', $summary['core_time_periods'][0]['effective_to']);
        self::assertSame(2, $summary['core_time_periods'][1]['revision']);
        self::assertSame([['start' => '08:00', 'end' => '16:00']], $summary['core_time_periods'][0]['windows'][1]);
        self::assertSame(60, $summary['inside_window_minutes']);
        self::assertSame(0, $summary['outside_window_minutes']);

        $final = $closures->createClosure(['type' => 'month', 'period' => '2025-05', 'user_id' => $userId], $userId);
        self::assertSame('snapshot', $final['employee_summary_status']);
        self::assertEquals($preview['employee_summaries'], $final['employee_summaries']);
        $previewItems = array_column($preview['items'], null, 'timesheet_id');
        foreach ($final['items'] as $item) {
            $original = $previewItems[$item['timesheet_id']];
            self::assertSame($original['absence_reason_code'], $item['absence_reason_code']);
            self::assertSame($original['credited_minutes'], $item['credited_minutes']);
            self::assertSame($original['work_time_policy'], $item['work_time_policy']);
        }
        $savedEvidence = $this->connection()->fetchColumn('SELECT document_summary_json FROM accounting_closures WHERE id = :id', ['id' => $final['closure']['id']]);
        self::assertNotEmpty($savedEvidence);
        $snapshot = json_decode((string) $savedEvidence, true, flags: JSON_THROW_ON_ERROR);
        $hashInput = [
            'closure_number' => $final['closure']['closure_number'], 'closure_type' => $final['closure']['closure_type'],
            'status' => $final['closure']['status'], 'period_start' => $final['closure']['period_start'],
            'period_end' => $final['closure']['period_end'], 'project_id' => null, 'user_id' => $userId,
            'items' => array_column($preview['items'], 'row_hash'), 'document_summary' => $snapshot,
        ];
        self::assertSame($final['closure']['snapshot_hash'], hash('sha256', json_encode($hashInput, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)));

        $this->connection()->execute('UPDATE users SET vacation_days_year = 99, first_name = "Changed" WHERE id = :id', ['id' => $userId]);
        $cutovers->addManualVacationAdjustment($userId, 2025, '2025-05-20', -3, 'Spätere Berichtigung', $userId);
        $this->connection()->execute('UPDATE work_time_policy_templates SET name = "Umbenannte Vorlage"');
        $reloaded = $this->closures($accounts, new WorkTimePolicyService($this->connection(), true))->findClosure((int) $final['closure']['id']);
        self::assertSame($final['employee_summaries'], $reloaded['employee_summaries']);
        self::assertSame($final['items'], $reloaded['items']);
        self::assertSame($final['closure']['snapshot_hash'], $reloaded['closure']['snapshot_hash']);
        self::assertSame($savedEvidence, $this->connection()->fetchColumn('SELECT document_summary_json FROM accounting_closures WHERE id = :id', ['id' => $final['closure']['id']]));
    }

    public function testProjectPackagesOmitPersonnelAccountsButKeepCorePolicyEvidence(): void
    {
        [$userId, $projectId, $accounts, , $policies] = $this->fixture();
        $package = $this->closures($accounts, $policies)->previewPackage(['type' => 'project', 'project_id' => $projectId]);
        self::assertSame('not_applicable', $package['employee_summary_status']);
        self::assertSame([], $package['employee_summaries']);
        self::assertSame('Kernzeit Test', $package['items'][0]['work_time_policy']['template_name']);
        self::assertArrayNotHasKey('vacation_remaining_days', $package['items'][0]);
    }

    public function testLegacyFinalClosuresNeverAcquireCurrentVacationBalances(): void
    {
        [$userId, , $accounts, , $policies] = $this->fixture();
        $closures = $this->closures($accounts, $policies);
        $final = $closures->createClosure(['type' => 'month', 'period' => '2025-05'], $userId);
        // A pre-migration closure has no summary evidence, even with today's services installed.
        $this->connection()->execute('UPDATE accounting_closures SET document_summary_json = NULL WHERE id = :id', ['id' => $final['closure']['id']]);
        $legacy = $closures->findClosure((int) $final['closure']['id']);
        self::assertSame([], $legacy['employee_summaries']);
        self::assertSame('unavailable', $legacy['employee_summary_status']);
        self::assertArrayNotHasKey('absence_reason_code', $legacy['items'][0]);
    }

    public function testVacationReportingDateFiltersFutureJournalCorrectionsAndPreservesCurrentApi(): void
    {
        [$userId, , $accounts] = $this->fixture();
        $may = $accounts->vacationYearAsOf($userId, 2025, '2025-05-31');
        $july = $accounts->vacationYearAsOf($userId, 2025, '2025-07-31');
        self::assertSame(30.0, $may['total_days']);
        self::assertSame(35.0, $july['total_days']);
        self::assertSame(28.0, $may['remaining_at_date_days']);
        self::assertSame(32.0, $july['remaining_at_date_days']);
        self::assertSame(35.0, $accounts->vacationYear($userId, 2025)['total_days']);
        self::assertArrayNotHasKey('as_of_date', $accounts->vacationYear($userId, 2025));
        self::assertTrue($accounts->vacationYearAsOf($userId, 2026, '2026-01-31')['is_provisional']);
        self::assertTrue($accounts->vacationYearAsOf($userId, 2024, '2024-12-31')['is_provisional']);
        $this->absence($userId, '2026-01-05', 'vacation', 'vacation_paid', 480);
        self::assertSame(3.0, $accounts->vacationYearAsOf($userId, 2025, '2026-11-27')['approved_taken_past_days']);
        self::assertSame(3.0, $accounts->vacationYear($userId, 2025)['approved_taken_past_days']);
    }

    public function testUnfilteredMonthIncludesRequiredEmployeeWithoutBookingsAndNoSchedule(): void
    {
        [$bookedId, , $accounts, , $policies] = $this->fixture();
        $missingId = $this->createUser(['first_name' => 'Ohne', 'last_name' => 'Buchung']);
        $voluntaryId = $this->createUser(['first_name' => 'Freiwillig']);
        $this->connection()->execute('UPDATE users SET time_tracking_required = 0 WHERE id = :id', ['id' => $voluntaryId]);
        $closures = $this->closures($accounts, $policies);
        $all = $closures->previewPackage(['type' => 'month', 'period' => '2025-05']);
        $byUser = array_column($all['employee_summaries'], null, 'user_id');
        self::assertCount(2, $byUser);
        self::assertArrayHasKey($bookedId, $byUser);
        self::assertArrayHasKey($missingId, $byUser);
        self::assertArrayNotHasKey($voluntaryId, $byUser);
        self::assertSame(0, $byUser[$missingId]['vacation_taken_days']);
        self::assertSame(0, $byUser[$missingId]['sick_days']);
        self::assertSame([], $byUser[$missingId]['core_time_periods']);
        self::assertNull($byUser[$missingId]['inside_window_minutes']);
        self::assertNull($byUser[$missingId]['outside_window_minutes']);
        self::assertTrue($byUser[$missingId]['vacation_is_provisional']);
        $selected = $closures->previewPackage(['type' => 'month', 'period' => '2025-05', 'user_id' => $missingId]);
        self::assertSame([], $selected['items']);
        self::assertCount(1, $selected['employee_summaries']);
        self::assertSame($missingId, $selected['employee_summaries'][0]['user_id']);
    }

    public function testAnnualRecordedLeaveBeforeMidyearCutoverIsDisplayedWithoutDoubleDebit(): void
    {
        $userId = $this->createUser();
        $cutovers = new EmployeeAccountCutoverService($this->connection(), new AccountJournalService($this->connection()));
        $this->absence($userId, '2025-06-02', 'vacation', 'vacation_paid', 480);
        $cutovers->finalize(['user_id' => $userId, 'effective_from' => '2025-07-01', 'leave_year' => 2025,
            'annual_leave_entitlement_days' => 30, 'opening_remaining_leave_days' => 24], $userId);
        $this->absence($userId, '2025-07-07', 'vacation', 'vacation_paid', 480);
        $accounts = new TimeAccountService($this->connection(), new CalendarPolicyService($this->connection()));
        $vacation = $accounts->vacationYearAsOf($userId, 2025, '2025-07-31');
        self::assertSame(2.0, $vacation['recorded_taken_year_days']);
        self::assertSame(1.0, $vacation['approved_taken_past_days']);
        self::assertSame(23.0, $vacation['remaining_at_date_days']);
        self::assertSame('2025-07-01', $vacation['consumption_from_date']);
        $summary = $this->closures($accounts, new WorkTimePolicyService($this->connection()))->previewPackage(['period' => '2025-07', 'user_id' => $userId])['employee_summaries'][0];
        self::assertSame(2.0, $summary['vacation_taken_year_days']);
        self::assertSame(23.0, $summary['vacation_remaining_days']);
        self::assertSame('2025-07-01', $summary['vacation_consumption_from_date']);
    }

    #[DataProvider('rollbackEvidenceCases')]
    public function testMigrationRollbackRefusesToRemoveStoredSummaryEvidence(bool $withEvidence): void
    {
        [$userId, , $accounts, , $policies] = $this->fixture();
        $final = $this->closures($accounts, $policies)->createClosure(['period' => '2025-05'], $userId);
        if (!$withEvidence) {
            $this->connection()->execute('UPDATE accounting_closures SET document_summary_json = NULL');
        }
        $configuration = tempnam(sys_get_temp_dir(), 'timeapp-summary-migration-');
        self::assertNotFalse($configuration);
        $config = ['paths' => ['migrations' => base_path('migrations')], 'environments' => [
            'default_migration_table' => 'phinxlog', 'default_environment' => 'test',
            'test' => MariaDbScratchConfig::phinxEnvironment((string) $this->connectionConfig()['database']),
        ]];
        file_put_contents($configuration, '<?php return ' . var_export($config, true) . ';');
        try {
            $process = proc_open([PHP_BINARY, base_path('vendor/bin/phinx'), 'rollback', '-c', $configuration, '-e', 'test', '-t', '20261126120000'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertNotSame(0, proc_close($process));
            self::assertStringContainsString('Festgeschriebene Abrechnungsnachweise', $output);
            self::assertTrue($this->connection()->columnExists('accounting_closures', 'document_summary_json'));
            self::assertSame('20261127120000', (string) $this->connection()->fetchColumn('SELECT MAX(version) FROM phinxlog'));
            self::assertSame($withEvidence ? $final['employee_summaries'] : [], $this->closures($accounts, $policies)->findClosure((int) $final['closure']['id'])['employee_summaries']);
        } finally {
            unlink($configuration);
        }
    }

    public static function rollbackEvidenceCases(): iterable
    {
        yield 'stored evidence' => [true];
        yield 'empty additive column still protects earlier boundary' => [false];
    }

    private function fixture(): array
    {
        $userId = $this->createUser(['target_hours_mode' => 'week', 'target_hours_week' => 40]);
        $projectId = $this->createProject();
        $calendar = new CalendarPolicyService($this->connection());
        $calendar->saveRegion('BW');
        $journal = new AccountJournalService($this->connection());
        $cutovers = new EmployeeAccountCutoverService($this->connection(), $journal);
        $cutovers->finalize(['user_id' => $userId, 'effective_from' => '2025-01-01', 'leave_year' => 2025,
            'annual_leave_entitlement_days' => 30, 'leave_carryover_days' => 2, 'opening_remaining_leave_days' => 28], $userId);
        $cutovers->addManualVacationAdjustment($userId, 2025, '2025-04-01', 2, 'Korrektur im April', $userId);
        $cutovers->addManualVacationAdjustment($userId, 2025, '2025-07-01', 5, 'Korrektur im Juli', $userId);
        $this->absence($userId, '2025-04-14', 'vacation', 'vacation_paid', 480);
        $this->absence($userId, '2025-05-05', 'vacation', 'vacation_paid', 480);
        $this->absence($userId, '2025-05-06', 'absent', 'unpaid_leave', 0);
        $this->absence($userId, '2025-05-07', 'sick', 'sick_paid', 480);
        $this->absence($userId, '2025-05-07', 'sick', 'sick_paid', 480);
        $this->absence($userId, '2025-05-01', 'sick', 'sick_paid', 480); // A malformed historic holiday never counts as a required absence day.
        $this->absence($userId, '2025-06-02', 'vacation', null, 480); // Legacy paid vacation.
        $this->connection()->execute('INSERT INTO timesheets (user_id,project_id,work_date,start_time,end_time,gross_minutes,break_minutes,net_minutes,entry_type,source) VALUES (:user,:project,"2025-05-08","08:00","09:00",60,0,60,"work","admin")', ['user' => $userId, 'project' => $projectId]);
        $policies = new WorkTimePolicyService($this->connection(), true);
        $windows = array_fill(1, 5, [['start' => '08:00', 'end' => '16:00']]);
        $template = $policies->createTemplate(['name' => 'Kernzeit Test', 'policy' => ['windows' => $windows]], $userId);
        $next = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $assignment = $policies->assign(['user_id' => $userId, 'policy_version_id' => $template['version']['id'], 'effective_from' => $next], $userId);
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = "2025-05-01", effective_to = "2025-05-15" WHERE id = :id', ['id' => $assignment['id']]);
        $version = $policies->addVersion((int) $template['id'], ['windows' => array_fill(1, 5, [['start' => '09:00', 'end' => '17:00']])], $userId);
        $assignment = $policies->assign(['user_id' => $userId, 'policy_version_id' => $version['id'], 'effective_from' => $next], $userId);
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = "2025-05-16" WHERE id = :id', ['id' => $assignment['id']]);
        $accounts = new TimeAccountService($this->connection(), $calendar, new DailyTargetService($calendar), $journal, $cutovers);
        return [$userId, $projectId, $accounts, $cutovers, new WorkTimePolicyService($this->connection(), true)];
    }

    private function closures(TimeAccountService $accounts, WorkTimePolicyService $policies): AccountingClosureService
    {
        $service = new AccountingClosureService($this->connection(), new AdminBookingService($this->connection(), new TimesheetCalculator()));
        $service->setEmployeeSummaryService(new AccountingEmployeeSummaryService($this->connection(), $accounts,
            new DailyTargetService(new CalendarPolicyService($this->connection())), $policies, new WorkTimePreviewService($this->connection(), $policies)));
        return $service;
    }

    private function absence(int $userId, string $date, string $type, ?string $reason, int $credit): void
    {
        $this->connection()->execute('INSERT INTO timesheets (user_id,work_date,entry_type,absence_reason_code,credited_minutes,net_minutes,source) VALUES (:user,:date,:type,:reason,:credit,0,"admin")', [
            'user' => $userId, 'date' => $date, 'type' => $type, 'reason' => $reason, 'credit' => $credit,
        ]);
    }
}
