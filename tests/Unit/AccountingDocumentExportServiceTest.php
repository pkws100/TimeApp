<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exports\AccountingDocumentExportService;
use App\Domain\Settings\CompanySettingsService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AccountingDocumentExportServiceTest extends TestCase
{
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
