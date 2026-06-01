<?php

declare(strict_types=1);

namespace App\Domain\Exports;

use App\Domain\Settings\CompanySettingsService;
use RuntimeException;
use ZipArchive;

final class AccountingDocumentExportService
{
    public function __construct(
        private CompanySettingsService $companySettingsService,
        private array $config
    ) {
    }

    public function export(array $package, string $format): array
    {
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
        $zip = new ZipArchive();

        if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Datei konnte nicht erzeugt werden.');
        }

        $base = $this->baseFilename($package);
        $zip->addFromString($base . '/sammeluebersicht.pdf', (string) $this->pdfExport($package, 'sammeluebersicht.pdf')['content']);
        $zip->addFromString($base . '/sammeluebersicht.xlsx', (string) $this->xlsxExport($package, 'sammeluebersicht.xlsx')['content']);

        foreach ($this->groupItems((array) ($package['items'] ?? []), 'employee_number', 'employee_name') as $group) {
            $employeePackage = $this->filteredPackage($package, $group['items']);
            $name = $this->slug('mitarbeiter-' . $group['label']);
            $zip->addFromString($base . '/mitarbeiter/' . $name . '.pdf', (string) $this->pdfExport($employeePackage, $name . '.pdf')['content']);
            $zip->addFromString($base . '/mitarbeiter/' . $name . '.xlsx', (string) $this->xlsxExport($employeePackage, $name . '.xlsx')['content']);
        }

        foreach ($this->groupItems((array) ($package['items'] ?? []), 'project_number', 'project_name') as $group) {
            $projectPackage = $this->filteredPackage($package, $group['items']);
            $name = $this->slug('projekt-' . $group['label']);
            $zip->addFromString($base . '/projekte/' . $name . '.pdf', (string) $this->pdfExport($projectPackage, $name . '.pdf')['content']);
            $zip->addFromString($base . '/projekte/' . $name . '.xlsx', (string) $this->xlsxExport($projectPackage, $name . '.xlsx')['content']);
        }

        $zip->close();
        $content = (string) file_get_contents($tempFile);
        @unlink($tempFile);

