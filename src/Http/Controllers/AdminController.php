<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Dashboard\DashboardService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;

final class AdminController
{
    public function __construct(
        private AdminView $view,
        private DashboardService $dashboardService,
        private DatabaseSettingsManager $databaseSettingsManager
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $overview = $this->dashboardService->overview();
        $messageClass = $overview['status'] === 'database' ? 'info' : 'warn';
        $attendanceChart = is_array($overview['attendance_chart'] ?? null) ? $overview['attendance_chart'] : [];
        $attendanceChartRows = $this->attendanceChartRows($attendanceChart);
        $attendanceChartPayload = $this->attendanceChartPayload($attendanceChartRows);
        $attendanceChartStatusRows = '';

        foreach ($attendanceChartRows as $attendanceChartRow) {
            $attendanceChartStatusRows .= '<li>' . $this->escape($attendanceChartRow['label']) . ': <strong>'
                . $this->escape((string) $attendanceChartRow['value']) . '</strong></li>';
        }

        $workforceCount = (int) ($attendanceChart['workforce_count'] ?? 0);
        $currentlyPresentCount = (int) ($attendanceChart['currently_present_count'] ?? 0);
        $readinessPercent = $this->percentLabel($attendanceChart['readiness_percent'] ?? null);
        $preventedPercent = $this->percentLabel($attendanceChart['prevented_percent'] ?? null);
        $cards = '';

        foreach ($overview['metrics'] as $label => $value) {
            $cards .= '<article class="card metric"><h3>' . htmlspecialchars((string) ucfirst(str_replace('_', ' ', $label)), ENT_QUOTES, 'UTF-8') . '</h3><p>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</p></article>';
        }

        $allocations = '';

        foreach ($overview['allocations'] as $allocation) {
            $allocations .= '<tr><td>' . htmlspecialchars((string) $allocation['project'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) $allocation['people'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        if ($allocations === '') {
            $allocations = '<tr><td colspan="2" class="table-empty">Heute liegen noch keine belastbaren Projektbelegungen vor.</td></tr>';
        }

        $absences = '';

        foreach ($overview['absences'] as $absence) {
            $derivedBadge = (bool) ($absence['is_derived'] ?? false) ? ' <span class="badge warn">automatisch</span>' : '';
            $typeLabel = (string) ($absence['type_label'] ?? $absence['type'] ?? '');
            $absences .= '<li><strong>' . htmlspecialchars((string) $absence['name'], ENT_QUOTES, 'UTF-8') . '</strong> - ' . htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') . $derivedBadge . '</li>';
        }

        if ($absences === '') {
            $absences = '<li class="muted">Heute liegen keine Krank-, Urlaubs-, Feiertags- oder Fehl-Eintraege vor.</li>';
        }

        $periods = '';

        foreach ($overview['periods'] as $period) {
            $periods .= '<article class="card period"><h3>' . htmlspecialchars((string) $period['label'], ENT_QUOTES, 'UTF-8') . '</h3><p>' . htmlspecialchars((string) $period['entries'], ENT_QUOTES, 'UTF-8') . ' Eintraege</p><p>' . htmlspecialchars((string) $period['hours'], ENT_QUOTES, 'UTF-8') . ' Std</p></article>';
        }

        $statusClass = $overview['status'] === 'database' ? 'ok' : 'warn';
        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Backend Uebersicht</p>
        <h1>Disposition und Zeiterfassung</h1>
        <p>Stand {$overview['today']} - Status: <span class="badge {$statusClass}">{$overview['status']}</span></p>
    </div>
    <a class="button" href="/admin/settings/database">Datenbank konfigurieren</a>
</header>

<p class="notice {$messageClass}">{$this->escape((string) ($overview['message'] ?? ''))}</p>

<section class="grid cards">{$cards}</section>

<section class="grid split">
    <article class="card">
        <h2>Projektbelegung heute</h2>
        <table>
            <thead><tr><th>Projekt / Ort</th><th>Personen</th></tr></thead>
            <tbody>{$allocations}</tbody>
        </table>
    </article>
    <article class="card">
        <h2>Krank / Urlaub / Feiertag / Fehlt</h2>
        <ul class="list">{$absences}</ul>
    </article>
</section>

<section class="grid split">
    <article class="card chart-card">
        <h2>Belegschaft heute</h2>
        <div class="attendance-status-chart"><canvas id="attendanceStatusChart" aria-label="Kreisdiagramm zum heutigen Belegschaftsstatus" aria-describedby="attendanceStatusChartDescription" role="img"></canvas></div>
        <p id="attendanceStatusChartFallback" class="muted">Das Kreisdiagramm wird mit JavaScript geladen. Die vollständige Statusübersicht steht unten.</p>
        <noscript><p class="notice info">Das Kreisdiagramm benötigt JavaScript. Die vollständige Statusübersicht ist unten aufgeführt.</p></noscript>
        <h3>Statusverteilung</h3>
        <ul id="attendanceStatusChartDescription" class="list attendance-chart-summary">{$attendanceChartStatusRows}</ul>
    </article>
    <article class="card status-card">
        <h2>Einsatzbereitschaft</h2>
        <p class="status-value">{$this->escape($readinessPercent)}</p>
        <p>aktuell eingecheckt: {$this->escape((string) $currentlyPresentCount)} von {$this->escape((string) $workforceCount)} aktiven Mitarbeitern</p>
        <h3>Verhindert</h3>
        <p class="status-value">{$this->escape($preventedPercent)}</p>
        <p>Krank, Urlaub, Feiertag oder fehlt. Bereits ausgecheckte Mitarbeiter sind als eigener Status im Diagramm enthalten.</p>
        <div class="status-card__actions"><a class="button button-secondary" href="/admin/attendance">Anwesenheit öffnen</a></div>
    </article>
</section>

<script id="attendanceStatusChartData" type="application/json">{$attendanceChartPayload}</script>

<section class="grid cards">
    {$periods}
</section>
HTML;

        return Response::html($this->view->render(
            'Dashboard',
            $content,
            '<script src="/assets/vendor/chart.umd.js"></script><script src="/assets/js/admin-attendance.js"></script>'
        ));
    }

    public function databaseSettings(Request $request): Response
    {
        $connectionSettings = $this->databaseSettingsManager->current();
        $settings = $this->databaseSettingsManager->currentForOutput();
        $status = (new DatabaseConnection($connectionSettings))->statusSummary();
        $badgeClass = $status['connected'] ? 'ok' : 'warn';
        $notice = $this->databaseNotice($request);
        $settingsTabs = $this->settingsTabs('database');

        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Anwendungs-Settings</p>
        <h1>Aktive Datenbankverbindung</h1>
        <p>Die Eingaben werden in <code>storage/config/database.override.php</code> gespeichert.</p>
    </div>
</header>

{$settingsTabs}
{$notice}

<section class="grid cards">
    <article class="card status-card">
        <h2>Verbindungsstatus</h2>
        <p class="status-value"><span class="badge {$badgeClass}">{$this->escape($status['connected'] ? 'verbunden' : 'nicht verbunden')}</span></p>
        <p>{$this->escape((string) $status['message'])}</p>
    </article>
    <article class="card status-card">
        <h2>Datenbank</h2>
        <p class="status-value">{$this->escape((string) $status['database'])}</p>
        <p>Host: {$this->escape((string) $status['host'])}</p>
        <p>Port/Socket: {$this->escape((string) (($status['socket'] ?? '') !== '' ? $status['socket'] : ($status['port'] ?? '')))}</p>
    </article>
    <article class="card status-card">
        <h2>Server</h2>
        <p class="status-value">{$this->escape((string) ($status['server_version'] ?? 'n/a'))}</p>
        <p>Tabellen: {$this->escape((string) ($status['table_count'] ?? 0))}</p>
        <p>Geprueft: {$this->escape((string) ($status['checked_at'] ?? ''))}</p>
    </article>
</section>

<form method="post" action="/admin/settings/database" class="card form-grid">
    <label><span>Host</span><input name="host" value="{$this->escape($settings['host'] ?? '')}" required></label>
    <label><span>Port</span><input name="port" type="number" value="{$this->escape((string) ($settings['port'] ?? 3306))}" required></label>
    <label><span>Datenbank</span><input name="database" value="{$this->escape($settings['database'] ?? '')}" required></label>
    <label><span>Benutzer</span><input name="username" value="{$this->escape($settings['username'] ?? '')}" required></label>
    <label><span>Passwort</span><input name="password" type="password" value="" placeholder="{$this->escape(($settings['password_is_set'] ?? false) ? 'Gespeichert - leer lassen zum Beibehalten' : '')}" autocomplete="off"></label>
    <label><span>Socket</span><input name="socket" value="{$this->escape($settings['socket'] ?? '')}"></label>
    <label><span>Charset</span><input name="charset" value="{$this->escape($settings['charset'] ?? 'utf8mb4')}"></label>
    <label><span>Collation</span><input name="collation" value="{$this->escape($settings['collation'] ?? 'utf8mb4_unicode_ci')}"></label>
    <input type="hidden" name="driver" value="mysql">
    <button class="button" type="submit">Verbindung testen und speichern</button>
</form>
HTML;

        return Response::html($this->view->render('Datenbank', $content));
    }

    private function databaseNotice(Request $request): string
    {
        $notice = (string) $request->query('notice', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            $message = urldecode($error);

            return '<p class="notice error">' . $this->escape($message) . '</p>';
        }

        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'saved' => 'Datenbankverbindung erfolgreich gespeichert.',
            default => 'Die Datenbankeinstellungen wurden aktualisiert.',
        };

        return '<p class="notice success">' . $this->escape($message) . '</p>';
    }

    private function settingsTabs(string $active): string
    {
        $companyClass = $active === 'company' ? 'scope-link is-active' : 'scope-link';
        $calendarClass = $active === 'calendar' ? 'scope-link is-active' : 'scope-link';
        $documentStatusClass = $active === 'document-statuses' ? 'scope-link is-active' : 'scope-link';
        $databaseClass = $active === 'database' ? 'scope-link is-active' : 'scope-link';
        $pushClass = $active === 'push' ? 'scope-link is-active' : 'scope-link';

        return '<section class="section-toolbar"><div class="scope-switch">'
            . '<a class="' . $companyClass . '" href="/admin/settings/company">Settings</a>'
            . '<a class="' . $calendarClass . '" href="/admin/settings/calendar">Kalender</a>'
            . '<a class="' . $documentStatusClass . '" href="/admin/settings/document-statuses">Datei-Status</a>'
            . '<a class="' . $databaseClass . '" href="/admin/settings/database">Datenbank</a>'
            . '<a class="' . $pushClass . '" href="/admin/settings/push">Push</a>'
            . '</div></section>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function attendanceChartRows(array $chart): array
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
    private function attendanceChartPayload(array $chartRows): string
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
}
