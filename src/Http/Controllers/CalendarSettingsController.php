<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Calendar\CalendarPolicyService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use InvalidArgumentException;
use RuntimeException;

final class CalendarSettingsController
{
    public function __construct(
        private AdminView $view,
        private CalendarPolicyService $calendarPolicyService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function show(Request $request): Response
    {
        $year = $this->year($request);
        $csrfToken = $this->e($this->csrfService->token());
        $regionSelect = $this->regionSelect($this->calendarPolicyService->currentRegion());
        $closures = $this->closureRows($this->calendarPolicyService->closuresForYear($year, 'all'), $csrfToken);
        $holidayRows = $this->holidayRows($year, $this->calendarPolicyService->currentRegion());
        $notice = $this->notice($request);

        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Kalender-Settings</p>
        <h1>Feiertage und Betriebsurlaub</h1>
        <p>Steuert, an welchen Tagen keine automatische Fehlbuchung erwartet wird.</p>
    </div>
</header>
{$this->settingsTabs('calendar')}
{$notice}
<section class="grid split">
    <form method="post" action="/admin/settings/calendar" class="card stack">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <div>
            <h2>Bundesland</h2>
            <p class="muted">Gesetzliche Feiertage werden lokal berechnet. Ohne Auswahl bleibt die bisherige Pflichtlogik aktiv.</p>
        </div>
        <label><span>Bundesland fuer Feiertage</span>{$regionSelect}</label>
        <button class="button" type="submit">Bundesland speichern</button>
    </form>
    <section class="card stack">
        <div>
            <h2>Feiertage {$this->e((string) $year)}</h2>
            <p class="muted">Diese Tage werden im Kalender markiert und erzeugen kein automatisches Fehlt.</p>
        </div>
        <div class="table-scroll">
            <table><thead><tr><th>Datum</th><th>Feiertag</th></tr></thead><tbody>{$holidayRows}</tbody></table>
        </div>
    </section>
</section>
<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Betriebsurlaub {$this->e((string) $year)}</h2>
            <p class="muted">Betriebsurlaub gilt global und deaktiviert die Pflichtbuchung fuer den Zeitraum.</p>
        </div>
    </div>
    <form method="post" action="/admin/settings/calendar/closures" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label><span>Titel</span><input name="title" placeholder="Betriebsurlaub Sommer" required></label>
        <label><span>Von</span><input type="date" name="date_from" value="{$this->e($year . '-01-01')}" required></label>
        <label><span>Bis</span><input type="date" name="date_to" value="{$this->e($year . '-01-01')}" required></label>
        <label class="full-span"><span>Notiz</span><textarea name="notes" rows="3"></textarea></label>
        <button class="button" type="submit">Betriebsurlaub anlegen</button>
    </form>
    <div class="table-scroll">
        <table><thead><tr><th>Titel</th><th>Zeitraum</th><th>Status</th><th>Aktionen</th></tr></thead><tbody>{$closures}</tbody></table>
    </div>
</section>
HTML;

        return Response::html($this->view->render('Kalender-Settings', $content));
    }

    public function saveRegion(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/calendar?error=csrf');
        }

        try {
            $this->calendarPolicyService->saveRegion((string) $request->input('holiday_region', ''));

            return Response::redirect('/admin/settings/calendar?notice=region-saved');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::redirect('/admin/settings/calendar?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function createClosure(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/calendar?error=csrf');
        }

        try {
            $closure = $this->calendarPolicyService->createClosure($request->input());

            return Response::redirect('/admin/settings/calendar?year=' . (int) $closure['year'] . '&notice=closure-created');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::redirect('/admin/settings/calendar?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function archiveClosure(Request $request, array $params): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/calendar?error=csrf');
        }

        $user = $this->authService->currentUser();
        $this->calendarPolicyService->archiveClosure((int) ($params['id'] ?? 0), $user !== null ? (int) $user['id'] : null);

        return Response::redirect('/admin/settings/calendar?notice=closure-archived');
    }

    private function regionSelect(string $selected): string
    {
        $html = '<select name="holiday_region">';

        foreach ($this->calendarPolicyService->regions() as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html . '</select>';
    }

    private function holidayRows(int $year, string $region): string
    {
        $holidays = $this->calendarPolicyService->publicHolidays($year, $region);

        if ($holidays === []) {
            return '<tr><td colspan="2" class="table-empty">Kein Bundesland ausgewaehlt.</td></tr>';
        }

        $rows = '';

        foreach ($holidays as $date => $holiday) {
            $rows .= '<tr><td>' . $this->e($date) . '</td><td>' . $this->e((string) $holiday['name']) . '</td></tr>';
        }

        return $rows;
    }

    private function closureRows(array $closures, string $csrfToken): string
    {
        if ($closures === []) {
            return '<tr><td colspan="4" class="table-empty">Noch kein Betriebsurlaub fuer dieses Jahr geplant.</td></tr>';
        }

        $rows = '';

        foreach ($closures as $closure) {
            $isArchived = (int) ($closure['is_deleted'] ?? 0) === 1;
            $status = $isArchived ? '<span class="badge warn">Archiviert</span>' : '<span class="badge ok">Aktiv</span>';
            $archive = $isArchived
                ? '<span class="muted">Bereits archiviert</span>'
                : '<form method="post" action="/admin/settings/calendar/closures/' . (int) $closure['id'] . '/archive" class="inline-form">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                    . '<button type="submit" class="button button-danger">Archivieren</button>'
                    . '</form>';
            $notes = trim((string) ($closure['notes'] ?? ''));
            $title = $this->e((string) $closure['title']) . ($notes !== '' ? '<br><span class="muted">' . $this->e($notes) . '</span>' : '');

            $rows .= '<tr>'
                . '<td>' . $title . '</td>'
                . '<td>' . $this->e((string) $closure['date_from']) . ' bis ' . $this->e((string) $closure['date_to']) . '</td>'
                . '<td>' . $status . '</td>'
                . '<td class="table-actions">' . $archive . '</td>'
                . '</tr>';
        }

        return $rows;
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', (int) date('Y'));

        return $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        return $this->csrfService->isValid((string) $request->input('csrf_token', ''));
    }

    private function settingsTabs(string $active): string
    {
        $links = [
            'company' => ['/admin/settings/company', 'Settings'],
            'calendar' => ['/admin/settings/calendar', 'Kalender'],
            'document-statuses' => ['/admin/settings/document-statuses', 'Datei-Status'],
            'database' => ['/admin/settings/database', 'Datenbank'],
            'push' => ['/admin/settings/push', 'Push'],
        ];
        $html = '<section class="section-toolbar"><div class="scope-switch">';

        foreach ($links as $key => [$href, $label]) {
            $class = $active === $key ? 'scope-link is-active' : 'scope-link';
            $html .= '<a class="' . $class . '" href="' . $href . '">' . $label . '</a>';
        }

        return $html . '</div></section>';
    }

    private function notice(Request $request): string
    {
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            $message = $error === 'csrf'
                ? 'Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'
                : urldecode($error);

            return '<p class="notice error">' . $this->e($message) . '</p>';
        }

        return match ((string) $request->query('notice', '')) {
            'region-saved' => '<p class="notice success">Das Bundesland wurde gespeichert.</p>',
            'closure-created' => '<p class="notice success">Der Betriebsurlaub wurde angelegt.</p>',
            'closure-archived' => '<p class="notice success">Der Betriebsurlaub wurde archiviert.</p>',
            default => '',
        };
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
