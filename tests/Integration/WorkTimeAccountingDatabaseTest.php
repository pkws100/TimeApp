<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Exports\AccountingClosureService;
use App\Domain\Exports\TimeAccountExportService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Domain\WorkTime\WorkTimeDeviationService;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\MariaDbTestCase;

final class WorkTimeAccountingDatabaseTest extends MariaDbTestCase
{
    public function testDisabledFeaturePreservesEntireAccountAndExportPayloads(): void
    {
        [$userId, , $date, $policies] = $this->fixture();
        $this->booking($userId, $this->createProject(), $date, '07:50', '08:10', 20);
        $legacy = $this->accounts();
        $disabled = $this->accounts(new WorkTimePreviewService($this->connection(), new WorkTimePolicyService($this->connection(), false)));
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);

        self::assertTrue($policies->enabled());
        self::assertSame($legacy->monthlyAccount($userId, $year, $month), $disabled->monthlyAccount($userId, $year, $month));
        self::assertSame($legacy->adminExportRows($year, $month), $disabled->adminExportRows($year, $month));
    }

    public function testWindowPreviewKeepsActualWorkCutoverAndJournalsUnchanged(): void
    {
        [$userId, , $date, $policies] = $this->fixture();
        $this->booking($userId, $this->createProject(), $date, '07:50', '08:10', 20);
        $before = $this->accountingEvidence($userId);
        $service = $this->accounts(new WorkTimePreviewService($this->connection(), $policies));

        $account = $service->monthlyAccount($userId, (int) substr($date, 0, 4), (int) substr($date, 5, 2));

        self::assertSame(20, $account['actual_work_minutes']);
        self::assertSame(20, $account['period_delta_minutes']);
        self::assertSame(80, $account['closing_balance_minutes']);
        self::assertTrue($account['balance_is_provisional']);
        self::assertTrue($account['work_time_window_preview']['preview_only']);
        self::assertSame(10, $account['work_time_window_preview']['inside_window_minutes']);
        self::assertSame(10, $account['work_time_window_preview']['outside_pending_minutes']);
        self::assertSame(10, $account['work_time_window_preview']['accountable_work_minutes']);
        self::assertSame(10, $account['work_time_window_preview']['period_delta_minutes']);
        self::assertSame(70, $account['work_time_window_preview']['closing_balance_minutes']);
        self::assertSame($before, $this->accountingEvidence($userId));
    }

    public function testEnabledFeatureDoesNotChangeAnEmployeeWithoutAnAssignment(): void
    {
        $userId = $this->createUser(['target_hours_month' => 0]);
        $date = (new DateTimeImmutable('first day of last month'))->format('Y-m-d');
        $this->booking($userId, $this->createProject(), $date, '07:50', '08:10', 20);
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);
        $legacy = $this->accounts();
        $preview = new WorkTimePreviewService($this->connection(), new WorkTimePolicyService($this->connection(), true));
        $enabled = $this->accounts($preview);

        self::assertSame($legacy->monthlyAccount($userId, $year, $month), $enabled->monthlyAccount($userId, $year, $month));
        self::assertSame($legacy->adminExportRows($year, $month), $enabled->adminExportRows($year, $month));
    }

    public function testDurationOnlyPauseNeverProducesInventedWindowBalance(): void
    {
        [$userId, , $date, $policies] = $this->fixture();
        $this->booking($userId, $this->createProject(), $date, '07:50', '08:50', 50, 10);
        $service = $this->accounts(new WorkTimePreviewService($this->connection(), $policies));

        $account = $service->monthlyAccount($userId, (int) substr($date, 0, 4), (int) substr($date, 5, 2));

        self::assertSame(50, $account['actual_work_minutes']);
        self::assertSame(110, $account['closing_balance_minutes']);
        self::assertTrue($account['balance_is_provisional']);
        self::assertNull($account['work_time_window_preview']['accountable_work_minutes']);
        self::assertNull($account['work_time_window_preview']['period_delta_minutes']);
        self::assertNull($account['work_time_window_preview']['closing_balance_minutes']);
    }

    public function testApprovedDeviationAddsOnlyToTheSeparateWindowPreview(): void
    {
        [$userId, , $date, $policies] = $this->fixture();
        $this->booking($userId, $this->createProject(), $date, '07:50', '08:10', 20);
        $before = $this->accountingEvidence($userId);
        $preview = new WorkTimePreviewService($this->connection(), $policies);
        self::assertSame(1, $preview->reconcileUser($userId, $date, $date)['created']);
        $deviation = $this->connection()->fetchOne('SELECT id FROM work_time_deviations WHERE user_id = :user_id', ['user_id' => $userId]);
        (new WorkTimeDeviationService($this->connection(), $policies))->decide((int) $deviation['id'], 'approved', $this->createUser(), 'Angeordnete Arbeit dokumentiert', 'accounting-approval');

        $account = $this->accounts($preview)->monthlyAccount($userId, (int) substr($date, 0, 4), (int) substr($date, 5, 2));

        self::assertSame(20, $account['actual_work_minutes']);
        self::assertSame(80, $account['closing_balance_minutes']);
        self::assertSame(20, $account['work_time_window_preview']['accountable_work_minutes']);
        self::assertSame(80, $account['work_time_window_preview']['closing_balance_minutes']);
        self::assertFalse($account['balance_is_provisional']);
        self::assertTrue($account['work_time_window_preview']['preview_only']);
        self::assertSame($before, $this->accountingEvidence($userId));
    }

    public function testPreviousMonthUncertaintyRemainsVisibleOnFirstDayOfNextMonth(): void
    {
        [$userId, , $date, $policies] = $this->fixture();
        $this->booking($userId, $this->createProject(), $date, '07:50', '08:10', 20);
        $nextMonth = (new DateTimeImmutable($date))->modify('first day of next month');
        $service = $this->accounts(new WorkTimePreviewService($this->connection(), $policies));

        $account = $service->employeeMonthlyAccount($userId, (int) $nextMonth->format('Y'), (int) $nextMonth->format('m'), $nextMonth->format('Y-m-d'));

        self::assertSame(0, $account['period_delta_minutes']);
        self::assertSame(80, $account['employee_balance']['minutes']);
        self::assertTrue($account['employee_balance']['is_provisional']);
        self::assertTrue($account['work_time_window_preview']['cumulative_unresolved']);
        self::assertSame(10, $account['work_time_window_preview']['cumulative_outside_pending_minutes']);
        self::assertSame(70, $account['work_time_window_preview']['closing_balance_minutes']);
    }

    public function testAccountingClosureBlocksUnresolvedWorkOnlyInItsSelectedProject(): void
    {
        [$userId, , $date, $policies] = $this->fixture();
        $outsideProject = $this->createProject();
        $insideProject = $this->createProject();
        $this->booking($userId, $outsideProject, $date, '07:50', '08:10', 20);
        $this->booking($userId, $insideProject, $date, '08:10', '08:30', 20);
        $service = new AccountingClosureService($this->connection(), new AdminBookingService($this->connection(), new TimesheetCalculator()));
        $service->setWorkTimePreviewService(new WorkTimePreviewService($this->connection(), $policies));
        $input = ['type' => 'project', 'date_from' => $date, 'date_to' => $date, 'project_id' => $insideProject];

        self::assertTrue($service->validateFinalization($service->selectionFromInput($input))['ok']);
        $input['project_id'] = $outsideProject;
        $blocked = $service->validateFinalization($service->selectionFromInput($input));
        self::assertFalse($blocked['ok']);
        self::assertStringContainsString('Arbeitszeitfenster', implode(' ', $blocked['errors']));

        $input['project_id'] = $insideProject;
        self::assertSame('final', $service->createClosure($input, $userId)['closure']['status']);
    }

    public function testNewCutoverCannotHideUnresolvedWindowTimesInTheLegacyPeriod(): void
    {
        [$userId, , $date, $policies] = $this->fixture(false);
        $this->booking($userId, $this->createProject(), $date, '07:50', '08:10', 20);
        $before = $this->accountingEvidence($userId);
        $cutovers = new EmployeeAccountCutoverService($this->connection(), new AccountJournalService($this->connection()));
        $cutovers->setWorkTimePreviewService(new WorkTimePreviewService($this->connection(), $policies));
        try {
            $cutovers->finalize(['user_id' => $userId, 'effective_from' => (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')], $userId);
            self::fail('Ein neuer Stichtag darf ungeklärte Fensterzeiten nicht festschreiben.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Aussenzeiten', $error->getMessage());
        }
        self::assertSame($before, $this->accountingEvidence($userId));
    }

    public function testCsvXlsxAndPdfExportContainSeparateWindowPreview(): void
    {
        [$userId, , $date, $policies] = $this->fixture();
        $this->booking($userId, $this->createProject(), $date, '07:50', '08:10', 20);
        $accounts = $this->accounts(new WorkTimePreviewService($this->connection(), $policies));
        $exporter = new TimeAccountExportService($accounts);
        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);
        $filters = ['user_id' => $userId];
        $csv = $exporter->export('csv', $year, $month, $filters);

        self::assertStringContainsString('Fenster Anrechnung Vorschau (Min)', $csv['content']);
        self::assertStringContainsString('Vorlaeufig:', $csv['content']);
        $tempFile = tempnam(sys_get_temp_dir(), 'timeapp-window-export-');
        self::assertNotFalse($tempFile);
        try {
            file_put_contents($tempFile, $exporter->export('xlsx', $year, $month, $filters)['content']);
            $sheet = IOFactory::load($tempFile)->getActiveSheet()->toArray();
            $values = array_combine($sheet[0], $sheet[1]);
            self::assertSame('00:20', $values['Tatsaechliche Arbeitszeit']);
            self::assertSame(10, (int) $values['Fenster Anrechnung Vorschau (Min)']);
            self::assertSame('+01:10', $values['Fenster Gesamtstand Vorschau']);
            self::assertSame('Ja', $values['Saldo vorlaeufig']);
        } finally {
            unlink($tempFile);
        }

        $pdf = $exporter->export('pdf', $year, $month, $filters);
        self::assertSame('application/pdf', $pdf['headers']['Content-Type']);
        self::assertStringStartsWith('%PDF-', $pdf['content']);
        self::assertGreaterThan(2000, strlen($pdf['content']));
    }

    /** @return array{int,int,string,WorkTimePolicyService} */
    private function fixture(bool $withCutover = true): array
    {
        $date = (new DateTimeImmutable('first day of last month', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        $userId = $this->createUser(['target_hours_month' => 0]);
        $cutovers = new EmployeeAccountCutoverService($this->connection(), new AccountJournalService($this->connection()));
        $cutover = $withCutover ? $cutovers->finalize(['user_id' => $userId, 'effective_from' => $date, 'opening_time_balance_minutes' => 60], $userId) : ['id' => 0];
        $policies = new WorkTimePolicyService($this->connection(), true);
        $windows = array_fill(1, 7, [['start' => '08:00', 'end' => '16:00']]);
        $template = $policies->createTemplate(['name' => 'Fenster Test', 'policy' => ['windows' => $windows]], $userId);
        $assignment = $policies->assign([
            'user_id' => $userId,
            'policy_version_id' => $template['version']['id'],
            'effective_from' => (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
        ], $userId);
        // Synthetic scratch fixture simulates a previously activated rule. The public
        // service correctly permits only future assignments, never historical edits.
        $this->connection()->execute('UPDATE employee_work_time_assignments SET effective_from = :date WHERE id = :id', ['date' => $date, 'id' => $assignment['id']]);

        return [$userId, (int) $cutover['id'], $date, $policies];
    }

    private function accounts(?WorkTimePreviewService $preview = null): TimeAccountService
    {
        $service = new TimeAccountService($this->connection(), new CalendarPolicyService($this->connection()));
        if ($preview !== null) {
            $service->setWorkTimePreviewService($preview);
        }
        return $service;
    }

    private function booking(int $userId, int $projectId, string $date, string $start, string $end, int $net, int $pause = 0): void
    {
        $this->connection()->execute(
            'INSERT INTO timesheets (user_id, project_id, work_date, start_time, end_time, gross_minutes, break_minutes, net_minutes, entry_type, source, created_at, updated_at) VALUES (:user_id, :project_id, :date, :start_time, :end_time, :gross, :pause, :net, "work", "admin", NOW(), NOW())',
            ['user_id' => $userId, 'project_id' => $projectId, 'date' => $date, 'start_time' => $start, 'end_time' => $end, 'gross' => $net + $pause, 'pause' => $pause, 'net' => $net]
        );
    }

    private function accountingEvidence(int $userId): array
    {
        $data = [];
        foreach (['employee_account_cutovers', 'time_account_entries', 'vacation_account_entries', 'timesheets'] as $table) {
            $data[$table] = $this->connection()->fetchAll('SELECT * FROM ' . $table . ' WHERE user_id = :user_id ORDER BY id', ['user_id' => $userId]);
        }
        return $data;
    }
}
