<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Exports\BookingExportService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use App\Presentation\Admin\BookingModalRenderer;
use InvalidArgumentException;
use RuntimeException;

final class AdminBookingController
{
    public function __construct(
        private AdminView $view,
        private AdminBookingService $bookingService,
        private BookingExportService $exportService,
        private ProjectService $projectService,
        private UserService $userService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->bookingService->normalizeFilters($request->query());
        $bookings = $this->bookingService->list($filters);
        $projects = $this->projectService->list('all');
        $users = $this->userService->list('all');
        $returnTo = $this->currentRequestUri($request);
        $csrfToken = $this->csrfService->token();

        $content = $this->renderPage(
            $request,
            $filters,
            $bookings,
            $projects,
            $users,
            $this->notice($request),
            $returnTo,
            $csrfToken
        );

        return Response::html($this->view->render('Buchungen', $content));
    }

    public function update(Request $request, array $params): Response
    {
        $returnTo = $this->returnTo($request);
        $userId = (int) (($this->authService->currentUser()['id'] ?? 0));
        $bookingId = (int) ($params['id'] ?? 0);

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'csrf'), $bookingId));
        }

        try {
            $this->bookingService->update(
                $bookingId,
                $request->input(),
                $userId,
                (string) $request->input('change_reason', '')
            );

            return Response::redirect($this->withNotice($returnTo, 'updated'));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'validation'), $bookingId));
        }
    }

    public function bulkAssign(Request $request): Response
    {
        $returnTo = $this->returnTo($request);
        $userId = (int) (($this->authService->currentUser()['id'] ?? 0));

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withError($returnTo, 'csrf'));
        }

        try {
            $bookingIds = $request->input('booking_ids', []);
            $projectId = $request->input('project_id');
            $projectId = $projectId === '__none__' || $projectId === '' || $projectId === null ? null : (int) $projectId;

            $updated = $this->bookingService->bulkAssign(
                is_array($bookingIds) ? $bookingIds : [],
                $projectId,
                $userId,
                (string) $request->input('change_reason', '')
            );

            return Response::redirect($this->withNotice($returnTo, $updated > 0 ? 'reassigned' : 'selection-missing'));
        } catch (InvalidArgumentException) {
            return Response::redirect($this->withError($returnTo, 'bulk'));
        }
    }

    public function archive(Request $request, array $params): Response
    {
        $returnTo = $this->returnTo($request);
        $userId = (int) (($this->authService->currentUser()['id'] ?? 0));
        $bookingId = (int) ($params['id'] ?? 0);

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'csrf'), $bookingId));
        }

        try {
            $this->bookingService->archive($bookingId, $userId, (string) $request->input('change_reason', ''));

            return Response::redirect($this->withNotice($returnTo, 'archived'));
        } catch (InvalidArgumentException) {
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'archive'), $bookingId));
        }
    }

    public function restore(Request $request, array $params): Response
    {
        $returnTo = $this->returnTo($request);
        $userId = (int) (($this->authService->currentUser()['id'] ?? 0));
        $bookingId = (int) ($params['id'] ?? 0);

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'csrf'), $bookingId));
        }

        try {
            $this->bookingService->restore($bookingId, $userId, (string) $request->input('change_reason', ''));

            return Response::redirect($this->withNotice($returnTo, 'restored'));
        } catch (InvalidArgumentException) {
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'restore'), $bookingId));
        }
    }

    public function export(Request $request): Response
    {
        try {
            $filters = $this->bookingService->normalizeFilters($request->query());
            $format = (string) $request->query('format', 'csv');
            $export = $this->exportService->export($format, $filters);

            return new Response((string) $export['content'], 200, $export['headers']);
        } catch (RuntimeException $exception) {
            return Response::redirect($this->withError('/admin/bookings', 'export'));
        }
    }

    private function renderPage(Request $request, array $filters, array $bookings, array $projects, array $users, string $notice, string $returnTo, string $csrfToken): string
    {
        $query = $this->filterQuery($filters);
        $exportBase = '/admin/bookings/export' . ($query !== '' ? '?' . $query : '');
        $projectOptions = $this->projectFilterOptions($projects, $filters['project_id']);
        $userOptions = $this->userOptions($users, $filters['user_id']);
        $entryTypeOptions = $this->entryTypeFilterOptions((string) $filters['entry_type']);
        $scopeSwitch = $this->scopeSwitch('/admin/bookings', (string) $filters['scope'], $filters);
        $renderer = new BookingModalRenderer();
        $canManage = $this->authService->hasPermission('timesheets.manage');
        $canArchive = $this->authService->hasPermission('timesheets.archive');
        $table = $renderer->renderTable(
            $bookings,
            $projects,
            $this->bookingService->entryTypeOptions(),
            [
                'show_selection' => true,
                'bulk_form_id' => 'bulk-assignment-form',
                'empty_message' => 'Keine Buchungen fuer die aktuelle Filterung gefunden.',
                'can_manage' => $canManage,
                'can_archive' => $canArchive,
                'open_booking_location' => $returnTo,
            ]
        );
        $modal = $renderer->renderModal(
            $projects,
            $this->bookingService->entryTypeOptions(),
            [
                'return_to' => $returnTo,
                'csrf_token' => $csrfToken,
                'can_manage' => $canManage,
                'can_archive' => $canArchive,
                'selected_booking' => $this->selectedBooking($request),
            ]
        );

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Zeitbuchungen</p>
        <h1>Buchungen</h1>
        <p>Buchungen filtern, GoBD-konform bearbeiten, projekten zuordnen und belastbar exportieren.</p>
    </div>
    <div class="toolbar-actions">
        <a class="button" href="{$this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=csv')}">CSV</a>
        <a class="button" href="{$this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=xlsx')}">Excel</a>
        <a class="button" href="{$this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=pdf')}">PDF</a>
    </div>
</header>
{$notice}
<section class="card stack">
    <form method="get" action="/admin/bookings" class="form-grid">
        <label><span>Von</span><input type="date" name="date_from" value="{$this->e((string) ($filters['date_from'] ?? ''))}"></label>
        <label><span>Bis</span><input type="date" name="date_to" value="{$this->e((string) ($filters['date_to'] ?? ''))}"></label>
        <label><span>Projekt</span><select name="project_id">{$projectOptions}</select></label>
        <label><span>Mitarbeiter</span><select name="user_id">{$userOptions}</select></label>
        <label><span>Typ</span><select name="entry_type">{$entryTypeOptions}</select></label>
        <label><span>Scope</span><select name="scope">
            <option value="active"{$this->selected($filters['scope'] === 'active')}>Aktiv</option>
            <option value="archived"{$this->selected($filters['scope'] === 'archived')}>Archiviert</option>
            <option value="all"{$this->selected($filters['scope'] === 'all')}>Alle</option>
        </select></label>
        <button class="button" type="submit">Filter anwenden</button>
    </form>
</section>
<section class="section-toolbar">
    <div class="scope-switch">{$scopeSwitch}</div>
    <p class="muted">Jede Aenderung braucht eine fachliche Begruendung. Loeschen erfolgt nur ueber Archivkennzeichen.</p>
</section>
<section class="card stack">
    {$table}
    <form id="bulk-assignment-form" method="post" action="/admin/bookings/bulk-assign" class="form-grid">
        <input type="hidden" name="return_to" value="{$this->e($returnTo)}">
        <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
        <label><span>Sammelzuordnung auf Projekt</span><select name="project_id">{$this->projectAssignmentOptions($projects, '__none__')}</select></label>
        <label class="full-span"><span>Begruendung</span><textarea name="change_reason" rows="3" required placeholder="Warum werden diese Buchungen umgehaengt?"></textarea></label>
        <button class="button" type="submit">Markierte Buchungen zuordnen</button>
    </form>
</section>
{$modal}
HTML;
    }

    private function projectFilterOptions(array $projects, string $selected): string
    {
        $html = '<option value="">Alle Projekte</option><option value="__none__"' . $this->selected($selected === '__none__') . '>Nicht zugeordnet</option>';

        foreach ($projects as $project) {
            $id = (string) ($project['id'] ?? '');
            $label = trim((string) ($project['project_number'] ?? '') . ' ' . (string) ($project['name'] ?? ''));

            if ((int) ($project['is_deleted'] ?? 0) === 1) {
                $label .= ' (archiviert)';
            }

            $html .= '<option value="' . $this->e($id) . '"' . $this->selected($selected === $id) . '>' . $this->e(trim($label)) . '</option>';
        }

        return $html;
    }

    private function projectAssignmentOptions(array $projects, string $selected): string
    {
        $html = '<option value="__none__"' . $this->selected($selected === '__none__') . '>Nicht zugeordnet</option>';

        foreach ($projects as $project) {
            $id = (string) ($project['id'] ?? '');
            $label = trim((string) ($project['project_number'] ?? '') . ' ' . (string) ($project['name'] ?? ''));

            if ((int) ($project['is_deleted'] ?? 0) === 1) {
                $label .= ' (archiviert)';
            }

            $html .= '<option value="' . $this->e($id) . '"' . $this->selected($selected === $id) . '>' . $this->e(trim($label)) . '</option>';
        }

        return $html;
    }

    private function userOptions(array $users, string $selected): string
    {
        $html = '<option value="">Alle Mitarbeiter</option>';

        foreach ($users as $user) {
            $id = (string) ($user['id'] ?? '');
            $label = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));

            if ((int) ($user['is_deleted'] ?? 0) === 1) {
                $label .= ' (archiviert)';
            }

            $html .= '<option value="' . $this->e($id) . '"' . $this->selected($selected === $id) . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function entryTypeFilterOptions(string $selected): string
    {
        $html = '<option value="">Alle Typen</option>';

        foreach ($this->bookingService->entryTypeOptions() as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . $this->selected($selected === $value) . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function scopeSwitch(string $baseUrl, string $currentScope, array $filters): string
    {
        $html = '';

        foreach (['active' => 'Aktiv', 'archived' => 'Archiviert', 'all' => 'Alle'] as $scope => $label) {
            $query = $filters;
            $query['scope'] = $scope;
            $class = $scope === $currentScope ? 'scope-link is-active' : 'scope-link';
            $html .= '<a class="' . $class . '" href="' . $this->e($baseUrl . '?' . http_build_query($query)) . '">' . $this->e($label) . '</a>';
        }

        return $html;
    }

    private function filterQuery(array $filters): string
    {
        return http_build_query(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== null
        ));
    }

    private function currentRequestUri(Request $request): string
    {
        $uri = (string) $request->server('REQUEST_URI', '/admin/bookings');

        return $this->sanitizeReturnTo($uri, '/admin/bookings');
    }

    private function returnTo(Request $request): string
    {
        $returnTo = trim((string) $request->input('return_to', '/admin/bookings'));

        return $this->sanitizeReturnTo($returnTo, '/admin/bookings');
    }

    private function withNotice(string $location, string $notice): string
    {
        return $this->withQueryValue($location, 'notice', $notice);
    }

    private function withError(string $location, string $error): string
    {
        return $this->withQueryValue($location, 'error', $error);
    }

    private function withBookingContext(string $location, int $bookingId): string
    {
        $location = $this->withQueryValue($location, 'booking_id', (string) $bookingId);

        return $this->withQueryValue($location, 'modal', 'edit');
    }

    private function sanitizeReturnTo(string $location, string $fallback): string
    {
        if (!str_starts_with($location, '/admin')) {
            return $fallback;
        }

        $path = parse_url($location, PHP_URL_PATH) ?: $fallback;
        $queryString = (string) parse_url($location, PHP_URL_QUERY);
        parse_str($queryString, $query);
        unset($query['notice'], $query['error'], $query['booking_id'], $query['modal']);

        $query = array_filter(
            $query,
            static fn ($value): bool => $value !== '' && $value !== null
        );

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    private function selectedBooking(Request $request): ?array
    {
        $bookingId = (int) $request->query('booking_id', 0);

        if ($bookingId <= 0 || (string) $request->query('modal', '') !== 'edit') {
            return null;
        }

        return $this->bookingService->find($bookingId);
    }

    private function withQueryValue(string $location, string $key, string $value): string
    {
        $separator = str_contains($location, '?') ? '&' : '?';

        return $location . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }

    private function notice(Request $request): string
    {
        $notice = (string) $request->query('notice', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            $message = match ($error) {
                'validation' => 'Die Buchung konnte nicht gespeichert werden. Bitte Datum, Zeiten und Begruendung pruefen.',
                'bulk' => 'Die Sammelzuordnung konnte nicht ausgefuehrt werden. Bitte Auswahl und Begruendung pruefen.',
                'archive' => 'Die Buchung konnte nicht archiviert werden. Bitte Begruendung und Datensatz pruefen.',
                'restore' => 'Die Buchung konnte nicht wiederhergestellt werden. Bitte Begruendung und Datensatz pruefen.',
                'export' => 'Der Export konnte nicht erstellt werden. Bitte Format oder Serverabhaengigkeiten pruefen.',
                'csrf' => 'Die Buchungsaktion konnte nicht bestaetigt werden. Bitte die Seite neu laden und erneut versuchen.',
                default => 'Beim Vorgang ist ein Fehler aufgetreten.',
            };

            return '<p class="notice error">' . $this->e($message) . '</p>';
        }

        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'updated' => 'Buchung erfolgreich gespeichert.',
            'reassigned' => 'Die markierten Buchungen wurden dem Projekt zugeordnet.',
            'archived' => 'Buchung erfolgreich archiviert.',
            'restored' => 'Buchung erfolgreich wiederhergestellt.',
            'selection-missing' => 'Es wurde keine passende Buchung fuer die Sammelaktion geaendert.',
            default => 'Vorgang erfolgreich ausgefuehrt.',
        };

        return '<p class="notice success">' . $this->e($message) . '</p>';
    }

    private function selected(bool $selected): string
    {
        return $selected ? ' selected' : '';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
