<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Attendance\AttendanceReportService;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Settings\CompanySettingsService;
use App\Infrastructure\Database\DatabaseConnection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AttendanceReportServiceTest extends TestCase
{
    public function testReportDataContainsTheFullStatusDistributionAndAnIntegrityHash(): void
    {
        $connection = new DatabaseConnection([]);
        $attendance = new AttendanceService($connection);
        $service = new AttendanceReportService(
            $attendance,
            new CompanySettingsService($connection, []),
            ['temp_path' => sys_get_temp_dir()]
        );

        $report = $service->reportData($attendance->todaySummary('2026-07-15'), new DateTimeImmutable('2026-07-15 14:30:00+02:00'));

        self::assertSame('2026-07-15', $report['report_date']);
        self::assertSame('15.07.2026', $report['report_date_label']);
        self::assertCount(7, $report['chart_rows']);
        self::assertSame('Noch da', $report['chart_rows'][0]['label']);
        self::assertSame('Ohne Tagesstatus', $report['chart_rows'][6]['label']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['snapshot_hash']);
    }

    public function testGenerateCreatesADownloadablePdfStatusReport(): void
    {
        $connection = new DatabaseConnection([]);
        $service = new AttendanceReportService(
            new AttendanceService($connection),
            new CompanySettingsService($connection, []),
            ['temp_path' => sys_get_temp_dir()]
        );

        $report = $service->generate('2026-07-15');

        self::assertStringStartsWith('%PDF-', (string) $report['content']);
        self::assertSame('application/pdf', $report['headers']['Content-Type']);
        self::assertStringContainsString('attachment; filename="anwesenheits-statusbericht-2026-07-15.pdf"', $report['headers']['Content-Disposition']);
        self::assertSame('private, no-store, max-age=0', $report['headers']['Cache-Control']);
        self::assertSame('no-cache', $report['headers']['Pragma']);
        self::assertSame('nosniff', $report['headers']['X-Content-Type-Options']);
        self::assertSame((string) strlen((string) $report['content']), $report['headers']['Content-Length']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['headers']['X-Attendance-Report-Hash']);
    }
}
