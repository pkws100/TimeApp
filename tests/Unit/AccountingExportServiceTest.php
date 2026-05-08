<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exports\AccountingExportService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class AccountingExportServiceTest extends TestCase
{
    public function testMapRowsCreatesStableAccountingShape(): void
    {
        $service = new AccountingExportService(
            new AdminBookingService(new DatabaseConnection([]), new TimesheetCalculator())
        );

        $rows = $service->mapRows([
            [
                'work_date' => '2026-04-23',
                'employee_number' => 'MA-0007',
                'first_name' => 'Nina',
                'last_name' => 'Feld',
                'project_number' => '2026-001',
                'project_name' => 'Neubau Kita Nord',
                'entry_type' => 'work',
                'gross_minutes' => 540,
                'break_minutes' => 45,
                'net_minutes' => 495,
                'expenses_amount' => '12.50',
                'note' => 'Materialannahme',
            ],
        ]);

        self::assertSame('MA-0007', $rows[0]['employee_number']);
        self::assertSame('Nina Feld', $rows[0]['employee_name']);
        self::assertSame('2026-001', $rows[0]['project_number']);
        self::assertSame(495, $rows[0]['net_minutes']);
        self::assertSame('12.50', $rows[0]['expenses_amount']);
    }
}
