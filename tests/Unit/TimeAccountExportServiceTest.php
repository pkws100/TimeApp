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

    private function service(TimeAccountPdoDouble $pdo): TimeAccountExportService
    {
        $connection = new DatabaseConnection(['database' => 'test']);
        $property = new \ReflectionProperty(DatabaseConnection::class, 'pdo');
        $property->setAccessible(true);
        $property->setValue($connection, $pdo);

        return new TimeAccountExportService(new TimeAccountService($connection, new CalendarPolicyService($connection)));
    }
}
