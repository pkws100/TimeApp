<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exports\AccountingDocumentExportService;
use App\Domain\Settings\CompanySettingsService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use ZipArchive;

final class AccountingDocumentExportServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/timeapp-accounting-render-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700);
    }

    protected function tearDown(): void
    {
        $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->tempDir);
    }

    public function testHtmlContainsPreliminaryMarkerAndAuditColumns(): void
    {
        $service = new AccountingDocumentExportService(new CompanySettingsService(new DatabaseConnection([]), []), []);
        $method = new ReflectionMethod($service, 'html');
        $method->setAccessible(true);

        $html = (string) $method->invoke($service, $this->package('VORLAEUFIG - nicht festgeschrieben'));

        self::assertStringContainsString('VORLAEUFIG - nicht festgeschrieben', $html);
        self::assertStringContainsString('Abrechnungs-Stundenzettel', $html);
        self::assertStringContainsString('Snapshot-Hash-123', $html);
        self::assertStringContainsString('Summen Projekte', $html);
        self::assertStringContainsString('Summen Typen', $html);
        self::assertStringContainsString('<th>Aend.</th>', $html);
        self::assertStringContainsString('<th>Hash</th>', $html);
    }

    public function testXlsxCellValuesAreProtectedAgainstFormulaInjection(): void
    {
        $service = new AccountingDocumentExportService(new CompanySettingsService(new DatabaseConnection([]), []), []);
        $method = new ReflectionMethod($service, 'safeXlsxCell');
        $method->setAccessible(true);

        self::assertSame("'=CMD()", $method->invoke($service, '=CMD()'));
        self::assertSame("'+SUM(1,1)", $method->invoke($service, '+SUM(1,1)'));
        self::assertSame('Montage', $method->invoke($service, 'Montage'));
    }

    public function testBaseFilenameUsesClosureNumber(): void
    {
        $service = new AccountingDocumentExportService(new CompanySettingsService(new DatabaseConnection([]), []), []);
        $method = new ReflectionMethod($service, 'baseFilename');
        $method->setAccessible(true);

        self::assertSame('abschluss-abr-month-2026-05-00001', $method->invoke($service, $this->package('FESTGESCHRIEBEN')));
    }

    public function testPersonnelHtmlShowsSeparatePeriodAndAnnualValuesWithoutGuessingMissingValues(): void
    {
        $service = $this->service();
        $method = new ReflectionMethod($service, 'html');
        $html = $method->invoke($service, $this->personnelPackage());

        self::assertStringContainsString('Urlaub genommen im Zeitraum (Tage)', $html);
        self::assertStringContainsString('Resturlaub am Standdatum (Tage)', $html);
        self::assertStringContainsString('Krankheit im Zeitraum (Tage)', $html);
        self::assertStringContainsString('<td>1,5</td>', $html);
        self::assertStringContainsString('Vorläufig', $html);
        self::assertStringContainsString('Automatischer Check-out', $html);
        self::assertStringContainsString('Mo 07:00–15:30', $html);
        self::assertStringContainsString('Unverbindliche Vorschau', $html);
        self::assertStringContainsString('<td>—</td>', $html);
        self::assertStringNotContainsString('Klärungsbedarf', $html);
        self::assertStringContainsString('keine genehmigten Überstunden', $html);
        self::assertStringContainsString('Kontoverbrauch ab', $html);
        self::assertStringContainsString('davor gebuchter Urlaub ist im Eröffnungsbestand berücksichtigt', $html);

        $legacyHtml = $method->invoke($service, $this->package('FESTGESCHRIEBEN'));
        self::assertStringContainsString('keine Urlaubs- und Krankheitsdaten gespeichert', $legacyHtml);
        self::assertStringNotContainsString('Resturlaub am Standdatum', $legacyHtml);
    }

    public function testRealXlsxPreservesNumericZeroUnknownBalancesAndFormulaProtection(): void
    {
        $package = $this->personnelPackage();
        $package['employee_summaries'][0]['employee_name'] = '=HYPERLINK("https://example.invalid")';
        $package['employee_summaries'][0]['employee_number'] = '';
        $package['employee_summaries'][0]['core_time_periods'][0]['template_name'] = '=CMD()';
        $book = $this->readWorkbook($this->service()->export($package, 'xlsx')['content']);
        try {
            self::assertSame(['Uebersicht', 'Buchungen', 'Mitarbeiter', 'Projekte', 'Typen', 'Abwesenheiten', 'Urlaubskonten', 'Kernarbeitszeit', 'Fensterzeiten'], $book->getSheetNames());
            self::assertSame("'=HYPERLINK(\"https://example.invalid\")", $book->getSheetByName('Urlaubskonten')->getCell('A2')->getValue());
            self::assertSame("'=CMD()", $book->getSheetByName('Kernarbeitszeit')->getCell('D2')->getValue());
            self::assertSame('s', $book->getSheetByName('Kernarbeitszeit')->getCell('D2')->getDataType());
            self::assertSame(1.5, $book->getSheetByName('Abwesenheiten')->getCell('F2')->getValue());
            self::assertSame(0, $book->getSheetByName('Abwesenheiten')->getCell('G2')->getValue());
            self::assertNull($book->getSheetByName('Urlaubskonten')->getCell('H3')->getValue());
            self::assertSame('Vorläufig', $book->getSheetByName('Urlaubskonten')->getCell('J3')->getValue());
            self::assertSame('B2', $book->getSheetByName('Urlaubskonten')->getFreezePane());
            self::assertSame('A1:L3', $book->getSheetByName('Urlaubskonten')->getAutoFilter()->getRange());
            self::assertSame('2026-03-01', $book->getSheetByName('Urlaubskonten')->getCell('K2')->getValue());
            self::assertSame(6, $book->getSheetByName('Urlaubskonten')->getCell('L2')->getValue());
            self::assertSame('Aend.', $book->getSheetByName('Buchungen')->getCell('K1')->getValue());
            self::assertSame('Hash', $book->getSheetByName('Buchungen')->getCell('M1')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function testRealZipKeepsEmployeeAccountsPrivateAndIncludesReadablePdfAndProjectPolicy(): void
    {
        $service = $this->service();
        $package = $this->personnelPackage();
        // Match the download sequence before the archive generates multiple
        // additional PDFs and workbooks within the same PHP process.
        self::assertStringStartsWith('%PDF-', $service->export($package, 'pdf')['content']);
        self::assertStringStartsWith('PK', $service->export($package, 'xlsx')['content']);
        $result = $service->export($package, 'zip');
        self::assertSame([], glob($this->tempDir . '/accounting_*'));
        $path = $this->tempDir . '/documents.zip';
        file_put_contents($path, $result['content']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $employeeFiles = [];
        $projectFiles = [];
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (str_ends_with($name, '.pdf')) {
                    self::assertStringStartsWith('%PDF-', $zip->getFromIndex($index));
                } elseif (str_contains($name, '/mitarbeiter/')) {
                    $employeeFiles[] = $name;
                    $book = $this->readWorkbook($zip->getFromIndex($index));
                    try {
                        self::assertSame(2, $book->getSheetByName('Urlaubskonten')->getHighestRow());
                        $employee = $book->getSheetByName('Urlaubskonten')->getCell('A2')->getValue();
                        self::assertContains($employee, ['M-7 Nina Feld', 'M-8 Timo Wald']);
                        self::assertSame($employee, $book->getSheetByName('Buchungen')->getCell('B2')->getValue());
                    } finally {
                        $book->disconnectWorksheets();
                    }
                } elseif (str_contains($name, '/projekte/')) {
                    $projectFiles[] = $name;
                    $book = $this->readWorkbook($zip->getFromIndex($index));
                    try {
                        self::assertNull($book->getSheetByName('Urlaubskonten'));
                        self::assertNull($book->getSheetByName('Abwesenheiten'));
                        self::assertNull($book->getSheetByName('Fensterzeiten'));
                        self::assertNotNull($book->getSheetByName('Kernarbeitszeit'));
                    } finally {
                        $book->disconnectWorksheets();
                    }
                }
            }
            self::assertCount(2, $employeeFiles);
            self::assertCount(1, $projectFiles);
        } finally {
            $zip->close();
        }
    }

    public function testFailedArchiveRenderingRemovesItsTemporaryFiles(): void
    {
        $package = $this->personnelPackage();
        $package['employee_summaries'][0]['core_time_periods'] = [null];

        try {
            $this->service()->export($package, 'zip');
            self::fail('The invalid policy must fail document rendering.');
        } catch (\TypeError $exception) {
            self::assertStringContainsString('coreTimeRow', $exception->getMessage());
        }

        self::assertSame([], glob($this->tempDir . '/accounting_*'));
    }

    public function testMixedAbsenceAndWorkDownloadsIncludeEveryZipMemberAndPrivateDiagnosticStages(): void
    {
        $package = $this->mixedBookingPackage();
        $stages = [];
        $service = $this->service(static function (string $stage) use (&$stages): void { $stages[] = $stage; });
        self::assertStringStartsWith('%PDF-', $service->export($package, 'pdf')['content']);
        self::assertStringStartsWith('PK', $service->export($package, 'xlsx')['content']);
        $stages = [];
        $result = $service->export($package, 'zip');
        self::assertSame([], glob($this->tempDir . '/accounting_*'));
        self::assertSame('zip.aggregate.pdf.render.start', $stages[0]);
        self::assertSame('zip.close.done', $stages[array_key_last($stages)]);
        foreach (['zip.aggregate', 'zip.employee.1', 'zip.project.1', 'zip.project.2'] as $member) {
            foreach (['pdf', 'xlsx'] as $format) {
                foreach (['render.start', 'render.done', 'add.done'] as $boundary) {
                    self::assertContains($member . '.' . $format . '.' . $boundary, $stages);
                }
            }
        }
        foreach ($stages as $stage) {
            self::assertMatchesRegularExpression('/\A(?:zip\.(?:aggregate|employee\.\d+|project\.\d+)\.(?:pdf|xlsx)\.(?:render\.(?:start|done)|add\.done)|zip\.close\.(?:start|done)|(?:pdf|xlsx)\.(?:construct|html|output|cleanup|collect|disconnect|write)\.(?:start|done))\z/', $stage);
        }
        $path = $this->tempDir . '/mixed.zip';
        file_put_contents($path, $result['content']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $projectRowCounts = [];
        try {
            self::assertSame(8, $zip->numFiles);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (str_ends_with($name, '.pdf')) {
                    self::assertStringStartsWith('%PDF-', $zip->getFromIndex($index));
                } elseif (str_contains($name, '/projekte/')) {
                    $book = $this->readWorkbook($zip->getFromIndex($index));
                    try {
                        self::assertNull($book->getSheetByName('Urlaubskonten'));
                        $projectRowCounts[] = $book->getSheetByName('Buchungen')->getHighestRow() - 1;
                    } finally {
                        $book->disconnectWorksheets();
                    }
                }
            }
            sort($projectRowCounts);
            self::assertSame([1, 3], $projectRowCounts);
        } finally {
            $zip->close();
        }
    }

    public function testDuplicateEmployeeLabelsUseSeparateArchiveNamesAndAmbiguousLegacyIdsRemainUnknown(): void
    {
        $service = $this->service();
        $package = $this->personnelPackage();
        $package['items'][1]['employee_number'] = 'M-7';
        $package['items'][1]['employee_name'] = 'Nina Feld';
        $package['employee_summaries'][1]['employee_number'] = 'M-7';
        $package['employee_summaries'][1]['employee_name'] = 'Nina Feld';
        $groupMethod = new ReflectionMethod($service, 'groupItems');
        $groups = $groupMethod->invoke($service, $package['items'], 'employee_number', 'employee_name', 'user_id');
        self::assertCount(2, $groups);
        self::assertNotSame($groups[0]['label'], $groups[1]['label']);
        unset($package['items'][0]['user_id']);
        $resolveMethod = new ReflectionMethod($service, 'resolveMissingItemIds');
        self::assertArrayNotHasKey('user_id', $resolveMethod->invoke($service, $package)['items'][0]);

        $package['closure']['user_id'] = 7;
        self::assertSame(7, $resolveMethod->invoke($service, $package)['items'][0]['user_id']);
    }

    public function testProjectScopeSuppressesPersonalSectionsEvenIfPackageContainsAccounts(): void
    {
        $package = $this->personnelPackage();
        $package['closure']['project_id'] = 1;
        $method = new ReflectionMethod($this->service(), 'html');
        $html = $method->invoke($this->service(), $package);
        self::assertStringNotContainsString('Urlaubskonten', $html);
        self::assertStringNotContainsString('Krankheit im Zeitraum', $html);
        self::assertStringNotContainsString('Sollzeit im Zeitraum', $html);
        self::assertStringContainsString('Mo 07:00–15:30', $html);
    }

    public function testSelectedEmployeeWithoutBookingsStillGetsOwnZipDocuments(): void
    {
        $package = $this->personnelPackage();
        $package['closure']['user_id'] = 7;
        $package['closure']['item_count'] = 0;
        $package['closure']['total_net_minutes'] = 0;
        $package['items'] = [];
        $package['employee_totals'] = $package['project_totals'] = $package['entry_type_totals'] = [];
        $package['employee_summaries'] = [$package['employee_summaries'][0]];
        $result = $this->service()->export($package, 'zip');
        $path = $this->tempDir . '/without-bookings.zip';
        file_put_contents($path, $result['content']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        try {
            self::assertSame(4, $zip->numFiles);
            $book = $this->readWorkbook($zip->getFromName('abschluss-abr-month-2026-05-00001/mitarbeiter/mitarbeiter-m-7-nina-feld.xlsx'));
            try {
                self::assertSame('M-7 Nina Feld', $book->getSheetByName('Urlaubskonten')->getCell('A2')->getValue());
                self::assertSame(25, $book->getSheetByName('Urlaubskonten')->getCell('H2')->getValue());
                self::assertSame('Keine Buchungen', $book->getSheetByName('Buchungen')->getCell('A1')->getValue());
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            $zip->close();
        }
    }

    public function testMixedEmployeesClearlyShowMissingCoreTimeAssignment(): void
    {
        $package = $this->personnelPackage();
        $package['employee_summaries'][1]['core_time_periods'] = [];
        $method = new ReflectionMethod($this->service(), 'coreTimeRows');
        $rows = $method->invoke($this->service(), $package);
        self::assertCount(2, $rows);
        self::assertSame('M-8 Timo Wald', $rows[1]['Mitarbeiter']);
        self::assertSame('Keine Kernarbeitszeit zugeordnet', $rows[1]['Regel']);
        self::assertNull($rows[1]['Version']);
        self::assertSame('Keine Zuordnung', $rows[1]['Aktivierung']);
    }

    private function service(?\Closure $diagnostics = null): AccountingDocumentExportService
    {
        return new AccountingDocumentExportService(new CompanySettingsService(new DatabaseConnection([]), []), ['temp_path' => $this->tempDir], $diagnostics);
    }

    private function readWorkbook(string $content): Spreadsheet
    {
        $path = $this->tempDir . '/book-' . bin2hex(random_bytes(4)) . '.xlsx';
        file_put_contents($path, $content);

        return IOFactory::load($path);
    }

    private function personnelPackage(): array
    {
        $package = $this->package('FESTGESCHRIEBEN');
        $policy = [
            'effective_from' => '2026-05-01', 'effective_to' => '2026-05-31', 'template_name' => 'Baustelle Regelzeit',
            'revision' => 3, 'timezone' => 'Europe/Berlin', 'windows' => [1 => [['start' => '07:00', 'end' => '15:30']], 5 => [['start' => '07:00', 'end' => '12:00']]],
            'checkout_mode' => 'automatic', 'enabled' => true,
        ];
        $package['items'][0]['work_time_policy'] = $policy;
        $package['items'][1] = array_replace($package['items'][0], ['user_id' => 8, 'employee_number' => 'M-8', 'employee_name' => 'Timo Wald']);
        $summary = [
            'user_id' => 7, 'employee_number' => 'M-7', 'employee_name' => 'Nina Feld', 'leave_year' => 2026,
            'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'as_of_date' => '2026-05-31',
            'vacation_taken_days' => 1.5, 'vacation_unpaid_days' => 0, 'sick_days' => 2,
            'credited_absence_minutes' => 1680, 'vacation_entitlement_days' => 30, 'vacation_total_days' => 35,
            'vacation_taken_year_days' => 10, 'vacation_future_days' => 4, 'vacation_remaining_days' => 25,
            'vacation_consumption_from_date' => '2026-03-01', 'vacation_taken_account_days' => 6,
            'vacation_booked_remaining_days' => 21, 'vacation_is_provisional' => false,
            'target_minutes' => 9600, 'required_days' => 20, 'core_time_periods' => [$policy],
            'inside_window_minutes' => 480, 'outside_window_minutes' => 0, 'window_is_provisional' => true,
        ];
        $package['employee_summaries'] = [$summary, array_replace($summary, [
            'user_id' => 8, 'employee_number' => 'M-8', 'employee_name' => 'Timo Wald',
            'vacation_remaining_days' => null, 'vacation_booked_remaining_days' => null,
            'vacation_is_provisional' => true, 'inside_window_minutes' => null, 'outside_window_minutes' => null,
        ])];
        $package['employee_summary_status'] = 'snapshot';
        $package['closure']['item_count'] = 2;
        $package['closure']['total_net_minutes'] = 960;
        $package['employee_totals'][] = array_replace($package['employee_totals'][0], ['label' => 'M-8 Timo Wald']);
        foreach (['project_totals', 'entry_type_totals'] as $key) {
            foreach ($package[$key][0] as $field => $value) {
                if (is_int($value)) {
                    $package[$key][0][$field] = $value * 2;
                }
            }
        }

        return $package;
    }

    private function mixedBookingPackage(): array
    {
        $package = $this->personnelPackage();
        $package['closure']['user_id'] = 7;
        $package['closure']['item_count'] = 4;
        $package['closure']['total_net_minutes'] = 450;
        $package['employee_summaries'] = [$package['employee_summaries'][0]];
        $package['employee_summaries'][0]['vacation_taken_days'] = 1;
        $package['employee_summaries'][0]['vacation_unpaid_days'] = 1;
        $package['employee_summaries'][0]['sick_days'] = 1;
        $package['employee_summaries'][0]['credited_absence_minutes'] = 960;
        $work = $package['items'][0];
        $work['work_date'] = '2026-05-07';
        $work['start_time'] = '08:00:00';
        $work['end_time'] = '16:00:00';
        $work['gross_minutes'] = 480;
        $work['net_minutes'] = 450;
        $work['row_hash'] = hash('sha256', 'mixed-work-booking');
        $work['work_time_policy']['windows'] = array_fill(1, 5, [['start' => '07:00', 'end' => '16:00']]);
        $package['employee_summaries'][0]['core_time_periods'][0]['windows'] = $work['work_time_policy']['windows'];
        $work['pause_assessment'] = [
            'linked_work_dates' => ['2026-05-07'], 'calculation_status' => 'resolved',
            'documented_break_minutes' => 30, 'required_break_minutes' => 30,
            'missing_break_minutes' => 0, 'legacy_deduction_minutes' => 0,
        ];
        $package['items'] = [];
        foreach ([['vacation', 'vacation_paid', 480], ['sick', 'sick_paid', 480], ['absent', 'unpaid_leave', 0]] as $index => [$type, $reason, $credit]) {
            $absence = array_replace($work, [
                'project_id' => null, 'project_number' => '', 'project_name' => '',
                'work_date' => '2026-05-0' . ($index + 4), 'entry_type' => $type,
                'absence_reason_code' => $reason, 'credited_minutes' => $credit,
                'start_time' => null, 'end_time' => null, 'gross_minutes' => 0,
                'break_minutes' => 0, 'net_minutes' => 0, 'source_label' => 'Admin',
            ]);
            unset($absence['pause_assessment']);
            $package['items'][] = $absence;
        }
        $package['items'][] = $work;
        $package['employee_totals'] = [array_replace($package['employee_totals'][0], ['count' => 4, 'gross_minutes' => 480, 'net_minutes' => 450])];
        $package['project_totals'] = [$package['project_totals'][0], [
            'label' => 'Ohne Projekt', 'count' => 3, 'gross_minutes' => 0, 'break_minutes' => 0, 'net_minutes' => 0,
        ]];
        $package['project_totals'][0] = array_replace($package['project_totals'][0], ['count' => 1, 'gross_minutes' => 480, 'break_minutes' => 30, 'net_minutes' => 450]);
        $package['entry_type_totals'] = [['label' => 'work', 'count' => 1, 'gross_minutes' => 480, 'break_minutes' => 30, 'net_minutes' => 450]];
        foreach (['vacation', 'sick', 'absent'] as $type) {
            $package['entry_type_totals'][] = ['label' => $type, 'count' => 1, 'gross_minutes' => 0, 'break_minutes' => 0, 'net_minutes' => 0];
        }

        return $package;
    }

    private function package(string $statusLabel): array
    {
        return [
            'closure' => [
                'closure_number' => 'ABR-MONTH-2026-05-00001',
                'status_label' => $statusLabel,
                'period_label' => '2026-05',
                'snapshot_hash' => 'Snapshot-Hash-123',
                'item_count' => 1,
                'total_net_minutes' => 480,
                'created_at' => '2026-06-01 10:00:00',
                'finalized_at' => $statusLabel === 'FESTGESCHRIEBEN' ? '2026-06-01 10:01:00' : null,
            ],
            'items' => [[
                'user_id' => 7,
                'project_id' => 1,
                'work_date' => '2026-05-04',
                'employee_number' => 'M-7',
                'employee_name' => 'Nina Feld',
                'project_number' => 'P-1',
                'project_name' => 'Kita Nord',
                'entry_type' => 'work',
                'start_time' => '07:00:00',
                'end_time' => '15:30:00',
                'gross_minutes' => 510,
                'break_minutes' => 30,
                'net_minutes' => 480,
                'source_label' => 'App',
                'change_count' => 2,
                'note' => 'Montage',
                'row_hash' => 'Row-Hash-123',
            ]],
            'employee_totals' => [[
                'label' => 'M-7 Nina Feld',
                'count' => 1,
                'gross_minutes' => 510,
                'break_minutes' => 30,
                'net_minutes' => 480,
            ]],
            'project_totals' => [[
                'label' => 'P-1 Kita Nord',
                'count' => 1,
                'gross_minutes' => 510,
                'break_minutes' => 30,
                'net_minutes' => 480,
            ]],
            'entry_type_totals' => [[
                'label' => 'work',
                'count' => 1,
                'gross_minutes' => 510,
                'break_minutes' => 30,
                'net_minutes' => 480,
            ]],
        ];
    }
}
