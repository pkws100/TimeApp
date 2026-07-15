<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\AttendanceReportService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;

final class AttendanceController
{
    public function __construct(
        private AttendanceService $attendanceService,
        private AttendanceReportService $attendanceReportService,
        private AdminView $view
    ) {
    }

    public function index(Request $request): Response
    {
        $summary = $this->attendanceService->todaySummary();
        $notice = $this->notice($request);
        $currentlyPresent = $summary['currently_present'] ?? $summary['present'];
        $currentlyPresentCount = (int) ($summary['currently_present_count'] ?? $summary['present_count']);
        $chart = is_array($summary['chart'] ?? null) ? $summary['chart'] : [];
        $chartRows = $this->chartRows($chart);
        $chartPayload = $this->chartPayload($chartRows);
        $workforceCount = (int) ($chart['workforce_count'] ?? 0);
        $readinessPercent = $this->percentLabel($chart['readiness_percent'] ?? null);
        $preventedPercent = $this->percentLabel($chart['prevented_percent'] ?? null);
        $presentRows = '';
        $completedRows = '';
        $chartStatusRows = '';

        foreach ($chartRows as $chartRow) {
            $chartStatusRows .= '<li>' . $this->escape($chartRow['label']) . ': <strong>'
                . $this->escape((string) $chartRow['value']) . '</strong></li>';
        }

        foreach ($currentlyPresent as $person) {
            $presentRows .= '<tr>'
                . '<td>' . $this->escape((string) ($person['employee_number'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($person['user_name'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($person['location'] ?? 'Nicht zugeordnet')) . '</td>'
                . '<td>' . $this->escape((string) ($person['start_time'] ?? '-')) . '</td>'
                . '<td>' . $this->escape((string) ($person['note'] ?? '')) . '</td>'
                . '</tr>';
        }

        if ($presentRows === '') {
            $presentRows = '<tr><td colspan="5" class="table-empty">Aktuell ist niemand eingecheckt.</td></tr>';
        }

        foreach ($summary['completed'] as $person) {
            $completedRows .= '<tr>'
                . '<td>' . $this->escape((string) ($person['employee_number'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($person['user_name'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($person['location'] ?? 'Nicht zugeordnet')) . '</td>'
                . '<td>' . $this->escape((string) ($person['start_time'] ?? '-')) . ' – ' . $this->escape((string) ($person['end_time'] ?? '-')) . '</td>'
                . '<td>' . $this->escape($this->durationLabel((int) ($person['net_minutes'] ?? 0))) . '</td>'
                . '<td>' . $this->escape((string) ($person['note'] ?? '')) . '</td>'
                . '</tr>';
        }

        if ($completedRows === '') {
            $completedRows = '<tr><td colspan="6" class="table-empty">Heute hat noch niemand ausgecheckt.</td></tr>';
        }

        $statusRows = '';

        foreach ($summary['statuses'] as $status) {
            $statusRows .= '<tr>'
                . '<td>' . $this->escape((string) ($status['employee_number'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($status['user_name'] ?? '')) . '</td>'
                . '<td>' . $this->escape($this->statusLabel((string) ($status['entry_type'] ?? '')))
                    . ((bool) ($status['is_derived'] ?? false) ? '<br><span class="badge warn">automatisch</span>' : '') . '</td>'
                . '<td>' . $this->escape((string) ($status['note'] ?? '')) . '</td>'
                . '</tr>';
        }

        if ($statusRows === '') {
            $statusRows = '<tr><td colspan="4" class="table-empty">Es liegen keine weiteren Tagesstatus vor.</td></tr>';
        }

        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Tagesstatus</p>
        <h1>Anwesenheit</h1>
        <p>Stand {$this->escape((string) $summary['today'])} - <span class="badge ok">{$this->escape((string) $currentlyPresentCount)} noch da</span></p>
    </div>
    <a class="button" href="/admin/attendance/report.pdf">PDF-Statusbericht</a>
</header>

{$notice}

<section class="grid cards">
    <article class="card metric">
        <h2>Noch da</h2>
        <p>{$this->escape((string) $currentlyPresentCount)}</p>
    </article>
    <article class="card status-card">
        <h2>Heute gegangen</h2>
        <p class="status-value">{$this->escape((string) ($summary['completed_count'] ?? 0))}</p>
    </article>
    <article class="card status-card">
        <h2>Krank</h2>
        <p class="status-value">{$this->escape((string) ($summary['status_counts']['sick'] ?? 0))}</p>
    </article>
    <article class="card status-card">
        <h2>Urlaub</h2>
        <p class="status-value">{$this->escape((string) ($summary['status_counts']['vacation'] ?? 0))}</p>
    </article>
    <article class="card status-card">
        <h2>Feiertag</h2>
        <p class="status-value">{$this->escape((string) ($summary['status_counts']['holiday'] ?? 0))}</p>
    </article>
    <article class="card status-card">
        <h2>Fehlt</h2>
        <p class="status-value">{$this->escape((string) ($summary['status_counts']['absent'] ?? 0))}</p>
        <p class="muted">{$this->escape((string) ($summary['derived_missing_count'] ?? 0))} automatisch erkannt</p>
    </article>
</section>

<section class="grid split">
    <article class="card chart-card">
        <h2>Belegschaft heute</h2>
        <div class="attendance-status-chart"><canvas id="attendanceStatusChart" aria-label="Kreisdiagramm zum heutigen Belegschaftsstatus" aria-describedby="attendanceStatusChartDescription" role="img"></canvas></div>
        <p id="attendanceStatusChartFallback" class="muted">Das Kreisdiagramm wird mit JavaScript geladen. Die vollständige Statusübersicht steht unten.</p>
        <noscript><p class="notice info">Das Kreisdiagramm benötigt JavaScript. Die vollständige Statusübersicht ist unten aufgeführt.</p></noscript>
        <h3>Statusverteilung</h3>
        <ul id="attendanceStatusChartDescription" class="list attendance-chart-summary">{$chartStatusRows}</ul>
    </article>
    <article class="card status-card">
        <h2>Einsatzbereitschaft</h2>
        <p class="status-value">{$this->escape($readinessPercent)}</p>
        <p>aktuell eingecheckt: {$this->escape((string) $currentlyPresentCount)} von {$this->escape((string) $workforceCount)} aktiven Mitarbeitern</p>
        <h3>Verhindert</h3>
        <p class="status-value">{$this->escape($preventedPercent)}</p>
        <p>Krank, Urlaub, Feiertag oder fehlt. Bereits ausgecheckte Mitarbeiter sind als eigener Status im Diagramm enthalten.</p>
    </article>
</section>

<script id="attendanceStatusChartData" type="application/json">{$chartPayload}</script>

<section class="card">
    <h2>Noch anwesend</h2>
    <p class="muted">Diese Mitarbeiter haben einen offenen Einsatz und sind aktuell eingecheckt.</p>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Nr.</th><th>Name</th><th>Standort</th><th>Start</th><th>Hinweis</th></tr></thead>
            <tbody>{$presentRows}</tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>Heute abgeschlossen</h2>
    <p class="muted">Diese Mitarbeiter haben heute ausgecheckt. Die Dauer ist die erfasste Nettoarbeitszeit des Tages.</p>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Nr.</th><th>Name</th><th>Letzter Standort</th><th>Zeitraum</th><th>Dauer</th><th>Letzter Hinweis</th></tr></thead>
            <tbody>{$completedRows}</tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>Weitere Tagesstatus</h2>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Nr.</th><th>Name</th><th>Status</th><th>Hinweis</th></tr></thead>
            <tbody>{$statusRows}</tbody>
        </table>
    </div>
</section>
HTML;

        return Response::html($this->view->render(
            'Anwesenheit',
            $content,
            '<script src="/assets/vendor/chart.umd.js"></script><script src="/assets/js/admin-attendance.js"></script>'
        ));
    }

    public function today(Request $request): Response
    {
        return Response::json($this->attendanceService->todaySummary());
    }

    public function report(Request $request): Response
    {
        try {
            $report = $this->attendanceReportService->generate();

            return new Response((string) $report['content'], 200, (array) $report['headers']);
        } catch (\Throwable $exception) {
            error_log('Anwesenheits-Statusbericht konnte nicht erstellt werden: ' . $exception->getMessage());

            return Response::redirect('/admin/attendance?error=' . rawurlencode('Der PDF-Statusbericht konnte nicht erstellt werden. Bitte erneut versuchen oder die Systemadministration informieren.'));
        }
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
        $minutes = max(0, $minutes);

        return intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . ' Std.';
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function chartRows(array $chart): array
    {
        return [
            ['label' => 'Noch da', 'value' => (int) ($chart['currently_present_count'] ?? 0)],
            ['label' => 'Heute gegangen', 'value' => (int) ($chart['completed_count'] ?? 0)],
            ['label' => 'Krank', 'value' => (int) ($chart['sick_count'] ?? 0)],
            ['label' => 'Urlaub', 'value' => (int) ($chart['vacation_count'] ?? 0)],
            ['label' => 'Feiertag', 'value' => (int) ($chart['holiday_count'] ?? 0)],
            ['label' => 'Fehlt', 'value' => (int) ($chart['absent_count'] ?? 0)],
            ['label' => 'Ohne Tagesstatus', 'value' => (int) ($chart['unreported_count'] ?? 0)],
        ];
    }

    /**
     * @param list<array{label: string, value: int}> $chartRows
     */
    private function chartPayload(array $chartRows): string
    {
        return json_encode([
            'labels' => array_column($chartRows, 'label'),
            'data' => array_column($chartRows, 'value'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }

    private function percentLabel(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '–';
        }

        return number_format((float) $value, 1, ',', '.') . ' %';
    }

    private function notice(Request $request): string
    {
        $error = trim(urldecode((string) $request->query('error', '')));

        return $error === '' ? '' : '<p class="notice error">' . $this->escape($error) . '</p>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
