<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Exports\TimeAccountExportService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/TimeAccountServiceTest.php';

final class TimeAccountExportServiceTest extends TestCase
{
    public function testCsvExportContainsHeadersAndRows(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user(['id' => 1, 'first_name' => 'Ada', 'last_name' => 'Export']);
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-01-02', 'entry_type' => 'work', 'net_minutes' => 120, 'is_deleted' => 0];

        $export = $this->service($pdo)->export('csv', 2026, 1, []);

        self::assertSame('text/csv; charset=utf-8', $export['headers']['Content-Type']);
        self::assertStringContainsString('zeitkonten-export-2026-01.csv', $export['headers']['Content-Disposition']);
        self::assertStringContainsString('Mitarbeiter;Jahr;Monat;Soll;Ist;Saldo', (string) $export['content']);
        self::assertStringContainsString('Ada Export', (string) $export['content']);
        self::assertStringContainsString('+02:00', (string) $export['content']);
    }

    public function testUnsupportedFormatThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service(new TimeAccountPdoDouble())->export('xml', 2026, 1, []);
    }

    public function testCsvSeparatesTakenAndFutureLeaveAtMonthEndAndKeepsLegacyColumns(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user(['vacation_days_year' => '30.00', 'target_hours_mode' => 'week', 'target_hours_week' => '40.00']);
        foreach ([['2026-01-05', 'vacation'], ['2026-02-02', 'vacation'], ['2026-01-06', 'sick']] as [$date, $type]) {
            $pdo->timesheets[] = ['user_id' => 1, 'work_date' => $date, 'entry_type' => $type, 'net_minutes' => 0, 'is_deleted' => 0];
        }

        $export = $this->service($pdo)->export('csv', 2026, 1, []);
        $row = $this->firstCsvRow((string) $export['content']);

        self::assertSame('2', $row['Urlaub genommen'], 'The established annual-booked field remains compatible.');
        self::assertSame('2026-01-31', $row['Urlaubsstand']);
        self::assertSame('Vorlaeufig', $row['Urlaubskonto Status']);
        self::assertSame('1', $row['Urlaub genommen bis Standdatum (Tage)']);
        self::assertSame('1', $row['Urlaub kuenftig genehmigt (Tage)']);
        self::assertSame('29', $row['Resturlaub am Standdatum (Tage)']);
        self::assertSame('28', $row['Resturlaub nach genehmigtem Urlaub (Tage)']);
        self::assertSame('1', $row['Urlaub im Abrechnungszeitraum (Tage)']);
        self::assertSame('1', $row['Krankheit im Abrechnungszeitraum (Tage)']);
    }

    public function testSpreadsheetPreservesLiteralNamesAndSignedDurations(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user(['first_name' => '=SUM(1,1)', 'last_name' => 'Export']);
        $pdo->timesheets[] = ['user_id' => 1, 'work_date' => '2026-01-02', 'entry_type' => 'work', 'net_minutes' => 120, 'is_deleted' => 0];
        $export = $this->service($pdo)->export('xlsx', 2026, 1, []);
        $file = tempnam(sys_get_temp_dir(), 'time_account_read_');
        self::assertIsString($file);
        file_put_contents($file, (string) $export['content']);
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $sheet = $spreadsheet->getSheet(0);
            self::assertSame('=SUM(1,1) Export', $sheet->getCell('A2')->getValue());
            self::assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
            self::assertSame('+02:00', $sheet->getCell('F2')->getValue());
            self::assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
            self::assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
            self::assertSame('B2', $sheet->getFreezePane());
            self::assertSame('Lesehinweise', $spreadsheet->getSheet(1)->getTitle());
            $spreadsheet->disconnectWorksheets();
        } finally {
            unlink($file);
        }

        $csv = $this->service($pdo)->export('csv', 2026, 1, []);
        self::assertSame("'=SUM(1,1) Export", $this->firstCsvRow((string) $csv['content'])['Mitarbeiter']);
        self::assertSame('+02:00', $this->firstCsvRow((string) $csv['content'])['Saldo']);
    }

    public function testMidmonthCutoverDoesNotHideEarlierSicknessInNewReportFields(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->users[1] = $pdo->user(['vacation_days_year' => '30.00', 'target_hours_mode' => 'week', 'target_hours_week' => '40.00']);
        $pdo->cutovers[] = [
            'id' => 11, 'user_id' => 1, 'effective_from' => '2026-01-15', 'opening_time_balance_minutes' => 0,
            'leave_year' => 2026, 'annual_leave_entitlement_days' => '30.00', 'leave_carryover_days' => '0.00',
            'opening_remaining_leave_days' => '30.00', 'status' => 'final', 'active_final_user_id' => 1,
        ];
        foreach (['2026-01-05', '2026-01-19'] as $date) {
            $pdo->timesheets[] = ['user_id' => 1, 'work_date' => $date, 'entry_type' => 'sick', 'net_minutes' => 0, 'is_deleted' => 0];
        }
        foreach (['2026-01-06', '2026-01-20'] as $date) {
            $pdo->timesheets[] = ['user_id' => 1, 'work_date' => $date, 'entry_type' => 'vacation', 'net_minutes' => 0, 'is_deleted' => 0];
        }

        $export = $this->service($pdo)->export('csv', 2026, 1, []);
        $row = $this->firstCsvRow((string) $export['content']);

        self::assertSame('1', $row['Krank'], 'Legacy time-account columns retain the cutover scope.');
        self::assertSame('2', $row['Krankheit im Abrechnungszeitraum (Tage)']);
        self::assertSame('2', $row['Urlaub genommen bis Standdatum (Tage)']);
        self::assertSame('2026-01-15', $row['Urlaubskonto Verbrauch ab']);
        self::assertSame('1', $row['Urlaubsverbrauch ab Kontostart (Tage)']);
        self::assertSame('29', $row['Resturlaub am Standdatum (Tage)']);
    }

    public function testPdfUsesReadableEmployeeGroupsAndDistinguishesScheduleFromPreview(): void
    {
        $service = $this->service(new TimeAccountPdoDouble());
        $method = new \ReflectionMethod($service, 'html');
        $html = $method->invoke($service, [[
            'Mitarbeiter' => '<Ada & Co>', 'Stichtag' => '', 'Standdatum' => '2026-01-31',
            'Urlaubsstand' => '2026-01-31', 'Urlaubskonto Status' => 'Vorlaeufig',
            'Urlaub genommen bis Standdatum (Tage)' => 2, 'Resturlaub am Standdatum (Tage)' => 28,
            'Krankheit im Abrechnungszeitraum (Tage)' => 1,
            'Kernarbeitszeit Konfiguration' => "2026-01-01 bis 2026-01-31 | Mo 08:00–16:00\nDi frei",
            'Arbeitszeitfenster Status' => 'Vorschau', 'Fenster Anrechnung Vorschau (Min)' => 450,
        ]], '2026-01');

        self::assertStringContainsString('&lt;Ada &amp; Co&gt;', $html);
        self::assertStringNotContainsString('<Ada & Co>', $html);
        self::assertStringContainsString('Urlaub genommen bis Standdatum (Tage)</th><td>2', $html);
        self::assertStringContainsString('Krankheit im Abrechnungszeitraum (Tage)</th><td>1', $html);
        self::assertStringContainsString('Kernarbeitszeit – zugeordnete Konfiguration', $html);
        self::assertStringContainsString('Arbeitszeitfenster – separate Vorschau', $html);
        self::assertStringContainsString('ohne verbindliche Abrechnungswirkung', $html);
        self::assertStringContainsString('Zeitkonto vorlaeufig', $html);
        self::assertStringContainsString('<br', $html);
    }

    public function testNewPeriodFieldsExcludeHistoricHolidayWeekendAndDuplicateAbsences(): void
    {
        $pdo = new TimeAccountPdoDouble();
        $pdo->holidayRegion = 'NW';
        $pdo->users[1] = $pdo->user(['target_hours_mode' => 'week', 'target_hours_week' => '40.00']);
        foreach ([['2026-01-01', 'sick'], ['2026-01-03', 'sick'], ['2026-01-05', 'sick'], ['2026-01-05', 'sick'],
            ['2026-01-01', 'vacation'], ['2026-01-03', 'vacation'], ['2026-01-06', 'vacation']] as [$date, $type]) {
            $pdo->timesheets[] = ['user_id' => 1, 'work_date' => $date, 'entry_type' => $type, 'net_minutes' => 0, 'is_deleted' => 0];
        }

        $row = $this->firstCsvRow((string) $this->service($pdo)->export('csv', 2026, 1, [])['content']);

        self::assertSame('3', $row['Krank']);
        self::assertSame('3', $row['Urlaub genommen']);
        self::assertSame('1', $row['Krankheit im Abrechnungszeitraum (Tage)']);
        self::assertSame('1', $row['Urlaub im Abrechnungszeitraum (Tage)']);
    }

    private function firstCsvRow(string $content): array
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);
        $headers = fgetcsv($stream, separator: ';');
        $values = fgetcsv($stream, separator: ';');
        fclose($stream);
        self::assertIsArray($headers);
        self::assertIsArray($values);
        return array_combine($headers, $values);
    }

    private function service(TimeAccountPdoDouble $pdo): TimeAccountExportService
    {
        $connection = new DatabaseConnection(['database' => 'test']);
        $property = new \ReflectionProperty(DatabaseConnection::class, 'pdo');
        $property->setAccessible(true);
        $property->setValue($connection, $pdo);

        return new TimeAccountExportService(new TimeAccountService($connection, new CalendarPolicyService($connection)));
    }
}
