<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Personnel\PersonnelEventService;
use App\Domain\Personnel\PersonnelLabelService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use App\Presentation\Admin\PersonnelIconRenderer;
use InvalidArgumentException;
use RuntimeException;

final class PersonnelController
{
    public function __construct(
        private AdminView $view,
        private PersonnelLabelService $labelService,
        private PersonnelEventService $eventService,
        private UserService $userService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function index(Request $request): Response
    {
        $overview = $this->eventService->overview();
        $content = $this->header('Personal', 'Qualifikationen, Schulungen und Faelligkeiten im Blick.')
            . $this->tabs('overview')
            . $this->notice($request)
            . $this->metricGrid([
                'Events' => (string) ($overview['total_events'] ?? 0),
                'Bald faellig' => (string) ($overview['due_soon_events'] ?? 0),
                'Ueberfaellig' => (string) ($overview['overdue_events'] ?? 0),
                'Erledigt' => (string) ($overview['completed_events'] ?? 0),
            ])
            . '<section class="analytics-grid">'
            . '<article class="card stack"><div><h2>Statusgrafik</h2><p class="muted">Aktive Mitarbeiter-Events nach berechnetem Status.</p></div><div class="personnel-chart-wrap"><canvas data-personnel-chart="status" data-chart-payload="' . $this->dataJson($overview['status_chart'] ?? []) . '"></canvas></div></article>'
            . $this->eventListCard('Naechste Termine', $overview['upcoming'] ?? [], 'Keine anstehenden Termine.')
            . $this->eventListCard('Ueberfaellig', $overview['overdue'] ?? [], 'Keine ueberfaelligen Termine.')
            . '</section>';

        return Response::html($this->view->render('Personal', $content, '<script src="/assets/js/admin-personnel.js"></script>'));
    }

    public function labels(Request $request): Response
    {
        $scope = $this->scope($request);
        $labels = $this->labelService->list($scope);
        $csrfToken = $this->e($this->csrfService->token());
        $canManage = $this->authService->hasPermission('personnel.manage');
        $editLabel = $canManage ? $this->labelService->find((int) $request->query('edit', 0)) : null;
        $rows = '';

        foreach ($labels as $label) {
            $id = (int) ($label['id'] ?? 0);
            $archived = (int) ($label['is_deleted'] ?? 0) === 1;
            $actions = $canManage
                ? '<a class="button button-secondary" href="/admin/personnel/labels?edit=' . $id . '">Bearbeiten</a>'
                    . '<form method="post" action="/admin/personnel/labels/' . $id . '/archive" class="inline-form"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><button class="button button-danger" type="submit">' . ($archived ? 'Archiviert' : 'Archivieren') . '</button></form>'
                : '<span class="muted">Nur Ansicht</span>';
            $rows .= '<tr>'
                . '<td>' . PersonnelIconRenderer::badge($label) . '</td>'
                . '<td>' . $this->e((string) ($label['description'] ?? '')) . '</td>'
                . '<td data-sort-type="number">' . (int) ($label['user_count'] ?? 0) . '</td>'
                . '<td>' . ($archived ? '<span class="badge warn">archiviert</span>' : '<span class="badge ok">aktiv</span>') . '</td>'
                . '<td class="table-actions">' . $actions . '</td>'
                . '</tr>';
        }

        $labelFormAction = $editLabel === null ? '/admin/personnel/labels' : '/admin/personnel/labels/' . (int) ($editLabel['id'] ?? 0);
        $labelFormTitle = $editLabel === null ? 'Label anlegen' : 'Label bearbeiten';
        $labelButton = $editLabel === null ? 'Label speichern' : 'Aenderungen speichern';
        $createForm = $canManage ? <<<HTML
<section class="card stack">
    <h2>{$this->e($labelFormTitle)}</h2>
    <form method="post" action="{$this->e($labelFormAction)}" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label><span>Name</span><input name="name" value="{$this->field($editLabel, 'name')}" required></label>
        <label><span>Farbe</span><input name="color" type="color" value="{$this->e((string) ($editLabel['color'] ?? '#2563eb'))}"></label>
        <label><span>Icon-Key</span><input name="icon" value="{$this->e((string) ($editLabel['icon'] ?? 'award'))}"></label>
        <label class="full-span"><span>Beschreibung</span><textarea name="description" rows="3">{$this->field($editLabel, 'description')}</textarea></label>
        <button class="button" type="submit">{$this->e($labelButton)}</button>
        {$this->cancelEditLink($editLabel, '/admin/personnel/labels')}
    </form>
</section>
HTML : '';

        $content = $this->header('Labels', 'Qualifikationsmarker fuer Mitarbeiter.')
            . $this->tabs('labels')
            . $this->notice($request)
            . '<section class="analytics-grid">'
            . '<article class="card stack"><div><h2>Label-Verteilung</h2><p class="muted">Mitarbeiteranzahl je aktivem Label.</p></div><div class="personnel-chart-wrap"><canvas data-personnel-chart="doughnut" data-chart-payload="' . $this->dataJson($this->labelService->statistics()) . '"></canvas></div></article>'
            . $createForm
            . '</section>'
            . '<section class="section-toolbar"><div class="scope-switch">'
            . $this->scopeLink('/admin/personnel/labels', 'active', 'Aktiv', $scope)
            . $this->scopeLink('/admin/personnel/labels', 'archived', 'Archiviert', $scope)
            . $this->scopeLink('/admin/personnel/labels', 'all', 'Alle', $scope)
            . '</div></section>'
            . '<section class="card"><div class="table-scroll"><table data-admin-table="personnel-labels" data-table-label="Labels"><thead><tr><th>Label</th><th>Beschreibung</th><th data-sort-type="number">Mitarbeiter</th><th>Status</th><th data-search="false" data-sort="false">Aktionen</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="5" class="table-empty">Keine Labels vorhanden.</td></tr>')
            . '</tbody></table></div></section>';

        return Response::html($this->view->render('Personal Labels', $content, '<script src="/assets/js/admin-personnel.js"></script>'));
    }

