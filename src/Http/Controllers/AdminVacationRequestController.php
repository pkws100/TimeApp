<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Users\UserService;
use App\Domain\Vacation\VacationRequestService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use InvalidArgumentException;

final class AdminVacationRequestController
{
    public function __construct(
        private AdminView $view,
        private VacationRequestService $vacationRequestService,
        private TimeAccountService $timeAccountService,
        private UserService $userService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function index(Request $request): Response
    {
        $year = $this->year($request);
        $filters = [
            'year' => $year,
            'status' => (string) $request->query('status', ''),
            'user_id' => (int) $request->query('user_id', 0),
        ];
        $requests = $this->vacationRequestService->listForAdmin($filters);
        $overview = $this->timeAccountService->adminVacationOverview($year, (int) $filters['user_id']);
        $users = array_values(array_filter(
            $this->userService->list('active'),
            static fn (array $user): bool => (string) ($user['employment_status'] ?? '') === 'active'
        ));

        return Response::html($this->view->render(
            'Urlaubskonten und Urlaubsantraege',
            $this->renderPage($requests, $overview, $users, $filters, $request)
        ));
    }

    public function approve(Request $request, array $params): Response
    {
        $returnTo = $this->returnTo($request);

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withQuery($returnTo, 'error', 'csrf'));
        }

