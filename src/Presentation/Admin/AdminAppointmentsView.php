<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use DateTimeImmutable;

final class AdminAppointmentsView
{
    private const ROOT = '/admin/appointments';
    private const STATUSES = ['planned' => 'Geplant', 'completed' => 'Erledigt', 'cancelled' => 'Abgesagt'];
    private const PERIODS = [
        'upcoming' => 'Kommende Termine', 'today' => 'Heute', 'week' => 'Nächste 7 Tage',
        'month' => 'Nächste 30 Tage', 'custom' => 'Benutzerdefinierter Zeitraum',
    ];

    public function __construct(private readonly AdminView $layout)
    {
    }

    public function index(array $result, array $filters, array $options, bool $canManage, bool $canViewCalendar, array $state): string
    {
        $calendar = $canViewCalendar ? $this->button('/admin/calendar?view=appointments', 'Zum Kalender') : '';
        $create = $canManage ? $this->button(self::ROOT . '/new', 'Termin anlegen', 'button') : '';
        $headings = ['Zeitraum', 'Termin', 'Projekt', 'Mitarbeiter', 'Einsatzort', 'Status', 'Aktion'];
        $rows = '';
        foreach ($result['items'] as $appointment) {
            $path = self::ROOT . '/' . (int) $appointment['id'];
            $action = $canManage && empty($appointment['archived_at']) ? $this->button($path . '/edit', 'Bearbeiten') : '';
            $rows .= $this->row($headings, [
                $this->dateRange($appointment),
                '<a href="' . $path . '">' . $this->e($appointment['title']) . '</a>',
                $this->project($appointment), $this->participants($appointment),
                $this->e($appointment['location'] ?? '—'), $this->status($appointment), $action,
            ]);
        }
        $table = '<p class="table-empty">Für diese Auswahl wurden keine Termine gefunden.</p>';
        if ($rows !== '') {
            $headers = '';
            foreach ($headings as $heading) {
                $headers .= '<th scope="col" role="columnheader">' . $this->e($heading) . '</th>';
            }
            $table = '<table class="appointments-table" role="table"><thead role="rowgroup"><tr role="row">' . $headers
                . '</tr></thead><tbody role="rowgroup">' . $rows . '</tbody></table>';
        }
        $pager = $this->pagination($result, $filters);

        return $this->screen('Termine', $calendar . $create, $state,
            $this->filters($filters, $options) . '<section class="card stack" aria-label="Terminliste">' . $table . $pager . '</section>',
            '<script src="/assets/js/admin-appointments.js" defer></script>');
    }

    public function show(array $appointment, bool $canManage, bool $canViewCalendar, array $state): string
    {
        $path = self::ROOT . '/' . (int) $appointment['id'];
        $actions = $this->button(self::ROOT, 'Zur Terminliste');
        if ($canViewCalendar) {
            $date = substr((string) $appointment['starts_at'], 0, 10);
            $actions .= $this->button('/admin/calendar?view=appointments&date=' . rawurlencode($date), 'Im Kalender anzeigen');
        }
        if ($canManage && empty($appointment['archived_at'])) {
            $actions .= $this->button($path . '/edit', 'Bearbeiten', 'button');
        }
        $facts = '';
        foreach ([
            'Zeitraum' => $this->dateRange($appointment), 'Projekt' => $this->project($appointment),
            'Mitarbeiter' => $this->participants($appointment), 'Einsatzort' => $this->e($appointment['location'] ?? '—'),
            'Status' => $this->status($appointment),
        ] as $label => $value) {
            $facts .= '<div><dt>' . $label . '</dt><dd>' . $value . '</dd></div>';
        }
        $description = (string) ($appointment['description'] ?? '');
        $details = '<section class="card stack" aria-label="Termindetails"><dl class="appointment-facts">' . $facts . '</dl>'
            . ($description === '' ? '' : '<div class="appointment-description"><h2>Beschreibung</h2><p>' . nl2br($this->e($description), false) . '</p></div>')
            . '</section>';

        return $this->screen((string) $appointment['title'], $actions, $state, $details);
    }

