<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Exports\TimeAccountExportService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use RuntimeException;

final class AdminTimeAccountController
{
    public function __construct(
        private AdminView $view,
        private TimeAccountService $timeAccountService,
        private TimeAccountExportService $exportService,
        private UserService $userService
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

    private function renderPage(array $overview, array $users, string $notice = ''): string
    {
        $year = (int) $overview['year'];
        $month = (int) $overview['month'];
        $filters = $overview['filters'] ?? [];
        $rows = $this->rows($overview['rows'] ?? []);
        $userOptions = $this->userOptions($users, (int) ($filters['user_id'] ?? 0));
        $sortOptions = $this->options($this->timeAccountService->adminSortOptions(), (string) ($filters['sort'] ?? 'name'));
        $directionOptions = $this->options(['asc' => 'Aufsteigend', 'desc' => 'Absteigend'], (string) ($filters['direction'] ?? 'asc'));
        $saldoFilterOptions = $this->options([
            '' => 'Alle Salden',
            'negative' => 'Nur Fehlstunden',
            'positive' => 'Nur Ueberstunden',
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
<section class="card stack">
    {$pager}
    <div class="table-scroll">
        <table>
            <thead><tr>
                <th>{$this->sortableHeader('name', 'Mitarbeiter', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('target', 'Soll', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('actual', 'Ist', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('saldo', 'Saldo', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('taken_vacation', 'Urlaub genommen', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('pending_vacation', 'Urlaub offen', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('resturlaub', 'Resturlaub', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('available_vacation', 'Verfuegbar', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('sick', 'Krank', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('absent', 'Fehltage', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('holiday', 'Feiertage', $sort, $direction, $sortFilters)}</th>
                <th>{$this->sortableHeader('company_closure', 'Betriebsurlaub', $sort, $direction, $sortFilters)}</th>
            </tr></thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
    {$pager}
</section>
HTML;
    }

    private function rows(array $rows): string
    {
        if ($rows === []) {
            return '<tr><td colspan="12" class="table-empty">Keine Mitarbeiter fuer diese Auswahl gefunden.</td></tr>';
        }

        $html = '';

        foreach ($rows as $row) {
            $vacation = $row['vacation'] ?? [];
            $saldoClass = (int) ($row['saldo_minutes'] ?? 0) < 0 ? ' class="badge warn"' : ' class="badge ok"';
            $html .= '<tr>'
                . '<td>' . $this->e((string) ($row['user'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($row['target_label'] ?? '00:00')) . '</td>'
                . '<td>' . $this->e((string) ($row['actual_label'] ?? '00:00')) . '</td>'
                . '<td><span' . $saldoClass . '>' . $this->e((string) ($row['saldo_label'] ?? '+00:00')) . '</span></td>'
                . '<td>' . $this->number($vacation['approved_taken_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($vacation['pending_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($vacation['remaining_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($vacation['available_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($row['sick_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($row['absent_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($row['holiday_days'] ?? 0) . '</td>'
                . '<td>' . $this->number($row['company_closure_days'] ?? 0) . '</td>'
                . '</tr>';
        }

        return $html;
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

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function notice(Request $request): string
    {
        $error = (string) $request->query('error', '');

        if ($error === '') {
            return '';
        }

        $message = match ($error) {
            'export' => 'Der Export konnte nicht erstellt werden. Bitte Format oder Serverabhaengigkeiten pruefen.',
            default => 'Beim Vorgang ist ein Fehler aufgetreten.',
        };

        return '<p class="notice error">' . $this->e($message) . '</p>';
    }
}