    public function createLabel(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/labels?error=csrf');
        }

        try {
            $this->labelService->create($request->input());
            return Response::redirect('/admin/personnel/labels?notice=saved');
        } catch (InvalidArgumentException|RuntimeException) {
            return Response::redirect('/admin/personnel/labels?error=validation');
        }
    }

    public function updateLabel(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/labels?error=csrf');
        }

        try {
            $this->labelService->update((int) ($params['id'] ?? 0), $request->input());
            return Response::redirect('/admin/personnel/labels?notice=saved');
        } catch (InvalidArgumentException|RuntimeException) {
            return Response::redirect('/admin/personnel/labels?error=validation');
        }
    }

    public function archiveLabel(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/labels?error=csrf');
        }

        $this->labelService->archive((int) ($params['id'] ?? 0), $this->currentUserId());

        return Response::redirect('/admin/personnel/labels?notice=archived');
    }

    public function eventTypes(Request $request): Response
    {
        $scope = $this->scope($request);
        $types = $this->eventService->eventTypes($scope);
        $csrfToken = $this->e($this->csrfService->token());
        $canManage = $this->authService->hasPermission('personnel.manage');
        $editType = $canManage ? $this->eventService->findEventType((int) $request->query('edit', 0)) : null;
        $rows = '';

        foreach ($types as $type) {
            $id = (int) ($type['id'] ?? 0);
            $archived = (int) ($type['is_deleted'] ?? 0) === 1;
            $actions = $canManage
                ? '<a class="button button-secondary" href="/admin/personnel/event-types?edit=' . $id . '">Bearbeiten</a>'
                    . '<form method="post" action="/admin/personnel/event-types/' . $id . '/archive" class="inline-form"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><button class="button button-danger" type="submit">' . ($archived ? 'Archiviert' : 'Archivieren') . '</button></form>'
                : '<span class="muted">Nur Ansicht</span>';
            $rows .= '<tr>'
                . '<td>' . PersonnelIconRenderer::badge($type, '#7c3aed', 'calendar-check') . '</td>'
                . '<td>' . $this->e((string) ($type['description'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($type['default_reminder_days'] ?? '')) . '</td>'
                . '<td data-sort-type="number">' . (int) ($type['event_count'] ?? 0) . '</td>'
                . '<td>' . ($archived ? '<span class="badge warn">archiviert</span>' : '<span class="badge ok">aktiv</span>') . '</td>'
                . '<td class="table-actions">' . $actions . '</td>'
                . '</tr>';
        }

        $typeFormAction = $editType === null ? '/admin/personnel/event-types' : '/admin/personnel/event-types/' . (int) ($editType['id'] ?? 0);
        $typeFormTitle = $editType === null ? 'Event-Typ anlegen' : 'Event-Typ bearbeiten';
        $typeButton = $editType === null ? 'Event-Typ speichern' : 'Aenderungen speichern';
        $createForm = $canManage ? <<<HTML
<section class="card stack">
    <h2>{$this->e($typeFormTitle)}</h2>
    <form method="post" action="{$this->e($typeFormAction)}" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label><span>Name</span><input name="name" value="{$this->field($editType, 'name')}" required></label>
        <label><span>Farbe</span><input name="color" type="color" value="{$this->e((string) ($editType['color'] ?? '#7c3aed'))}"></label>
        <label><span>Icon-Key</span><input name="icon" value="{$this->e((string) ($editType['icon'] ?? 'calendar-check'))}"></label>
        <label><span>Standard-Reminder Tage</span><input name="default_reminder_days" type="number" min="0" value="{$this->field($editType, 'default_reminder_days')}"></label>
        <label class="full-span"><span>Beschreibung</span><textarea name="description" rows="3">{$this->field($editType, 'description')}</textarea></label>
        <button class="button" type="submit">{$this->e($typeButton)}</button>
        {$this->cancelEditLink($editType, '/admin/personnel/event-types')}
    </form>
</section>
HTML : '';

        $content = $this->header('Event-Typen', 'Vorlagen fuer Schulungen, Fuehrerscheinmodule und Nachweise.')
            . $this->tabs('event-types')
            . $this->notice($request)
            . $createForm
            . '<section class="section-toolbar"><div class="scope-switch">'
            . $this->scopeLink('/admin/personnel/event-types', 'active', 'Aktiv', $scope)
            . $this->scopeLink('/admin/personnel/event-types', 'archived', 'Archiviert', $scope)
            . $this->scopeLink('/admin/personnel/event-types', 'all', 'Alle', $scope)
            . '</div></section>'
            . '<section class="card"><div class="table-scroll"><table data-admin-table="personnel-event-types" data-table-label="Event-Typen"><thead><tr><th>Typ</th><th>Beschreibung</th><th>Reminder</th><th data-sort-type="number">Events</th><th>Status</th><th data-search="false" data-sort="false">Aktionen</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="6" class="table-empty">Keine Event-Typen vorhanden.</td></tr>')
            . '</tbody></table></div></section>';

        return Response::html($this->view->render('Personal Event-Typen', $content));
    }

    public function createEventType(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/event-types?error=csrf');
        }

        try {
            $this->eventService->createEventType($request->input());
            return Response::redirect('/admin/personnel/event-types?notice=saved');
        } catch (InvalidArgumentException|RuntimeException) {
            return Response::redirect('/admin/personnel/event-types?error=validation');
        }
    }

    public function updateEventType(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/event-types?error=csrf');
        }

        try {
            $this->eventService->updateEventType((int) ($params['id'] ?? 0), $request->input());
            return Response::redirect('/admin/personnel/event-types?notice=saved');
        } catch (InvalidArgumentException|RuntimeException) {
            return Response::redirect('/admin/personnel/event-types?error=validation');
        }
    }

    public function archiveEventType(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/event-types?error=csrf');
        }

        $this->eventService->archiveEventType((int) ($params['id'] ?? 0), $this->currentUserId());

        return Response::redirect('/admin/personnel/event-types?notice=archived');
    }

    public function events(Request $request): Response
    {
        $filters = [
            'status' => (string) $request->query('status', 'all'),
            'event_type_id' => (string) $request->query('event_type_id', ''),
            'user_id' => (string) $request->query('user_id', ''),
            'date_from' => (string) $request->query('date_from', ''),
            'date_to' => (string) $request->query('date_to', ''),
            'scope' => (string) $request->query('scope', 'active'),
        ];
        $events = $this->eventService->events($filters);
        $types = $this->eventService->eventTypes('active');
        $users = $this->userService->list('active');
        $csrfToken = $this->e($this->csrfService->token());
        $canManage = $this->authService->hasPermission('personnel.manage');
        $rows = '';

        foreach ($events as $event) {
            $id = (int) ($event['id'] ?? 0);
            $statusClass = match ((string) ($event['status'] ?? 'ok')) {
                'overdue' => 'error',
                'due_soon' => 'warn',
                'completed' => 'ok',
                default => 'ok',
            };
            $actions = $canManage
                ? '<a class="button button-secondary" href="/admin/personnel/events?edit=' . $id . '">Bearbeiten</a>'
                    . '<form method="post" action="/admin/personnel/events/' . $id . '/archive" class="inline-form"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><button class="button button-danger" type="submit">Archivieren</button></form>'
                : '<span class="muted">Nur Ansicht</span>';
            $rows .= '<tr>'
                . '<td><strong>' . $this->e((string) ($event['display_title'] ?? '')) . '</strong><br><span class="muted">' . $this->e((string) ($event['event_type_name'] ?? '')) . '</span></td>'
                . '<td>' . $this->e((string) ($event['user_name'] ?? '')) . '</td>'
                . '<td data-sort-value="' . $this->e((string) ($event['due_on'] ?? '')) . '">' . $this->e((string) ($event['due_on'] ?? '')) . '</td>'
                . '<td><span class="badge ' . $statusClass . '">' . $this->e((string) ($event['status_label'] ?? '')) . '</span></td>'
                . '<td>' . $this->e(implode(', ', $event['reminder_channels_list'] ?? [])) . '</td>'
                . '<td class="table-actions">' . $actions . '</td>'
                . '</tr>';
        }

        $editEvent = $this->eventService->findEvent((int) $request->query('edit', 0));
        $eventForm = $canManage ? $this->eventForm($editEvent, $types, $users, $csrfToken) : '';
        $content = $this->header('Mitarbeiter-Events', 'Faelligkeiten, Gueltigkeiten und Erinnerungen.')
            . $this->tabs('events')
            . $this->notice($request)
            . $eventForm
            . $this->eventFilters($filters, $types, $users)
            . '<section class="card"><div class="table-scroll"><table data-admin-table="personnel-events" data-table-label="Events"><thead><tr><th>Event</th><th>Mitarbeiter</th><th data-sort-type="text">Faelligkeit</th><th>Status</th><th>Kanaele</th><th data-search="false" data-sort="false">Aktionen</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="6" class="table-empty">Keine Events im aktuellen Filter.</td></tr>')
            . '</tbody></table></div></section>';

        return Response::html($this->view->render('Personal Events', $content));
    }

    public function createEvent(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/events?error=csrf');
        }

        try {
            $this->eventService->createEvent($request->input());
            return Response::redirect('/admin/personnel/events?notice=saved');
        } catch (InvalidArgumentException|RuntimeException) {
            return Response::redirect('/admin/personnel/events?error=validation');
        }
    }

    public function updateEvent(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/events?error=csrf');
        }

        try {
            $payload = $request->input();
            $payload['completed_by_user_id'] = $this->currentUserId();
            $this->eventService->updateEvent((int) ($params['id'] ?? 0), $payload);
            return Response::redirect('/admin/personnel/events?notice=saved');
        } catch (InvalidArgumentException|RuntimeException) {
            return Response::redirect('/admin/personnel/events?error=validation');
        }
    }

    public function archiveEvent(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect('/admin/personnel/events?error=csrf');
        }

        $this->eventService->archiveEvent((int) ($params['id'] ?? 0), $this->currentUserId());

        return Response::redirect('/admin/personnel/events?notice=archived');
    }

    public function charts(Request $request): Response
    {
        return Response::json([
            'ok' => true,
            'data' => [
                'labels' => $this->labelService->statistics(),
                'events' => $this->eventService->overview()['status_chart'] ?? [],
            ],
        ]);
    }

    private function eventForm(?array $event, array $types, array $users, string $csrfToken): string
    {
        $isEdit = $event !== null;
        $action = $isEdit ? '/admin/personnel/events/' . (int) ($event['id'] ?? 0) : '/admin/personnel/events';
        $title = $isEdit ? 'Event bearbeiten' : 'Event anlegen';
        $completedChecked = $isEdit && (string) ($event['status'] ?? '') === 'completed' ? 'checked' : '';
        $channels = array_column($event['reminders'] ?? [], 'channel');
        $channels = $channels === [] ? ['admin', 'push', 'email'] : array_values(array_unique(array_map('strval', $channels)));

        return <<<HTML
<section class="card stack">
    <h2>{$this->e($title)}</h2>
    <form method="post" action="{$this->e($action)}" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label><span>Mitarbeiter</span><select name="user_id" required>{$this->userOptions($users, (int) ($event['user_id'] ?? 0))}</select></label>
        <label><span>Event-Typ</span><select name="event_type_id" required>{$this->typeOptions($types, (int) ($event['event_type_id'] ?? 0))}</select></label>
        <label><span>Titel optional</span><input name="title" value="{$this->field($event, 'title')}"></label>
        <label><span>Faelligkeit / Termin</span><input type="date" name="due_on" value="{$this->field($event, 'due_on')}" required></label>
        <label><span>Gueltig ab</span><input type="date" name="valid_from" value="{$this->field($event, 'valid_from')}"></label>
        <label><span>Gueltig bis</span><input type="date" name="valid_until" value="{$this->field($event, 'valid_until')}"></label>
        <label><span>Reminder Tage vorher</span><input type="number" name="reminder_days" min="0" value="{$this->reminderDays($event)}" placeholder="Standard des Event-Typs"></label>
        <div class="field-group full-span">
            <span>Reminder-Kanaele</span>
            <div class="checkbox-grid">
                {$this->channelCheckbox('admin', 'Admin-Anzeige', $channels)}
                {$this->channelCheckbox('push', 'Push an Mitarbeiter', $channels)}
                {$this->channelCheckbox('email', 'E-Mail an Mitarbeiter', $channels)}
            </div>
        </div>
        <label class="checkbox-item full-span"><input type="hidden" name="completed" value="0"><input type="checkbox" name="completed" value="1" {$completedChecked}> <span>Event erledigt</span></label>
        <label class="full-span"><span>Notiz</span><textarea name="note" rows="3">{$this->field($event, 'note')}</textarea></label>
        <button class="button" type="submit">Event speichern</button>
    </form>
</section>
HTML;
    }

    private function eventFilters(array $filters, array $types, array $users): string
    {
        return '<section class="card stack"><h2>Filter</h2><form method="get" action="/admin/personnel/events" class="form-grid">'
            . '<label><span>Status</span>' . $this->select('status', ['all' => 'Alle', 'ok' => 'OK', 'due_soon' => 'Bald faellig', 'overdue' => 'Ueberfaellig', 'completed' => 'Erledigt'], (string) ($filters['status'] ?? 'all')) . '</label>'
            . '<label><span>Typ</span><select name="event_type_id"><option value="">Alle</option>' . $this->typeOptions($types, (int) ($filters['event_type_id'] ?? 0)) . '</select></label>'
            . '<label><span>Mitarbeiter</span><select name="user_id"><option value="">Alle</option>' . $this->userOptions($users, (int) ($filters['user_id'] ?? 0), false) . '</select></label>'
            . '<label><span>Von</span><input type="date" name="date_from" value="' . $this->e((string) ($filters['date_from'] ?? '')) . '"></label>'
            . '<label><span>Bis</span><input type="date" name="date_to" value="' . $this->e((string) ($filters['date_to'] ?? '')) . '"></label>'
            . '<label><span>Archiv</span>' . $this->select('scope', ['active' => 'Aktiv', 'archived' => 'Archiviert', 'all' => 'Alle'], (string) ($filters['scope'] ?? 'active')) . '</label>'
            . '<button class="button" type="submit">Filtern</button>'
            . '</form></section>';
    }

    private function eventListCard(string $title, array $events, string $empty): string
    {
        if ($events === []) {
            return '<article class="card stack"><h2>' . $this->e($title) . '</h2><p class="muted">' . $this->e($empty) . '</p></article>';
        }

        $items = '';

        foreach ($events as $event) {
            $items .= '<li><strong>' . $this->e((string) ($event['display_title'] ?? '')) . '</strong><span>' . $this->e((string) ($event['user_name'] ?? '') . ' · ' . (string) ($event['due_on'] ?? '') . ' · ' . (string) ($event['status_label'] ?? '')) . '</span></li>';
        }

        return '<article class="card stack"><h2>' . $this->e($title) . '</h2><ul class="personnel-event-list">' . $items . '</ul></article>';
    }

    private function metricGrid(array $metrics): string
    {
        $html = '<section class="metrics-grid">';

        foreach ($metrics as $label => $value) {
            $html .= '<article class="card metric"><h3>' . $this->e((string) $label) . '</h3><p>' . $this->e((string) $value) . '</p></article>';
        }

        return $html . '</section>';
    }

    private function tabs(string $active): string
    {
        $tabs = [
            'overview' => ['/admin/personnel', 'Uebersicht'],
            'events' => ['/admin/personnel/events', 'Events'],
            'event-types' => ['/admin/personnel/event-types', 'Event-Typen'],
            'labels' => ['/admin/personnel/labels', 'Labels'],
        ];
        $html = '<section class="section-toolbar"><div class="scope-switch">';

        foreach ($tabs as $key => [$href, $label]) {
            $html .= '<a class="scope-link' . ($key === $active ? ' is-active' : '') . '" href="' . $this->e($href) . '">' . $this->e($label) . '</a>';
        }

        return $html . '</div></section>';
    }

    private function header(string $title, string $copy): string
    {
        return '<header class="page-header"><div><p class="eyebrow">Personal</p><h1>' . $this->e($title) . '</h1><p>' . $this->e($copy) . '</p></div></header>';
    }

    private function userOptions(array $users, int $selected, bool $includePlaceholder = true): string
    {
        $html = $includePlaceholder ? '<option value="">Bitte waehlen</option>' : '';

        foreach ($users as $user) {
            $id = (int) ($user['id'] ?? 0);
            $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $number = trim((string) ($user['employee_number'] ?? ''));
            $label = trim($name . ($number !== '' ? ' (' . $number . ')' : ''));
            $html .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>' . $this->e($label !== '' ? $label : (string) ($user['email'] ?? '')) . '</option>';
        }

        return $html;
    }

    private function typeOptions(array $types, int $selected): string
    {
        $html = '';

        foreach ($types as $type) {
            $id = (int) ($type['id'] ?? 0);
            $html .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>' . $this->e((string) ($type['name'] ?? '')) . '</option>';
        }

        return $html;
    }

    private function channelCheckbox(string $value, string $label, array $selected): string
    {
        $checked = in_array($value, $selected, true) ? 'checked' : '';

        return '<label class="checkbox-item"><input type="checkbox" name="reminder_channels[]" value="' . $this->e($value) . '" ' . $checked . '> <span>' . $this->e($label) . '</span></label>';
    }

    private function reminderDays(?array $event): string
    {
        $reminders = $event['reminders'] ?? [];

        if (isset($reminders[0]['days_before'])) {
            return $this->e((string) $reminders[0]['days_before']);
        }

        return '';
    }

    private function cancelEditLink(?array $row, string $href): string
    {
        if ($row === null) {
            return '';
        }

        return '<a class="button button-secondary" href="' . $this->e($href) . '">Abbrechen</a>';
    }

    private function notice(Request $request): string
    {
        $error = (string) $request->query('error', '');
        $notice = (string) $request->query('notice', '');

        if ($error !== '') {
            $message = match ($error) {
                'csrf' => 'Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.',
                'validation' => 'Die Eingaben konnten nicht gespeichert werden. Bitte Pflichtfelder pruefen.',
                default => 'Beim Vorgang ist ein Fehler aufgetreten.',
            };

            return '<p class="notice error">' . $this->e($message) . '</p>';
        }

        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'saved' => 'Die Personal-Daten wurden gespeichert.',
            'archived' => 'Der Eintrag wurde archiviert.',
            default => 'Vorgang erfolgreich ausgefuehrt.',
        };

        return '<p class="notice success">' . $this->e($message) . '</p>';
    }

    private function scope(Request $request): string
    {
        return match ((string) $request->query('scope', 'active')) {
            'archived' => 'archived',
            'all' => 'all',
            default => 'active',
        };
    }

    private function scopeLink(string $baseUrl, string $value, string $label, string $current): string
    {
        $class = 'scope-link' . ($value === $current ? ' is-active' : '');

        return '<a class="' . $class . '" href="' . $this->e($baseUrl . '?scope=' . $value) . '">' . $this->e($label) . '</a>';
    }

    private function select(string $name, array $options, string $selected): string
    {
        $html = '<select name="' . $this->e($name) . '">';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . $this->e((string) $value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . $this->e((string) $label) . '</option>';
        }

        return $html . '</select>';
    }

    private function field(?array $row, string $key): string
    {
        return $this->e((string) ($row[$key] ?? ''));
    }

    private function dataJson(array $data): string
    {
        return $this->e((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }

    private function validCsrf(Request $request): bool
    {
        return $this->csrfService->isValid((string) $request->input('csrf_token', ''));
    }

    private function currentUserId(): ?int
    {
        $userId = $this->authService->currentUser()['id'] ?? null;

        return is_numeric($userId) ? (int) $userId : null;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
