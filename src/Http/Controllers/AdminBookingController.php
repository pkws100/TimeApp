<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Exports\BookingExportService;
use App\Domain\Files\DocumentStatusService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetGeoLocationService;
use App\Domain\Timesheets\TimesheetSignatureService;
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
        private FileAttachmentService $fileAttachmentService,
        private DocumentStatusService $documentStatusService,
        private TimesheetGeoLocationService $geoLocationService,
        private AuthService $authService,
        private CsrfService $csrfService,
        private ?TimesheetSignatureService $timesheetSignatureService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->bookingService->normalizeFilters($request->query());
        $pagination = $this->bookingService->paginatedList($filters);
        $bookings = $this->withTimesheetAttachments($pagination['items']);
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
            $csrfToken,
            $pagination
        );

        return Response::html($this->view->render('Buchungen', $content));
    }

    public function create(Request $request): Response
    {
        $returnTo = $this->returnTo($request);
        $userId = (int) (($this->authService->currentUser()['id'] ?? 0));

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withError($returnTo, 'csrf'));
        }

        try {
            $this->bookingService->createManual($request->input(), $userId);

            return Response::redirect($this->withNotice($returnTo, 'created'));
        } catch (InvalidArgumentException $exception) {
            return Response::redirect($this->withError($returnTo, 'validation', $exception->getMessage()));
        }
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
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'validation', $exception->getMessage()), $bookingId));
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
        } catch (InvalidArgumentException $exception) {
            return Response::redirect($this->withError($returnTo, 'bulk', $exception->getMessage()));
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
        } catch (InvalidArgumentException $exception) {
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'archive', $exception->getMessage()), $bookingId));
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
        } catch (InvalidArgumentException $exception) {
            return Response::redirect($this->withBookingContext($this->withError($returnTo, 'restore', $exception->getMessage()), $bookingId));
        }
    }

    public function export(Request $request): Response
    {
        try {
            $filters = $this->bookingService->normalizeFilters($request->query());
            unset($filters['page'], $filters['per_page']);
            $format = (string) $request->query('format', 'csv');
            $export = $this->exportService->export($format, $filters);

            return new Response((string) $export['content'], 200, $export['headers']);
        } catch (RuntimeException $exception) {
            return Response::redirect($this->withError('/admin/bookings', 'export'));
        }
    }

    private function renderPage(Request $request, array $filters, array $bookings, array $projects, array $users, string $notice, string $returnTo, string $csrfToken, array $pagination = []): string
    {
        $query = $this->filterQuery($filters, ['page', 'per_page']);
        $exportBase = '/admin/bookings/export' . ($query !== '' ? '?' . $query : '');
        $projectOptions = $this->projectFilterOptions($projects, $filters['project_id']);
        $userOptions = $this->userOptions($users, $filters['user_id']);
        $entryTypeOptions = $this->entryTypeFilterOptions((string) $filters['entry_type']);
        $issueOptions = $this->issueFilterOptions((string) ($filters['issue'] ?? ''));
        $sortOptions = $this->sortOptions((string) ($filters['sort'] ?? 'date'));
        $directionOptions = $this->directionOptions((string) ($filters['direction'] ?? 'desc'));
        $perPageOptions = $this->perPageOptions((int) ($pagination['per_page'] ?? ($filters['per_page'] ?? 100)));
        $scopeSwitch = $this->scopeSwitch('/admin/bookings', (string) $filters['scope'], $filters);
        $openProjectAssignmentUrl = '/admin/bookings?scope=active&project_id=__none__&entry_type=work';
        $openIssuesUrl = '/admin/bookings?scope=active&issue=all';
        $renderer = new BookingModalRenderer();
        $canManage = $this->authService->hasPermission('timesheets.manage');
        $canArchive = $this->authService->hasPermission('timesheets.archive');
        $canExport = $this->authService->hasPermission('timesheets.export');
        $pager = $this->paginationControls($pagination, $filters);
        $table = $renderer->renderTable(
            $bookings,
            $projects,
            $this->bookingService->entryTypeOptions(),
            [
                'show_selection' => $canManage,
                'bulk_form_id' => 'bulk-assignment-form',
                'empty_message' => 'Keine Buchungen fuer die aktuelle Filterung gefunden.',
                'can_manage' => $canManage,
                'can_archive' => $canArchive,
                'can_view_attachments' => true,
                'document_statuses' => $this->documentStatusService->activeList(),
                'open_booking_location' => $returnTo,
                'column_controls' => true,
                'sort_enabled' => true,
                'sort' => (string) ($filters['sort'] ?? 'date'),
                'direction' => (string) ($filters['direction'] ?? 'desc'),
                'sort_base_url' => '/admin/bookings',
                'sort_filters' => $filters,
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
                'can_view_attachments' => true,
                'document_statuses' => $this->documentStatusService->activeList(),
                'selected_booking' => $this->selectedBooking($request),
                'absence_reason_options' => $this->bookingService->absenceReasonOptions(),
            ]
        );
        $exportButtons = $canExport
            ? '<a class="button" href="' . $this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=csv') . '">CSV</a>'
                . '<a class="button" href="' . $this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=xlsx') . '">Excel</a>'
                . '<a class="button" href="' . $this->e($exportBase . (str_contains($exportBase, '?') ? '&' : '?') . 'format=pdf') . '">PDF</a>'
            : '';
        $bulkForm = $canManage
            ? <<<HTML
    <form id="bulk-assignment-form" method="post" action="/admin/bookings/bulk-assign" class="form-grid booking-bulk-form">
        <input type="hidden" name="return_to" value="{$this->e($returnTo)}">
        <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
        <label><span>Sammelzuordnung auf Projekt</span><select name="project_id">{$this->projectAssignmentOptions($projects, '__none__')}</select></label>
        <label class="full-span"><span>Begruendung</span><textarea name="change_reason" rows="3" required placeholder="Warum werden diese Buchungen umgehaengt?"></textarea></label>
        <button class="button" type="submit">Markierte Buchungen zuordnen</button>
    </form>
HTML
            : '';

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Zeitbuchungen</p>
        <h1>Buchungen</h1>
        <p>Buchungen filtern, GoBD-konform bearbeiten, projekten zuordnen und belastbar exportieren.</p>
    </div>
    <div class="toolbar-actions">
        {$exportButtons}
    </div>
</header>
{$notice}
<div class="notice info">
    <strong>Offene Projektzuordnungen</strong><br>
    Aktive Arbeitsbuchungen ohne Projekt brauchen eine spaetere Zuordnung. Nutzen Sie den Schnellfilter und weisen Sie die Buchungen einzeln oder gesammelt zu.
    <div class="notice-actions">
        <a class="button button-secondary" href="{$this->e($openProjectAssignmentUrl)}">Offene Projektzuordnungen anzeigen</a>
        <a class="button button-secondary" href="{$this->e($openIssuesUrl)}">Fehlbuchungen anzeigen</a>
    </div>
</div>
<section class="card stack">
    <form method="get" action="/admin/bookings" class="form-grid">
        <label><span>Von</span><input type="date" name="date_from" value="{$this->e((string) ($filters['date_from'] ?? ''))}"></label>
        <label><span>Bis</span><input type="date" name="date_to" value="{$this->e((string) ($filters['date_to'] ?? ''))}"></label>
        <label><span>Projekt</span><select name="project_id">{$projectOptions}</select></label>
        <label><span>Mitarbeiter</span><select name="user_id">{$userOptions}</select></label>
        <label><span>Typ</span><select name="entry_type">{$entryTypeOptions}</select></label>
        <label><span>Fehlbuchungen</span><select name="issue">{$issueOptions}</select></label>
        <label><span>Scope</span><select name="scope">
            <option value="active"{$this->selected($filters['scope'] === 'active')}>Aktiv</option>
            <option value="archived"{$this->selected($filters['scope'] === 'archived')}>Archiviert</option>
            <option value="all"{$this->selected($filters['scope'] === 'all')}>Alle</option>
        </select></label>
        <label><span>Sortierung</span><select name="sort">{$sortOptions}</select></label>
        <label><span>Richtung</span><select name="direction">{$directionOptions}</select></label>
        <label><span>Pro Seite</span><select name="per_page">{$perPageOptions}</select></label>
        <button class="button" type="submit">Filter anwenden</button>
    </form>
</section>
<section class="section-toolbar">
    <div class="scope-switch">{$scopeSwitch}</div>
    <p class="muted">Jede Aenderung braucht eine fachliche Begruendung. Loeschen erfolgt nur ueber Archivkennzeichen.</p>
</section>
<section class="card stack">
    {$bulkForm}
    {$pager}
    {$table}
    {$pager}
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

    private function issueFilterOptions(string $selected): string
    {
        return '<option value=""' . $this->selected($selected === '') . '>Alle Buchungen</option>'
            . '<option value="all"' . $this->selected($selected === 'all') . '>Nur Fehlbuchungen</option>';
    }

    private function sortOptions(string $selected): string
    {
        $html = '';

        foreach ($this->bookingService->sortOptions() as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . $this->selected($selected === $value) . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function directionOptions(string $selected): string
    {
        return '<option value="desc"' . $this->selected($selected === 'desc') . '>Absteigend</option>'
            . '<option value="asc"' . $this->selected($selected === 'asc') . '>Aufsteigend</option>';
    }

    private function perPageOptions(int $selected): string
    {
        $html = '';

        foreach ($this->bookingService->perPageOptions() as $value) {
            $html .= '<option value="' . $value . '"' . $this->selected($selected === $value) . '>' . $value . '</option>';
        }

        return $html;
    }

    private function scopeSwitch(string $baseUrl, string $currentScope, array $filters): string
    {
        $html = '';

        foreach (['active' => 'Aktiv', 'archived' => 'Archiviert', 'all' => 'Alle'] as $scope => $label) {
            $query = $filters;
            $query['scope'] = $scope;
            $query['page'] = 1;
            $class = $scope === $currentScope ? 'scope-link is-active' : 'scope-link';
            $html .= '<a class="' . $class . '" href="' . $this->e($baseUrl . '?' . http_build_query($query)) . '">' . $this->e($label) . '</a>';
        }

        return $html;
    }

    private function filterQuery(array $filters, array $exclude = []): string
    {
        foreach ($exclude as $key) {
            unset($filters[$key]);
        }

        return http_build_query(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== null
        ));
    }

    private function paginationControls(array $pagination, array $filters): string
    {
        if ($pagination === []) {
            return '';
        }

        $total = max(0, (int) ($pagination['total'] ?? 0));
        $page = max(1, (int) ($pagination['page'] ?? 1));
        $perPage = max(1, (int) ($pagination['per_page'] ?? 100));
        $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = min($total, $page * $perPage);
        $previous = $this->pageUrl($filters, max(1, $page - 1));
        $next = $this->pageUrl($filters, min($totalPages, $page + 1));
        $previousControl = $page <= 1
            ? '<span class="button button-secondary is-disabled" aria-disabled="true">Zurueck</span>'
            : '<a class="button button-secondary" href="' . $this->e($previous) . '">Zurueck</a>';
        $nextControl = $page >= $totalPages
            ? '<span class="button button-secondary is-disabled" aria-disabled="true">Weiter</span>'
            : '<a class="button button-secondary" href="' . $this->e($next) . '">Weiter</a>';
        $summary = $total === 0
            ? 'Keine Buchungen gefunden'
            : $from . '-' . $to . ' von ' . $total . ' Buchungen';

        return <<<HTML
<nav class="booking-pagination" aria-label="Buchungsseiten">
    <p class="muted">{$this->e($summary)} · Seite {$page} von {$totalPages}</p>
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

        return '/admin/bookings?' . http_build_query(array_filter(
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

    private function withError(string $location, string $error, ?string $detail = null): string
    {
        $location = $this->withQueryValue($location, 'error', $error);
        $detail = $this->errorDetail($detail);

        if ($detail === '') {
            unset($_SESSION['admin_booking_error_detail']);
        } else {
            $_SESSION['admin_booking_error_detail'] = $detail;
        }

        return $location;
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
        unset($query['notice'], $query['error'], $query['error_detail'], $query['booking_id'], $query['modal']);

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

        $booking = $this->bookingService->find($bookingId);

        if ($booking === null) {
            return null;
        }

        return $this->withTimesheetAttachments([$booking])[0] ?? $booking;
    }

    private function withTimesheetAttachments(array $bookings): array
    {
        $timesheetIds = array_map(static fn (array $booking): int => (int) ($booking['id'] ?? 0), $bookings);
        $geoByTimesheet = $this->geoLocationService->listForTimesheetsGrouped($timesheetIds);
        $signatureByTimesheet = $this->timesheetSignatureService?->listForTimesheetsGrouped($timesheetIds) ?? [];
        $attachmentsByTimesheet = $this->fileAttachmentService->listForTimesheetsAdminGrouped($timesheetIds, 'all');

        foreach ($bookings as $index => $booking) {
            $timesheetId = (int) ($booking['id'] ?? 0);
            $attachments = $attachmentsByTimesheet[$timesheetId] ?? [];
            $activeAttachments = array_values(array_filter(
                $attachments,
                static fn (array $file): bool => (int) ($file['is_deleted'] ?? 0) === 0
            ));
            $archivedAttachments = array_values(array_filter(
                $attachments,
                static fn (array $file): bool => (int) ($file['is_deleted'] ?? 0) === 1
            ));

            $bookings[$index]['attachments'] = $attachments;
            $bookings[$index]['attachment_count'] = count($activeAttachments);
            $bookings[$index]['attachment_total_count'] = count($attachments);
            $bookings[$index]['archived_attachment_count'] = count($archivedAttachments);
            $bookings[$index]['image_attachment_count'] = count(array_filter(
                $activeAttachments,
                static fn (array $file): bool => (bool) ($file['is_image'] ?? false)
            ));
            $bookings[$index]['geo_records'] = $geoByTimesheet[$timesheetId] ?? [];
            $bookings[$index]['geo_count'] = count($bookings[$index]['geo_records']);
            $bookings[$index]['latest_geo'] = $bookings[$index]['geo_records'][0] ?? null;
            $bookings[$index]['customer_signature'] = $signatureByTimesheet[$timesheetId] ?? null;
            $bookings[$index]['customer_signature_present'] = isset($signatureByTimesheet[$timesheetId]);
        }

        return $bookings;
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
            $detail = in_array($error, ['validation', 'bulk', 'archive', 'restore'], true)
                ? $this->consumeErrorDetail()
                : '';
            $message = match ($error) {
                'validation' => 'Die Buchung konnte nicht gespeichert werden. Bitte Datum, Zeiten und Begruendung pruefen.',
                'bulk' => 'Die Sammelzuordnung konnte nicht ausgefuehrt werden. Bitte Auswahl und Begruendung pruefen.',
                'archive' => 'Die Buchung konnte nicht archiviert werden. Bitte Begruendung und Datensatz pruefen.',
                'restore' => 'Die Buchung konnte nicht wiederhergestellt werden. Bitte Begruendung und Datensatz pruefen.',
                'export' => 'Der Export konnte nicht erstellt werden. Bitte Format oder Serverabhaengigkeiten pruefen.',
                'attachment-csrf' => 'Die Anhangsaktion konnte nicht bestaetigt werden. Bitte die Seite neu laden und erneut versuchen.',
                'attachment-missing' => 'Der Anhang wurde nicht gefunden oder ist bereits archiviert.',
                'attachment-status' => 'Der Dokumentstatus konnte nicht gespeichert werden.',
                'csrf' => 'Die Buchungsaktion konnte nicht bestaetigt werden. Bitte die Seite neu laden und erneut versuchen.',
                default => 'Beim Vorgang ist ein Fehler aufgetreten.',
            };

            if ($detail !== '') {
                $message .= ' Ursache: ' . $detail;
            }

            return '<p class="notice error">' . $this->e($message) . '</p>';
        }

        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'created' => 'Buchung erfolgreich nacherfasst.',
            'updated' => 'Buchung erfolgreich gespeichert.',
            'reassigned' => 'Die markierten Buchungen wurden dem Projekt zugeordnet.',
            'archived' => 'Buchung erfolgreich archiviert.',
            'restored' => 'Buchung erfolgreich wiederhergestellt.',
            'attachment-archived' => 'Anhang erfolgreich archiviert.',
            'attachment-status-updated' => 'Dokumentstatus erfolgreich gespeichert.',
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

    private function errorDetail(?string $detail): string
    {
        $detail = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim((string) $detail)) ?? '';

        return mb_substr(trim($detail), 0, 500);
    }

    private function consumeErrorDetail(): string
    {
        $detail = $this->errorDetail($_SESSION['admin_booking_error_detail'] ?? null);
        unset($_SESSION['admin_booking_error_detail']);

        return $detail;
    }
}
