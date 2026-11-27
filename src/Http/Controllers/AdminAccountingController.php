<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Exports\AccountingClosureService;
use App\Domain\Exports\AccountingDocumentExportService;
use App\Domain\Projects\ProjectService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use InvalidArgumentException;
use RuntimeException;

final class AdminAccountingController
{
    public function __construct(
        private AdminView $view,
        private AccountingClosureService $closureService,
        private AccountingDocumentExportService $exportService,
        private ProjectService $projectService,
        private UserService $userService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function index(Request $request): Response
    {
        $selection = $this->closureService->selectionFromInput($request->query());
        $preview = $this->closureService->previewPackage($selection);
        $validation = $this->closureService->validateFinalization($selection);
        $closureStatus = (string) $request->query('closure_status', '');
        $closures = $this->closureService->listClosures(['status' => $closureStatus]);
        $content = $this->renderPage(
            $selection,
            $preview,
            $validation,
            $closures,
            $this->projectService->list('all'),
            $this->userService->list('all'),
            $closureStatus,
            $this->notice($request),
            $this->csrfService->token()
        );

        return Response::html($this->view->render('Abrechnung', $content));
    }

    public function export(Request $request): Response
    {
        try {
            $format = (string) $request->query('format', 'pdf');
            $package = $this->closureService->previewPackage($request->query());
            $export = $this->exportService->export($package, $format);

            return new Response((string) $export['content'], 200, $export['headers']);
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/accounting?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function createClosure(Request $request): Response
    {
        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect('/admin/accounting?error=' . rawurlencode('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'));
        }

        try {
            $closure = $this->closureService->createClosure($request->input(), (int) ($this->authService->currentUser()['id'] ?? 0));

            return Response::redirect('/admin/accounting?notice=finalized&closure_id=' . rawurlencode((string) ($closure['closure']['id'] ?? '')));
        } catch (InvalidArgumentException | RuntimeException $exception) {
            return Response::redirect('/admin/accounting?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function download(Request $request, array $params): Response
    {
        try {
            $closure = $this->closureService->findClosure((int) ($params['id'] ?? 0));

            if ($closure === null) {
                return Response::redirect('/admin/accounting?error=' . rawurlencode('Der Abschluss wurde nicht gefunden.'));
            }

            $export = $this->exportService->export($closure, (string) $request->query('format', 'zip'));

            return new Response((string) $export['content'], 200, $export['headers']);
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/accounting?error=' . rawurlencode($exception->getMessage()));
        }
    }

    private function renderPage(array $selection, array $preview, array $validation, array $closures, array $projects, array $users, string $closureStatus, string $notice, string $csrfToken): string
    {
        $query = http_build_query(array_filter([
            'type' => $selection['type'],
            'period' => $selection['period'],
            'date_from' => $selection['date_from'],
            'date_to' => $selection['date_to'],
            'project_id' => $selection['project_id'],
            'user_id' => $selection['user_id'],
        ], static fn ($value): bool => $value !== null && $value !== ''));
        $exportBase = '/admin/accounting/export' . ($query !== '' ? '?' . $query : '');
        $closure = (array) ($preview['closure'] ?? []);
        $items = (array) ($preview['items'] ?? []);
        $errors = '';

        foreach ((array) ($validation['errors'] ?? []) as $error) {
            $errors .= '<li>' . $this->e((string) $error) . '</li>';
        }

        $canFinalize = $this->authService->hasPermission('accounting.finalize');
        $validationNotice = $validation['ok']
            ? '<p class="notice success">' . ($canFinalize ? 'Der aktuelle Bereich kann festgeschrieben werden.' : 'Der aktuelle Bereich ist fachlich pruefbar. Festschreiben ist nur mit entsprechender Berechtigung moeglich.') . '</p>'
            : '<div class="notice error"><strong>Festschreibung blockiert</strong><ul>' . $errors . '</ul></div>';
        $finalizeControls = $canFinalize
            ? '<label class="checkbox-item"><input type="checkbox" required> <span>Ich bestaetige, dass dieser Abschluss festgeschrieben und die enthaltenen Buchungen fuer normale Aenderungen gesperrt werden.</span></label>'
                . '<button class="button" type="submit"' . (!$validation['ok'] ? ' disabled' : '') . '>Abschluss festschreiben</button>'
            : '<p class="muted">Keine Berechtigung zum Festschreiben. Vorlaeufige Exporte bleiben verfuegbar.</p>';

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Buchhaltung</p>
        <h1>Abrechnung</h1>
        <p>Vorlaeufige Stundenzettel exportieren, Monats- oder Projektabschluesse festschreiben und Snapshot-Dokumente abrufen.</p>
    </div>
    <div class="toolbar-actions">
        <a class="button" href="{$this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=pdf')}">Vorlaeufiges PDF</a>
        <a class="button" href="{$this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=xlsx')}">Vorlaeufiges Excel</a>
        <a class="button" href="{$this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=zip')}">Vorlaeufiges ZIP</a>
    </div>
</header>
{$notice}
<section class="card stack">
    <form method="get" action="/admin/accounting" class="form-grid">
        <label><span>Abschlussart</span>{$this->select('type', ['month' => 'Monat', 'project' => 'Projekt'], (string) $selection['type'])}</label>
        <label><span>Monat</span><input type="month" name="period" value="{$this->e((string) ($selection['type'] === 'month' ? $selection['period'] : ''))}"><small class="muted">Wirksam bei Abschlussart Monat.</small></label>
        <label><span>Von</span><input type="date" name="date_from" value="{$this->e((string) ($selection['date_from'] ?? ''))}"><small class="muted">Wirksam bei Projektabschluss.</small></label>
        <label><span>Bis</span><input type="date" name="date_to" value="{$this->e((string) ($selection['date_to'] ?? ''))}"><small class="muted">Wirksam bei Projektabschluss.</small></label>
        <label><span>Projekt</span><select name="project_id">{$this->projectOptions($projects, $selection['project_id'])}</select></label>
        <label><span>Mitarbeiter</span><select name="user_id">{$this->userOptions($users, $selection['user_id'])}</select></label>
        <label><span>Abschlussstatus der Liste</span>{$this->select('closure_status', ['' => 'Alle Abschluesse', 'final' => 'Festgeschrieben'], $closureStatus)}</label>
        <button class="button" type="submit">Vorschau aktualisieren</button>
    </form>
</section>
<section class="grid cards">
    <article class="card metric"><h2>Status</h2><p>{$this->e((string) ($closure['status_label'] ?? ''))}</p></article>
    <article class="card metric"><h2>Buchungen</h2><p>{$this->e((string) count($items))}</p></article>
    <article class="card metric"><h2>Netto</h2><p>{$this->e($this->minutes((int) ($closure['total_net_minutes'] ?? 0)))}</p></article>
    <article class="card metric"><h2>Zeitraum</h2><p>{$this->e((string) ($closure['period_label'] ?? ''))}</p></article>
</section>
{$validationNotice}
{$this->employeeSummary($preview)}
<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Festschreiben</h2>
            <p class="muted">Beim Festschreiben wird ein unveraenderlicher Snapshot erzeugt. Enthaltene Buchungen werden fuer normale Aenderungen gesperrt.</p>
        </div>
    </div>
    <form method="post" action="/admin/accounting/closures" class="inline-form">
        <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
        <input type="hidden" name="type" value="{$this->e((string) $selection['type'])}">
        <input type="hidden" name="period" value="{$this->e((string) $selection['period'])}">
        <input type="hidden" name="date_from" value="{$this->e((string) ($selection['date_from'] ?? ''))}">
        <input type="hidden" name="date_to" value="{$this->e((string) ($selection['date_to'] ?? ''))}">
        <input type="hidden" name="project_id" value="{$this->e((string) ($selection['project_id'] ?? ''))}">
        <input type="hidden" name="user_id" value="{$this->e((string) ($selection['user_id'] ?? ''))}">
        {$finalizeControls}
    </form>
</section>
<section class="card stack">
    <h2>Bestehende Abschluesse</h2>
    {$this->closuresTable($closures)}
</section>
HTML;
    }

    private function employeeSummary(array $package): string
    {
        if (($package['employee_summary_status'] ?? '') === 'not_applicable') { return ''; }
        $summaries = (array) ($package['employee_summaries'] ?? []);
        if ($summaries === []) { return ''; }
        $rows = '';
        $cards = '';
        foreach ($summaries as $row) {
            $name = trim((string) ($row['employee_number'] ?? '') . ' ' . (string) ($row['employee_name'] ?? ''));
            $status = !empty($row['vacation_is_provisional']) ? 'Vorläufiges Urlaubskonto' : 'Bestätigter Kontostand';
            $core = [];
            foreach ((array) ($row['core_time_periods'] ?? []) as $period) {
                $days = [];
                foreach ((array) ($period['windows'] ?? []) as $weekday => $windows) {
                    $times = array_map(static fn (array $window): string => (string) $window['start'] . '–' . (string) $window['end'], (array) $windows);
                    if ($times !== []) { $days[] = ([1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'][(int) $weekday] ?? (string) $weekday) . ' ' . implode(', ', $times); }
                }
                $core[] = '<strong>' . $this->e((string) ($period['template_name'] ?? '')) . '</strong> · V' . (int) ($period['revision'] ?? 0)
                    . '<br>' . $this->e((string) ($period['effective_from'] ?? '') . ' bis ' . (string) ($period['effective_to'] ?? ''))
                    . '<br>' . $this->e(implode(' · ', $days))
                    . '<br><small>' . $this->e((string) ($period['timezone'] ?? 'Europe/Berlin'))
                    . (empty($period['enabled']) ? ' · Global deaktiviert' : '') . '</small>';
            }
            $rows .= '<tr><td>' . $this->e($name) . '<br><small>' . $this->e($status) . '</small></td>'
                . '<td>' . (int) ($row['leave_year'] ?? 0) . '<br><small>Stand ' . $this->e((string) ($row['as_of_date'] ?? '')) . '</small>'
                . '<br><small>Kontoverbrauch ab ' . $this->e((string) ($row['vacation_consumption_from_date'] ?? '')) . '</small></td>'
                . '<td>' . $this->days($row['vacation_taken_days'] ?? null) . '</td>'
                . '<td>' . $this->days($row['sick_days'] ?? null) . '</td>'
                . '<td>' . $this->days($row['vacation_taken_year_days'] ?? null) . '</td>'
                . '<td>' . $this->days($row['vacation_remaining_days'] ?? null) . '</td>'
                . '<td>' . $this->days($row['vacation_future_days'] ?? null) . '</td>'
                . '<td>' . $this->days($row['vacation_booked_remaining_days'] ?? null) . '</td>'
                . '<td>' . ($core === [] ? 'Keine Kernarbeitszeit zugeordnet' : implode('<hr>', $core)) . '</td></tr>';
            $values = '';
            foreach ([
                'Urlaub im Zeitraum (Tage)' => 'vacation_taken_days',
                'Krankheit im Zeitraum (Tage)' => 'sick_days',
                'Urlaub genommen im Jahr (Tage)' => 'vacation_taken_year_days',
                'Resturlaub am Standdatum (Tage)' => 'vacation_remaining_days',
                'Künftig gebuchter Urlaub (Tage)' => 'vacation_future_days',
                'Rest nach künftig gebuchtem Urlaub (Tage)' => 'vacation_booked_remaining_days',
            ] as $label => $key) {
                $values .= '<div><dt>' . $this->e($label) . '</dt><dd>' . $this->days($row[$key] ?? null) . '</dd></div>';
            }
            $cards .= '<article class="card vacation-account-card"><header><div><strong>' . $this->e($name)
                . '</strong><small>' . $this->e($status) . '</small><small>Urlaubsjahr ' . (int) ($row['leave_year'] ?? 0)
                . ' · Stand ' . $this->e((string) ($row['as_of_date'] ?? '')) . '</small><small>Kontoverbrauch ab '
                . $this->e((string) ($row['vacation_consumption_from_date'] ?? '')) . '</small></div></header>'
                . '<dl class="vacation-account-values">' . $values . '</dl><div><h3>Kernarbeitszeit</h3>'
                . ($core === [] ? 'Keine Kernarbeitszeit zugeordnet' : implode('<hr>', $core)) . '</div></article>';
        }

        return '<section class="card stack" aria-label="Abrechnungsübersicht"><h2>Urlaub, Krankheit und Kernarbeitszeit</h2>'
            . '<p class="muted">Urlaub und Krankheit werden in Tagen ausgewiesen, im Zeitraum jeweils bis zum Standdatum. Der Jahreswert zeigt gebuchten und genommenen Urlaub. Urlaub vor dem Kontostart ist bereits im Eröffnungsbestand berücksichtigt und wird vom Resturlaub nicht erneut abgezogen. Künftig gebuchter Urlaub wird gesondert ausgewiesen. PDF und Excel enthalten zusätzlich Abwesenheitsgutschriften und Arbeitszeitvorschau.</p>'
            . '<div class="table-scroll vacation-account-desktop"><table class="accounting-summary-table"><colgroup><col style="width:16%"><col style="width:12%"><col span="6" style="width:8%"><col style="width:24%"></colgroup><thead><tr><th>Mitarbeiter</th><th>Urlaubsjahr / Standdatum</th><th>Urlaub im Zeitraum</th><th>Krankheit im Zeitraum</th><th>Urlaub im Jahr</th><th>Resturlaub<br><small>am Standdatum</small></th><th>Künftig gebucht</th><th>Rest nach Buchungen</th><th>Kernarbeitszeit</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div><div class="vacation-account-mobile">' . $cards . '</div></section>';
    }

    private function days(mixed $value): string
    {
        return $value === null ? '—' : $this->e(rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ','));
    }

    private function closuresTable(array $closures): string
    {
        if ($closures === []) {
            return '<p class="muted">Noch keine Abschluesse vorhanden.</p>';
        }

        $rows = '';

        foreach ($closures as $closure) {
            $id = (int) ($closure['id'] ?? 0);
            $rows .= '<tr>'
                . '<td>' . $this->e((string) ($closure['closure_number'] ?? '')) . '</td>'
                . '<td>' . $this->e($this->typeLabel((string) ($closure['closure_type'] ?? ''))) . '</td>'
                . '<td><span class="badge">' . $this->e($this->statusLabel((string) ($closure['status'] ?? ''))) . '</span></td>'
                . '<td>' . $this->e((string) ($closure['period_start'] ?? '') . ' bis ' . (string) ($closure['period_end'] ?? '')) . '</td>'
                . '<td>' . $this->e($this->projectLabel($closure)) . '</td>'
                . '<td>' . $this->e($this->employeeLabel($closure)) . '</td>'
                . '<td>' . $this->e((string) ($closure['item_count'] ?? 0)) . '</td>'
                . '<td>' . $this->e($this->minutes((int) ($closure['total_net_minutes'] ?? 0))) . '</td>'
                . '<td class="table-actions"><a class="button button-secondary" href="/admin/accounting/closures/' . $id . '/download?format=zip">ZIP</a>'
                . '<a class="button button-secondary" href="/admin/accounting/closures/' . $id . '/download?format=pdf">PDF</a>'
                . '<a class="button button-secondary" href="/admin/accounting/closures/' . $id . '/download?format=xlsx">Excel</a></td>'
                . '</tr>';
        }

        return '<div class="table-scroll"><table><thead><tr><th>Nummer</th><th>Typ</th><th>Status</th><th>Zeitraum</th><th>Projekt</th><th>Mitarbeiter</th><th>Buchungen</th><th>Netto</th><th>Aktionen</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function projectOptions(array $projects, ?int $selected): string
    {
        $html = '<option value="">Alle Projekte</option>';

        foreach ($projects as $project) {
            $id = (int) ($project['id'] ?? 0);
            $label = trim((string) ($project['project_number'] ?? '') . ' ' . (string) ($project['name'] ?? ''));
            $html .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function userOptions(array $users, ?int $selected): string
    {
        $html = '<option value="">Alle Mitarbeiter</option>';

        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            $label = trim((string) ($user['employee_number'] ?? '') . ' ' . (string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $html .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function select(string $name, array $options, string $selected): string
    {
        $html = '<select name="' . $this->e($name) . '">';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . $this->e((string) $value) . '"' . ($selected === (string) $value ? ' selected' : '') . '>' . $this->e((string) $label) . '</option>';
        }

        return $html . '</select>';
    }

    private function notice(Request $request): string
    {
        $error = trim((string) $request->query('error', ''));

        if ($error !== '') {
            return '<p class="notice error">' . $this->e($error) . '</p>';
        }

        return match ((string) $request->query('notice', '')) {
            'finalized' => '<p class="notice success">Der Abschluss wurde festgeschrieben.</p>',
            default => '',
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'project' => 'Projekt',
            default => 'Monat',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'final' => 'Festgeschrieben',
            default => 'Vorlaeufig',
        };
    }

    private function projectLabel(array $closure): string
    {
        if (($closure['project_id'] ?? null) === null) {
            return 'Alle Projekte';
        }

        $label = trim((string) ($closure['project_number'] ?? '') . ' ' . (string) ($closure['project_name'] ?? ''));

        return $label !== '' ? $label : 'Projekt #' . (string) $closure['project_id'];
    }

    private function employeeLabel(array $closure): string
    {
        if (($closure['user_id'] ?? null) === null) {
            return 'Alle Mitarbeiter';
        }

        $label = trim((string) ($closure['employee_number'] ?? '') . ' ' . (string) ($closure['first_name'] ?? '') . ' ' . (string) ($closure['last_name'] ?? ''));

        return $label !== '' ? $label : 'Mitarbeiter #' . (string) $closure['user_id'];
    }

    private function minutes(int $minutes): string
    {
        return sprintf('%d:%02d h', intdiv(max(0, $minutes), 60), max(0, $minutes) % 60);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
