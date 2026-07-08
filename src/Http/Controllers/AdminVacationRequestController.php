<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
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
        private UserService $userService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => (string) $request->query('status', ''),
            'user_id' => (int) $request->query('user_id', 0),
        ];
        $requests = $this->vacationRequestService->listForAdmin($filters);

        return Response::html($this->view->render(
            'Urlaubsantraege',
            $this->renderPage($requests, $this->userService->list('active'), $filters, $request)
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

    private function renderPage(array $requests, array $users, array $filters, Request $request): string
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
        $rows = $this->rows($requests, $csrfToken, $this->currentRequestUri($request));

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Urlaub</p>
        <h1>Urlaubsantraege</h1>
        <p>Antraege pruefen, genehmigen oder ablehnen. Genehmigungen erzeugen nachvollziehbare Urlaubsbuchungen.</p>
    </div>
</header>
{$notice}
<section class="card stack">
    <form method="get" action="/admin/vacation-requests" class="form-grid">
        <label><span>Status</span><select name="status">{$statusOptions}</select></label>
        <label><span>Mitarbeiter</span><select name="user_id">{$userOptions}</select></label>
        <button class="button" type="submit">Filter anwenden</button>
    </form>
</section>
<section class="card stack">
    <div class="table-scroll">
        <table>
            <thead><tr><th>Status</th><th>Mitarbeiter</th><th>Zeitraum</th><th>Tage</th><th>Notiz</th><th>Entscheidung</th><th>Aktionen</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
</section>
HTML;
    }

    private function rows(array $requests, string $csrfToken, string $returnTo): string
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