    public function form(?array $appointment, array $values, array $options, string $csrf, array $state): string
    {
        $path = $appointment === null ? self::ROOT : self::ROOT . '/' . (int) $appointment['id'];
        $heading = $appointment === null ? 'Termin anlegen' : 'Termin bearbeiten';
        $buttonLabel = $appointment === null ? 'Termin speichern' : 'Änderungen speichern';
        $csrfField = $this->hidden('csrf_token', $csrf);
        $version = $appointment === null ? '' : $this->hidden('version', $values['version'] ?? '');
        $participantInput = $this->participantInputs($options['participants'], $values['participant_ids'] ?? []);
        $projectInput = $this->select('project_id', 'Projekt', ['' => 'Ohne Projekt'] + $this->projectOptions($options['projects']), $values['project_id'] ?? '');
        $statuses = $appointment !== null && $appointment['status'] !== 'planned'
            ? array_intersect_key(self::STATUSES, [$appointment['status'] => true]) : self::STATUSES;
        $statusInput = $this->select('status', 'Status', $statuses, $values['status'] ?? 'planned');
        $timezone = $this->e(date_default_timezone_get());
        $conflict = !empty($state['conflict']);
        $actions = $conflict
            ? $this->button($path . '/edit', 'Aktuellen Stand öffnen', 'button')
            : '<button type="submit" class="button">' . $buttonLabel . '</button>';
        $actions .= $this->button($path, 'Abbrechen');

        $content = <<<HTML
<section class="card stack" aria-label="Termindaten">
    <form method="post" action="{$path}" class="form-grid" data-appointment-form>
        {$csrfField}{$version}
        <label class="full-span"><span>Terminbezeichnung</span><input name="title" value="{$this->value($values, 'title')}" maxlength="180" required></label>
        <label><span>Beginn</span><input name="starts_at" type="datetime-local" value="{$this->value($values, 'starts_at')}" aria-describedby="appointment-timezone" required></label>
        <label><span>Ende</span><input name="ends_at" type="datetime-local" value="{$this->value($values, 'ends_at')}" aria-describedby="appointment-timezone" required></label>
        <p class="muted full-span appointment-form-hint" id="appointment-timezone">Zeitzone {$timezone}</p>
        {$projectInput}
        <label><span>Einsatzort</span><input name="location" value="{$this->value($values, 'location')}" maxlength="255"></label>
        {$statusInput}
        <label class="full-span"><span>Beschreibung</span><textarea name="description" rows="4" maxlength="4000">{$this->value($values, 'description')}</textarea></label>
        {$participantInput}
        <div class="toolbar-actions full-span">{$actions}</div>
    </form>
</section>
HTML;
        if ($appointment !== null && !$conflict) {
            $content .= '<section class="card stack" aria-label="Archivierung"><form method="post" action="' . $path . '/archive" class="inline-form">'
                . $csrfField . $this->hidden('version', $appointment['version'])
                . '<button class="button button-danger" type="submit">Termin archivieren</button></form></section>';
        }

        return $this->screen($heading, $this->button(self::ROOT, 'Zur Terminliste'), $state, $content,
            '<script src="/assets/js/admin-appointments.js" defer></script>');
    }

    public function error(string $message): string
    {
        return $this->screen('Termine', $this->button(self::ROOT, 'Zur Terminliste'), ['error' => $message], '');
    }

    private function filters(array $filters, array $options): string
    {
        $period = $this->select('period', 'Zeitraum', self::PERIODS, $filters['period'] ?? 'upcoming');
        $users = ['' => 'Alle Mitarbeiter'];
        foreach ($options['participants'] as $user) {
            $users[(int) $user['id']] = (string) $user['name'] . (!empty($user['archived']) ? ' (archiviert)' : '');
        }
        $user = $this->select('user_id', 'Mitarbeiter', $users, $filters['user_id'] ?? '');
        $project = $this->select('project_id', 'Projekt', ['' => 'Alle Projekte'] + $this->projectOptions($options['projects']), $filters['project_id'] ?? '');
        $status = $this->select('status', 'Status', ['' => 'Alle Status'] + self::STATUSES, $filters['status'] ?? '');

        return <<<HTML
<section class="card stack" aria-label="Terminfilter">
    <form method="get" action="/admin/appointments" class="form-grid" data-appointment-filters>
        {$period}
        <label><span>Von</span><input type="date" name="from" value="{$this->value($filters, 'from')}"></label>
        <label><span>Bis</span><input type="date" name="to" value="{$this->value($filters, 'to')}"></label>
        {$user}{$project}{$status}
        <label><span>Suchtext</span><input type="search" name="q" value="{$this->value($filters, 'q')}" maxlength="160"></label>
        <p class="muted full-span appointment-form-hint">Für eigene Datumsgrenzen „Benutzerdefinierter Zeitraum“ auswählen.</p>
        <div class="toolbar-actions full-span"><button class="button" type="submit">Filtern</button>{$this->button(self::ROOT, 'Filter zurücksetzen')}</div>
    </form>
</section>
HTML;
    }

    private function participantInputs(array $participants, mixed $selected): string
    {
        $selected = is_array($selected) ? array_map('strval', array_filter($selected, 'is_scalar')) : [];
        $checks = '';
        foreach ($participants as $participant) {
            $id = (int) $participant['id'];
            $name = $this->e($participant['name']);
            $checked = in_array((string) $id, $selected, true) ? ' checked' : '';
            $archived = !empty($participant['archived']) ? ' <small class="muted">(archiviert)</small>' : '';
            $checks .= '<label class="checkbox-item" data-appointment-participant><input type="checkbox" name="participant_ids[]" value="' . $id
                . '"' . $checked . '><span>' . $name . $archived . '</span></label>';
        }
        $content = $checks === '' ? '<p class="muted">Keine Mitarbeiter verfügbar.</p>' : '<div class="checkbox-grid appointment-participant-list">' . $checks . '</div>';
        $search = $checks === '' ? '' : '<label data-appointment-participant-search hidden><span>Mitarbeiter suchen</span><input type="search" autocomplete="off"></label>';

        return '<fieldset class="field-group full-span appointment-participants"><legend>Mitarbeiter</legend>'
            . $this->hidden('participants_submitted', '1') . $search . $content . '</fieldset>';
    }

