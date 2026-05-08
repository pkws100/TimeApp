<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Domain\Timesheets\AdminBookingService;
use RuntimeException;

final class AccountingExportService
{
    public function __construct(
        private AdminBookingService $bookingService
    ) {
    }

    public function export(string $period, string $format, string $profile, bool $includeArchived = false): array
    {
        $rows = $this->rows($period, $profile, $includeArchived);

        return match ($format) {
            'json' => [
                'profile' => $profile,
                'period' => $period,
                'rows' => $rows,
            ],
            'csv' => $this->csvExport($rows, $period, $profile),
            default => throw new RuntimeException('Exportformat wird nicht unterstuetzt.'),
        };
    }

    public function rows(string $period, string $profile = 'basic', bool $includeArchived = false): array
    {
        return $this->bookingService->accountingRows($period, $profile, $includeArchived);
    }

    public function mapRows(array $rows): array
    {
        return array_map(
            static function (array $row): array {
                $employeeName = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));

                return [
                    'work_date' => (string) ($row['work_date'] ?? ''),
                    'employee_number' => (string) ($row['employee_number'] ?? ''),
                    'employee_name' => $employeeName,
                    'project_number' => (string) ($row['project_number'] ?? ''),
                    'project_name' => (string) ($row['project_name'] ?? ''),
                    'entry_type' => (string) ($row['entry_type'] ?? ''),
                    'gross_minutes' => (int) ($row['gross_minutes'] ?? 0),
                    'break_minutes' => (int) ($row['break_minutes'] ?? 0),
                    'net_minutes' => (int) ($row['net_minutes'] ?? 0),
                    'expenses_amount' => (string) ($row['expenses_amount'] ?? '0.00'),
                    'note' => (string) ($row['note'] ?? ''),
                ];
            },
            $rows
        );
    }

    private function csvExport(array $rows, string $period, string $profile): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('CSV-Stream konnte nicht erzeugt werden.');
        }

        if ($rows !== []) {
            fputcsv($stream, array_keys($rows[0]), ';');

            foreach ($rows as $row) {
                fputcsv($stream, $row, ';');
            }
        }

        rewind($stream);
        $content = stream_get_contents($stream) ?: '';
        fclose($stream);

        return [
            'content' => $content,
            'headers' => [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="buchhaltung-export-' . $profile . '-' . $period . '.csv"',
            ],
        ];
    }

}
