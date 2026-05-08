<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Domain\Timesheets\TimesheetService;
use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class ReportService
{
    public function __construct(
        private DatabaseConnection $connection,
        private array $config,
        private TimesheetService $timesheetService
    ) {
    }

    public function export(string $format, string $period): array
    {
        $rows = $this->timesheetService->reportRows($period);

        return match ($format) {
            'csv' => $this->exportCsv($rows),
            'xlsx' => $this->exportXlsx($rows),
            'pdf' => $this->exportPdf($rows, $period),
            default => throw new RuntimeException('Exportformat wird nicht unterstuetzt.'),
        };
    }

    private function exportCsv(array $rows): array
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
                'Content-Disposition' => 'attachment; filename="zeiterfassung-export.csv"',
            ],
        ];
    }

    private function exportXlsx(array $rows): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new RuntimeException('PhpSpreadsheet ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        if ($rows !== []) {
            $sheet->fromArray(array_keys($rows[0]), null, 'A1');
            $sheet->fromArray(array_values($rows), null, 'A2');
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer->save($tempFile);
        $content = (string) file_get_contents($tempFile);
        @unlink($tempFile);

        return [
            'content' => $content,
            'headers' => [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="zeiterfassung-export.xlsx"',
            ],
        ];
    }

    private function exportPdf(array $rows, string $period): array
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('mPDF ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $html = '<h1>Zeiterfassung ' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '</h1><table border="1" cellpadding="6" cellspacing="0"><thead><tr>';

        if ($rows !== []) {
            foreach (array_keys($rows[0]) as $header) {
                $html .= '<th>' . htmlspecialchars((string) $header, ENT_QUOTES, 'UTF-8') . '</th>';
            }

            $html .= '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $html .= '<tr>';

                foreach ($row as $cell) {
                    $html .= '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
                }

                $html .= '</tr>';
            }

            $html .= '</tbody>';
        }

        $html .= '</table>';

        $pdf = new \Mpdf\Mpdf();
        $pdf->WriteHTML($html);

        return [
            'content' => $pdf->Output('', 'S'),
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="zeiterfassung-export.pdf"',
            ],
        ];
    }
}