        return [
            'content' => $content,
            'headers' => [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $base . '.zip"',
            ],
        ];
    }

    private function pdfExport(array $package, string $filename): array
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('mPDF ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $pdf = new \Mpdf\Mpdf(['tempDir' => $this->tempDir()]);
        $pdf->WriteHTML($this->html($package));

        return [
            'content' => $pdf->Output('', 'S'),
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
        ];
    }

    private function xlsxExport(array $package, string $filename): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw new RuntimeException('PhpSpreadsheet ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator((string) (($this->companySettingsService->current()['company_name'] ?? '') ?: 'Zeiterfassung'))
            ->setTitle($this->documentTitle($package));

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Uebersicht');
        $summary->fromArray($this->sheetRows($this->summaryRows($package)), null, 'A1');

        $bookings = $spreadsheet->createSheet();
        $bookings->setTitle('Buchungen');
        $rows = $this->bookingRows((array) ($package['items'] ?? []));
        $bookings->fromArray($this->sheetRow($rows !== [] ? array_keys($rows[0]) : ['Keine Buchungen']), null, 'A1');
        if ($rows !== []) {
            $bookings->fromArray($this->sheetRows(array_values($rows)), null, 'A2');
        }

        $employees = $spreadsheet->createSheet();
        $employees->setTitle('Mitarbeiter');
        $employeeRows = $this->totalRows((array) ($package['employee_totals'] ?? []));
        $employees->fromArray($this->sheetRow($employeeRows !== [] ? array_keys($employeeRows[0]) : ['Keine Summen']), null, 'A1');
        if ($employeeRows !== []) {
            $employees->fromArray($this->sheetRows(array_values($employeeRows)), null, 'A2');
        }

        $projects = $spreadsheet->createSheet();
        $projects->setTitle('Projekte');
        $projectRows = $this->totalRows((array) ($package['project_totals'] ?? []));
        $projects->fromArray($this->sheetRow($projectRows !== [] ? array_keys($projectRows[0]) : ['Keine Summen']), null, 'A1');
        if ($projectRows !== []) {
            $projects->fromArray($this->sheetRows(array_values($projectRows)), null, 'A2');
        }

        $types = $spreadsheet->createSheet();
        $types->setTitle('Typen');
        $typeRows = $this->totalRows((array) ($package['entry_type_totals'] ?? []));
        $types->fromArray($this->sheetRow($typeRows !== [] ? array_keys($typeRows[0]) : ['Keine Summen']), null, 'A1');
        if ($typeRows !== []) {
            $types->fromArray($this->sheetRows(array_values($typeRows)), null, 'A2');
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach (range('A', 'M') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam($this->tempDir(), 'accounting_xlsx_');
        $writer->save($tempFile);
        $content = (string) file_get_contents($tempFile);
        @unlink($tempFile);

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
        $rows = '';

        foreach ($this->bookingRows($items) as $row) {
            $rows .= '<tr>';
            foreach ($row as $cell) {
                $rows .= '<td>' . $this->e((string) $cell) . '</td>';
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
            body{font-family:DejaVu Sans,sans-serif;font-size:10pt;color:#111827}
            h1{font-size:18pt;margin:0 0 8px}
            h2{font-size:13pt;margin:18px 0 8px}
            table{width:100%;border-collapse:collapse}
            th,td{border:1px solid #d1d5db;padding:5px;vertical-align:top}
            th{background:#f3f4f6}
            .badge{display:inline-block;padding:4px 8px;border:1px solid #111827;font-weight:bold}
            .muted{color:#4b5563}
        </style>'
            . '<h1>Abrechnungs-Stundenzettel</h1>'
            . '<p><span class="badge">' . $this->e((string) ($closure['status_label'] ?? 'VORLAEUFIG - nicht festgeschrieben')) . '</span></p>'
            . '<table><tbody>'
            . '<tr><th>Firma</th><td>' . $this->e($this->companyLine($company)) . '</td><th>Abschluss</th><td>' . $this->e((string) ($closure['closure_number'] ?? '')) . '</td></tr>'
            . '<tr><th>Zeitraum</th><td>' . $this->e((string) ($closure['period_label'] ?? '')) . '</td><th>Hash</th><td>' . $this->e((string) ($closure['snapshot_hash'] ?? '')) . '</td></tr>'
            . '<tr><th>Erstellt</th><td>' . $this->e((string) ($closure['created_at'] ?? '')) . '</td><th>Festgeschrieben</th><td>' . $this->e((string) ($closure['finalized_at'] ?? '')) . '</td></tr>'
            . '<tr><th>Buchungen</th><td>' . count($items) . '</td><th>Netto gesamt</th><td>' . $this->minutes((int) ($closure['total_net_minutes'] ?? 0)) . '</td></tr>'
            . '</tbody></table>'
            . '<h2>Summen Mitarbeiter</h2><table><thead><tr><th>Name</th><th>Buchungen</th><th>Brutto</th><th>Pause</th><th>Netto</th></tr></thead><tbody>' . $employeeTotalRows . '</tbody></table>'
            . '<h2>Summen Projekte</h2><table><thead><tr><th>Name</th><th>Buchungen</th><th>Brutto</th><th>Pause</th><th>Netto</th></tr></thead><tbody>' . $projectTotalRows . '</tbody></table>'
            . '<h2>Summen Typen</h2><table><thead><tr><th>Name</th><th>Buchungen</th><th>Brutto</th><th>Pause</th><th>Netto</th></tr></thead><tbody>' . $typeTotalRows . '</tbody></table>'
            . '<h2>Buchungen</h2><table><thead><tr>'
            . '<th>Datum</th><th>Mitarbeiter</th><th>Projekt</th><th>Typ</th><th>Start</th><th>Ende</th><th>Pause</th><th>Brutto</th><th>Netto</th><th>Quelle</th><th>Aend.</th><th>Notiz</th><th>Hash</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="muted">Hinweis: Dieser Export stellt pruefbare Arbeitszeit- und Auditdaten bereit und ersetzt keine steuerliche oder rechtliche Beratung.</p>';
    }

    private function summaryRows(array $package): array
    {
        $company = $this->companySettingsService->current();
        $closure = (array) ($package['closure'] ?? []);

        return [
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
    }

    private function bookingRows(array $items): array
    {
        return array_map(
            fn (array $item): array => [
                'Datum' => (string) ($item['work_date'] ?? ''),
                'Mitarbeiter' => trim((string) ($item['employee_number'] ?? '') . ' ' . (string) ($item['employee_name'] ?? '')),
                'Projekt' => trim((string) ($item['project_number'] ?? '') . ' ' . (string) ($item['project_name'] ?? '')),
                'Typ' => (string) ($item['entry_type'] ?? ''),
                'Start' => (string) ($item['start_time'] ?? ''),
                'Ende' => (string) ($item['end_time'] ?? ''),
                'Pause' => $this->minutes((int) ($item['break_minutes'] ?? 0)),
                'Brutto' => $this->minutes((int) ($item['gross_minutes'] ?? 0)),
                'Netto' => $this->minutes((int) ($item['net_minutes'] ?? 0)),
                'Quelle' => (string) ($item['source_label'] ?? ''),
                'Aend.' => (int) ($item['change_count'] ?? 0),
                'Notiz' => (string) ($item['note'] ?? ''),
                'Hash' => (string) ($item['row_hash'] ?? ''),
            ],
            $items
        );
    }

    private function totalRows(array $totals): array
    {
        return array_map(
            fn (array $row): array => [
                'Name' => (string) ($row['label'] ?? ''),
                'Buchungen' => (int) ($row['count'] ?? 0),
                'Brutto' => $this->minutes((int) ($row['gross_minutes'] ?? 0)),
                'Pause' => $this->minutes((int) ($row['break_minutes'] ?? 0)),
                'Netto' => $this->minutes((int) ($row['net_minutes'] ?? 0)),
            ],
            $totals
        );
    }

    private function filteredPackage(array $package, array $items): array
    {
        $closure = (array) ($package['closure'] ?? []);
        $closure['item_count'] = count($items);
        $closure['total_net_minutes'] = array_sum(array_map(static fn (array $item): int => (int) ($item['net_minutes'] ?? 0), $items));

        return [
            'closure' => $closure,
            'items' => $items,
            'employee_totals' => $this->totalsBy($items, 'employee_name', 'employee_number'),
            'project_totals' => $this->totalsBy($items, 'project_name', 'project_number'),
            'entry_type_totals' => $this->totalsBy($items, 'entry_type', null),
        ];
    }

    private function htmlTotalRow(array $row): string
    {
        return '<tr>'
            . '<td>' . $this->e((string) $row['Name']) . '</td>'
            . '<td>' . $this->e((string) $row['Buchungen']) . '</td>'
            . '<td>' . $this->e((string) $row['Brutto']) . '</td>'
            . '<td>' . $this->e((string) $row['Pause']) . '</td>'
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

    private function groupItems(array $items, string $numberKey, string $labelKey): array
    {
        $groups = [];

        foreach ($items as $item) {
            $label = trim((string) ($item[$numberKey] ?? '') . ' ' . (string) ($item[$labelKey] ?? ''));
            $label = $label !== '' ? $label : 'ohne-angabe';
            $groups[$label] ??= ['label' => $label, 'items' => []];
            $groups[$label]['items'][] = $item;
        }

        ksort($groups);

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
}
