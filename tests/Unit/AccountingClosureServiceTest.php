<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exports\AccountingClosureService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AccountingClosureServiceTest extends TestCase
{
    public function testSelectionNormalizesMonthAndProjectFilters(): void
    {
        $service = $this->service();

        $month = $service->selectionFromInput(['type' => 'month', 'period' => '2026-05', 'project_id' => '7', 'user_id' => '9']);
        self::assertSame('month', $month['type']);
        self::assertSame('2026-05-01', $month['date_from']);
        self::assertSame('2026-05-31', $month['date_to']);
        self::assertSame(7, $month['project_id']);
        self::assertSame(9, $month['user_id']);

        $project = $service->selectionFromInput(['type' => 'project', 'date_from' => '2026-04-10', 'date_to' => '2026-04-01', 'project_id' => '5']);
        self::assertSame('project', $project['type']);
        self::assertSame('2026-04-01', $project['date_from']);
        self::assertSame('2026-04-10', $project['date_to']);
        self::assertSame(5, $project['project_id']);
    }

    public function testSnapshotRowsKeepStableHashesAndTotals(): void
    {
        $service = $this->service();
        $snapshotRows = new ReflectionMethod($service, 'snapshotRows');
        $snapshotRows->setAccessible(true);
        $package = new ReflectionMethod($service, 'package');
        $package->setAccessible(true);

        $items = $snapshotRows->invoke($service, [
            $this->bookingRow(['id' => 1, 'employee_name' => 'Nina Feld', 'net_minutes' => 480]),
            $this->bookingRow(['id' => 2, 'employee_name' => 'Nina Feld', 'net_minutes' => 60, 'entry_type' => 'sick']),
        ]);
        $result = $package->invoke($service, [
            'closure_number' => 'VORLAEUFIG-MONTH-2026-05',
            'closure_type' => 'month',
            'status' => 'draft',
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'item_count' => 2,
            'total_net_minutes' => 540,
        ], $items);

        self::assertCount(2, $items);
        self::assertSame(64, strlen((string) $items[0]['row_hash']));
        self::assertNotSame($items[0]['row_hash'], $items[1]['row_hash']);
        self::assertSame('M-7 Nina Feld', $result['employee_totals'][0]['label']);
        self::assertSame(540, $result['employee_totals'][0]['net_minutes']);
        self::assertSame('work', $result['entry_type_totals'][1]['label']);
    }

    public function testAutomaticCorrectionClosuresAreRejectedUntilDeltaWorkflowExists(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->createClosure(['type' => 'month', 'period' => '2026-05'], 7, 'correction', 1);
    }

    private function service(): AccountingClosureService
    {
        $connection = new DatabaseConnection([]);

        return new AccountingClosureService($connection, new AdminBookingService($connection, new TimesheetCalculator()));
    }

    private function bookingRow(array $overrides = []): array
    {
        return [
            'id' => 1,
            'work_date' => '2026-05-04',
            'user_id' => 7,
            'employee_number' => 'M-7',
            'employee_name' => 'Nina Feld',
            'project_id' => 5,
            'project_number' => 'P-1',
            'project_name_display' => 'Kita Nord',
            'entry_type' => 'work',
            'source' => 'app',
            'source_label' => 'App',
            'start_time' => '07:00:00',
            'end_time' => '15:30:00',
            'gross_minutes' => 510,
            'break_minutes' => 30,
            'net_minutes' => 480,
            'expenses_amount' => '0.00',
            'note' => 'Montage',
            'change_count' => 0,
            'version_hint' => 'Originalstand',
            'updated_at' => '2026-05-04 15:30:00',
            ...$overrides,
        ];
    }
}