    private function projectOptions(array $projects): array
    {
        $options = [];
        foreach ($projects as $project) {
            $label = trim((string) ($project['project_number'] ?? '') . ' ' . (string) ($project['name'] ?? ''));
            $options[(int) $project['id']] = $label . (!empty($project['archived']) ? ' (archiviert)' : '');
        }

        return $options;
    }

    private function select(string $name, string $label, array $options, mixed $value): string
    {
        $current = is_scalar($value) ? (string) $value : '';
        $items = '';
        foreach ($options as $key => $option) {
            $selected = (string) $key === $current ? ' selected' : '';
            $items .= '<option value="' . $this->e($key) . '"' . $selected . '>' . $this->e($option) . '</option>';
        }

        return '<label><span>' . $this->e($label) . '</span><select name="' . $name . '">' . $items . '</select></label>';
    }

    private function pagination(array $result, array $filters): string
    {
        $page = max(1, (int) $result['page']);
        $pages = max(1, (int) ceil((int) $result['total'] / max(1, (int) $result['per_page'])));
        if ($pages === 1) {
            return '';
        }
        $filters = array_filter($filters, static fn (mixed $value): bool => is_scalar($value) && $value !== '');
        $links = $page > 1 ? $this->button($this->pageUrl($filters, $page - 1), 'Vorherige Seite') : '';
        $links .= '<span class="muted">Seite ' . $page . ' von ' . $pages . '</span>';
        $links .= $page < $pages ? $this->button($this->pageUrl($filters, $page + 1), 'Nächste Seite') : '';

        return '<nav class="toolbar-actions" aria-label="Seitennavigation">' . $links . '</nav>';
    }

    private function pageUrl(array $filters, int $page): string
    {
        $filters['page'] = $page;

        return self::ROOT . '?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986);
    }

    private function row(array $headings, array $values): string
    {
        $cells = '';
        foreach ($values as $index => $value) {
            $cells .= '<td role="cell"><span class="appointment-cell-label" aria-hidden="true">' . $headings[$index] . '</span><div>' . $value . '</div></td>';
        }

        return '<tr role="row">' . $cells . '</tr>';
    }

    private function dateRange(array $appointment): string
    {
        $start = (string) ($appointment['starts_at'] ?? '');
        $end = (string) ($appointment['ends_at'] ?? '');
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $start);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $end);
        if ($startDate === false || $endDate === false) {
            return '—';
        }
        $endFormat = substr($start, 0, 10) === substr($end, 0, 10) ? 'H:i' : 'd.m.Y H:i';

        return '<time datetime="' . $this->e($start) . '">' . $startDate->format('d.m.Y H:i') . '</time> – <time datetime="'
            . $this->e($end) . '">' . $endDate->format($endFormat) . '</time>';
    }

    private function project(array $appointment): string
    {
        $text = trim((string) ($appointment['project_number'] ?? '') . ' ' . (string) ($appointment['project_name'] ?? ''));

        return $text === '' ? '—' : $this->e($text);
    }

    private function participants(array $appointment): string
    {
        $names = array_column($appointment['participants'] ?? [], 'name');

        return $names === [] ? '—' : $this->e(implode(', ', $names));
    }

    private function status(array $appointment): string
    {
        $status = $appointment['status'];
        $class = match ($status) { 'completed' => 'ok', 'cancelled' => 'warn', default => 'neutral' };
        $archived = empty($appointment['archived_at']) ? '' : ' <span class="badge neutral">Archiviert</span>';

        return '<span class="badge ' . $class . '">' . $this->e(self::STATUSES[$status] ?? 'Unbekannt') . '</span>' . $archived;
    }

    private function screen(string $heading, string $actions, array $state, string $content, ?string $script = null): string
    {
        $notice = '';
        if (!empty($state['error'])) {
            $notice = '<p class="notice error" role="alert">' . $this->e($state['error']) . '</p>';
        } elseif (!empty($state['notice'])) {
            $notice = '<p class="notice success" role="status">' . $this->e($state['notice']) . '</p>';
        }
        $title = $this->e($heading);
        $toolbar = $actions === '' ? '' : '<div class="toolbar-actions">' . $actions . '</div>';

        return $this->layout->render($heading, '<link rel="stylesheet" href="/assets/css/admin-appointments.css">'
            . '<div class="stack appointments-page"><header class="page-header"><h1>' . $title . '</h1>' . $toolbar
            . '</header>' . $notice . $content . '</div>', $script);
    }

    private function button(string $path, string $label, string $class = 'button button-secondary'): string
    {
        return '<a class="' . $class . '" href="' . $this->e($path) . '">' . $this->e($label) . '</a>';
    }

    private function hidden(string $name, mixed $value): string
    {
        return '<input type="hidden" name="' . $name . '" value="' . $this->e($value) . '">';
    }

    private function value(array $values, string $key): string
    {
        return $this->e($values[$key] ?? '');
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
