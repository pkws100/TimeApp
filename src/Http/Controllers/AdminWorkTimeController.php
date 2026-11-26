<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Users\UserService;
use App\Domain\WorkTime\WorkTimeAccessService;
use App\Domain\WorkTime\WorkTimeDeviationService;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimeNotificationService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePauseCorrectionService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class AdminWorkTimeController
{
    public function __construct(
        private AdminView $view,
        private AuthService $auth,
        private CsrfService $csrf,
        private UserService $users,
        private WorkTimePolicyService $policies,
        private WorkTimePreviewService $preview,
        private WorkTimeDeviationService $deviations,
        private WorkTimeAccessService $access,
        private WorkTimeEventService $events,
        private ?WorkTimePauseCorrectionService $pauseCorrections = null,
        private ?AdminPausePolicyController $pausePolicies = null
    ) {
    }

    public function index(Request $request): Response
    {
        $pausePanel = $this->pausePolicies?->section($request) ?? '';
        $pauseScript = $pausePanel === '' ? '' : '<script src="/assets/js/admin-pause-models.js"></script>';
        if (!$this->auth->hasPermission('work_time_policies.view') && !$this->auth->hasPermission('work_time_deviations.view') && !$this->auth->hasPermission('work_time_notifications.manage')) {
            if ($pausePanel !== '') {
                return Response::html($this->view->render('Pausenmodelle', $pausePanel, $pauseScript));
            }
            return Response::html('Keine Berechtigung für diesen Bereich.', 403);
        }
        $actor = $this->auth->currentUser() ?? [];
        $templates = $this->auth->hasPermission('work_time_policies.view') ? $this->policies->listTemplates() : [];
        $assignments = array_values(array_filter($this->policies->listAssignments((int) $request->query('user_id', 0) ?: null, true), fn (array $a): bool => $this->access->canManageUser($actor, (int) $a['user_id'], (string) $a['effective_from'])));
        $users = array_values(array_filter($this->users->list('active'), fn (array $u): bool => $this->access->canManageUser($actor, (int) $u['id'])));
        $csrf = $this->e($this->csrf->token());
        $enabled = $this->policies->enabled() ? 'Global eingeschaltet; wirksam nur bei zugeordneten Mitarbeitern ab Gültigkeitsbeginn.' : 'Global ausgeschaltet. Die bisherige Zeiterfassung bleibt aktiv.';
        $notice = $request->query('error') ? '<div class="notice notice-error" role="alert">' . $this->e((string) $request->query('error')) . '</div>' : '';
        if ($request->query('saved')) {
            $notice .= '<div class="notice notice-success" role="status">Gespeichert. Historische Regeln und Stempelzeiten bleiben erhalten.</div>';
        }
        $content = '<header class="page-header"><div><p class="eyebrow">Optionale Mitarbeiterregeln</p><h1>Kernarbeitszeit</h1><p>' . $enabled . '</p></div></header>' . $notice
            . '<section class="card stack"><h2>Anwesenheit und Arbeit getrennt erfassen</h2><p>Das Fenster dient zur zeitlichen Orientierung. Die Anrechnung innerhalb und außerhalb des Fensters wird als Vorschau ausgewiesen.</p><p>„Nur anwesend“ setzt voraus, dass tatsächlich noch nicht gearbeitet wird. Bereits geleistete Arbeit muss als Arbeitsbeginn bestätigt werden.</p><p>Mit dem automatischen Check-out endet der laufende Einsatz zur Uhrzeit des Fensterendes. Für weitere Arbeit checkt der Mitarbeiter erneut auf das gewünschte Projekt ein. Der Hinweis wird über eine aktive Push-Anmeldung verschickt.</p></section>';
        if ($this->auth->hasPermission('work_time_notifications.manage')) {
            $content .= '<p><a class="button button-secondary" href="/admin/work-time/notifications">Versandübersicht und berechtigte Wiederholungen</a></p>';
        }
        $templateOptions = '<option value="">Neue Vorlage</option>';
        $versionOptions = '<option value="">Bitte Regelversion wählen</option>';
        $templateRows = '';
        foreach ($templates as $template) {
            $archive = !empty($template['is_active']) && $this->auth->hasPermission('work_time_policies.manage')
                ? '<form method="post" action="/admin/work-time/templates/' . (int) $template['id'] . '/archive"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button class="button button-secondary">Vorlage archivieren</button></form>' : '';
            if (!empty($template['is_active'])) {
                $templateOptions .= '<option value="' . (int) $template['id'] . '">' . $this->e((string) $template['name']) . ' – neue Revision</option>';
            }
            foreach ($template['versions'] ?? [] as $version) {
                $policy = $version['policy'] ?? [];
                if (!empty($template['is_active'])) {
                    $versionOptions .= '<option value="' . (int) $version['id'] . '" data-policy="' . $this->e(json_encode($policy, JSON_THROW_ON_ERROR)) . '">' . $this->e((string) $template['name']) . ' · Version ' . (int) ($version['revision'] ?? $version['id']) . '</option>';
                }
                $checkoutMode = $policy['checkout_mode'] ?? (!empty($policy['checkout_proposals_enabled']) ? 'automatic' : 'manual');
                $checkoutLabel = $this->checkoutModes()[$checkoutMode] ?? $this->checkoutModes()['manual'];
                $templateRows .= '<tr><td>' . $this->e((string) $template['name']) . (empty($template['is_active']) ? ' (archiviert)' : '') . $archive . '</td><td>' . (int) $version['id'] . '</td><td>' . $this->e((string) ($policy['early_check_in_mode'] ?? 'work_started')) . '</td><td>' . $this->e($checkoutLabel) . '</td><td>' . $this->e((string) ($version['created_at'] ?? '')) . '</td></tr>';
                $archive = '';
            }
        }
        if ($this->auth->hasPermission('work_time_policies.manage')) {
            $content .= '<section class="card stack"><h2>Arbeitszeitvorlage / neue Revision</h2><form method="post" action="/admin/work-time/templates" class="form-grid" data-worktime-form><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="intent" value="template"><input type="hidden" name="preview_token"><label><span>Vorlage</span><select name="template_id">' . $templateOptions . '</select></label><label><span>Name für neue Vorlage</span><input name="name" maxlength="160"></label><label class="full-span"><span>Beschreibung</span><textarea name="description"></textarea></label>' . $this->ruleFields()
                . $this->exampleFields() . '</form></section>';
            $userOptions = $this->userOptions($users, (int) $request->query('user_id', 0));
            $supervisors = '';
            if ($this->access->isAdministrator($actor)) {
                $supervisors = '<label class="full-span"><span>Zuständige Vorgesetzte (Mehrfachauswahl)</span><select name="supervisor_user_ids[]" multiple>' . $this->userOptions($this->users->list('active')) . '</select></label>';
            }
            $content .= '<section class="card stack" id="work-time-assignment"><h2>Für einen Mitarbeiter zuordnen</h2><p>Die Vorschau zeigt betroffene Buchungen, Regelkonflikte und vorhandene Abschlüsse. Rückwirkende Zuordnungen benötigen eine Begründung.</p><form method="post" action="/admin/work-time/assignments" class="form-grid" data-worktime-form><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="intent" value="assignment"><input type="hidden" name="preview_token"><label><span>Mitarbeiter</span><select name="user_id" required><option value="">Bitte wählen</option>' . $userOptions . '</select></label><label><span>Regelversion</span><select name="policy_version_id" required data-worktime-version>' . $versionOptions . '</select></label><label><span>Gültig ab</span><input name="effective_from" type="date" required></label><label><span>Gültig bis (optional)</span><input name="effective_to" type="date"></label>' . $supervisors
                . '<label class="full-span"><span><input type="checkbox" name="use_overrides" value="1" data-worktime-overrides> Individuelle Zeitfenster und Optionen überschreiben</span></label><fieldset class="full-span form-grid" data-worktime-override-fields hidden disabled><legend>Individuelle Regel</legend>' . $this->ruleFields() . '</fieldset><label class="full-span"><span>Begründung für die Zuordnung</span><textarea name="reason" maxlength="2000" rows="2" required></textarea></label>' . $this->impactFields('Zuordnung speichern') . '</form></section>';
        }
        $content .= '<section class="card stack"><h2>Unveränderliche Regelversionen</h2><div class="table-scroll"><table><thead><tr><th>Vorlage</th><th>Version-ID</th><th>Früher Check-in</th><th>Arbeitsende</th><th>Erstellt</th></tr></thead><tbody>' . ($templateRows ?: '<tr><td colspan="5">Noch keine Vorlagen.</td></tr>') . '</tbody></table></div></section>';
        $assignmentRows = '';
        $today = $this->localToday();
        foreach ($assignments as $assignment) {
            $endForm = '';
            $superseded = !empty($assignment['interval_revision']);
            $status = $superseded ? 'Ersetzt' : ($assignment['effective_from'] > $today ? 'Geplant' : (($assignment['effective_to'] ?? '9999-12-31') < $today ? 'Historisch' : 'Gültig'));
            if (!$superseded && $this->auth->hasPermission('work_time_policies.manage')) {
                $id = (int) $assignment['id'];
                $endForm = '<details><summary>Gültigkeit verkürzen</summary><form method="post" action="/admin/work-time/assignments/' . $id . '/end" class="stack" data-worktime-form><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="intent" value="end_assignment"><input type="hidden" name="assignment_id" value="' . $id . '"><input type="hidden" name="preview_token"><label><span>Letzter Gültigkeitstag</span><input name="effective_to" type="date" min="' . $this->e($assignment['effective_from']) . '" required></label><label><span>Begründung</span><textarea name="reason" required maxlength="2000" rows="2"></textarea></label>' . $this->impactFields('Zuordnung beenden', false) . '</form></details>';
            }
            $assignmentRows .= '<tr><td><a href="/admin/work-time?user_id=' . (int) $assignment['user_id'] . '">' . $this->e((string) ($assignment['employee_name'] ?? ('Mitarbeiter #' . $assignment['user_id']))) . '</a></td><td>' . $this->e((string) $assignment['template_name']) . ' · Version ' . (int) $assignment['revision'] . '</td><td>' . $this->e((string) $assignment['effective_from']) . '</td><td>' . $this->e((string) ($assignment['effective_to'] ?? 'offen')) . '</td><td>' . $status . $endForm . '</td></tr>';
        }
        $content .= '<section class="card stack"><h2>Mitarbeiterzuordnungen</h2><div class="table-scroll"><table><thead><tr><th>Mitarbeiter</th><th>Regelversion</th><th>Ab</th><th>Bis</th><th>Status</th></tr></thead><tbody>' . ($assignmentRows ?: '<tr><td colspan="5">Keine zugeordneten Mitarbeiter.</td></tr>') . '</tbody></table></div></section>';
        $content .= $this->assignmentHistoryPanel((int) $request->query('user_id', 0), $actor);
        $content .= '<section class="card stack"><h2>Mitarbeiter prüfen</h2><form method="get" class="form-grid"><label><span>Mitarbeiter</span><select name="user_id" required><option value="">Bitte wählen</option>' . $this->userOptions($users, (int) $request->query('user_id', 0)) . '</select></label><button class="button button-secondary">Informationen laden</button></form></section>';
        $content .= $this->employeePreviewPanel($request, $actor);
        $content .= $pausePanel;
        $content .= $this->pauseClarificationPanel($request, $actor, $csrf);
        $content .= $this->deviationPanel($request, $actor, $csrf);
        $content .= $this->notificationPanel($request, $actor);
        return Response::html($this->view->render('Kernarbeitszeit', $content, '<script src="/assets/js/admin-work-time.js"></script>' . $pauseScript));
    }

    public function preview(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['ok' => false, 'message' => 'Bitte die Seite neu laden.'], 403);
        }
        try {
            $actor = $this->auth->currentUser() ?? [];
            if ($request->input('intent') === 'end_assignment') {
                [$effectiveTo, $reason] = $this->endInput($request);
                $id = (int) $request->input('assignment_id', 0);
                if (!$this->canEndAssignment($actor, $id, $effectiveTo)) {
                    return Response::json(['ok' => false, 'message' => 'Keine Berechtigung für diese Mitarbeiterzuordnung.'], 403);
                }
                return $this->impactResponse($this->policies->endAssignmentPreview($id, $effectiveTo, (int) $actor['id'], $reason, $this->csrf->token()));
            }
            $payload = $this->normalizedPayload($request);
            if ($payload['intent'] === 'assignment') {
                if (!$this->canAssign($actor, $payload)) {
                    return Response::json(['ok' => false, 'message' => 'Keine Berechtigung für diese Mitarbeiterzuordnung.'], 403);
                }
                $impact = $this->policies->assignmentPreview($this->assignmentPayload($payload, $actor, $request), (int) $actor['id'], $this->csrf->token());
                return $this->impactResponse($impact);
            }
            $result = $this->policies->preview($this->examplePayload($request, $payload['policy']));
            return Response::json(['ok' => true, 'data' => $result, 'preview_token' => $this->previewToken($payload)]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function save(Request $request): Response
    {
        $assignment = $request->input('intent') === 'assignment';
        if (!$this->validCsrf($request)) {
            return $assignment ? $this->assignmentFormError($request, 'Bitte die Seite neu laden.', 403) : $this->error('Bitte die Seite neu laden.');
        }
        try {
            $payload = $this->normalizedPayload($request);
            $actor = $this->auth->currentUser() ?? [];
            if ($payload['intent'] === 'assignment') {
                if (!$this->canAssign($actor, $payload)) {
                    return $this->assignmentFormError($request, 'Keine Berechtigung für diesen Mitarbeiter.', 403);
                }
                $this->policies->assign($this->assignmentPayload($payload, $actor, $request), (int) $actor['id'], $this->csrf->token());
                return $this->assignmentSaved($request, $payload['user_id']);
            }
            if (!hash_equals($this->previewToken($payload), (string) $request->input('preview_token', ''))) {
                throw new InvalidArgumentException('Bitte die Regel mit einer Beispielbuchung prüfen, bevor Sie speichern.');
            }
            if ($payload['template_id'] > 0) {
                $this->policies->addVersion($payload['template_id'], $payload['policy'], (int) $actor['id']);
            } else {
                $this->policies->createTemplate($payload, (int) $actor['id']);
            }
            return Response::redirect('/admin/work-time?saved=1');
        } catch (InvalidArgumentException $exception) {
            return $assignment ? $this->assignmentFormError($request, $exception->getMessage()) : $this->error($exception->getMessage());
        }
    }

    public function decide(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return $this->error('Bitte die Seite neu laden.');
        }
        $actor = $this->auth->currentUser() ?? [];
        $userId = (int) $request->input('user_id', 0);
        // Look up the actual deviation, never authorize using the posted employee alone.
        $rows = $this->deviations->listForUser($userId);
        $row = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['id'] === (int) ($params['id'] ?? 0)))[0] ?? null;
        if ($row === null || !$this->access->canManageUser($actor, (int) $row['user_id'], (string) ($row['work_date'] ?? substr((string) ($row['segment_start_at'] ?? ''), 0, 10)))) {
            return Response::html('Keine Berechtigung für diese Abweichung.', 403);
        }
        try {
            $this->deviations->decide((int) $row['id'], (string) $request->input('status'), (int) $actor['id'], (string) $request->input('reason'), (string) $request->input('request_id'));
            return Response::redirect('/admin/work-time?user_id=' . $userId . '&saved=1');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function endAssignment(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return $this->assignmentFormError($request, 'Bitte die Seite neu laden.', 403);
        }
        $actor = $this->auth->currentUser() ?? [];
        $id = (int) ($params['id'] ?? 0);
        try {
            [$effectiveTo, $reason] = $this->endInput($request);
            $userId = $this->canEndAssignment($actor, $id, $effectiveTo);
            if ($userId === null) {
                return $this->assignmentFormError($request, 'Keine Berechtigung für diese Mitarbeiterzuordnung.', 403);
            }
            $this->policies->endAssignment($id, $effectiveTo, (int) $actor['id'], $reason, $this->csrf->token(), $this->approval($request));
            return $this->assignmentSaved($request, $userId);
        } catch (InvalidArgumentException $exception) {
            return $this->assignmentFormError($request, $exception->getMessage());
        }
    }

    private function assignmentSaved(Request $request, int $userId): Response
    {
        $location = '/admin/work-time?user_id=' . $userId . '&saved=1';
        return $request->server('HTTP_ACCEPT') === 'application/json'
            ? Response::json(['ok' => true, 'location' => $location]) : Response::redirect($location);
    }

    private function assignmentFormError(Request $request, string $message, int $status = 422): Response
    {
        return $request->server('HTTP_ACCEPT') === 'application/json'
            ? Response::json(['ok' => false, 'message' => $message], $status)
            : ($status === 403 ? Response::html($this->e($message), $status) : $this->error($message));
    }

    private function impactResponse(array $impact): Response
    {
        $token = $impact['preview_token'];
        unset($impact['preview_token']);
        return Response::json(['ok' => true, 'data' => $impact, 'preview_token' => $token], 200, ['Cache-Control' => 'no-store']);
    }

    private function canAssign(array $actor, array $payload): bool
    {
        return $this->auth->hasPermission('work_time_policies.manage')
            && ($payload['effective_from'] > $this->localToday() || $this->access->isAdministrator($actor))
            && $this->access->canManageUser($actor, $payload['user_id']);
    }

    private function canEndAssignment(array $actor, int $id, string $effectiveTo): ?int
    {
        if (!$this->auth->hasPermission('work_time_policies.manage') || (!$this->access->isAdministrator($actor) && $effectiveTo < $this->localToday())) {
            return null;
        }
        foreach ($this->policies->listAssignments() as $row) {
            if ($row['id'] === $id && $this->access->canManageUser($actor, $row['user_id'], $row['effective_from'])) {
                return $row['user_id'];
            }
        }
        return null;
    }

    private function endInput(Request $request): array
    {
        $date = $request->input('effective_to');
        $reason = $request->input('reason');
        if (!is_string($date) || !is_string($reason)) {
            throw new InvalidArgumentException('Bitte Gültigkeitsende und Begründung angeben.');
        }
        return [$date, $reason];
    }

    private function approval(Request $request): array
    {
        $token = $request->input('preview_token');
        return [
            'preview_token' => is_string($token) ? $token : '',
            'confirm_impact' => $request->input('confirm_impact') === '1' ? '1' : '',
            'replace_overlaps' => $request->input('replace_overlaps') === '1' ? '1' : '',
        ];
    }

    private function assignmentPayload(array $payload, array $actor, Request $request): array
    {
        if (!$this->access->isAdministrator($actor)) {
            $payload['supervisor_user_ids'] = [(int) $actor['id']];
            $payload['manager_user_id'] = (int) $actor['id'];
        }
        $payload['require_reason'] = true;
        return $payload + $this->approval($request);
    }

    private function localToday(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }

    public function archiveTemplate(Request $request, array $params): Response
    {
        if (!$this->validCsrf($request)) {
            return $this->error('Bitte die Seite neu laden.');
        }
        try {
            $this->policies->archiveTemplate((int) ($params['id'] ?? 0), (int) ($this->auth->currentUser()['id'] ?? 0));
            return Response::redirect('/admin/work-time?saved=1');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    public function documentPauses(Request $request, array $params): Response
    {
        if (!$this->auth->hasPermission('timesheets.manage') || !$this->auth->hasPermission('work_time_deviations.manage')) {
            return Response::html('Für Pausennachweise werden Buchungs- und Abweichungsverwaltung benötigt.', 403);
        }
        if (!$this->validCsrf($request)) {
            return $this->error('Bitte die Seite neu laden.');
        }
        if ($this->pauseCorrections === null) {
            return $this->error('Pausennachweise sind noch nicht verfügbar.');
        }
        try {
            $id = (int) ($params['id'] ?? 0);
            $details = $this->pauseCorrections->details($id);
            $actor = $this->auth->currentUser() ?? [];
            if (!$this->access->canManageUser($actor, (int) $details['timesheet']['user_id'], (string) $details['timesheet']['work_date'])) {
                return Response::html('Keine Berechtigung für diese Mitarbeiterbuchung.', 403);
            }
            foreach (['reason', 'request_id', 'source_token', 'actual_break_attestation'] as $key) {
                if (!is_string($request->input($key))) {
                    throw new InvalidArgumentException('Bitte den Pausennachweis vollständig prüfen und bestätigen.');
                }
            }
            if ($request->input('actual_break_attestation') !== '1') {
                throw new InvalidArgumentException('Bitte ausdrücklich bestätigen, dass die eingetragenen Pausen tatsächlich genommen wurden.');
            }
            $starts = $request->input('pause_start', []);
            $ends = $request->input('pause_end', []);
            if (!is_array($starts) || !is_array($ends) || count($starts) !== count($ends)) {
                throw new InvalidArgumentException('Jede Pause benötigt Beginn und Ende.');
            }
            $intervals = [];
            foreach (array_values($starts) as $index => $start) {
                $intervals[] = ['start' => $start, 'end' => array_values($ends)[$index]];
            }
            $this->pauseCorrections->document($id, $intervals, (int) $actor['id'], $request->input('reason'), $request->input('request_id'), $request->input('source_token'));
            return Response::redirect('/admin/work-time?user_id=' . (int) $details['timesheet']['user_id'] . '&pause_month=' . substr($details['timesheet']['work_date'], 0, 7) . '&saved=1');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
    }

    private function pauseClarificationPanel(Request $request, array $actor, string $csrf): string
    {
        $userId = (int) $request->query('user_id', 0);
        if ($this->pauseCorrections === null || !$this->auth->hasPermission('work_time_deviations.view') || $userId <= 0 || !$this->access->canManageUser($actor, $userId)) {
            return '';
        }
        $monthInput = $request->query('pause_month', date('Y-m'));
        $month = is_string($monthInput) ? $monthInput : date('Y-m');
        if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $month)) {
            $month = date('Y-m');
        }
        $firstDay = new DateTimeImmutable($month . '-01', new DateTimeZone('Europe/Berlin'));
        $html = '<section class="card stack" id="pausenlage" aria-label="Pausenlage klären"><h2>Pausenlage klären</h2><p>Hier lassen sich bestätigte Uhrzeiten zu dokumentierten Pausen ergänzen. Bei Altbuchungen ist für einen Nachtrag ein belegtes bisher fehlendes Intervall erforderlich.</p><form method="get" action="/admin/work-time#pausenlage" class="form-grid"><input type="hidden" name="user_id" value="' . $userId . '"><label><span>Prüfmonat</span><input type="month" name="pause_month" value="' . $month . '" required></label><button class="button button-secondary">Pausen prüfen</button></form>';
        $rows = $this->pauseCorrections->listForUser($userId, $firstDay->format('Y-m-d'), $firstDay->modify('last day of this month')->format('Y-m-d'));
        if ($rows === []) {
            return $html . '<p>Keine klärungsbedürftigen Pausen oder Nachträge unter den letzten 100 Arbeitsbuchungen dieses Monats.</p></section>';
        }
        foreach ($rows as $details) {
            $entry = $details['timesheet'];
            $id = (int) $entry['id'];
            $html .= '<article class="card stack" data-pause-evidence="' . $id . '"><h3>Arbeitsbuchung #' . $id . ' · ' . $this->e((string) $entry['work_date']) . '</h3><p>' . $this->e((string) $entry['start_time']) . '–' . $this->e((string) $entry['end_time']) . ' · Gespeicherte Pause: <strong>' . (int) $details['stored_break_minutes'] . ' Min.</strong> · Dokumentiert: ' . $this->e((string) $details['documented_break_minutes']) . ' Min. · Fehlende Lage: ' . $this->e((string) $details['missing_break_minutes']) . ' Min.</p>';
            $assessment = $details['break_assessment'] ?? null;
            if (is_array($assessment)) {
                $html .= '<p>Tagesprüfung · Dokumentierte Pausen ' . (int) $assessment['documented_break_minutes'] . ' Min. · Pflichtpause ' . (int) $assessment['required_break_minutes'] . ' Min. · Fehlende Pflichtpause ' . (int) $assessment['missing_break_minutes'] . ' Min. · Ohne bekannte Uhrzeiten ' . (int) $assessment['unplaced_break_minutes'] . ' Min. · ' . $this->calculationStatusLabel((string) $assessment['calculation_status']) . '</p>';
            } else {
                $html .= '<p>' . $this->e((string) $details['clarification_reason']) . '</p>';
            }
            $html .= '<ul>';
            foreach ($details['breaks'] as $break) {
                $html .= '<li>Bestehender Nachweis #' . (int) $break['id'] . ': ' . $this->e((string) $break['break_started_at']) . ' bis ' . $this->e((string) ($break['break_ended_at'] ?? 'offen')) . '</li>';
            }
            if ($details['breaks'] === []) {
                $html .= '<li>Keine konkreten Pausenintervalle dokumentiert.</li>';
            }
            $html .= '</ul>';
            if ($details['can_document'] && $this->auth->hasPermission('timesheets.manage') && $this->auth->hasPermission('work_time_deviations.manage')) {
                $source = $this->e((string) $details['source_token']);
                $requestId = bin2hex(random_bytes(16));
                $html .= '<form method="post" action="/admin/work-time/timesheets/' . $id . '/pauses" class="form-grid" data-pause-evidence-form><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="source_token" value="' . $source . '"><input type="hidden" name="request_id" value="' . $requestId . '"><div class="full-span stack" data-pause-intervals><div class="form-grid" data-pause-interval><label><span>Tatsächlich Pause ab</span><input type="datetime-local" name="pause_start[]" required></label><label><span>Tatsächlich Pause bis</span><input type="datetime-local" name="pause_end[]" required></label><button class="button button-secondary" type="button" data-pause-remove hidden>Intervall entfernen</button></div></div><button class="button button-secondary" type="button" data-pause-add>Weiteres tatsächliches Intervall</button><label class="full-span"><span>Pflichtbegründung und Herkunft des Nachweises</span><textarea name="reason" minlength="5" maxlength="2000" required></textarea></label><label class="full-span"><span><input type="checkbox" name="actual_break_attestation" value="1" required> Ich bestätige, dass diese Pausen tatsächlich genommen wurden. Ein rechnerischer Abzug allein ist kein Nachweis.</span></label><button class="button" type="submit">Tatsächliche Pausen dokumentieren</button></form>';
            } elseif ($details['blocked_reason'] !== '') {
                $html .= '<p class="notice notice-error">' . $this->e((string) $details['blocked_reason']) . '</p>';
            }
            $html .= '<details><summary>Nachweis- und Berechnungshistorie</summary><ul>';
            foreach ($details['history'] as $audit) {
                $before = json_decode((string) $audit['before_json'], true) ?: [];
                $after = json_decode((string) $audit['after_json'], true) ?: [];
                $html .= '<li>' . $this->e((string) $audit['created_at']) . ' · ' . $this->e((string) $audit['actor_name']) . ' · ' . $this->e((string) $audit['reason']) . ' · Berechnung ' . $this->calculationStatusLabel((string) ($before['calculation']['calculation_status'] ?? '')) . ' → ' . $this->calculationStatusLabel((string) ($after['calculation']['calculation_status'] ?? '')) . '</li>';
            }
            $html .= '</ul></details></article>';
        }
        return $html . '</section>';
    }

    private function deviationPanel(Request $request, array $actor, string $csrf): string
    {
        if (!$this->auth->hasPermission('work_time_deviations.view')) {
            return '';
        }
        $userId = (int) $request->query('user_id', 0);
        $html = '<section class="card stack"><h2>Zeitabweichungen prüfen</h2>';
        if ($userId <= 0) {
            return $html . '</section>';
        }
        if (!$this->access->canManageUser($actor, $userId)) {
            return $html . '<p>Keine Berechtigung für diesen Mitarbeiter.</p></section>';
        }
        foreach ($this->deviations->listForUser($userId) as $row) {
            $html .= '<article class="card stack"><h3>Abweichung #' . (int) $row['id'] . '</h3><p>' . $this->e((string) ($row['segment_start_at'] ?? '')) . ' – ' . $this->e((string) ($row['segment_end_at'] ?? '')) . ' · ' . (int) ($row['minutes'] ?? 0) . ' Min. · ' . $this->e((string) ($row['status'] ?? 'pending')) . '</p>';
            if ($this->auth->hasPermission('work_time_deviations.manage')) {
                $html .= '<form method="post" action="/admin/work-time/deviations/' . (int) $row['id'] . '/decide" class="form-grid"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="user_id" value="' . $userId . '"><input type="hidden" name="request_id" value="' . bin2hex(random_bytes(16)) . '"><label><span>Prüfstatus</span><select name="status"><option value="approved">Genehmigt</option><option value="rejected">Nach Prüfung abgelehnt</option><option value="disputed">Klärungsbedürftig</option><option value="pending">Offen</option></select></label><label><span>Pflichtbegründung</span><input name="reason" required maxlength="2000"></label><button class="button">Entscheidung protokollieren</button></form>';
            }
            $html .= '<details><summary>Entscheidungshistorie</summary><ul>';
            foreach ($row['decisions'] ?? [] as $decision) {
                $html .= '<li>' . $this->e((string) ($decision['created_at'] ?? '')) . ' · ' . $this->e((string) ($decision['new_status'] ?? '')) . ' · ' . $this->e((string) ($decision['reason'] ?? '')) . ' · Akteur #' . (int) ($decision['actor_user_id'] ?? 0) . '</li>';
            }
            $html .= '</ul></details></article>';
        }
        return $html . '<p>Der Prüfstatus entscheidet nicht abschließend über arbeitsrechtliche Ansprüche.</p></section>';
    }

    private function normalizedPayload(Request $request): array
    {
        foreach ($request->input() as $key => $value) {
            if ($key !== 'supervisor_user_ids' && !is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Ungültige Formulareingabe. Bitte die Regel neu prüfen.');
            }
        }
        $intent = (string) $request->input('intent', 'template');
        $policy = $this->policyInput($request);
        $notificationReference = [];
        if ($intent === 'assignment') {
            $versionId = (int) $request->input('policy_version_id');
            $base = null;
            foreach ($this->policies->listTemplates() as $template) {
                foreach ($template['versions'] ?? [] as $version) {
                    if ((int) $version['id'] === $versionId) {
                        $base = $version['policy'];
                    }
                }
            }
            if ($base === null) {
                throw new InvalidArgumentException('Bitte eine vorhandene Regelversion wählen.');
            }
            $override = (bool) $request->input('use_overrides', false);
            $notificationReference = $base;
            $payload = ['intent' => $intent, 'user_id' => (int) $request->input('user_id'), 'policy_version_id' => $versionId,
                'effective_from' => (string) $request->input('effective_from'), 'effective_to' => $request->input('effective_to') ?: null,
                'overrides' => $override ? $policy : [], 'supervisor_user_ids' => array_map('intval', (array) $request->input('supervisor_user_ids', [])), 'policy' => $override ? $policy : $base,
                'reason' => (string) $request->input('reason', '')];
        } else {
            $payload = ['intent' => 'template', 'template_id' => (int) $request->input('template_id', 0), 'name' => trim((string) $request->input('name')), 'description' => trim((string) $request->input('description')), 'policy' => $policy];
            foreach ($this->policies->listTemplates() as $template) {
                if ((int) $template['id'] === $payload['template_id']) {
                    $notificationReference = $template['versions'][0]['policy'] ?? [];
                }
            }
        }
        if (!$this->auth->hasPermission('work_time_notifications.manage')) {
            foreach (['notify_employee_push', 'notify_employee_sms', 'notify_supervisor_push', 'notify_supervisor_sms', 'outside_window_notifications_enabled'] as $key) {
                if ((bool) ($payload['policy'][$key] ?? false) !== (bool) ($notificationReference[$key] ?? false)) {
                    throw new InvalidArgumentException('Für Benachrichtigungsoptionen fehlt die Berechtigung.');
                }
            }
            if ((int) ($payload['policy']['outside_window_delay_minutes'] ?? 5) !== (int) ($notificationReference['outside_window_delay_minutes'] ?? 5)) {
                throw new InvalidArgumentException('Für die Benachrichtigungsverzögerung fehlt die Berechtigung.');
            }
        }
        return $payload;
    }

    private function notificationPanel(Request $request, array $actor): string
    {
        $userId = (int) $request->query('user_id', 0);
        if (!$this->auth->hasPermission('work_time_notifications.manage') || $userId <= 0 || !$this->access->canManageUser($actor, $userId)) {
            return '';
        }
        $rows = '';
        foreach ($this->events->notificationEventsForEmployee($userId) as $event) {
            $rows .= '<tr><td>' . $this->e((string) $event['event_key']) . '</td><td>' . $this->e((string) $event['recipient_name']) . '</td><td>' . $this->e((string) $event['channel']) . '</td><td>' . $this->e(WorkTimeNotificationService::statusLabel((string) $event['status'])) . '</td><td>' . (int) $event['attempts'] . '</td><td>' . $this->e((string) ($event['error_message'] ?? '')) . '</td></tr>';
        }
        return '<section class="card stack"><h2>Benachrichtigungsstatus</h2><p>Provider-Annahme ist kein Nachweis der Zustellung auf dem Empfängergerät. Ein nach Abbruch ungeklärter Versand wird nicht automatisch wiederholt.</p><p><a class="button button-secondary" href="/admin/work-time/notifications?user_id=' . $userId . '">Vollständige Versandereignisse und Wiederholungen</a></p><div class="table-scroll"><table><thead><tr><th>Ereignis</th><th>Empfänger</th><th>Kanal</th><th>Status</th><th>Versuche</th><th>Fehler</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6">Keine Versandereignisse.</td></tr>') . '</tbody></table></div></section>';
    }

    private function employeePreviewPanel(Request $request, array $actor): string
    {
        $userId = (int) $request->query('user_id', 0);
        if (!$this->policies->enabled() || $userId <= 0
            || (!$this->auth->hasPermission('work_time_policies.view') && !$this->auth->hasPermission('work_time_deviations.view'))
            || !$this->access->canManageUser($actor, $userId)) {
            return '';
        }
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
        $month = $request->query('work_month');
        $month = is_string($month) && preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month) ? $month : $today->format('Y-m');
        $from = new DateTimeImmutable($month . '-01', new DateTimeZone('Europe/Berlin'));
        $until = $month === $today->format('Y-m') ? $today : $from->modify('last day of this month');
        $preview = $this->preview->forUserPeriod($userId, $from->format('Y-m-d'), $until->format('Y-m-d'));
        $html = '<section class="card stack"><h2>Mitarbeiter #' . $userId . ' · Monatsvorschau</h2><p>Stand ' . $until->format('d.m.Y') . '. Laufende oder ungeklärte Anteile bleiben vorläufig.</p><form method="get" class="form-grid"><input type="hidden" name="user_id" value="' . $userId . '"><label><span>Auswertungsmonat</span><input type="month" name="work_month" value="' . $this->e($month) . '"></label><button class="button button-secondary">Monat prüfen</button></form><dl>';
        foreach (['actual_work_minutes' => 'Tatsächliche Arbeit', 'inside_window_minutes' => 'Im Fenster', 'outside_before_minutes' => 'Vor dem Fenster', 'outside_after_minutes' => 'Nach dem Fenster', 'outside_gap_minutes' => 'Zwischen Fenstern', 'outside_pending_minutes' => 'Außenzeit offen', 'outside_approved_minutes' => 'Außenzeit genehmigt', 'outside_rejected_minutes' => 'Außenzeit abgelehnt', 'outside_disputed_minutes' => 'Außenzeit strittig', 'accountable_work_minutes' => 'Vorläufig anrechenbar'] as $key => $label) {
            $html .= '<dt>' . $label . '</dt><dd>' . (isset($preview[$key]) ? (int) $preview[$key] . ' Min.' : 'Klärungsbedürftig / laufend') . '</dd>';
        }
        $rows = '';
        foreach ($preview['rows'] as $row) {
            $policy = $row['policy'];
            $calculation = $row['calculation'];
            $rows .= '<tr><td>' . $this->e($row['work_date']) . '</td><td>' . ($policy === null ? 'Ohne Zuordnung' : $this->e($policy['template_name']) . ' · Version ' . (int) $policy['revision']) . '</td><td>' . $this->minutes($calculation['actual_work_minutes']) . '</td><td>' . $this->minutes($calculation['inside_window_minutes']) . '</td><td>' . $this->minutes($calculation['outside_pending_minutes']) . '</td><td>' . $this->minutes($calculation['outside_approved_minutes']) . '</td><td>' . $this->e($this->calculationStatus($calculation['calculation_status'])) . '</td></tr>';
        }
        return $html . '</dl><p>Status ' . $this->e($this->calculationStatus($preview['calculation_status'])) . '</p><div class="table-scroll"><table><thead><tr><th>Arbeitstag</th><th>Regel</th><th>Arbeit</th><th>Im Fenster</th><th>Außenzeit offen</th><th>Außenzeit genehmigt</th><th>Status</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="7">Keine Arbeitsbuchungen im Auswertungsmonat.</td></tr>') . '</tbody></table></div></section>';
    }

    private function assignmentHistoryPanel(int $userId, array $actor): string
    {
        if ($userId <= 0 || !$this->auth->hasPermission('work_time_policies.view') || !$this->access->isAdministrator($actor)) {
            return '';
        }
        $rows = '';
        foreach ($this->policies->assignmentHistory($userId) as $event) {
            $label = match ($event['action']) {
                'assignment_created' => 'Zugeordnet', 'assignment_ended' => 'Beendet',
                'assignment_shortened' => 'Verkürzt', 'assignment_superseded' => 'Ersetzt',
                'assignment_continued' => 'Fortgeführt', default => 'Geändert',
            };
            $after = $event['after'];
            $previous = $event['before'];
            $beforePeriod = $previous === null ? '–' : ($previous['effective_from'] ?? '–') . ' bis ' . ($previous['effective_to'] ?? 'offen');
            $afterPeriod = ($after['effective_from'] ?? ($previous['effective_from'] ?? '–')) . ' bis ' . ($after['effective_to'] ?? 'offen');
            $rows .= '<tr><td>' . $this->e($event['created_at']) . '</td><td>' . $this->e($event['actor_name']) . '</td><td>' . $label . '</td><td>' . $this->e($beforePeriod) . '</td><td>' . $this->e($afterPeriod) . '</td><td>' . $this->e((string) ($after['reason'] ?? '')) . '</td></tr>';
        }
        return '<section class="card stack"><h2>Zuordnungshistorie</h2><div class="table-scroll"><table><thead><tr><th>Änderungszeitpunkt</th><th>Geändert von</th><th>Aktion</th><th>Vorheriger Zeitraum</th><th>Protokollierter Zeitraum</th><th>Begründung</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="6">Keine Zuordnungsänderungen.</td></tr>') . '</tbody></table></div></section>';
    }

    private function impactFields(string $saveLabel, bool $showOverlap = true): string
    {
        $conflict = $showOverlap ? '<div class="full-span" data-worktime-replace hidden><label><span><input name="replace_overlaps" type="checkbox" value="1"> Überschneidende Zuordnungen im angezeigten Zeitraum ersetzen</span></label></div>' : '';
        return '<button type="button" class="button button-secondary" data-worktime-preview>Auswirkungen prüfen</button><div class="full-span" data-worktime-result role="status"></div>'
            . '<div class="full-span" data-worktime-confirm hidden><label><span><input name="confirm_impact" type="checkbox" value="1"> Rückwirkende Änderung nach dieser Vorschau bestätigen</span></label></div>'
            . $conflict . '<button type="submit" class="button" data-worktime-save disabled>' . $this->e($saveLabel) . '</button>';
    }

    private function minutes(?int $minutes): string
    {
        return $minutes === null ? 'Klärungsbedürftig' : $minutes . ' Min.';
    }

    private function calculationStatus(string $status): string
    {
        return match ($status) {
            'complete' => 'Berechnet', 'pending' => 'Vorläufig', 'no_policy' => 'Ohne Zuordnung',
            'open' => 'Laufend', 'needs_clarification' => 'Klärungsbedürftig', default => 'Vorläufig',
        };
    }

    private function policyInput(Request $request): array
    {
        $windows = [];
        foreach (range(1, 7) as $day) {
            $windows[$day] = [];
            foreach (array_filter(array_map('trim', explode(',', (string) $request->input('window_' . $day, '')))) as $window) {
                if (!preg_match('/^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/', $window, $match)) {
                    throw new InvalidArgumentException('Zeitfenster bitte als 08:00-16:00 eingeben, mehrere mit Komma trennen.');
                }
                $windows[$day][] = ['start' => $match[1], 'end' => $match[2]];
            }
        }
        $checkoutMode = $request->input('checkout_mode');
        $checkout = $checkoutMode === null
            ? ['checkout_proposals_enabled' => $request->input('checkout_proposals_enabled', false)]
            : ['checkout_mode' => $checkoutMode];
        return ['windows' => $windows, 'timezone' => 'Europe/Berlin', 'early_check_in_mode' => (string) $request->input('early_check_in_mode', 'work_started'),
            ...$checkout, 'checkout_delay_minutes' => $request->input('checkout_delay_minutes', 0),
            'outside_window_notifications_enabled' => (bool) $request->input('outside_window_notifications_enabled', false), 'outside_window_delay_minutes' => $request->input('outside_window_delay_minutes', 5),
            'notify_employee_push' => (bool) $request->input('notify_employee_push', false), 'notify_employee_sms' => (bool) $request->input('notify_employee_sms', false),
            'notify_supervisor_push' => (bool) $request->input('notify_supervisor_push', false), 'notify_supervisor_sms' => (bool) $request->input('notify_supervisor_sms', false)];
    }

    private function examplePayload(Request $request, array $policy): array
    {
        $example = ['policy' => $policy, 'work_date' => (string) $request->input('example_date'), 'start_time' => (string) $request->input('example_start'), 'end_time' => (string) $request->input('example_end')];
        $breakStart = trim((string) $request->input('example_break_start'));
        $breakEnd = trim((string) $request->input('example_break_end'));
        if ($breakStart !== '' || $breakEnd !== '') {
            $example['break_intervals'] = [['start' => $example['work_date'] . ' ' . $breakStart . ':00', 'end' => $example['work_date'] . ' ' . $breakEnd . ':00']];
        } else {
            $example['break_intervals'] = [];
        }
        return $example;
    }

    private function ruleFields(): string
    {
        $html = '<p class="full-span muted">Zeitfenster pro Tag, z. B. 08:00-16:00. Mehrere Fenster mit Komma trennen. Leer bedeutet kein Fenster. Nachtfenster können über Mitternacht reichen.</p>';
        foreach ([1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'] as $day => $name) {
            $html .= '<label><span>' . $name . '</span><input name="window_' . $day . '" value="' . ($day < 6 ? '08:00-16:00' : '') . '" placeholder="08:00-16:00"></label>';
        }
        $html .= '<label><span>Früher Check-in</span><select name="early_check_in_mode"><option value="work_started">Arbeit beginnt tatsächlich</option><option value="presence_only">Nur anwesend, Arbeitsbeginn separat bestätigen</option></select></label>';
        $html .= '<label><span>Am Ende des Zeitfensters</span><select name="checkout_mode">';
        foreach ($this->checkoutModes() as $value => $label) {
            $html .= '<option value="' . $value . '">' . $this->e($label) . '</option>';
        }
        $html .= '</select></label><label><span>Verzögerung für Check-out oder Vorschlag (Minuten)</span><input name="checkout_delay_minutes" type="number" min="0" max="1440" value="0"></label>';
        $html .= '<label><span>Verzögerung bei Arbeitsbeginn nach Fensterende (Minuten)</span><input name="outside_window_delay_minutes" type="number" min="0" max="1440" value="5"></label>';
        foreach (['outside_window_notifications_enabled' => 'Arbeitsbeginn nach Fensterende unabhängig melden', 'notify_employee_push' => 'Mitarbeiter-Push für Vorschläge und Arbeitsbeginn außerhalb des Fensters', 'notify_supervisor_push' => 'PWA-Push für zugeordnete Vorgesetzte', 'notify_employee_sms' => 'Mitarbeiter-SMS (Testkanal)', 'notify_supervisor_sms' => 'SMS für Vorgesetzte (Testkanal)'] as $key => $label) {
            $html .= '<label><span><input type="checkbox" name="' . $key . '" value="1"> ' . $label . '</span></label>';
        }
        return $html;
    }

    private function checkoutModes(): array
    {
        return [
            'automatic' => 'Automatisch am Fensterende auschecken',
            'proposal' => 'Arbeitsende zur Bestätigung vorschlagen',
            'manual' => 'Manuell auschecken',
        ];
    }

    private function exampleFields(): string
    {
        $exampleDate = (new DateTimeImmutable('next monday', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        return '<fieldset class="full-span form-grid"><legend>Auswirkung vor dem Speichern prüfen</legend><label><span>Beispieldatum</span><input type="date" name="example_date" value="' . $exampleDate . '" required></label><label><span>Arbeitsbeginn</span><input type="time" name="example_start" value="07:42" required></label><label><span>Arbeitsende</span><input type="time" name="example_end" value="16:35" required></label><label><span>Pause ab</span><input type="time" name="example_break_start" value="12:00"></label><label><span>Pause bis</span><input type="time" name="example_break_end" value="12:30"></label><button type="button" class="button button-secondary" data-worktime-preview>Beispiel prüfen</button><output class="full-span" data-worktime-result aria-live="polite"></output></fieldset><div class="toolbar-actions full-span"><button class="button" type="submit" data-worktime-save disabled>Geprüfte Regel speichern</button></div>';
    }

    private function previewToken(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $this->csrf->token());
    }

    private function validCsrf(Request $request): bool
    {
        $token = $request->input('csrf_token');
        return is_string($token) && $this->csrf->isValid($token);
    }

    private function userOptions(array $users, ?int $selectedId = null): string
    {
        return implode('', array_map(fn (array $u): string => '<option value="' . (int) $u['id'] . '"' . ((int) $u['id'] === $selectedId ? ' selected' : '') . '>' . $this->e(trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''))) . ' (#' . (int) $u['id'] . ')</option>', $users));
    }

    private function calculationStatusLabel(string $status): string
    {
        return match ($status) {
            'needs_clarification' => 'Klärungsbedürftig',
            'open' => 'Einsatz läuft',
            'pending' => 'Außenzeiten offen',
            'disputed' => 'Außenzeiten strittig',
            'complete' => 'Berechnet (Vorschau)',
            'no_policy' => 'Keine Regel zugeordnet',
            'disabled' => 'Deaktiviert',
            default => 'Noch nicht berechnet',
        };
    }

    private function error(string $message): Response
    {
        return Response::redirect('/admin/work-time?error=' . rawurlencode($message));
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
