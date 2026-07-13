<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Exports\TimeAccountExportService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use RuntimeException;
use InvalidArgumentException;

final class AdminTimeAccountController
{
    public function __construct(
        private AdminView $view,
        private TimeAccountService $timeAccountService,
        private TimeAccountExportService $exportService,
        private UserService $userService,
        private ?EmployeeAccountCutoverService $cutoverService = null,
        private ?AccountJournalService $journalService = null,
        private ?AuthService $authService = null,
        private ?CsrfService $csrfService = null,
        private ?CompanySettingsService $companySettingsService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $year = $this->year($request);
        $month = $this->month($request);
        $filters = [
            'user_id' => (int) $request->query('user_id', 0),
            'q' => (string) $request->query('q', ''),
            'saldo_filter' => (string) $request->query('saldo_filter', ''),
            'vacation_filter' => (string) $request->query('vacation_filter', ''),
            'sort' => (string) $request->query('sort', 'name'),
            'direction' => (string) $request->query('direction', 'asc'),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 50),
        ];
        $overview = $this->timeAccountService->adminOverview($year, $month, $filters);
        $users = $this->activeEmploymentUsers($this->userService->list('active'));

        return Response::html($this->view->render('Zeitkonten', $this->renderPage($overview, $users, $this->notice($request))));
    }

    public function previewCutover(Request $request): Response
    {
        if (!$this->csrfValid($request)) {
            return Response::redirect('/admin/time-accounts?error=csrf');
        }

        try {
            $preview = $this->cutoverService?->preview($request->input()) ?? [];
            $overview = $this->timeAccountService->adminOverview($this->year($request), $this->month($request), $this->filters($request));
            $users = $this->activeEmploymentUsers($this->userService->list('active'));

            return Response::html($this->view->render('Stichtag pruefen', $this->renderPage($overview, $users, '', $preview)));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/time-accounts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function finalizeCutover(Request $request): Response
    {
        if (!$this->csrfValid($request)) {
            return Response::redirect('/admin/time-accounts?error=csrf');
        }

        try {
            $user = $this->authService?->currentUser() ?? [];
            $cutover = $this->cutoverService?->finalize($request->input(), (int) ($user['id'] ?? 0));

            return Response::redirect('/admin/time-accounts?notice=cutover_finalized&user_id=' . (int) ($cutover['user_id'] ?? 0));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/time-accounts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function saveDraft(Request $request): Response
    {
        if (!$this->csrfValid($request)) {
            return Response::redirect('/admin/time-accounts?error=csrf');
        }

        try {
            $user = $this->authService?->currentUser() ?? [];
            $draft = $this->cutoverService?->saveDraft($request->input(), (int) ($user['id'] ?? 0));

            return Response::redirect('/admin/time-accounts?notice=cutover_draft&user_id=' . (int) ($draft['user_id'] ?? 0));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/time-accounts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function reverseCutover(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) {
            return Response::redirect('/admin/time-accounts?error=csrf');
        }

        try {
            $user = $this->authService?->currentUser() ?? [];
            $this->cutoverService?->reverse((int) ($params['id'] ?? 0), (int) ($user['id'] ?? 0), (string) $request->input('reason', ''));

            return Response::redirect('/admin/time-accounts?notice=cutover_reversed');
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/time-accounts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function adjustTime(Request $request): Response
    {
        if (!$this->csrfValid($request)) {
            return Response::redirect('/admin/time-accounts?error=csrf');
        }

        try {
            $user = $this->authService?->currentUser() ?? [];
            $this->cutoverService?->addManualTimeAdjustment(
                (int) $request->input('user_id', 0),
                (string) $request->input('effective_date', ''),
                $this->parseSignedDuration($request->input('minutes', '0:00')),
                (string) $request->input('reason', ''),
                (int) ($user['id'] ?? 0),
                (string) $request->input('entry_type', 'manual_adjustment')
            );

            return Response::redirect('/admin/time-accounts?notice=time_adjusted&user_id=' . (int) $request->input('user_id', 0));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/time-accounts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function adjustVacation(Request $request): Response
    {
        if (!$this->csrfValid($request)) {
            return Response::redirect('/admin/time-accounts?error=csrf');
        }

        try {
            $user = $this->authService?->currentUser() ?? [];
            $this->cutoverService?->addManualVacationAdjustment(
                (int) $request->input('user_id', 0),
                (int) $request->input('leave_year', (int) date('Y')),
                (string) $request->input('effective_date', ''),
                (float) str_replace(',', '.', (string) $request->input('days', '0')),
                (string) $request->input('reason', ''),
                (int) ($user['id'] ?? 0),
                (string) $request->input('entry_type', 'manual_adjustment')
            );

            return Response::redirect('/admin/time-accounts?notice=vacation_adjusted&user_id=' . (int) $request->input('user_id', 0));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/time-accounts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function reverseTimeEntry(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) {
            return Response::redirect('/admin/time-accounts?error=csrf');
        }

        try {
            $user = $this->authService?->currentUser() ?? [];
            $this->journalService?->reverseTimeEntry((int) ($params['id'] ?? 0), (int) ($user['id'] ?? 0), (string) $request->input('reason', ''));

            return Response::redirect('/admin/time-accounts?notice=time_reversed');
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/time-accounts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function reverseVacationEntry(Request $request, array $params): Response
    {
        if (!$this->csrfValid($request)) {
            return Response::redirect('/admin/time-accounts?error=csrf');
        }

        try {
            $user = $this->authService?->currentUser() ?? [];
            $this->journalService?->reverseVacationEntry((int) ($params['id'] ?? 0), (int) ($user['id'] ?? 0), (string) $request->input('reason', ''));

            return Response::redirect('/admin/time-accounts?notice=vacation_reversed');
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/time-accounts?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function protocol(Request $request, array $params): Response
    {
        try {
            $pdf = $this->cutoverService?->protocolPdf((int) ($params['id'] ?? 0), $this->companySettingsService?->current() ?? []) ?? [];

            return new Response((string) ($pdf['content'] ?? ''), 200, $pdf['headers'] ?? []);
        } catch (RuntimeException|InvalidArgumentException) {
            return Response::redirect('/admin/time-accounts?error=protocol');
        }
    }

    public function export(Request $request): Response
    {
        try {
            $year = $this->year($request);
            $month = $this->month($request);
            $filters = $this->filters($request);
            unset($filters['page'], $filters['per_page']);
            $format = (string) $request->query('format', 'csv');
            $export = $this->exportService->export($format, $year, $month, $filters);

            return new Response((string) $export['content'], 200, $export['headers']);
        } catch (RuntimeException) {
            return Response::redirect('/admin/time-accounts?error=export');
        }
    }

    private function renderPage(array $overview, array $users, string $notice = '', ?array $cutoverPreview = null): string
    {
        $year = (int) $overview['year'];
        $month = (int) $overview['month'];
        $filters = $overview['filters'] ?? [];
        $csrf = $this->e($this->csrfService?->token() ?? '');
        $canManage = $this->authService?->hasPermission('time_accounts.manage') ?? true;
        $rows = $this->rows($overview['rows'] ?? [], $csrf, $canManage);
        $userOptions = $this->userOptions($users, (int) ($filters['user_id'] ?? 0));
        $sortOptions = $this->options($this->timeAccountService->adminSortOptions(), (string) ($filters['sort'] ?? 'name'));
        $directionOptions = $this->options(['asc' => 'Aufsteigend', 'desc' => 'Absteigend'], (string) ($filters['direction'] ?? 'asc'));
        $saldoFilterOptions = $this->options([
            '' => 'Alle Salden',
            'negative' => 'Nur Fehlstunden',
            'positive' => 'Nur positiver Zeitkontostand',
            'zero' => 'Ausgeglichen',
        ], (string) ($filters['saldo_filter'] ?? ''));
        $vacationFilterOptions = $this->options([
            '' => 'Alle Urlaubskonten',
            'pending' => 'Mit offenen Antraegen',
            'negative_remaining' => 'Resturlaub negativ',
            'negative_available' => 'Verfuegbar negativ',
        ], (string) ($filters['vacation_filter'] ?? ''));
        $perPageOptions = $this->perPageOptions((int) ($filters['per_page'] ?? 50));
        $pager = $this->paginationControls($overview['pagination'] ?? [], $year, $month, $filters);
        $sort = (string) ($filters['sort'] ?? 'name');
        $direction = (string) ($filters['direction'] ?? 'asc');
        $sortFilters = ['year' => $year, 'month' => $month] + $filters;
        $exportBase = '/admin/time-accounts/export?' . $this->filterQuery($sortFilters, ['page', 'per_page']);
        $separator = str_contains($exportBase, '?') && !str_ends_with($exportBase, '?') ? '&' : '';
        $exportButtons = '<a class="button" href="' . $this->e($exportBase . $separator . 'format=csv') . '">CSV</a>'
            . '<a class="button" href="' . $this->e($exportBase . $separator . 'format=xlsx') . '">Excel</a>'
            . '<a class="button" href="' . $this->e($exportBase . $separator . 'format=pdf') . '">PDF</a>';
        $cutoverForm = $canManage ? $this->cutoverForm($users, $csrf, $cutoverPreview) : '';
        $adjustmentForms = $canManage ? $this->adjustmentForms($users, $csrf) : '';

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Zeitkonto</p>
        <h1>Zeitkonten</h1>
        <p>Monatssoll, Istzeit, Saldo und Urlaubskonto pro aktivem Mitarbeiter.</p>
    </div>
    <div class="toolbar-actions">
        {$exportButtons}
    </div>
</header>
{$notice}
<section class="card stack">
    <form method="get" action="/admin/time-accounts" class="form-grid">
        <label><span>Jahr</span><input type="number" name="year" min="2000" max="2100" value="{$this->e((string) $year)}"></label>
        <label><span>Monat</span><input type="number" name="month" min="1" max="12" value="{$this->e((string) $month)}"></label>
        <label><span>Mitarbeiter</span><select name="user_id">{$userOptions}</select></label>
        <label><span>Suche</span><input type="search" name="q" value="{$this->e((string) ($filters['q'] ?? ''))}" placeholder="Name suchen"></label>
        <label><span>Saldo</span><select name="saldo_filter">{$saldoFilterOptions}</select></label>
        <label><span>Urlaub</span><select name="vacation_filter">{$vacationFilterOptions}</select></label>
        <label><span>Sortierung</span><select name="sort">{$sortOptions}</select></label>
        <label><span>Richtung</span><select name="direction">{$directionOptions}</select></label>
        <label><span>Pro Seite</span><select name="per_page">{$perPageOptions}</select></label>
        <button class="button" type="submit">Filter anwenden</button>
        <a class="button button-secondary" href="/admin/time-accounts">Zuruecksetzen</a>
    </form>
</section>
{$cutoverForm}
{$adjustmentForms}
<section class="card stack">
    {$pager}
    <div class="table-scroll">
        <table>
            <thead><tr>
                <th>{$this->sortableHeader('name', 'Mitarbeiter', $sort, $direction, $sortFilters)}</th>
                <th>Stichtag</th>
                <th>Monatsanfang</th>
                <th>{$this->sortableHeader('target', 'Soll', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('actual', 'Ist', $sort, $direction, $sortFilters)}</th>
                <th>Gutschrift</th>
                <th>{$this->sortableHeader('saldo', 'Monatsveraenderung', $sort, $direction, $sortFilters)}</th>
                <th>Zeitkontostand</th>
                <th>{$this->sortableHeader('taken_vacation', 'Urlaub genommen', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('pending_vacation', 'Urlaub offen', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('resturlaub', 'Resturlaub', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('available_vacation', 'Verfuegbar', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('sick', 'Krank', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('absent', 'Fehltage', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('holiday', 'Feiertage', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('company_closure', 'Betriebsurlaub', $sort, $direction, $sortFilters)}</th>
                <th>Aktionen</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
    {$pager}
</section>
HTML;
    }

    private function rows(array $rows, string $csrfToken, bool $canManage): string
    {
        if ($rows === []) {
            return '<tr><td colspan="17" class="table-empty">Keine Mitarbeiter fuer diese Auswahl gefunden.</td></tr>';
        }

        $html = '';

        foreach ($rows as $row) {
            $vacation = $row['vacation'] ?? [];
            $saldoClass = (int) ($row['saldo_minutes'] ?? 0) < 0 ? ' class="badge warn"' : ' class="badge ok"';
            $closing = $row['closing_balance_label'] ?? null;
            $closingClass = (int) ($row['closing_balance_minutes'] ?? 0) < 0 ? ' class="badge warn"' : ' class="badge ok"';
            $html .= '<tr>'
                . '<td>' . $this->e((string) ($row['user'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) (($row['cutover_date'] ?? null) ?: 'Nicht eingerichtet')) . '</td>'
                . '<td>' . $this->e((string) (($row['opening_balance_at_period_start_label'] ?? null) ?: '-')) . '</td>'
                . '<td>' . $this->e((string) ($row['target_label'] ?? '00:00')) . '</td>'
                . '<td>' . $this->e((string) ($row['actual_label'] ?? '00:00')) . '</td>'
                . '<td>' . $this->e((string) ($row['credited_absence_label'] ?? '00:00')) . '</td>'
                . '<td><span' . $saldoClass . '>' . $this->e((string) ($row['saldo_label'] ?? '+00:00')) . '</span></td>'
                . '<td>' . ($closing === null ? '<span class="badge warn">Nicht eingerichtet</span>' : '<span' . $closingClass . '>' . $this->e((string) $closing) . '</span>') . '</td>'
                . '<td>' . $this->number($vacation['approved_taken_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($vacation['pending_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($vacation['remaining_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($vacation['available_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($row['sick_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($row['absent_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($row['holiday_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($row['company_closure_days'] ?? 0) . '</td>'
                . '<td class="table-actions">' . $this->rowActions($row, $csrfToken, $canManage) . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function rowActions(array $row, string $csrfToken, bool $canManage): string
    {
        $cutoverId = (int) ($row['cutover_id'] ?? 0);
        $actions = $cutoverId > 0
            ? '<a class="button button-secondary" href="/admin/time-accounts/cutovers/' . $cutoverId . '/protocol">Protokoll</a>'
            : '<span class="muted">Stichtag offen</span>';

        if ($canManage && $cutoverId > 0) {
            $actions .= '<form method="post" action="/admin/time-accounts/cutovers/' . $cutoverId . '/reverse" class="inline-form">'
                . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                . '<input name="reason" required placeholder="Revidierungsgrund">'
                . '<button class="button button-danger" type="submit">Revidieren</button>'
                . '</form>';
        }

        $entries = [];

        foreach (array_slice($row['time_entries'] ?? [], 0, 3) as $entry) {
            $entries[] = $this->journalAction('time', $entry, $csrfToken, $canManage);
        }

        foreach (array_slice($row['vacation_entries'] ?? [], 0, 3) as $entry) {
            $entries[] = $this->journalAction('vacation', $entry, $csrfToken, $canManage);
        }

        if ($entries !== []) {
            $actions .= '<details><summary>Journal</summary><div class="stack">' . implode('', $entries) . '</div></details>';
        }

        return $actions;
    }

    private function journalAction(string $kind, array $entry, string $csrfToken, bool $canManage): string
    {
        $id = (int) ($entry['id'] ?? 0);
        $sourceType = (string) ($entry['source_type'] ?? '');
        $value = $kind === 'time'
            ? $this->timeLabel((int) ($entry['minutes'] ?? 0))
            : $this->number($entry['days'] ?? 0) . ' Tage';
        $text = $this->e((string) ($entry['effective_date'] ?? '') . ' ' . (string) ($entry['entry_type'] ?? '') . ' ' . $value);

        if (!$canManage || $id <= 0 || $sourceType === 'employee_account_cutover') {
            return '<p class="muted">' . $text . '</p>';
        }

        $path = $kind === 'time' ? 'time' : 'vacation';

        return '<form method="post" action="/admin/time-accounts/entries/' . $path . '/' . $id . '/reverse" class="inline-form">'
            . '<span>' . $text . '</span>'
            . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
            . '<input name="reason" required placeholder="Grund Gegenbuchung">'
            . '<button class="button button-secondary" type="submit">Ausgleichen</button>'
            . '</form>';
    }

    private function cutoverForm(array $users, string $csrfToken, ?array $preview): string
    {
        $userOptions = $this->userOptions($users, (int) ($preview['user_id'] ?? 0));
        $today = date('Y-m-d');
        $year = date('Y');
        $values = [
            'effective_from' => (string) ($preview['effective_from'] ?? $today),
            'opening_time_balance' => (string) ($preview['opening_time_balance_label'] ?? '+00:00'),
            'leave_year' => (string) ($preview['leave_year'] ?? $year),
            'annual_leave_entitlement_days' => (string) ($preview['annual_leave_entitlement_days'] ?? '0'),
            'leave_carryover_days' => (string) ($preview['leave_carryover_days'] ?? '0'),
            'opening_remaining_leave_days' => (string) ($preview['opening_remaining_leave_days'] ?? '0'),
            'source_reference' => (string) ($preview['source_reference'] ?? ''),
            'note' => (string) ($preview['note'] ?? ''),
        ];
        $previewMarkup = '';

        if ($preview !== null) {
            $warnings = array_map(fn (string $warning): string => '<li>' . $this->e($warning) . '</li>', $preview['warnings'] ?? []);
            $warningMarkup = $warnings === [] ? '<p class="badge ok">Keine auffaelligen Warnungen.</p>' : '<ul class="notice warn">' . implode('', $warnings) . '</ul>';
            $hidden = '';

            foreach ($values as $name => $value) {
                $hidden .= '<input type="hidden" name="' . $this->e($name) . '" value="' . $this->e($value) . '">';
            }

            $hidden .= '<input type="hidden" name="user_id" value="' . (int) $preview['user_id'] . '">';
            $previewMarkup = <<<HTML
<div class="notice">
    <h3>Stichtag-Vorschau</h3>
    <p><strong>{$this->e((string) $preview['employee_name'])}</strong>: Berechnung ab {$this->e((string) $preview['effective_from'])}, uebernommener Zeitkontostand {$this->e((string) $preview['opening_time_balance_label'])}, Resturlaub {$this->number($preview['opening_remaining_leave_days'] ?? 0)} Tage.</p>
    <p>Altzeitraum bis {$this->e((string) $preview['locked_until'])} wird bei Finalisierung festgeschrieben. Urlaubseroeffnungsanpassung: {$this->number($preview['opening_adjustment_days'] ?? 0)} Tage.</p>
    {$warningMarkup}
    <form method="post" action="/admin/time-accounts/cutovers/finalize" class="inline-form">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        {$hidden}
        <button class="button button-danger" type="submit">Stichtag verbindlich finalisieren</button>
    </form>
</div>
HTML;
        }

        return <<<HTML
<section class="card stack">
    <h2>Stichtag einrichten</h2>
    <form method="post" action="/admin/time-accounts/cutovers/preview" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label><span>Mitarbeiter</span><select name="user_id" required>{$userOptions}</select></label>
        <label><span>Stichtag</span><input type="date" name="effective_from" value="{$this->e($values['effective_from'])}" required></label>
        <label><span>Zeitkontostand Vortag</span><input name="opening_time_balance" value="{$this->e($values['opening_time_balance'])}" placeholder="+12:30" required></label>
        <label><span>Urlaubsjahr</span><input type="number" name="leave_year" min="2000" max="2100" value="{$this->e($values['leave_year'])}" required></label>
        <label><span>Jahresanspruch</span><input type="number" step="0.5" name="annual_leave_entitlement_days" value="{$this->e($values['annual_leave_entitlement_days'])}" required></label>
        <label><span>Uebertrag</span><input type="number" step="0.5" name="leave_carryover_days" value="{$this->e($values['leave_carryover_days'])}" required></label>
        <label><span>Resturlaub Vortag</span><input type="number" step="0.5" name="opening_remaining_leave_days" value="{$this->e($values['opening_remaining_leave_days'])}" required></label>
        <label><span>Quelle</span><input name="source_reference" value="{$this->e($values['source_reference'])}"></label>
        <label class="full-span"><span>Bemerkung</span><textarea name="note" rows="3">{$this->e($values['note'])}</textarea></label>
        <button class="button" type="submit">Vorschau pruefen</button>
        <button class="button button-secondary" type="submit" formaction="/admin/time-accounts/cutovers/draft">Entwurf speichern</button>
    </form>
    {$previewMarkup}
</section>
HTML;
    }

    private function adjustmentForms(array $users, string $csrfToken): string
    {
        $userOptions = $this->userOptions($users, 0);
        $today = $this->e(date('Y-m-d'));
        $year = $this->e(date('Y'));

        return <<<HTML
<section class="card stack">
    <h2>Korrekturbuchungen</h2>
    <div class="form-grid">
        <form method="post" action="/admin/time-accounts/entries/time" class="stack">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <label><span>Mitarbeiter</span><select name="user_id" required>{$userOptions}</select></label>
            <label><span>Wirksamkeitsdatum</span><input type="date" name="effective_date" value="{$today}" required></label>
            <label><span>Art</span><select name="entry_type"><option value="manual_adjustment">Korrektur</option><option value="payout">Auszahlung/Abgang</option><option value="time_off_compensation">Freizeitausgleich</option></select></label>
            <label><span>Wert</span><input name="minutes" placeholder="+01:30 oder -00:45" required></label>
            <label class="full-span"><span>Begruendung</span><textarea name="reason" rows="3" required></textarea></label>
            <button class="button" type="submit">Zeitkonto buchen</button>
        </form>
        <form method="post" action="/admin/time-accounts/entries/vacation" class="stack">
            <input type="hidden" name="csrf_token" value="{$csrfToken}">
            <label><span>Mitarbeiter</span><select name="user_id" required>{$userOptions}</select></label>
            <label><span>Urlaubsjahr</span><input type="number" name="leave_year" value="{$year}" required></label>
            <label><span>Wirksamkeitsdatum</span><input type="date" name="effective_date" value="{$today}" required></label>
            <label><span>Art</span><select name="entry_type"><option value="manual_adjustment">Korrektur</option><option value="expiry">Verfall</option></select></label>
            <label><span>Tage</span><input type="number" step="0.5" name="days" required></label>
            <label class="full-span"><span>Begruendung</span><textarea name="reason" rows="3" required></textarea></label>
            <button class="button" type="submit">Urlaubskonto buchen</button>
        </form>
    </div>
</section>
HTML;
    }

    private function userOptions(array $users, int $selected): string
    {
        $html = '<option value="">Alle Mitarbeiter</option>';

        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            $label = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $html .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function activeEmploymentUsers(array $users): array
    {
        return array_values(array_filter(
            $users,
            static fn (array $user): bool => ($user['employment_status'] ?? 'active') === 'active'
        ));
    }

    private function options(array $options, string $selected): string
    {
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function perPageOptions(int $selected): string
    {
        $html = '';

        foreach ($this->timeAccountService->adminPerPageOptions() as $value) {
            $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $value . '</option>';
        }

        return $html;
    }

    private function sortableHeader(string $sort, string $label, string $currentSort, string $currentDirection, array $filters): string
    {
        $nextDirection = $currentSort === $sort && $currentDirection === 'asc' ? 'desc' : 'asc';
        $filters['sort'] = $sort;
        $filters['direction'] = $nextDirection;
        $filters['page'] = 1;
        $href = '/admin/time-accounts?' . $this->filterQuery($filters);
        $indicator = $currentSort === $sort ? ($currentDirection === 'asc' ? ' ^' : ' v') : '';
        $sortState = $currentSort === $sort
            ? '<span class="sr-only">, aktuell ' . $this->e($currentDirection === 'asc' ? 'aufsteigend' : 'absteigend') . ' sortiert</span>'
            : '';
        $nextState = '<span class="sr-only">, ' . $this->e($nextDirection === 'asc' ? 'aufsteigend' : 'absteigend') . ' sortieren</span>';

        return '<a class="admin-table-sort booking-sort-link" href="' . $this->e($href) . '">' . $this->e($label . $indicator) . $sortState . $nextState . '</a>';
    }

    private function paginationControls(array $pagination, int $year, int $month, array $filters): string
    {
        if ($pagination === []) {
            return '';
        }

        $total = max(0, (int) ($pagination['total'] ?? 0));
        $page = max(1, (int) ($pagination['page'] ?? 1));
        $perPage = max(1, (int) ($pagination['per_page'] ?? 50));
        $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = min($total, $page * $perPage);
        $filters = ['year' => $year, 'month' => $month] + $filters;
        $previous = $this->pageUrl($filters, max(1, $page - 1));
        $next = $this->pageUrl($filters, min($totalPages, $page + 1));
        $previousControl = $page <= 1
            ? '<span class="button button-secondary is-disabled" aria-disabled="true">Zurueck</span>'
            : '<a class="button button-secondary" href="' . $this->e($previous) . '">Zurueck</a>';
        $nextControl = $page >= $totalPages
            ? '<span class="button button-secondary is-disabled" aria-disabled="true">Weiter</span>'
            : '<a class="button button-secondary" href="' . $this->e($next) . '">Weiter</a>';
        $summary = $total === 0
            ? 'Keine Zeitkonten gefunden'
            : $from . '-' . $to . ' von ' . $total . ' Zeitkonten';

        return <<<HTML
<nav class="booking-pagination" aria-label="Zeitkonto-Seiten">
    <p class="muted">{$this->e($summary)} - Seite {$page} von {$totalPages}</p>
    <div class="table-actions">
        {$previousControl}
        {$nextControl}
    </div>
</nav>
HTML;
    }

    private function pageUrl(array $filters, int $page): string
    {
        $filters['page'] = $page;

        return '/admin/time-accounts?' . $this->filterQuery($filters);
    }

    private function filterQuery(array $filters, array $exclude = []): string
    {
        foreach ($exclude as $key) {
            unset($filters[$key]);
        }

        return http_build_query(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== null && $value !== 0
        ));
    }

    private function filters(Request $request): array
    {
        return [
            'user_id' => (int) $request->query('user_id', 0),
            'q' => (string) $request->query('q', ''),
            'saldo_filter' => (string) $request->query('saldo_filter', ''),
            'vacation_filter' => (string) $request->query('vacation_filter', ''),
            'sort' => (string) $request->query('sort', 'name'),
            'direction' => (string) $request->query('direction', 'asc'),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 50),
        ];
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', (int) date('Y'));

        return $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');
    }

    private function month(Request $request): int
    {
        $month = (int) $request->query('month', (int) date('m'));

        return $month >= 1 && $month <= 12 ? $month : (int) date('m');
    }

    private function number(mixed $value): string
    {
        return $this->e(number_format((float) $value, 2, ',', '.'));
    }

    private function timeLabel(int $minutes): string
    {
        $prefix = $minutes >= 0 ? '+' : '-';
        $absolute = abs($minutes);

        return $prefix . sprintf('%02d:%02d', intdiv($absolute, 60), $absolute % 60);
    }

    private function csrfValid(Request $request): bool
    {
        return $this->csrfService === null || $this->csrfService->isValid((string) $request->input('csrf_token', ''));
    }

    private function parseSignedDuration(mixed $value): int
    {
        $text = trim((string) $value);

        if (preg_match('/^([+-])?\s*(\d{1,5})(?::([0-5]\d))?$/', $text, $matches) !== 1) {
            throw new InvalidArgumentException('Bitte den Wert im Format +01:30 oder -00:45 angeben.');
        }

        $sign = ($matches[1] ?? '') === '-' ? -1 : 1;
        $hours = (int) $matches[2];
        $minutes = isset($matches[3]) ? (int) $matches[3] : 0;

        return $sign * (($hours * 60) + $minutes);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function notice(Request $request): string
    {
        $error = (string) $request->query('error', '');
        $notice = (string) $request->query('notice', '');

        if ($notice !== '') {
            $message = match ($notice) {
                'cutover_finalized' => 'Stichtag wurde finalisiert.',
                'cutover_reversed' => 'Stichtag wurde revidiert.',
                'cutover_draft' => 'Stichtag-Entwurf wurde gespeichert.',
                'time_adjusted' => 'Zeitkonto-Korrektur wurde gebucht.',
                'vacation_adjusted' => 'Urlaubskonto-Korrektur wurde gebucht.',
                'time_reversed' => 'Zeitkonto-Buchung wurde ausgeglichen.',
                'vacation_reversed' => 'Urlaubskonto-Buchung wurde ausgeglichen.',
                default => 'Vorgang wurde ausgefuehrt.',
            };

            return '<p class="notice success">' . $this->e($message) . '</p>';
        }

        if ($error === '') {
            return '';
        }

        $message = match ($error) {
            'csrf' => 'Die Sicherheitspruefung ist abgelaufen. Bitte erneut versuchen.',
            'protocol' => 'Das Stichtagsprotokoll konnte nicht erstellt werden.',
            'export' => 'Der Export konnte nicht erstellt werden. Bitte Format oder Serverabhaengigkeiten pruefen.',
            default => $error,
        };

        return '<p class="notice error">' . $this->e($message) . '</p>';
    }
}
