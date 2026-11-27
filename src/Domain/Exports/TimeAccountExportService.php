<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Support\MpdfFactory;
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
            fputcsv($stream, array_map(fn (string $header): mixed => $this->safeCsvCell($row[$header] ?? ''), $headers), ';');
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
        $sheet->setTitle('Zeitkonten');
        $headers = $this->headers($rows);
        $sheet->fromArray($headers, null, 'A1');

        foreach ($rows as $rowIndex => $row) {
            foreach ($headers as $columnIndex => $header) {
                $value = $row[$header] ?? '';
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 2], $value,
                    is_int($value) || is_float($value)
                        ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                        : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->freezePane('B2');
        $sheet->setAutoFilter('A1:' . $lastColumn . (count($rows) + 1));
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF24445C');
        $sheet->getStyle('A1:' . $lastColumn . (count($rows) + 1))->getAlignment()->setWrapText(true)->setVertical('top');
        $sheet->getRowDimension(1)->setRowHeight(58);
        foreach ($headers as $index => $header) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($column)->setWidth($header === 'Mitarbeiter' ? 28 : ($header === 'Kernarbeitszeit Konfiguration' ? 85 : 23));
            if (str_ends_with($header, '(Tage)')) {
                $sheet->getStyle($column . '2:' . $column . max(2, count($rows) + 1))->getNumberFormat()->setFormatCode('0.##');
            }
        }

        $notes = $spreadsheet->createSheet();
        $notes->setTitle('Lesehinweise');
        foreach ($this->notes() as $index => $note) {
            $notes->setCellValueExplicit('A' . ($index + 1), $note, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $notes->getColumnDimension('A')->setWidth(110);
        $notes->getStyle('A1:A' . count($this->notes()))->getAlignment()->setWrapText(true);
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'time_account_xlsx_');

        if ($tempFile === false) {
            throw new RuntimeException('Excel-Temp-Datei konnte nicht erstellt werden.');
        }

        try {
            $writer->save($tempFile);
            $content = (string) file_get_contents($tempFile);
        } finally {
            @unlink($tempFile);
            $spreadsheet->disconnectWorksheets();
        }

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

        $html = $this->html($rows, $suffix);

        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'baustelle-mpdf';

        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new RuntimeException('PDF-Temp-Verzeichnis konnte nicht erstellt werden.');
        }

        if (!is_writable($tempDir)) {
            throw new RuntimeException('PDF-Temp-Verzeichnis ist nicht beschreibbar.');
        }

        $pdf = MpdfFactory::create(['tempDir' => $tempDir]);
        $pdf->SetHTMLFooter('<div style="font-size:8pt;color:#4c5e6b;text-align:right">Zeitkonten ' . $this->escape($suffix) . ' · Seite {PAGENO}/{nbpg}</div>');
        $pdf->WriteHTML($html);

        return [
            'content' => $pdf->Output('', 'S'),
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="zeitkonten-export-' . $suffix . '.pdf"',
            ],
        ];
    }

    private function html(array $rows, string $suffix): string
    {
        $html = '<style>body{font-family:dejavusans;font-size:9pt;color:#172b3a}h1{font-size:18pt}h2{font-size:14pt;margin-top:20pt}h3{font-size:11pt;margin-bottom:5pt}table{width:100%;border-collapse:collapse;margin-bottom:9pt}th,td{border:1px solid #c9d2d9;padding:5pt;text-align:left;vertical-align:top}th{width:48%;background:#edf2f5}p{line-height:1.4}.warning{background:#fff3d6;padding:7pt}.note{color:#4c5e6b;font-size:8pt}</style>'
            . '<h1>Zeitkonten ' . $this->escape($suffix) . '</h1>';
        foreach ($this->notes() as $note) {
            $html .= '<p class="note">' . $this->escape($note) . '</p>';
        }
        if ($rows === []) {
            return $html . '<p>Keine Mitarbeiter im gewaehlten Abrechnungszeitraum.</p>';
        }

        foreach ($rows as $row) {
            $html .= '<h2>' . $this->escape((string) ($row['Mitarbeiter'] ?? '')) . '</h2>';
            if (($row['Buchungskonflikt'] ?? '') !== '') {
                $html .= '<p class="warning">' . $this->escape((string) $row['Buchungskonflikt']) . '</p>';
            }
            if (($row['Saldo vorlaeufig'] ?? '') === 'Ja' || ($row['Stichtag'] ?? '') === '') {
                $html .= '<p class="warning">Zeitkonto vorlaeufig. Bitte Hinweise zu Stichtag, Buchungskonflikten und Pausennachweisen vor der Abrechnung pruefen.</p>';
            }

            $groups = [
                'Arbeitszeit und Zeitkonto' => ['Standdatum', 'Stichtag', 'Monatsanfangsbestand', 'Monats-Soll gesamt', 'Soll bis Standdatum', 'Feiertagsreduzierung (Min)', 'Betriebsschliessungsreduzierung (Min)', 'Effektives Soll (Min)', 'Tatsaechliche Arbeitszeit', 'Abwesenheitsgutschriften', 'Manuelle Korrekturen', 'Monatsveraenderung', 'Monatsendbestand', 'Saldo vorlaeufig'],
                'Urlaub im Urlaubsjahr' => ['Urlaubsjahr', 'Urlaubsstand', 'Urlaubskonto Status', 'Urlaubsanspruch am Standdatum (Tage)', 'Urlaubsuebertrag am Standdatum (Tage)', 'Urlaubskorrekturen bis Standdatum (Tage)', 'Urlaubsbudget am Standdatum (Tage)', 'Urlaub genommen bis Standdatum (Tage)', 'Urlaubskonto Verbrauch ab', 'Urlaubsverbrauch ab Kontostart (Tage)', 'Urlaub kuenftig genehmigt (Tage)', 'Urlaub gebucht gesamt (Tage)', 'Resturlaub am Standdatum (Tage)', 'Resturlaub nach genehmigtem Urlaub (Tage)', 'Urlaub offen', 'Verfuegbar nach offenen Antraegen (Tage)'],
                'Abwesenheiten im Abrechnungszeitraum bis Standdatum' => ['Urlaub im Abrechnungszeitraum (Tage)', 'Krankheit im Abrechnungszeitraum (Tage)', 'Fehltage', 'Feiertage', 'Betriebsurlaub'],
                'Kernarbeitszeit – zugeordnete Konfiguration' => ['Kernarbeitszeit Zeitraum', 'Kernarbeitszeit Status', 'Kernarbeitszeit Konfiguration'],
                'Arbeitszeitfenster – separate Vorschau' => array_values(array_filter(array_keys($row), static fn (string $key): bool => str_starts_with($key, 'Fenster ') || $key === 'Arbeitszeitfenster Status')),
            ];
            foreach ($groups as $title => $fields) {
                $fields = array_values(array_filter($fields, static fn (string $field): bool => array_key_exists($field, $row)));
                if ($fields === []) {
                    continue;
                }
                $html .= '<h3>' . $this->escape($title) . '</h3><table><thead><tr><th colspan="2">'
                    . $this->escape((string) ($row['Mitarbeiter'] ?? '')) . ' · ' . $this->escape($title) . '</th></tr></thead><tbody>';
                foreach ($fields as $field) {
                    $html .= '<tr><th>' . $this->escape($field) . '</th><td>' . nl2br($this->escape((string) $row[$field])) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        }

        return $html;
    }

    private function notes(): array
    {
        return [
            'Die neuen Abwesenheitsfelder zaehlen dokumentierte Tage mit positivem Tagessoll im gewaehlten Monat bis zum Standdatum. Urlaubswerte gelten fuer das ausgewiesene Urlaubsjahr zum Urlaubsstand. Werte ohne finalen Mitarbeiter-Stichtag sind vorlaeufig.',
            'Genommener Jahresurlaub umfasst die dokumentierten bezahlten Urlaubstage des Kalenderjahres bis zum Urlaubsstand. Importierter Altverbrauch kann im Eroeffnungssaldo enthalten sein. Der Resturlaub zieht vom gespeicherten Kontobudget nur den gesondert ausgewiesenen Verbrauch ab Kontostart ab. Resturlaub nach genehmigtem Urlaub beruecksichtigt zusaetzlich kuenftig genehmigten Urlaub.',
            'Die bisherigen CSV-/Excel-Spalten bleiben erhalten. Die Altspalte "Urlaub genommen" enthaelt den gebuchten Verbrauch des Urlaubskontos inklusive kuenftiger Buchungen. Die datierten Zusatzspalten unterscheiden dokumentierten Jahresurlaub und Verbrauch ab Kontostart.',
            'Die Kernarbeitszeit zeigt die im Monat zugeordnete Konfiguration mit Gueltigkeit, Zeitzone und Checkout-Modus. Fenster-Anrechnungswerte bleiben eine separate vorlaeufige Vorschau ohne verbindliche Abrechnungswirkung.',
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function safeCsvCell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[\x00-\x20]*[=+@-]/', $value) === 1 && preg_match('/^[+-]?\d+:[0-5]\d$/D', $value) !== 1) {
            return "'" . $value;
        }
        return $value;
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
                'Urlaubsjahr',
                'Urlaubsstand',
                'Urlaubskonto Status',
                'Urlaubsanspruch am Standdatum (Tage)',
                'Urlaubsuebertrag am Standdatum (Tage)',
                'Urlaubskorrekturen bis Standdatum (Tage)',
                'Urlaubsbudget am Standdatum (Tage)',
                'Urlaub genommen bis Standdatum (Tage)',
                'Urlaubskonto Verbrauch ab',
                'Urlaubsverbrauch ab Kontostart (Tage)',
                'Urlaub kuenftig genehmigt (Tage)',
                'Urlaub gebucht gesamt (Tage)',
                'Resturlaub am Standdatum (Tage)',
                'Resturlaub nach genehmigtem Urlaub (Tage)',
                'Verfuegbar nach offenen Antraegen (Tage)',
                'Urlaub im Abrechnungszeitraum (Tage)',
                'Krankheit im Abrechnungszeitraum (Tage)',
                'Kernarbeitszeit Zeitraum',
                'Kernarbeitszeit Status',
                'Kernarbeitszeit Konfiguration',
            ]
            : array_keys($rows[0]);

    }
}
