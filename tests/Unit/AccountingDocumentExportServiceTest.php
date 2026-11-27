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
        $result = $this->service()->export($this->personnelPackage(), 'zip');
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

    private function service(): AccountingDocumentExportService
    {
        return new AccountingDocumentExportService(new CompanySettingsService(new DatabaseConnection([]), []), ['temp_path' => $this->tempDir]);
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