        try {
            $user = $this->authService->currentUser();
            $this->vacationRequestService->approve(
                (int) ($params['id'] ?? 0),
                (int) ($user['id'] ?? 0),
                (string) $request->input('decision_note', '')
            );

            return Response::redirect($this->withQuery($returnTo, 'notice', 'approved'));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect($this->withQuery($returnTo, 'error', $exception->getMessage()));
        }
    }

    public function reject(Request $request, array $params): Response
    {
        $returnTo = $this->returnTo($request);

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withQuery($returnTo, 'error', 'csrf'));
        }

        try {
            $user = $this->authService->currentUser();
            $this->vacationRequestService->reject(
                (int) ($params['id'] ?? 0),
                (int) ($user['id'] ?? 0),
                (string) $request->input('decision_note', '')
            );

            return Response::redirect($this->withQuery($returnTo, 'notice', 'rejected'));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect($this->withQuery($returnTo, 'error', $exception->getMessage()));
        }
    }

    private function renderPage(array $requests, array $overview, array $users, array $filters, Request $request): string
    {
        $csrfToken = $this->e($this->csrfService->token());
        $statusOptions = $this->options([
            '' => 'Alle',
            'pending' => 'Offen',
            'approved' => 'Genehmigt',
            'rejected' => 'Abgelehnt',
            'cancelled' => 'Storniert',
        ], (string) ($filters['status'] ?? ''));
        $userOptions = $this->userOptions($users, (int) ($filters['user_id'] ?? 0));
        $notice = $this->notice($request);
        $requestRows = $this->requestRows($requests, $csrfToken, $this->currentRequestUri($request));
        $canViewTimeAccounts = $this->authService->hasPermission('time_accounts.view');
        $canManageTimeAccounts = $this->authService->hasPermission('time_accounts.manage');
        $accountRows = $this->accountRows($overview['rows'] ?? [], $canViewTimeAccounts, $canManageTimeAccounts);
        $accountCards = $this->accountCards($overview['rows'] ?? [], $canViewTimeAccounts, $canManageTimeAccounts);
        $year = (int) ($overview['year'] ?? $filters['year'] ?? date('Y'));

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Urlaub</p>
        <h1>Urlaubskonten und Urlaubsantraege</h1>
        <p>Urlaubsstaende einsehen und Antraege pruefen, genehmigen oder ablehnen.</p>
    </div>
</header>
{$notice}
<section class="card stack">
    <form method="get" action="/admin/vacation-requests" class="form-grid">
        <label><span>Urlaubsjahr</span><input type="number" name="year" min="2000" max="2100" value="{$this->e((string) $year)}" required></label>
        <label><span>Status</span><select name="status">{$statusOptions}</select></label>
        <label><span>Mitarbeiter</span><select name="user_id">{$userOptions}</select></label>
        <button class="button" type="submit">Filter anwenden</button>
        <a class="button button-secondary" href="/admin/vacation-requests">Zuruecksetzen</a>
    </form>
</section>
<section class="stack vacation-account-overview" aria-labelledby="vacation-account-heading">
    <div>
        <p class="eyebrow">Urlaubsjahr {$this->e((string) $year)}</p>
        <h2 id="vacation-account-heading">Urlaubskonten</h2>
    </div>
    <div class="card vacation-account-desktop">
        <div class="table-scroll">
            <table>
                <thead><tr><th>Mitarbeiter</th><th>Kontoquelle</th><th>Eingebucht</th><th>Genommen</th><th>Zukuenftig</th><th>Offen</th><th>Resturlaub</th><th>Verfuegbar</th><th>Aktion</th></tr></thead>
                <tbody>{$accountRows}</tbody>
            </table>
        </div>
    </div>
    <div class="vacation-account-mobile">{$accountCards}</div>
</section>
<section class="card stack">
    <div>
        <p class="eyebrow">Antraege</p>
        <h2>Urlaubsantraege</h2>
    </div>
    <div class="table-scroll">
        <table>
            <thead><tr><th>Status</th><th>Mitarbeiter</th><th>Zeitraum</th><th>Tage</th><th>Notiz</th><th>Entscheidung</th><th>Aktionen</th></tr></thead>
            <tbody>{$requestRows}</tbody>
        </table>
    </div>
</section>
HTML;
    }

    private function requestRows(array $requests, string $csrfToken, string $returnTo): string
    {
        if ($requests === []) {
            return '<tr><td colspan="7" class="table-empty">Keine Urlaubsantraege fuer diese Auswahl gefunden.</td></tr>';
        }

        $html = '';

        foreach ($requests as $request) {
            $id = (int) $request['id'];
            $status = (string) $request['status'];
            $actions = $status === 'pending'
                ? '<form method="post" action="/admin/vacation-requests/' . $id . '/approve" class="inline-form vacation-decision-form">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                    . '<input type="hidden" name="return_to" value="' . $this->e($returnTo) . '">'
                    . '<input name="decision_note" placeholder="Entscheidungsnotiz">'
                    . '<button class="button" type="submit" formaction="/admin/vacation-requests/' . $id . '/approve">Genehmigen</button>'
                    . '<button class="button button-danger" type="submit" formaction="/admin/vacation-requests/' . $id . '/reject">Ablehnen</button>'
                    . '</form>'
                : '<span class="muted">Abgeschlossen</span>';

            $html .= '<tr>'
                . '<td>' . $this->statusBadge($status) . '</td>'
                . '<td>' . $this->e((string) $request['employee_name']) . '</td>'
                . '<td>' . $this->e((string) $request['date_from']) . ' bis ' . $this->e((string) $request['date_to']) . '</td>'
                . '<td>' . $this->e(number_format((float) $request['day_count'], 2, ',', '.')) . '</td>'
                . '<td>' . $this->e((string) ($request['employee_note'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($request['decision_note'] ?? '')) . '</td>'
                . '<td class="table-actions">' . $actions . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function accountRows(array $rows, bool $canViewTimeAccounts, bool $canManageTimeAccounts): string
    {
        if ($rows === []) {
            return '<tr><td colspan="9" class="table-empty">Keine aktiven Mitarbeiter fuer diese Auswahl gefunden.</td></tr>';
        }

        $html = '';

        foreach ($rows as $row) {
            $vacation = is_array($row['vacation'] ?? null) ? $row['vacation'] : null;
            $html .= '<tr>'
                . '<td><strong>' . $this->e((string) ($row['user'] ?? '')) . '</strong>' . $this->employeeNumber($row) . '</td>'
                . '<td>' . $this->accountSource($row) . '</td>'
                . '<td>' . $this->vacationNumber($vacation, 'total_days') . '</td>'
                . '<td>' . $this->vacationNumber($vacation, 'approved_taken_past_days') . '</td>'
                . '<td>' . $this->vacationNumber($vacation, 'future_approved_days') . '</td>'
                . '<td>' . $this->vacationNumber($vacation, 'pending_days') . '</td>'
                . '<td>' . $this->vacationNumber($vacation, 'remaining_days', true) . '</td>'
                . '<td>' . $this->vacationNumber($vacation, 'available_days', true) . '</td>'
                . '<td class="table-actions">' . $this->accountAction($row, $canViewTimeAccounts, $canManageTimeAccounts) . '</td>'
                . '</tr>';
        }

        return $html;
    }

    private function accountCards(array $rows, bool $canViewTimeAccounts, bool $canManageTimeAccounts): string
    {
        if ($rows === []) {
            return '<div class="card table-empty">Keine aktiven Mitarbeiter fuer diese Auswahl gefunden.</div>';
        }

        $html = '';

        foreach ($rows as $row) {
            $vacation = is_array($row['vacation'] ?? null) ? $row['vacation'] : null;
            $html .= '<article class="card vacation-account-card">'
                . '<header><div><strong>' . $this->e((string) ($row['user'] ?? '')) . '</strong>' . $this->employeeNumber($row) . '</div>' . $this->accountSource($row) . '</header>'
                . '<dl class="vacation-account-values">'
                . $this->accountCardValue('Eingebucht', $vacation, 'total_days')
                . $this->accountCardValue('Genommen', $vacation, 'approved_taken_past_days')
                . $this->accountCardValue('Zukuenftig', $vacation, 'future_approved_days')
                . $this->accountCardValue('Offen', $vacation, 'pending_days')
                . $this->accountCardValue('Resturlaub', $vacation, 'remaining_days', true)
                . $this->accountCardValue('Verfuegbar', $vacation, 'available_days', true)
                . '</dl><div class="vacation-account-card__action">' . $this->accountAction($row, $canViewTimeAccounts, $canManageTimeAccounts) . '</div>'
                . '</article>';
        }

        return $html;
    }

    private function accountCardValue(string $label, ?array $vacation, string $key, bool $emphasize = false): string
    {
        return '<div' . ($emphasize ? ' class="is-emphasized"' : '') . '><dt>' . $this->e($label) . '</dt><dd>'
            . $this->vacationNumber($vacation, $key, $emphasize) . '</dd></div>';
    }

    private function accountSource(array $row): string
    {
        $status = (string) ($row['account_status'] ?? 'missing');

        if ($status === 'missing') {
            return '<span class="badge warn">Nicht eingerichtet</span>';
        }

        if ($status === 'not_active_in_year') {
            return '<span class="badge warn">Noch nicht aktiv</span>';
        }

        $source = (string) ($row['source'] ?? '');
        $label = match ($source) {
            'cutover_snapshot' => 'Stichtag',
            'journal' => 'Journal',
            'user_defaults' => 'Vorschlag',
            default => 'Aktiv',
        };
        $class = $source === 'user_defaults' ? 'warn' : 'ok';
        $date = (string) ($row['cutover_date'] ?? '');
        $meta = $date !== '' ? '<small class="muted">seit ' . $this->e($date) . '</small>' : '';

        return '<span class="vacation-account-source"><span class="badge ' . $class . '">' . $this->e($label) . '</span>' . $meta . '</span>';
    }

    private function accountAction(array $row, bool $canViewTimeAccounts, bool $canManageTimeAccounts): string
    {
        if (!$canViewTimeAccounts) {
            return '<span class="muted">-</span>';
        }

        $userId = (int) ($row['user_id'] ?? 0);
        $year = (int) ($row['year'] ?? date('Y'));
        $label = (string) ($row['account_status'] ?? '') === 'missing' && $canManageTimeAccounts
            ? 'Stichtag einrichten'
            : 'Zeitkonto oeffnen';
        $href = '/admin/time-accounts?user_id=' . $userId . '&year=' . $year;

        return '<a class="button button-secondary" href="' . $this->e($href) . '">' . $label . '</a>';
    }

    private function vacationNumber(?array $vacation, string $key, bool $emphasize = false): string
    {
        if ($vacation === null) {
            return '<span class="muted">-</span>';
        }

        $value = (float) ($vacation[$key] ?? 0);
        $class = $emphasize ? ($value < 0 ? 'badge warn' : 'badge ok') : '';
        $label = $this->e(number_format($value, 2, ',', '.') . ' Tage');

        return $class !== '' ? '<span class="' . $class . '">' . $label . '</span>' : $label;
    }

    private function employeeNumber(array $row): string
    {
        $number = trim((string) ($row['employee_number'] ?? ''));

        return $number === '' ? '' : '<small class="muted">' . $this->e($number) . '</small>';
    }

    private function statusBadge(string $status): string
    {
        $label = [
            'pending' => 'Offen',
            'approved' => 'Genehmigt',
            'rejected' => 'Abgelehnt',
            'cancelled' => 'Storniert',
        ][$status] ?? $status;
        $class = match ($status) {
            'approved' => 'ok',
            'pending' => 'warn',
            'rejected' => 'error',
            default => 'neutral',
        };

        return '<span class="badge ' . $class . '">' . $this->e($label) . '</span>';
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

    private function options(array $options, string $selected): string
    {
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function notice(Request $request): string
    {
        $notice = (string) $request->query('notice', '');
        $error = (string) $request->query('error', '');

        if ($notice !== '') {
            $message = $notice === 'approved' ? 'Urlaubsantrag genehmigt.' : 'Urlaubsantrag abgelehnt.';

            return '<div class="notice success">' . $this->e($message) . '</div>';
        }

        if ($error !== '') {
            $message = $error === 'csrf' ? 'Die Sicherheitspruefung ist abgelaufen. Bitte erneut versuchen.' : $error;

            return '<div class="notice error">' . $this->e($message) . '</div>';
        }

        return '';
    }

    private function currentRequestUri(Request $request): string
    {
        $query = http_build_query($request->query());

        return '/admin/vacation-requests' . ($query !== '' ? '?' . $query : '');
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', date('Y'));

        return $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');
    }

    private function returnTo(Request $request): string
    {
        $returnTo = (string) $request->input('return_to', '/admin/vacation-requests');

        return str_starts_with($returnTo, '/admin/vacation-requests') ? $returnTo : '/admin/vacation-requests';
    }

    private function withQuery(string $url, string $key, string $value): string
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '/admin/vacation-requests');
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['notice'], $query['error']);
        $query[$key] = $value;
        $queryString = http_build_query($query);

        return $path . ($queryString !== '' ? '?' . $queryString : '');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
