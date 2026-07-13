<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\TimesheetService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TimesheetServiceTest extends TestCase
{
    public function testLegacyUnpaidVacationUsesAbsenceTypeInReports(): void
    {
        $service = new TimesheetService(new DatabaseConnection([]), new TimesheetCalculator());
        $method = new ReflectionMethod($service, 'semanticEntryType');
        $method->setAccessible(true);

        self::assertSame('absent', $method->invoke(null, [
            'entry_type' => 'vacation',
            'absence_reason_code' => 'unpaid_leave',
        ]));
        self::assertSame('vacation', $method->invoke(null, [
            'entry_type' => 'vacation',
            'absence_reason_code' => 'vacation_paid',
        ]));
    }
}
