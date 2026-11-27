<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Support\MpdfFactory;
use App\Domain\Settings\CompanySettingsService;
use RuntimeException;
use ZipArchive;

final class AccountingDocumentExportService
{
    public function __construct(
        private CompanySettingsService $companySettingsService,
        private array $config,
        private ?\Closure $diagnostics = null
    ) {
    }

    public function export(array $package, string $format): array
    {
        $package = $this->resolveMissingItemIds($package);
        $format = in_array($format, ['pdf', 'xlsx', 'zip'], true) ? $format : 'pdf';

        return match ($format) {
            'xlsx' => $this->xlsxExport($package, $this->baseFilename($package) . '.xlsx'),
            'zip' => $this->zipExport($package),
            default => $this->pdfExport($package, $this->baseFilename($package) . '.pdf'),
        };
    }

    private function zipExport(array $package): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive ist nicht installiert. Bitte PHP-Zip aktivieren.');
        }

        $tempFile = tempnam($this->tempDir(), 'accounting_zip_');
        if ($tempFile === false) {
            throw new RuntimeException('ZIP-Temp-Datei konnte nicht erstellt werden.');
        }
        $zip = new ZipArchive();
        $opened = false;

        try {
            if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ZIP-Datei konnte nicht erzeugt werden.');
            }
            $opened = true;

            $base = $this->baseFilename($package);
            $this->zipDocument($zip, $package, 'pdf', $base . '/sammeluebersicht.pdf', 'zip.aggregate');
            $this->zipDocument($zip, $package, 'xlsx', $base . '/sammeluebersicht.xlsx', 'zip.aggregate');

            foreach ($this->groupItems((array) ($package['items'] ?? []), 'employee_number', 'employee_name', 'user_id', $this->personnelSummaries($package)) as $index => $group) {
                $employeePackage = $this->filteredPackage($package, $group['items'], true, $group['id']);
                $name = $this->slug('mitarbeiter-' . $group['label']);
                $this->zipDocument($zip, $employeePackage, 'pdf', $base . '/mitarbeiter/' . $name . '.pdf', 'zip.employee.' . ($index + 1));
                $this->zipDocument($zip, $employeePackage, 'xlsx', $base . '/mitarbeiter/' . $name . '.xlsx', 'zip.employee.' . ($index + 1));
            }

            foreach ($this->groupItems((array) ($package['items'] ?? []), 'project_number', 'project_name', 'project_id') as $index => $group) {
                $projectPackage = $this->filteredPackage($package, $group['items'], false);
                $name = $this->slug('projekt-' . $group['label']);
                $this->zipDocument($zip, $projectPackage, 'pdf', $base . '/projekte/' . $name . '.pdf', 'zip.project.' . ($index + 1));
                $this->zipDocument($zip, $projectPackage, 'xlsx', $base . '/projekte/' . $name . '.xlsx', 'zip.project.' . ($index + 1));
            }

            $this->diagnose('zip.close.start');
            $opened = false;
            if (!$zip->close()) {
                throw new RuntimeException('ZIP-Datei konnte nicht abgeschlossen werden.');
            }
            $this->diagnose('zip.close.done');
            $content = file_get_contents($tempFile);
            if ($content === false) {
                throw new RuntimeException('ZIP-Datei konnte nicht gelesen werden.');
            }

            return [
                'content' => $content,
                'headers' => [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="' . $base . '.zip"',
                ],
            ];
        } finally {
            try {
                if ($opened) {
                    $zip->close();
                }
            } finally {
                @unlink($tempFile);
            }
        }
    }

    private function zipDocument(ZipArchive $zip, array $package, string $format, string $path, string $stage): void
    {
        $stage .= '.' . $format;
        $this->diagnose($stage . '.render.start');
        $document = $format === 'pdf' ? $this->pdfExport($package, basename($path)) : $this->xlsxExport($package, basename($path));
        $this->diagnose($stage . '.render.done');
        if (!$zip->addFromString($path, (string) $document['content'])) {
            throw new RuntimeException('ZIP-Dokument konnte nicht hinzugefügt werden.');
        }
        $this->diagnose($stage . '.add.done');
    }

    private function diagnose(string $stage): void
    {
        if ($this->diagnostics !== null) {
            ($this->diagnostics)($stage);
        }
    }

    private function pdfExport(array $package, string $filename): array
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('mPDF ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $this->diagnose('pdf.construct.start');
        $pdf = MpdfFactory::create([
            'tempDir' => $this->tempDir(),
            'format' => 'A4-L',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 16,
            'use_kwt' => true,
        ]);
        $this->diagnose('pdf.construct.done');
        try {
            $pdf->SetTitle($this->documentTitle($package));
            $pdf->SetHTMLFooter('<div style="text-align:right;font-size:8pt;color:#4b5563">Seite {PAGENO} von {nbpg}</div>');
            $this->diagnose('pdf.html.start');
            $pdf->WriteHTML($this->html($package));
            $this->diagnose('pdf.html.done');
            $this->diagnose('pdf.output.start');
            $content = $pdf->Output('', 'S');
            $this->diagnose('pdf.output.done');

            return [
                'content' => $content,
                'headers' => [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ],
            ];
        } finally {
            try {
                $this->diagnose('pdf.cleanup.start');
                $pdf->cleanup();
                $this->diagnose('pdf.cleanup.done');
            } finally {
                unset($pdf);
                // mPDF's writers and layout services hold references back to it.
                // Release each document graph before rendering the next ZIP member.
                $this->diagnose('pdf.collect.start');
                gc_collect_cycles();
                $this->diagnose('pdf.collect.done');
            }
        }
    }

    private function xlsxExport(array $package, string $filename): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new RuntimeException('PhpSpreadsheet ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $this->diagnose('xlsx.construct.start');
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $this->diagnose('xlsx.construct.done');
        $tempFile = null;
        try {
            $tempFile = tempnam($this->tempDir(), 'accounting_xlsx_');
            if ($tempFile === false) {
                throw new RuntimeException('Excel-Temp-Datei konnte nicht erstellt werden.');
            }

            return $this->renderXlsx($package, $filename, $spreadsheet, $tempFile);
        } finally {
            try {
                $this->diagnose('xlsx.disconnect.start');
                $spreadsheet->disconnectWorksheets();
                $this->diagnose('xlsx.disconnect.done');
            } finally {
                unset($spreadsheet);
                // renderXlsx has returned, so worksheet aliases and writer parts
                // no longer keep the workbook graph reachable from local variables.
                $this->diagnose('xlsx.collect.start');
                gc_collect_cycles();
                $this->diagnose('xlsx.collect.done');
                if (is_string($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }
    }

    private function renderXlsx(array $package, string $filename, \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $tempFile): array
    {
        $spreadsheet->getProperties()
            ->setCreator((string) (($this->companySettingsService->current()['company_name'] ?? '') ?: 'Zeiterfassung'))
            ->setTitle($this->documentTitle($package));

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Uebersicht');
        $summary->fromArray($this->sheetRows($this->summaryRows($package)), null, 'A1', true);

        $bookings = $spreadsheet->createSheet();
        $bookings->setTitle('Buchungen');
        $rows = $this->bookingRows((array) ($package['items'] ?? []));
        $bookings->fromArray($this->sheetRow($rows !== [] ? array_keys($rows[0]) : ['Keine Buchungen']), null, 'A1', true);
        if ($rows !== []) {
            $bookings->fromArray($this->sheetRows(array_values($rows)), null, 'A2', true);
            if (isset($rows[0]['Pausenprüfung am Arbeitstag'])) {
                $bookings->getColumnDimension('N')->setWidth(54);
                $bookings->getStyle('N2:N' . (count($rows) + 1))->getAlignment()->setWrapText(true);
            }
        }

        $employees = $spreadsheet->createSheet();
        $employees->setTitle('Mitarbeiter');
        $employeeRows = $this->totalRows((array) ($package['employee_totals'] ?? []));
        $employees->fromArray($this->sheetRow($employeeRows !== [] ? array_keys($employeeRows[0]) : ['Keine Summen']), null, 'A1', true);
        if ($employeeRows !== []) {
            $employees->fromArray($this->sheetRows(array_values($employeeRows)), null, 'A2', true);
        }

        $projects = $spreadsheet->createSheet();
        $projects->setTitle('Projekte');
        $projectRows = $this->totalRows((array) ($package['project_totals'] ?? []));
        $projects->fromArray($this->sheetRow($projectRows !== [] ? array_keys($projectRows[0]) : ['Keine Summen']), null, 'A1', true);
        if ($projectRows !== []) {
            $projects->fromArray($this->sheetRows(array_values($projectRows)), null, 'A2', true);
        }

        $types = $spreadsheet->createSheet();
        $types->setTitle('Typen');
        $typeRows = $this->totalRows((array) ($package['entry_type_totals'] ?? []));
        $types->fromArray($this->sheetRow($typeRows !== [] ? array_keys($typeRows[0]) : ['Keine Summen']), null, 'A1', true);
        if ($typeRows !== []) {
            $types->fromArray($this->sheetRows(array_values($typeRows)), null, 'A2', true);
        }

        $additionalSheets = [
            'Abwesenheiten' => $this->absenceRows($package),
            'Urlaubskonten' => $this->vacationRows($package),
            'Kernarbeitszeit' => $this->coreTimeRows($package),
            'Fensterzeiten' => $this->windowRows($package),
        ];
        foreach ($additionalSheets as $title => $dataRows) {
            if ($dataRows === []) {
                continue;
            }
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($this->sheetRow(array_keys($dataRows[0])), null, 'A1', true);
            $sheet->fromArray($this->sheetRows($dataRows), null, 'A2', true);
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lastColumn = $sheet->getHighestColumn();
            $lastRow = $sheet->getHighestRow();
            $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('A1:' . $lastColumn . '1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('24394F');
            $sheet->getStyle('A1:' . $lastColumn . $lastRow)->getAlignment()
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
            $sheet->freezePane('B2');
            $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
                ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
            if ($sheet->getTitle() !== 'Uebersicht') {
                $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
            }
            $columnCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastColumn);
            for ($index = 1; $index <= $columnCount; $index++) {
                $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            if ($sheet->getTitle() === 'Uebersicht') {
                $sheet->getColumnDimension('A')->setAutoSize(false)->setWidth(32);
                $sheet->getColumnDimension('B')->setAutoSize(false)->setWidth(100);
                $sheet->getStyle('B2:B' . $lastRow)->getAlignment()->setWrapText(true);
            }
            if (array_key_exists($sheet->getTitle(), $additionalSheets)) {
                $sheet->getStyle('A1:' . $lastColumn . $lastRow)->getAlignment()->setWrapText(true);
                $sheet->getRowDimension(1)->setRowHeight(58);
                for ($index = 1; $index <= $columnCount; $index++) {
                    $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
                    $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($index === 1 ? 28 : 22);
                }
            }
            if ($sheet->getTitle() === 'Buchungen') {
                foreach (['L' => 48, 'M' => 36, 'N' => 54] as $column => $width) {
                    if (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column) <= $columnCount) {
                        $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
                        $sheet->getStyle($column . '1:' . $column . $lastRow)->getAlignment()->setWrapText(true);
                    }
                }
            }
            if (in_array($sheet->getTitle(), ['Kernarbeitszeit', 'Fensterzeiten'], true)) {
                $sheet->getStyle('A2:' . $lastColumn . $lastRow)->getAlignment()->setWrapText(true);
                $sheet->getColumnDimension('F')->setAutoSize(false)->setWidth(58);
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $this->diagnose('xlsx.write.start');
        $writer->save($tempFile);
        $this->diagnose('xlsx.write.done');
        $content = file_get_contents($tempFile);
        if ($content === false) {
            throw new RuntimeException('Excel-Datei konnte nicht gelesen werden.');
        }

        return [
            'content' => $content,
            'headers' => [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
        ];
    }

    private function html(array $package): string
    {
        $company = $this->companySettingsService->current();
        $closure = (array) ($package['closure'] ?? []);
        $items = (array) ($package['items'] ?? []);
        $hasAssessment = $this->hasPauseAssessment($items);
        $rows = '';

        foreach ($this->bookingRows($items) as $row) {
            $rows .= '<tr>';
            foreach ($row as $key => $cell) {
                $rows .= $key === 'Hash'
                    ? '<td class="hash">' . $this->htmlHash((string) $cell, 24) . '</td>'
                    : '<td>' . $this->e((string) $cell) . '</td>';
            }
            $rows .= '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="13">Keine Buchungen im gewaehlten Bereich.</td></tr>';
        }

        $employeeTotalRows = '';
        foreach ($this->totalRows((array) ($package['employee_totals'] ?? [])) as $row) {
            $employeeTotalRows .= $this->htmlTotalRow($row);
        }

        $projectTotalRows = '';
        foreach ($this->totalRows((array) ($package['project_totals'] ?? [])) as $row) {
            $projectTotalRows .= $this->htmlTotalRow($row);
        }

        $typeTotalRows = '';
        foreach ($this->totalRows((array) ($package['entry_type_totals'] ?? [])) as $row) {
            $typeTotalRows .= $this->htmlTotalRow($row);
        }

        return '<style>
            body{font-family:DejaVu Sans,sans-serif;font-size:9pt;color:#111827}
            h1{font-size:18pt;margin:0 0 8px}
            h2{font-size:13pt;margin:14px 0 6px}
            table{width:100%;border-collapse:collapse}
            th,td{border:1px solid #d1d5db;padding:4px;vertical-align:top}
            th{background:#e8edf3;font-size:8pt}
            thead{display:table-header-group}
            h2{page-break-after:avoid}
            tr{page-break-inside:avoid}
            .badge{display:inline-block;padding:4px 8px;border:1px solid #111827;font-weight:bold}
            .muted{color:#4b5563}
            .hash{font-family:DejaVu Sans Mono,monospace;font-size:7pt}
        </style>'
            . '<h1>Abrechnungs-Stundenzettel</h1>'
            . '<p><span class="badge">' . $this->e((string) ($closure['status_label'] ?? 'VORLAEUFIG - nicht festgeschrieben')) . '</span></p>'
            . '<table><tbody>'
            . '<tr><th>Firma</th><td>' . $this->e($this->companyLine($company)) . '</td><th>Abschluss</th><td>' . $this->e((string) ($closure['closure_number'] ?? '')) . '</td></tr>'
            . '<tr><th>Zeitraum</th><td>' . $this->e((string) ($closure['period_label'] ?? '')) . '</td><th>Hash</th><td class="hash">' . $this->htmlHash((string) ($closure['snapshot_hash'] ?? ''), 32) . '</td></tr>'
            . '<tr><th>Erstellt</th><td>' . $this->e((string) ($closure['created_at'] ?? '')) . '</td><th>Festgeschrieben</th><td>' . $this->e((string) ($closure['finalized_at'] ?? '')) . '</td></tr>'
            . '<tr><th>Buchungen</th><td>' . count($items) . '</td><th>Netto gesamt</th><td>' . $this->minutes((int) ($closure['total_net_minutes'] ?? 0)) . '</td></tr>'
            . '</tbody></table>'
            . '<h2>Summen Mitarbeiter</h2><table><thead><tr><th>Name</th><th>Buchungen</th><th>Brutto</th><th>Gespeicherte Pause</th><th>Netto</th></tr></thead><tbody>' . $employeeTotalRows . '</tbody></table>'
            . '<h2>Summen Projekte</h2><table><thead><tr><th>Name</th><th>Buchungen</th><th>Brutto</th><th>Gespeicherte Pause</th><th>Netto</th></tr></thead><tbody>' . $projectTotalRows . '</tbody></table>'
            . '<h2>Summen Typen</h2><table><thead><tr><th>Name</th><th>Buchungen</th><th>Brutto</th><th>Gespeicherte Pause</th><th>Netto</th></tr></thead><tbody>' . $typeTotalRows . '</tbody></table>'
            . $this->personnelHtml($package)
            . $this->coreTimeHtml($package)
            . '<h2>Buchungen</h2><table><thead><tr>'
            . '<th>Datum</th><th>Mitarbeiter</th><th>Projekt</th><th>Typ</th><th>Start</th><th>Ende</th><th>Gespeicherte Pause</th><th>Brutto</th><th>Netto</th><th>Quelle</th><th>Aend.</th><th>Notiz</th><th>Hash</th>'
            . ($hasAssessment ? '<th>Pausenprüfung am Arbeitstag</th>' : '')
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="muted">Hinweis: Dieser Export stellt pruefbare Arbeitszeit- und Auditdaten bereit und ersetzt keine steuerliche oder rechtliche Beratung.</p>';
    }

    private function summaryRows(array $package): array
    {
        $company = $this->companySettingsService->current();
        $closure = (array) ($package['closure'] ?? []);

        $rows = [
            ['Feld', 'Wert'],
            ['Firma', $this->companyLine($company)],
            ['Status', (string) ($closure['status_label'] ?? '')],
            ['Abschlussnummer', (string) ($closure['closure_number'] ?? '')],
            ['Zeitraum', (string) ($closure['period_label'] ?? '')],
            ['Buchungen', (int) ($closure['item_count'] ?? count((array) ($package['items'] ?? [])))],
            ['Netto gesamt', $this->minutes((int) ($closure['total_net_minutes'] ?? 0))],
            ['Snapshot-Hash', (string) ($closure['snapshot_hash'] ?? '')],
            ['Erstellt am', (string) ($closure['created_at'] ?? '')],
            ['Festgeschrieben am', (string) ($closure['finalized_at'] ?? '')],
        ];
        if ($this->includesPersonnel($package)) {
            $rows[] = ['Urlaubs- und Krankheitsdaten', $this->summaryStatusLabel($package)];
            if ($this->personnelSummaries($package) !== []) {
                $rows[] = ['Kontobasis des Resturlaubs', 'Resturlaub basiert auf dem Kontostart; davor gebuchter Urlaub ist im Eröffnungsbestand berücksichtigt.'];
            }
        }

        return $rows;
    }

    private function absenceRows(array $package): array
    {
        return array_map(fn (array $row): array => [
            'Mitarbeiter' => $this->employeeLabel($row),
            'Jahr' => (int) $row['leave_year'],
            'Zeitraum von' => (string) $row['period_start'],
            'Zeitraum bis' => (string) $row['period_end'],
            'Standdatum' => (string) $row['as_of_date'],
            'Urlaub genommen im Zeitraum (Tage)' => $row['vacation_taken_days'] ?? null,
            'Unbezahlter Urlaub im Zeitraum (Tage)' => $row['vacation_unpaid_days'] ?? null,
            'Krankheit im Zeitraum (Tage)' => $row['sick_days'] ?? null,
            'Abwesenheitsgutschrift im Zeitraum' => $this->nullableMinutes($row['credited_absence_minutes'] ?? null),
        ], $this->personnelSummaries($package));
    }

    private function vacationRows(array $package): array
    {
        return array_map(fn (array $row): array => [
            'Mitarbeiter' => $this->employeeLabel($row),
            'Urlaubsjahr' => (int) $row['leave_year'],
            'Standdatum' => (string) $row['as_of_date'],
            'Jahresanspruch (Tage)' => $row['vacation_entitlement_days'] ?? null,
            'Anspruch inkl. Übertrag und Korrekturen (Tage)' => $row['vacation_total_days'] ?? null,
            'Urlaub genommen bis Standdatum (Tage)' => $row['vacation_taken_year_days'] ?? null,
            'Künftig gebuchter Urlaub (Tage)' => $row['vacation_future_days'] ?? null,
            'Resturlaub am Standdatum (Tage)' => $row['vacation_remaining_days'] ?? null,
            'Rest nach künftig gebuchtem Urlaub (Tage)' => $row['vacation_booked_remaining_days'] ?? null,
            'Status' => !empty($row['vacation_is_provisional']) ? 'Vorläufig' : 'Bestätigter Kontostand',
            'Kontoverbrauch ab' => $row['vacation_consumption_from_date'] ?? null,
            'Kontoverbrauch bis Standdatum (Tage)' => $row['vacation_taken_account_days'] ?? null,
        ], $this->personnelSummaries($package));
    }

    private function coreTimeRows(array $package): array
    {
        $rows = [];
        foreach ($this->personnelSummaries($package) as $employee) {
            $periods = (array) ($employee['core_time_periods'] ?? []);
            if ($periods === []) {
                $rows[] = array_replace($this->coreTimeRow($employee, []), [
                    'Gültig von' => (string) $employee['period_start'],
                    'Gültig bis' => (string) $employee['period_end'],
                    'Regel' => 'Keine Kernarbeitszeit zugeordnet',
                    'Version' => null,
                    'Zeitzone' => '',
                    'Arbeitsende' => '',
                    'Aktivierung' => 'Keine Zuordnung',
                ]);
            }
            foreach ($periods as $period) {
                $rows[] = $this->coreTimeRow($employee, $period);
            }
        }
        if ($rows !== []) {
            return $rows;
        }
        foreach ((array) ($package['items'] ?? []) as $item) {
            if (!is_array($item['work_time_policy'] ?? null)) {
                continue;
            }
            $row = $this->coreTimeRow($item, $item['work_time_policy']);
            $key = (int) ($item['user_id'] ?? 0) . ':' . json_encode($row, JSON_THROW_ON_ERROR);
            $rows[$key] = $row;
        }

        return array_values($rows);
    }

    private function coreTimeRow(array $employee, array $policy): array
    {
        return [
            'Mitarbeiter' => $this->employeeLabel($employee),
            'Gültig von' => (string) ($policy['effective_from'] ?? ''),
            'Gültig bis' => (string) ($policy['effective_to'] ?? ''),
            'Regel' => (string) ($policy['template_name'] ?? ''),
            'Version' => (int) ($policy['revision'] ?? 0),
            'Zeitfenster' => $this->windowsLabel((array) ($policy['windows'] ?? [])),
            'Zeitzone' => (string) ($policy['timezone'] ?? 'Europe/Berlin'),
            'Arbeitsende' => match ($policy['checkout_mode'] ?? '') {
                'automatic' => 'Automatischer Check-out',
                'proposal' => 'Ende zur Bestätigung vorschlagen',
                default => 'Manueller Check-out',
            },
            'Aktivierung' => !empty($policy['enabled']) ? 'Aktiv' : 'Arbeitszeitfenster global deaktiviert',
        ];
    }

    private function windowRows(array $package): array
    {
        return array_map(fn (array $row): array => [
            'Mitarbeiter' => $this->employeeLabel($row),
            'Zeitraum von' => (string) $row['period_start'],
            'Zeitraum bis' => (string) $row['period_end'],
            'Innerhalb der Kernarbeitszeit' => $this->nullableMinutes($row['inside_window_minutes'] ?? null),
            'Außerhalb der Kernarbeitszeit' => $this->nullableMinutes($row['outside_window_minutes'] ?? null),
            'Berechnungsstatus' => 'Unverbindliche Vorschau',
            'Sollzeit im Zeitraum' => $this->nullableMinutes($row['target_minutes'] ?? null),
            'Solltage im Zeitraum' => $row['required_days'] ?? null,
        ], $this->personnelSummaries($package));
    }

    private function personnelHtml(array $package): string
    {
        if (!$this->includesPersonnel($package)) {
            return '';
        }
        $html = '<h2>Urlaub und Krankheit</h2><p class="muted">' . $this->e($this->summaryStatusLabel($package)) . '</p>';
        $absenceRows = $this->absenceRows($package);
        if ($absenceRows === []) {
            return $html;
        }

        $accountRows = [];
        foreach ($this->personnelSummaries($package) as $row) {
            if (!empty($row['vacation_consumption_from_date'])) {
                $accountRows[] = [
                    'Mitarbeiter' => $this->employeeLabel($row),
                    'Urlaubsjahr' => (int) $row['leave_year'],
                    'Kontoverbrauch ab' => (string) $row['vacation_consumption_from_date'],
                    'Kontoverbrauch bis Standdatum (Tage)' => $row['vacation_taken_account_days'] ?? null,
                ];
            }
        }
        return $html . $this->htmlDataTable($absenceRows)
            . '<h2>Urlaubskonten am Standdatum</h2>'
            . '<p class="muted">Genommener Urlaub und Resturlaub beziehen sich auf das angegebene Urlaubsjahr und Standdatum. Künftig gebuchter Urlaub wird gesondert ausgewiesen.</p>'
            . $this->htmlDataTable(array_map(static fn (array $row): array => array_slice($row, 0, 10, true), $this->vacationRows($package)))
            . '<p class="muted">Resturlaub basiert auf dem Kontostart; davor gebuchter Urlaub ist im Eröffnungsbestand berücksichtigt.</p>'
            . ($accountRows === [] ? '' : $this->htmlDataTable($accountRows));
    }

    private function coreTimeHtml(array $package): string
    {
        $rows = $this->coreTimeRows($package);
        if ($rows === [] && $this->personnelSummaries($package) === []) {
            return '';
        }
        $html = '<h2>Kernarbeitszeit</h2>';
        $html .= $rows === [] ? '<p>Im Zeitraum ist keine Kernarbeitszeit zugeordnet.</p>' : $this->htmlDataTable($rows);
        $windowRows = $this->windowRows($package);
        if ($windowRows !== []) {
            $html .= '<h2>Arbeitszeit innerhalb und außerhalb der Kernarbeitszeit</h2>'
                . '<p class="muted">Die Fensterzeiten sind eine unverbindliche Vorschau. Die Abrechnung verwendet die erfasste Arbeitszeit. Zeit außerhalb der Kernarbeitszeit bedeutet keine genehmigten Überstunden. Nicht ermittelbare Werte werden mit einem Gedankenstrich dargestellt.</p>'
                . $this->htmlDataTable($windowRows);
        }

        return $html;
    }

    private function htmlDataTable(array $rows): string
    {
        $html = '<table><thead><tr>';
        foreach (array_keys($rows[0]) as $header) {
            $html .= '<th>' . $this->e((string) $header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $label = $cell === null ? '—' : (is_float($cell) ? rtrim(rtrim(number_format($cell, 2, ',', ''), '0'), ',') : (string) $cell);
                $html .= '<td>' . $this->e($label) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function summaryStatusLabel(array $package): string
    {
        return match ($package['employee_summary_status'] ?? 'unavailable') {
            'snapshot' => 'Die Urlaubs- und Krankheitsdaten sind mit diesem Abschluss gespeichert.',
            'preview' => 'Vorläufige Urlaubs- und Krankheitsdaten zum angegebenen Standdatum.',
            default => 'Für diesen Abschluss wurden keine Urlaubs- und Krankheitsdaten gespeichert.',
        };
    }

    private function employeeLabel(array $row): string
    {
        return trim((string) ($row['employee_number'] ?? '') . ' ' . (string) ($row['employee_name'] ?? ''));
    }

    private function includesPersonnel(array $package): bool
    {
        return ($package['employee_summary_status'] ?? '') !== 'not_applicable'
            && (int) ($package['closure']['project_id'] ?? 0) <= 0;
    }

    private function personnelSummaries(array $package): array
    {
        return $this->includesPersonnel($package) ? (array) ($package['employee_summaries'] ?? []) : [];
    }

    private function resolveMissingItemIds(array $package): array
    {
        $userIdsByLabel = [];
        foreach ((array) ($package['employee_summaries'] ?? []) as $summary) {
            if ((int) ($summary['user_id'] ?? 0) > 0) {
                $userIdsByLabel[$this->employeeLabel($summary)][(int) $summary['user_id']] = true;
            }
        }
        foreach ($package['items'] ?? [] as $index => $item) {
            if ((int) ($item['user_id'] ?? 0) <= 0) {
                $knownIds = array_keys($userIdsByLabel[$this->employeeLabel($item)] ?? []);
                $knownId = (int) ($package['closure']['user_id'] ?? 0);
                if ($knownId > 0 || count($knownIds) === 1) {
                    $package['items'][$index]['user_id'] = $knownId > 0 ? $knownId : $knownIds[0];
                }
            }
            if ((int) ($item['project_id'] ?? 0) <= 0 && (int) ($package['closure']['project_id'] ?? 0) > 0) {
                $package['items'][$index]['project_id'] = (int) $package['closure']['project_id'];
            }
        }

        return $package;
    }

    private function nullableMinutes(mixed $minutes): ?string
    {
        return $minutes === null ? null : $this->minutes((int) $minutes);
    }

    private function windowsLabel(array $windows): string
    {
        $weekdays = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        $labels = [];
        foreach ($weekdays as $day => $label) {
            $intervals = array_map(static fn (array $window): string => (string) $window['start'] . '–' . (string) $window['end'], $windows[$day] ?? []);
            if ($intervals !== []) {
                $labels[] = $label . ' ' . implode(', ', $intervals);
            }
        }

        return implode(' | ', $labels);
    }

    private function bookingRows(array $items): array
    {
        $hasAssessment = $this->hasPauseAssessment($items);

        return array_map(
            fn (array $item): array => [
                'Datum' => (string) ($item['work_date'] ?? ''),
                'Mitarbeiter' => trim((string) ($item['employee_number'] ?? '') . ' ' . (string) ($item['employee_name'] ?? '')),
                'Projekt' => trim((string) ($item['project_number'] ?? '') . ' ' . (string) ($item['project_name'] ?? '')),
                'Typ' => (string) ($item['entry_type'] ?? ''),
                'Start' => (string) ($item['start_time'] ?? ''),
                'Ende' => (string) ($item['end_time'] ?? ''),
                'Gespeicherte Pause' => $this->minutes((int) ($item['break_minutes'] ?? 0)),
                'Brutto' => $this->minutes((int) ($item['gross_minutes'] ?? 0)),
                'Netto' => $this->minutes((int) ($item['net_minutes'] ?? 0)),
                'Quelle' => (string) ($item['source_label'] ?? ''),
                'Aend.' => (int) ($item['change_count'] ?? 0),
                'Notiz' => (string) ($item['note'] ?? ''),
                'Hash' => (string) ($item['row_hash'] ?? ''),
                ...($hasAssessment ? ['Pausenprüfung am Arbeitstag' => $this->pauseAssessmentLabel($item['pause_assessment'] ?? null)] : []),
            ],
            $items
        );
    }

    private function hasPauseAssessment(array $items): bool
    {
        return (bool) array_filter($items, static fn (array $item): bool => isset($item['pause_assessment']));
    }

    private function pauseAssessmentLabel(?array $assessment): string
    {
        if ($assessment === null) {
            return '';
        }

        $dates = implode(', ', $assessment['linked_work_dates'] ?? []);
        $status = match ($assessment['calculation_status'] ?? '') {
            'resolved' => 'geprüft',
            'pending' => 'laufend',
            'needs_clarification' => 'klärungsbedürftig',
            default => '',
        };
        return $dates . ' · ' . $status
            . ' · Dokumentiert ' . $this->minutes((int) $assessment['documented_break_minutes'])
            . ' · Pflicht ' . $this->minutes((int) $assessment['required_break_minutes'])
            . ' · Fehlend ' . $this->minutes((int) $assessment['missing_break_minutes'])
            . ' · Alt-Abzug ohne Nachweis ' . $this->minutes((int) $assessment['legacy_deduction_minutes']);
    }

    private function totalRows(array $totals): array
    {
        return array_map(
            fn (array $row): array => [
                'Name' => (string) ($row['label'] ?? ''),
                'Buchungen' => (int) ($row['count'] ?? 0),
                'Brutto' => $this->minutes((int) ($row['gross_minutes'] ?? 0)),
                'Gespeicherte Pause' => $this->minutes((int) ($row['break_minutes'] ?? 0)),
                'Netto' => $this->minutes((int) ($row['net_minutes'] ?? 0)),
            ],
            $totals
        );
    }

    private function filteredPackage(array $package, array $items, bool $includePersonnel = true, ?int $employeeId = null): array
    {
        $closure = (array) ($package['closure'] ?? []);
        $closure['item_count'] = count($items);
        $closure['total_net_minutes'] = array_sum(array_map(static fn (array $item): int => (int) ($item['net_minutes'] ?? 0), $items));

        $summaries = [];
        $userIds = array_fill_keys(array_filter(array_map(static fn (array $item): int => (int) ($item['user_id'] ?? 0), $items)), true);
        if ($employeeId !== null && $employeeId > 0) {
            $userIds[$employeeId] = true;
        }
        if ($includePersonnel) {
            $summaries = array_values(array_filter(
                (array) ($package['employee_summaries'] ?? []),
                static fn (array $row): bool => isset($userIds[(int) ($row['user_id'] ?? 0)])
            ));
        }

        return [
            'closure' => $closure,
            'items' => $items,
            'employee_totals' => $this->totalsBy($items, 'employee_name', 'employee_number'),
            'project_totals' => $this->totalsBy($items, 'project_name', 'project_number'),
            'entry_type_totals' => $this->totalsBy($items, 'entry_type', null),
            'employee_summaries' => $summaries,
            'employee_summary_status' => !$includePersonnel ? 'not_applicable'
                : ($summaries === [] ? 'unavailable' : ($package['employee_summary_status'] ?? 'unavailable')),
        ];
    }

    private function htmlTotalRow(array $row): string
    {
        return '<tr>'
            . '<td>' . $this->e((string) $row['Name']) . '</td>'
            . '<td>' . $this->e((string) $row['Buchungen']) . '</td>'
            . '<td>' . $this->e((string) $row['Brutto']) . '</td>'
            . '<td>' . $this->e((string) $row['Gespeicherte Pause']) . '</td>'
            . '<td>' . $this->e((string) $row['Netto']) . '</td>'
            . '</tr>';
    }

    private function sheetRow(array $row): array
    {
        return array_map(fn (mixed $cell): mixed => $this->safeXlsxCell($cell), $row);
    }

    private function sheetRows(array $rows): array
    {
        return array_map(fn (array $row): array => $this->sheetRow($row), $rows);
    }

    private function safeXlsxCell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+\-@]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    private function groupItems(array $items, string $numberKey, string $labelKey, string $idKey, array $supplementalRows = []): array
    {
        $groups = [];

        foreach ($items as $item) {
            $label = trim((string) ($item[$numberKey] ?? '') . ' ' . (string) ($item[$labelKey] ?? ''));
            $label = $label !== '' ? $label : 'ohne-angabe';
            $key = (int) ($item[$idKey] ?? 0) > 0 ? 'id:' . (int) $item[$idKey] : 'label:' . $label;
            $groups[$key] ??= ['label' => $label, 'items' => [], 'id' => (int) ($item[$idKey] ?? 0) ?: null];
            $groups[$key]['items'][] = $item;
        }

        foreach ($supplementalRows as $row) {
            $id = (int) ($row[$idKey] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string) ($row[$numberKey] ?? '') . ' ' . (string) ($row[$labelKey] ?? ''));
            $groups['id:' . $id] ??= ['label' => $label !== '' ? $label : 'ohne-angabe', 'items' => [], 'id' => $id];
        }

        ksort($groups);
        $filenames = array_count_values(array_map(fn (array $group): string => $this->slug($group['label']), $groups));
        foreach ($groups as $key => &$group) {
            if ($filenames[$this->slug($group['label'])] > 1) {
                $group['label'] .= '-' . substr(hash('sha256', $key), 0, 8);
            }
        }
        unset($group);

        return array_values($groups);
    }

    private function totalsBy(array $items, string $labelKey, ?string $numberKey): array
    {
        $totals = [];

        foreach ($items as $item) {
            $label = trim(($numberKey !== null ? (string) ($item[$numberKey] ?? '') . ' ' : '') . (string) ($item[$labelKey] ?? ''));
            $label = $label !== '' ? $label : 'Ohne Angabe';
            $totals[$label] ??= ['label' => $label, 'count' => 0, 'gross_minutes' => 0, 'break_minutes' => 0, 'net_minutes' => 0];
            $totals[$label]['count']++;
            $totals[$label]['gross_minutes'] += (int) ($item['gross_minutes'] ?? 0);
            $totals[$label]['break_minutes'] += (int) ($item['break_minutes'] ?? 0);
            $totals[$label]['net_minutes'] += (int) ($item['net_minutes'] ?? 0);
        }

        return array_values($totals);
    }

    private function documentTitle(array $package): string
    {
        $closure = (array) ($package['closure'] ?? []);

        return 'Abrechnung ' . (string) ($closure['closure_number'] ?? '');
    }

    private function baseFilename(array $package): string
    {
        $closure = (array) ($package['closure'] ?? []);

        return $this->slug('abschluss-' . (string) ($closure['closure_number'] ?? 'vorlaeufig'));
    }

    private function companyLine(array $company): string
    {
        return trim((string) ($company['company_name'] ?? '') . ' ' . (string) ($company['legal_form'] ?? '') . ', '
            . trim((string) ($company['street'] ?? '') . ' ' . (string) ($company['house_number'] ?? '')) . ', '
            . trim((string) ($company['postal_code'] ?? '') . ' ' . (string) ($company['city'] ?? '')));
    }

    private function tempDir(): string
    {
        $path = (string) ($this->config['temp_path'] ?? sys_get_temp_dir());

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Export-Temp-Verzeichnis konnte nicht erstellt werden.');
        }

        if (!is_writable($path)) {
            throw new RuntimeException('Export-Temp-Verzeichnis ist nicht beschreibbar.');
        }

        return $path;
    }

    private function minutes(int $minutes): string
    {
        return sprintf('%d:%02d h', intdiv(max(0, $minutes), 60), max(0, $minutes) % 60);
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?: 'abschluss';

        return trim($value, '-') ?: 'abschluss';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function htmlHash(string $value, int $width): string
    {
        return nl2br($this->e(wordwrap($value, $width, "\n", true)), false);
    }
}
