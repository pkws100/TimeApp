<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Domain\Timesheets\AdminBookingService;
use RuntimeException;

final class BookingExportService
{
    public function __construct(private AdminBookingService $bookingService)
    {
    }

    public function export(string $format, array $filters): array
    {
        $rows = $this->bookingService->exportRows($filters);

        return match ($format) {
            'csv' => $this->exportCsv($rows),
            'xlsx' => $this->exportXlsx($rows),
            'pdf' => $this->exportPdf($rows),
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
                'Content-Disposition' => 'attachment; filename="buchungen-export.csv"',
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
        $tempFile = tempnam(sys_get_temp_dir(), 'booking_xlsx_');
        $writer->save($tempFile);
        $content = (string) file_get_contents($tempFile);
        @unlink($tempFile);

        return [
            'content' => $content,
            'headers' => [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="buchungen-export.xlsx"',
            ],
        ];
    }

    private function exportPdf(array $rows): array
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('mPDF ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $html = '<h1>Buchungen</h1><table border="1" cellpadding="6" cellspacing="0"><thead><tr>';

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

        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'baustelle-mpdf';

        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new RuntimeException('PDF-Temp-Verzeichnis konnte nicht erstellt werden.');
        }

        if (!is_writable($tempDir)) {
            throw new RuntimeException('PDF-Temp-Verzeichnis ist nicht beschreibbar.');
        }

        $pdf = new \Mpdf\Mpdf(['tempDir' => $tempDir]);
        $pdf->WriteHTML($html);

        return [
            'content' => $pdf->Output('', 'S'),
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="buchungen-export.pdf"',
            ],
        ];
    }
}
