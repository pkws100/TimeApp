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
            $absences .= '<li><strong>' . htmlspecialchars((string) $absence['name'], ENT_QUOTES, 'UTF-8') . '</strong> - ' . htmlspecialchars((string) $absence['type'], ENT_QUOTES, 'UTF-8') . '</li>';
        }

        if ($absences === '') {
            $absences = '<li class="muted">Heute liegen keine Krank-, Fehl- oder Urlaubseintraege vor.</li>';
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
        <h2>Krank / Fehlt / Urlaub</h2>
        <ul class="list">{$absences}</ul>
    </article>
</section>

<section class="grid cards">
    {$periods}
    <article class="card chart-card">
        <h2>Live-Daten Grafik</h2>
        <canvas id="headcountChart" aria-label="Anwesenheitschart"></canvas>
        <p class="muted">Die Grafik nutzt <code>/api/v1/dashboard/charts</code> als Live-Datenquelle. Ohne lokal eingebundenes Chart.js zeigt der Fallback den aktuellen Datenstatus an.</p>
    </article>
</section>
HTML;

        return Response::html($this->view->render('Dashboard', $content));
    }

    public function databaseSettings(Request $request): Response
    {
        $settings = $this->databaseSettingsManager->current();
        $status = (new DatabaseConnection($settings))->statusSummary();
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
    <label><span>Passwort</span><input name="password" type="password" value="{$this->escape($settings['password'] ?? '')}"></label>
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
        $databaseClass = $active === 'database' ? 'scope-link is-active' : 'scope-link';
        $pushClass = $active === 'push' ? 'scope-link is-active' : 'scope-link';

        return '<section class="section-toolbar"><div class="scope-switch">'
            . '<a class="' . $companyClass . '" href="/admin/settings/company">Settings</a>'
            . '<a class="' . $databaseClass . '" href="/admin/settings/database">Datenbank</a>'
            . '<a class="' . $pushClass . '" href="/admin/settings/push">Push</a>'
            . '</div></section>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
