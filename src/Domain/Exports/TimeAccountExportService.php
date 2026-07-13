<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Domain\TimeAccounts\TimeAccountService;
use RuntimeException;

final class TimeAccountExportService
{
    public function __construct(private TimeAccountService $timeAccountService)
    {
    }

    public function export(string $format, int $year, int $month, array $filters): array
    {
        $rows = $this->timeAccountService->adminExportRows($year, $month, $filters);
        $suffix = sprintf('%04d-%02d', $year, $month);

        return match ($format) {
            'csv' => $this->exportCsv($rows, $suffix),
            'xlsx' => $this->exportXlsx($rows, $suffix),
            'pdf' => $this->exportPdf($rows, $suffix),
            default => throw new RuntimeException('Exportformat wird nicht unterstuetzt.'),
        };
    }

    private function exportCsv(array $rows, string $suffix): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('CSV-Stream konnte nicht erzeugt werden.');
        }

        $headers = $this->headers($rows);
        fputcsv($stream, $headers, ';');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ';');
        }

        rewind($stream);
        $content = stream_get_contents($stream) ?: '';
        fclose($stream);

        return [
            'content' => $content,
            'headers' => [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="zeitkonten-export-' . $suffix . '.csv"',
            ],
        ];
    }

    private function exportXlsx(array $rows, string $suffix): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new RuntimeException('PhpSpreadsheet ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($this->headers($rows), null, 'A1');

        if ($rows !== []) {
            $sheet->fromArray(array_values($rows), null, 'A2');
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'time_account_xlsx_');

        if ($tempFile === false) {
            throw new RuntimeException('Excel-Temp-Datei konnte nicht erstellt werden.');
        }

        $writer->save($tempFile);
        $content = (string) file_get_contents($tempFile);
        @unlink($tempFile);

        return [
            'content' => $content,
            'headers' => [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="zeitkonten-export-' . $suffix . '.xlsx"',
            ],
        ];
    }

    private function exportPdf(array $rows, string $suffix): array
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('mPDF ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $html = '<h1>Zeitkonten ' . htmlspecialchars($suffix, ENT_QUOTES, 'UTF-8') . '</h1><table border="1" cellpadding="6" cellspacing="0"><thead><tr>';

        foreach ($this->headers($rows) as $header) {
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

        $html .= '</tbody></table>';

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
                'Content-Disposition' => 'attachment; filename="zeitkonten-export-' . $suffix . '.pdf"',
            ],
        ];
    }

    private function headers(array $rows): array
    {
        return $rows === []
            ? [
                'Mitarbeiter',
                'Jahr',
                'Monat',
                'Soll',
                'Ist',
                'Saldo',
                'Stichtag',
                'Standdatum',
                'Monatsanfangsbestand',
                'Monats-Soll gesamt',
                'Soll bis Standdatum',
                'Feiertagsreduzierung (Min)',
                'Betriebsschliessungsreduzierung (Min)',
                'Effektives Soll (Min)',
                'Tatsaechliche Arbeitszeit',
                'Abwesenheitsgutschriften',
                'Manuelle Korrekturen',
                'Monatsveraenderung',
                'Monatsendbestand',
                'Urlaub genommen',
                'Urlaub offen',
                'Jahresurlaub',
                'Uebertrag',
                'Resturlaub',
                'Verfuegbar',
                'Krank',
                'Fehltage',
                'Feiertage',
                'Betriebsurlaub',
            ]
            : array_keys($rows[0]);
    }
}
