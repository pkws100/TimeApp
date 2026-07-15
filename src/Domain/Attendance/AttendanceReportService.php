<?php

declare(strict_types=1);

namespace App\Domain\Attendance;

use App\Domain\Settings\CompanySettingsService;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AttendanceReportService
{
    private const CHART_COLORS = ['#15803d', '#2563eb', '#dc2626', '#7c3aed', '#d97706', '#b91c1c', '#64748b'];

    public function __construct(
        private AttendanceService $attendanceService,
        private CompanySettingsService $companySettingsService,
        private array $exportConfig,
        private string $timezone = 'Europe/Berlin'
    ) {
    }

    public function generate(?string $today = null): array
    {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            throw new RuntimeException('mPDF ist nicht installiert. Bitte Composer-Abhaengigkeiten installieren.');
        }

        $summary = $this->attendanceService->todaySummary($today);
        $generatedAt = new DateTimeImmutable('now', new DateTimeZone($this->timezone));
        $report = $this->reportData($summary, $generatedAt);
        $company = $this->companySettingsService->current();
        $pdf = new \Mpdf\Mpdf([
            'tempDir' => $this->tempDir(),
            'margin_top' => 34,
            'margin_bottom' => 21,
            'margin_header' => 6,
            'margin_footer' => 8,
        ]);
        $pdf->SetTitle('Anwesenheits-Statusbericht ' . $report['report_date']);
        $pdf->SetAuthor((string) (($company['company_name'] ?? '') ?: 'Zeiterfassung'));
        $pdf->SetHTMLHeader($this->headerHtml($company, $report));
        $pdf->SetHTMLFooter($this->footerHtml($report));
        $pdf->WriteHTML($this->html($summary, $report));

        $content = $pdf->Output('', 'S');

        return [
            'content' => $content,
            'report' => $report,
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="anwesenheits-statusbericht-' . $report['report_date'] . '.pdf"',
                'Content-Length' => (string) strlen($content),
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-Attendance-Report-Hash' => $report['snapshot_hash'],
            ],
        ];
    }

    public function reportData(array $summary, DateTimeImmutable $generatedAt): array
    {
        $chart = is_array($summary['chart'] ?? null) ? $summary['chart'] : [];
        $chartRows = $this->chartRows($chart);
        $reportDate = (string) ($summary['today'] ?? $generatedAt->format('Y-m-d'));
        $snapshot = [
            'report_type' => 'attendance_status_daily',
            'report_date' => $reportDate,
            'generated_at' => $generatedAt->format(DATE_ATOM),
            'chart' => $chartRows,
            'currently_present' => array_values((array) ($summary['currently_present'] ?? [])),
            'completed' => array_values((array) ($summary['completed'] ?? [])),
            'statuses' => array_values((array) ($summary['statuses'] ?? [])),
        ];

        return [
            'report_date' => $reportDate,
            'report_date_label' => $this->dateLabel($reportDate),
            'generated_at' => $generatedAt->format('d.m.Y H:i:s T'),
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'chart_rows' => $chartRows,
            'workforce_count' => (int) ($chart['workforce_count'] ?? 0),
            'currently_present_count' => (int) ($chart['currently_present_count'] ?? 0),
            'completed_count' => (int) ($chart['completed_count'] ?? 0),
            'readiness_percent' => $this->percentLabel($chart['readiness_percent'] ?? null),
            'prevented_percent' => $this->percentLabel($chart['prevented_percent'] ?? null),
        ];
    }

    private function html(array $summary, array $report): string
    {
        $presentRows = $this->tableRows(
            (array) ($summary['currently_present'] ?? []),
            static fn (array $person): array => [
                (string) ($person['employee_number'] ?? ''),
                (string) ($person['user_name'] ?? ''),
                (string) ($person['location'] ?? 'Nicht zugeordnet'),
                (string) ($person['start_time'] ?? '-'),
                (string) ($person['note'] ?? ''),
            ],
            5,
            'Aktuell ist niemand eingecheckt.'
        );
        $completedRows = $this->tableRows(
            (array) ($summary['completed'] ?? []),
            fn (array $person): array => [
                (string) ($person['employee_number'] ?? ''),
                (string) ($person['user_name'] ?? ''),
                (string) ($person['location'] ?? 'Nicht zugeordnet'),
                trim((string) ($person['start_time'] ?? '-') . ' – ' . (string) ($person['end_time'] ?? '-')),
                $this->durationLabel((int) ($person['net_minutes'] ?? 0)),
            ],
            5,
            'Heute hat noch niemand ausgecheckt.'
        );
        $statusRows = $this->tableRows(
            (array) ($summary['statuses'] ?? []),
            fn (array $status): array => [
                (string) ($status['employee_number'] ?? ''),
                (string) ($status['user_name'] ?? ''),
                $this->statusLabel((string) ($status['entry_type'] ?? '')) . ((bool) ($status['is_derived'] ?? false) ? ' (automatisch)' : ''),
                (string) ($status['note'] ?? ''),
            ],
            4,
            'Es liegen keine weiteren Tagesstatus vor.'
        );

        return '<style>
            body{font-family:dejavusans,sans-serif;font-size:9pt;color:#111827}
            h1{font-size:18pt;margin:0 0 5mm} h2{font-size:12pt;margin:7mm 0 3mm}
            h3{font-size:10pt;margin:0 0 2mm} p{margin:0 0 3mm}
            table{width:100%;border-collapse:collapse} th,td{border:0.2mm solid #cbd5e1;padding:2.2mm;vertical-align:top}
            th{background:#e2e8f0;text-align:left} .summary td{width:25%;text-align:center}.summary .value{font-size:15pt;font-weight:bold}
            .metrics td{width:33.33%}.muted{color:#475569}.status-grid td{width:50%}.chart-cell{width:45%;text-align:center}.legend-cell{width:55%}
            .legend td{border:0;padding:1.1mm}.legend-dot{display:inline-block;width:3.5mm;height:3.5mm;border-radius:50%;margin-right:1.5mm}
            .hash{font-family:monospace;font-size:7.5pt;word-break:break-all}.notice{background:#eff6ff;border:0.2mm solid #93c5fd;padding:3mm}
        </style>'
            . '<h1>Anwesenheits-Statusbericht</h1>'
            . '<p class="muted">Tagesbericht für <strong>' . $this->e($report['report_date_label']) . '</strong> – Datenstand: ' . $this->e($report['generated_at']) . '</p>'
            . '<table class="summary"><tr>'
            . $this->metricCell('Aktuell eingecheckt', (string) $report['currently_present_count'])
            . $this->metricCell('Heute ausgecheckt', (string) $report['completed_count'])
            . $this->metricCell('Aktive Belegschaft', (string) $report['workforce_count'])
            . $this->metricCell('Einsatzbereit', $report['readiness_percent'])
            . '</tr></table>'
            . '<h2>Statusverteilung</h2><table class="status-grid"><tr><td class="chart-cell">' . $this->chartSvg($report['chart_rows']) . '</td><td class="legend-cell">'
            . $this->chartLegend($report['chart_rows'])
            . '<p><strong>Verhindert: ' . $this->e($report['prevented_percent']) . '</strong><br><span class="muted">Krank, Urlaub, Feiertag oder fehlt.</span></p></td></tr></table>'
            . '<h2>Noch anwesend</h2><table><thead><tr><th>Nr.</th><th>Name</th><th>Standort</th><th>Start</th><th>Hinweis</th></tr></thead><tbody>' . $presentRows . '</tbody></table>'
            . '<h2>Heute abgeschlossen</h2><table><thead><tr><th>Nr.</th><th>Name</th><th>Letzter Standort</th><th>Zeitraum</th><th>Dauer</th></tr></thead><tbody>' . $completedRows . '</tbody></table>'
            . '<h2>Weitere Tagesstatus</h2><table><thead><tr><th>Nr.</th><th>Name</th><th>Status</th><th>Hinweis</th></tr></thead><tbody>' . $statusRows . '</tbody></table>'
            . '<p class="notice"><strong>Prüfhinweis:</strong> Dieser Bericht dokumentiert den zum angegebenen Erstellungszeitpunkt vorliegenden Systemstatus. Die Prüfsumme verknüpft die enthaltenen Statusdaten mit diesem Bericht. Für eine rechtsverbindliche Langzeitaufbewahrung ist das ausgegebene PDF nach den betrieblichen Aufbewahrungs- und Archivierungsrichtlinien abzulegen.</p>'
            . '<p class="hash">Snapshot-Prüfsumme (SHA-256): ' . $this->e($report['snapshot_hash']) . '</p>';
    }

    private function headerHtml(array $company, array $report): string
    {
        $name = trim((string) ($company['company_name'] ?? '')) ?: 'Zeiterfassung';
        $address = trim(implode(', ', array_filter([
            trim((string) ($company['street'] ?? '') . ' ' . (string) ($company['house_number'] ?? '')),
            trim((string) ($company['postal_code'] ?? '') . ' ' . (string) ($company['city'] ?? '')),
        ])));
        $logo = $this->companySettingsService->publicLogoFile();
        $logoPath = is_array($logo) ? (string) ($logo['path'] ?? '') : '';
        $logoHtml = $logoPath !== '' && is_file($logoPath)
            ? '<img src="' . $this->e($logoPath) . '" style="max-height:18mm;max-width:55mm">'
            : '';

        return '<table width="100%" style="border-bottom:0.4mm solid #1e3a5f;padding-bottom:2mm"><tr>'
            . '<td style="border:0;width:65%"><strong style="font-size:13pt">' . $this->e($name) . '</strong><br><span style="font-size:8pt">' . $this->e($address) . '</span></td>'
            . '<td style="border:0;width:35%;text-align:right">' . $logoHtml . '</td>'
            . '</tr></table>';
    }

    private function footerHtml(array $report): string
    {
        return '<div style="border-top:0.2mm solid #94a3b8;padding-top:2mm;font-size:7.5pt;color:#475569">'
            . 'Anwesenheits-Statusbericht ' . $this->e($report['report_date_label'])
            . ' · Prüfsumme: ' . $this->e(substr($report['snapshot_hash'], 0, 16))
            . ' · Seite {PAGENO} von {nbpg}</div>';
    }

    private function metricCell(string $label, string $value): string
    {
        return '<td><span class="muted">' . $this->e($label) . '</span><br><span class="value">' . $this->e($value) . '</span></td>';
    }

    /** @return list<array{label: string, value: int, color: string}> */
    private function chartRows(array $chart): array
    {
        $labels = ['Noch da', 'Heute gegangen', 'Krank', 'Urlaub', 'Feiertag', 'Fehlt', 'Ohne Tagesstatus'];
        $keys = ['currently_present_count', 'completed_count', 'sick_count', 'vacation_count', 'holiday_count', 'absent_count', 'unreported_count'];
        $rows = [];

        foreach ($labels as $index => $label) {
            $rows[] = ['label' => $label, 'value' => (int) ($chart[$keys[$index]] ?? 0), 'color' => self::CHART_COLORS[$index]];
        }

        return $rows;
    }

    /** @param list<array{label: string, value: int, color: string}> $rows */
    private function chartLegend(array $rows): string
    {
        $html = '<table class="legend">';
        foreach ($rows as $row) {
            $html .= '<tr><td><span class="legend-dot" style="background:' . $this->e($row['color']) . '"></span>'
                . $this->e($row['label']) . '</td><td style="text-align:right"><strong>' . (int) $row['value'] . '</strong></td></tr>';
        }

        return $html . '</table>';
    }

    /** @param list<array{label: string, value: int, color: string}> $rows */
    private function chartSvg(array $rows): string
    {
        $total = array_sum(array_column($rows, 'value'));
        if ($total <= 0) {
            return '<svg width="55mm" height="55mm" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"><circle cx="100" cy="100" r="75" fill="#e2e8f0"/><text x="100" y="96" text-anchor="middle" font-size="13" fill="#475569">Keine</text><text x="100" y="114" text-anchor="middle" font-size="13" fill="#475569">Statusdaten</text></svg>';
        }

        $start = -90.0;
        $paths = '';
        foreach ($rows as $row) {
            if ($row['value'] <= 0) {
                continue;
            }
            $sweep = ((int) $row['value'] / $total) * 360;
            if ($sweep >= 359.999) {
                $paths .= '<circle cx="100" cy="100" r="75" fill="' . $this->e($row['color']) . '"/>';
            } else {
                $paths .= '<path d="' . $this->piePath($start, $start + $sweep) . '" fill="' . $this->e($row['color']) . '" stroke="#ffffff" stroke-width="1"/>';
            }
            $start += $sweep;
        }

        return '<svg width="55mm" height="55mm" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">'
            . $paths . '<circle cx="100" cy="100" r="43" fill="#ffffff"/><text x="100" y="96" text-anchor="middle" font-size="18" font-weight="bold" fill="#111827">' . $total . '</text><text x="100" y="116" text-anchor="middle" font-size="11" fill="#475569">Status</text></svg>';
    }

    private function piePath(float $start, float $end): string
    {
        $startRadians = deg2rad($start);
        $endRadians = deg2rad($end);
        $x1 = 100 + 75 * cos($startRadians);
        $y1 = 100 + 75 * sin($startRadians);
        $x2 = 100 + 75 * cos($endRadians);
        $y2 = 100 + 75 * sin($endRadians);
        $largeArc = ($end - $start) > 180 ? 1 : 0;

        return sprintf('M 100 100 L %.3F %.3F A 75 75 0 %d 1 %.3F %.3F Z', $x1, $y1, $largeArc, $x2, $y2);
    }

    /** @param list<array<string, mixed>> $items */
    private function tableRows(array $items, callable $mapper, int $colspan, string $empty): string
    {
        if ($items === []) {
            return '<tr><td colspan="' . $colspan . '" class="muted">' . $this->e($empty) . '</td></tr>';
        }

        $html = '';
        foreach ($items as $item) {
            $html .= '<tr>';
            foreach ($mapper($item) as $value) {
                $html .= '<td>' . $this->e((string) $value) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html;
    }

    private function statusLabel(string $entryType): string
    {
        return match ($entryType) {
            'sick' => 'Krank',
            'vacation' => 'Urlaub',
            'holiday' => 'Feiertag',
            'absent' => 'Fehlt',
            default => $entryType,
        };
    }

    private function durationLabel(int $minutes): string
    {
        return intdiv(max(0, $minutes), 60) . ':' . str_pad((string) (max(0, $minutes) % 60), 2, '0', STR_PAD_LEFT) . ' Std.';
    }

    private function percentLabel(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 1, ',', '.') . ' %' : '–';
    }

    private function dateLabel(string $date): string
    {
        try {
            return (new DateTimeImmutable($date))->format('d.m.Y');
        } catch (\Exception) {
            return $date;
        }
    }

    private function tempDir(): string
    {
        $path = (string) ($this->exportConfig['temp_path'] ?? sys_get_temp_dir());

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('PDF-Temp-Verzeichnis konnte nicht erstellt werden.');
        }
        if (!is_writable($path)) {
            throw new RuntimeException('PDF-Temp-Verzeichnis ist nicht beschreibbar.');
        }

        return $path;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
