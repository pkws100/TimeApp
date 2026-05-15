<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use App\Presentation\Admin\BookingModalRenderer;
use DateTimeImmutable;

final class AdminCalendarController
{
    public function __construct(
        private AdminView $view,
        private AdminCalendarService $calendarService,
        private AdminBookingService $bookingService,
        private ProjectService $projectService,
        private UserService $userService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function index(Request $request): Response
    {
        $selectedDate = $this->selectedDate($request);
        $month = $this->calendarService->month((string) $request->query('month', substr($selectedDate, 0, 7)));
        $day = $this->calendarService->day($selectedDate);
        $projects = $this->projectService->list('all');
        $users = $this->userService->list('active');
        $returnTo = $this->calendarReturnTo($selectedDate);
        $renderer = new BookingModalRenderer();
        $csrfToken = $this->csrfService->token();
        $canManage = $this->authService->hasPermission('timesheets.manage');
        $canArchive = $this->authService->hasPermission('timesheets.archive');
        $content = $this->renderPage(
            $month,
            $day,
            $projects,
            $users,
            $this->notice($request),
            $returnTo,
            $csrfToken,
            $canManage,
            $canArchive
        );
        $content .= $renderer->renderModal(
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

        return Response::html($this->view->render('Kalender', $content, '<script src="/assets/js/admin-calendar.js"></script>'));
    }

    public function month(Request $request): Response
    {
        return Response::json([
            'data' => $this->calendarService->month((string) $request->query('month', '')),
        ]);
    }

    public function day(Request $request): Response
    {
        $day = $this->calendarService->day((string) $request->query('date', ''));
        $date = (string) $day['date'];
        $projects = $this->projectService->list('all');
        $users = $this->userService->list('active');
        $returnTo = $this->calendarReturnTo($date);

        return Response::json([
            'data' => [
                'date' => $date,
                'label' => (string) $day['label'],
                'summary' => $day['summary'],
                'html' => $this->renderDayPanel(
                    $day,
                    $projects,
                    $users,
                    $returnTo,
                    $this->csrfService->token(),
                    $this->authService->hasPermission('timesheets.manage'),
                    $this->authService->hasPermission('timesheets.archive')
                ),
            ],
        ]);
    }

    private function renderPage(array $month, array $day, array $projects, array $users, string $notice, string $returnTo, string $csrfToken, bool $canManage, bool $canArchive): string
    {
        $monthJson = $this->dataJson($month);
        $selectedDate = $this->e((string) ($day['date'] ?? (new DateTimeImmutable())->format('Y-m-d')));
        $dayPanel = $this->renderDayPanel($day, $projects, $users, $returnTo, $csrfToken, $canManage, $canArchive);

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Zeitbuchungen</p>
        <h1>Kalender</h1>
        <p>Monatliche Sicht auf Arbeitszeiten, Abwesenheiten, offene Buchungen und zugeordnete Fahrzeuge.</p>
    </div>
</header>
{$notice}
<section class="calendar-shell" data-admin-calendar data-month="{$this->e((string) $month['month'])}" data-selected-date="{$selectedDate}" data-month-payload="{$monthJson}">
    <div class="calendar-main">
        <div class="calendar-toolbar">
            <button type="button" class="button button-secondary calendar-nav-button" data-calendar-prev aria-label="Vorherigen Monat anzeigen">&lt;</button>
            <div>
                <p class="eyebrow">Monatsuebersicht</p>
                <h2 data-calendar-title>{$this->e((string) $month['label'])}</h2>
            </div>
            <button type="button" class="button button-secondary calendar-nav-button" data-calendar-next aria-label="Naechsten Monat anzeigen">&gt;</button>
        </div>
        <div class="calendar-legend" aria-label="Kalenderstatus">
            <span><i class="calendar-dot is-ok"></i>Sauber</span>
            <span><i class="calendar-dot is-empty"></i>Keine Buchung</span>
            <span><i class="calendar-dot is-issue"></i>Pruefen</span>
        </div>
        <div class="calendar-weekdays" aria-hidden="true">
            <span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span><span>Sa</span><span>So</span>
        </div>
        <div class="calendar-grid" data-calendar-grid>
            {$this->renderCalendarGrid($month, $selectedDate)}
        </div>
    </div>
    <aside class="calendar-detail" data-calendar-day-panel>
        {$dayPanel}
    </aside>
</section>
HTML;
    }

    private function renderCalendarGrid(array $month, string $selectedDate): string
    {
        $html = '';

        foreach (($month['days'] ?? []) as $day) {
            $date = (string) ($day['date'] ?? '');
            $classes = [
                'calendar-day',
                'is-' . (string) ($day['status'] ?? 'empty'),
                (bool) ($day['is_current_month'] ?? false) ? 'is-current-month' : 'is-outside-month',
                $date === $selectedDate ? 'is-selected' : '',
                $date === (string) ($month['today'] ?? '') ? 'is-today' : '',
            ];
            $meta = [];

            if ((int) ($day['active_booking_count'] ?? 0) > 0) {
                $meta[] = (int) $day['active_booking_count'] . ' Buch.';
            }

            if ((int) ($day['net_minutes'] ?? 0) > 0) {
                $meta[] = $this->formatMinutes((int) $day['net_minutes']);
            }

            if ((int) ($day['issue_count'] ?? 0) > 0) {
                $meta[] = (int) $day['issue_count'] . ' offen';
            }

            $html .= '<button type="button" class="' . trim(implode(' ', array_filter($classes))) . '" data-calendar-date="' . $this->e($date) . '" aria-pressed="' . ($date === $selectedDate ? 'true' : 'false') . '">'
                . '<span class="calendar-day__number">' . $this->e((string) ($day['day_number'] ?? '')) . '</span>'
                . '<span class="calendar-day__status">' . $this->e((string) ($day['status_label'] ?? '')) . '</span>'
                . '<span class="calendar-day__meta">' . $this->e(implode(' · ', $meta)) . '</span>'
                . '</button>';
        }

        return $html;
    }

    private function renderDayPanel(array $day, array $projects, array $users, string $returnTo, string $csrfToken, bool $canManage, bool $canArchive): string
    {
        $renderer = new BookingModalRenderer();
        $summary = is_array($day['summary'] ?? null) ? $day['summary'] : [];
        $bookings = is_array($day['bookings'] ?? null) ? $day['bookings'] : [];
        $assets = is_array($day['assets'] ?? null) ? $day['assets'] : [];
        $date = (string) ($day['date'] ?? '');
        $table = $renderer->renderTable(
            $bookings,
            $projects,
            $this->bookingService->entryTypeOptions(),
            [
                'show_selection' => false,
                'empty_message' => 'An diesem Tag sind keine Buchungen vorhanden.',
                'can_manage' => $canManage,
                'can_archive' => $canArchive,
                'open_booking_location' => $returnTo,
            ]
        );
        $createForm = $canManage ? $this->renderManualBookingForm($date, $projects, $users, $returnTo, $csrfToken) : '';

        return <<<HTML
<div class="calendar-detail__header">
    <div>
        <p class="eyebrow">Tagesdetail</p>
        <h2>{$this->e((string) ($day['label'] ?? $date))}</h2>
    </div>
    <span class="badge calendar-status-badge is-{$this->e((string) ($summary['status'] ?? 'empty'))}">{$this->e((string) ($summary['status_label'] ?? 'Keine Buchung'))}</span>
</div>
<div class="calendar-stats">
    <div><span>Buchungen</span><strong>{$this->e((string) ($summary['active_booking_count'] ?? 0))}</strong></div>
    <div><span>Mitarbeiter</span><strong>{$this->e((string) ($summary['employee_count'] ?? 0))}</strong></div>
    <div><span>Stunden</span><strong>{$this->e($this->formatMinutes((int) ($summary['net_minutes'] ?? 0)))}</strong></div>
</div>
{$this->renderAssetList($assets)}
{$createForm}
<section class="card stack calendar-bookings-card">
    <div>
        <h3>Buchungen am Tag</h3>
        <p class="muted">Eintrag antippen, um Details zu bearbeiten oder zu archivieren.</p>
    </div>
    {$table}
</section>
HTML;
    }

    private function renderManualBookingForm(string $date, array $projects, array $users, string $returnTo, string $csrfToken): string
    {
        return <<<HTML
<section class="calendar-create-panel">
    <div>
        <h3>Buchung hinzufuegen</h3>
        <p class="muted">Nacherfasste Buchungen werden als Admin-Nacherfassung gespeichert.</p>
    </div>
    <form method="post" action="/admin/bookings" class="form-grid">
        <input type="hidden" name="return_to" value="{$this->e($returnTo)}">
        <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
        <label><span>Mitarbeiter</span><select name="user_id" required>{$this->userOptions($users)}</select></label>
        <label><span>Datum</span><input type="date" name="work_date" value="{$this->e($date)}" required></label>
        <label><span>Projekt</span><select name="project_id">{$this->projectOptions($projects)}</select></label>
        <label><span>Typ</span><select name="entry_type">{$this->entryTypeOptions()}</select></label>
        <label><span>Start</span><input type="time" name="start_time"></label>
        <label><span>Ende</span><input type="time" name="end_time"></label>
        <label><span>Pause in Minuten</span><input type="number" name="break_minutes" min="0" step="1" value="0"></label>
        <label class="full-span"><span>Notiz</span><textarea name="note" rows="3"></textarea></label>
        <label class="full-span"><span>Begruendung</span><textarea name="change_reason" rows="3" required placeholder="Warum wird diese Buchung nacherfasst?"></textarea></label>
        <button class="button" type="submit">Buchung hinzufuegen</button>
    </form>
</section>
HTML;
    }

    private function renderAssetList(array $assets): string
    {
        if ($assets === []) {
            return '<section class="calendar-assets"><h3>Fahrzeuge und Geraete</h3><p class="muted">Keine passende Zuweisung fuer diesen Tag gefunden.</p></section>';
        }

        $items = '';

        foreach ($assets as $asset) {
            $type = (string) ($asset['asset_type'] ?? '') === 'vehicle' ? 'Fahrzeug' : 'Geraet';
            $items .= '<li><strong>' . $this->e((string) ($asset['name'] ?? '')) . '</strong><span>' . $this->e($type . ' · ' . (string) ($asset['identifier'] ?? '')) . '</span></li>';
        }

        return '<section class="calendar-assets"><h3>Fahrzeuge und Geraete</h3><ul>' . $items . '</ul></section>';
    }

    private function selectedDate(Request $request): string
    {
        $date = $this->calendarService->normalizeDate((string) $request->query('date', ''))->format('Y-m-d');

        if ((string) $request->query('date', '') === '' && (string) $request->query('month', '') !== '') {
            return $this->calendarService->normalizeMonth((string) $request->query('month', ''))->format('Y-m-d');
        }

        return $date;
    }

    private function selectedBooking(Request $request): ?array
    {
        $bookingId = (int) $request->query('booking_id', 0);

        if ($bookingId <= 0 || (string) $request->query('modal', '') !== 'edit') {
            return null;
        }

        return $this->bookingService->find($bookingId);
    }

    private function calendarReturnTo(string $date): string
    {
        return '/admin/calendar?month=' . rawurlencode(substr($date, 0, 7)) . '&date=' . rawurlencode($date);
    }

    private function userOptions(array $users): string
    {
        $html = '<option value="">Bitte waehlen</option>';

        foreach ($users as $user) {
            if ((int) ($user['is_deleted'] ?? 0) === 1 || (string) ($user['employment_status'] ?? 'active') !== 'active') {
                continue;
            }

            $label = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $number = trim((string) ($user['employee_number'] ?? ''));
            $html .= '<option value="' . $this->e((string) ($user['id'] ?? '')) . '">' . $this->e($label . ($number !== '' ? ' (' . $number . ')' : '')) . '</option>';
        }

        return $html;
    }

    private function projectOptions(array $projects): string
    {
        $html = '<option value="__none__">Nicht zugeordnet</option>';

        foreach ($projects as $project) {
            $label = trim((string) ($project['project_number'] ?? '') . ' ' . (string) ($project['name'] ?? ''));

            if ((int) ($project['is_deleted'] ?? 0) === 1) {
                $label .= ' (archiviert)';
            }

            $html .= '<option value="' . $this->e((string) ($project['id'] ?? '')) . '">' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function entryTypeOptions(): string
    {
        $html = '';

        foreach ($this->bookingService->entryTypeOptions() as $value => $label) {
            $html .= '<option value="' . $this->e((string) $value) . '">' . $this->e((string) $label) . '</option>';
        }

        return $html;
    }

    private function notice(Request $request): string
    {
        $notice = (string) $request->query('notice', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            $message = match ($error) {
                'validation' => 'Die Buchung konnte nicht gespeichert werden. Bitte Datum, Zeiten und Begruendung pruefen.',
                'csrf' => 'Die Buchungsaktion konnte nicht bestaetigt werden. Bitte die Seite neu laden und erneut versuchen.',
                default => 'Beim Vorgang ist ein Fehler aufgetreten.',
            };

            return '<p class="notice error">' . $this->e($message) . '</p>';
        }

        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'created' => 'Buchung erfolgreich nacherfasst.',
            'updated' => 'Buchung erfolgreich gespeichert.',
            'archived' => 'Buchung erfolgreich archiviert.',
            'restored' => 'Buchung erfolgreich wiederhergestellt.',
            default => 'Vorgang erfolgreich ausgefuehrt.',
        };

        return '<p class="notice success">' . $this->e($message) . '</p>';
    }

    private function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0:00 h';
        }

        return sprintf('%d:%02d h', intdiv($minutes, 60), $minutes % 60);
    }

    private function dataJson(array $data): string
    {
        return $this->e((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
