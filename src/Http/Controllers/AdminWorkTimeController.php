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
        private ?WorkTimePauseCorrectionService $pauseCorrections = null
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->hasPermission('work_time_policies.view') && !$this->auth->hasPermission('work_time_deviations.view') && !$this->auth->hasPermission('work_time_notifications.manage')) {
            return Response::html('Keine Berechtigung für diesen Bereich.', 403);
        }
        $actor = $this->auth->currentUser() ?? [];
        $templates = $this->auth->hasPermission('work_time_policies.view') ? $this->policies->listTemplates() : [];
        $assignments = array_values(array_filter($this->policies->listAssignments(), fn (array $a): bool => $this->access->canManageUser($actor, (int) $a['user_id'], (string) $a['effective_from'])));
        $users = array_values(array_filter($this->users->list('active'), fn (array $u): bool => $this->access->canManageUser($actor, (int) $u['id'])));
        $csrf = $this->e($this->csrf->token());
        $enabled = $this->policies->enabled() ? 'Global eingeschaltet; wirksam nur bei zugeordneten Mitarbeitern ab Gültigkeitsbeginn.' : 'Global ausgeschaltet. Die bisherige Zeiterfassung bleibt aktiv.';
        $notice = $request->query('error') ? '<div class="notice notice-error" role="alert">' . $this->e((string) $request->query('error')) . '</div>' : '';
        if ($request->query('saved')) {
            $notice .= '<div class="notice notice-success" role="status">Gespeichert. Historische Regeln und Stempelzeiten bleiben erhalten.</div>';
        }
        $content = '<header class="page-header"><div><p class="eyebrow">Optionale Mitarbeiterregeln</p><h1>Kernarbeitszeit</h1><p>' . $enabled . '</p></div></header>' . $notice
            . '<section class="card stack"><h2>Anwesenheit und Arbeit getrennt erfassen</h2><p>Das Fenster ist eine zeitliche Orientierung, keine Zusage über Nettoarbeitsstunden. Tatsächliche Arbeit außerhalb des Fensters bleibt dokumentiert. Die abweichende Anrechnung ist eine vorläufige Vorschau; bestehende Zeitkontowerte werden weiterhin nach der bisherigen Berechnung geführt.</p><p>„Nur anwesend“ setzt voraus, dass tatsächlich noch nicht gearbeitet wird. Bereits geleistete Arbeit muss als Arbeitsbeginn bestätigt werden. Ein Vorschlag zum Arbeitsende beendet keine Buchung automatisch.</p></section>';
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
                $templateRows .= '<tr><td>' . $this->e((string) $template['name']) . (empty($template['is_active']) ? ' (archiviert)' : '') . $archive . '</td><td>' . (int) $version['id'] . '</td><td>' . $this->e((string) ($policy['early_check_in_mode'] ?? 'work_started')) . '</td><td>' . $this->e((string) ($version['created_at'] ?? '')) . '</td></tr>';
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
            $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
            $content .= '<section class="card stack"><h2>Für einen Mitarbeiter aktivieren</h2><p>Ohne gültige Zuordnung bleibt der Ablauf unverändert. Überschneidende Zuordnungen werden abgewiesen. Ein optionales Enddatum begrenzt die Gültigkeit.</p><form method="post" action="/admin/work-time/assignments" class="form-grid" data-worktime-form><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="intent" value="assignment"><input type="hidden" name="preview_token"><label><span>Mitarbeiter</span><select name="user_id" required><option value="">Bitte wählen</option>' . $userOptions . '</select></label><label><span>Regelversion</span><select name="policy_version_id" required data-worktime-version>' . $versionOptions . '</select></label><label><span>Gültig ab</span><input name="effective_from" type="date" min="' . $tomorrow . '" required></label><label><span>Gültig bis (optional)</span><input name="effective_to" type="date" min="' . $tomorrow . '"></label>' . $supervisors
                . '<label class="full-span"><span><input type="checkbox" name="use_overrides" value="1" data-worktime-overrides> Individuelle Zeitfenster und Optionen überschreiben</span></label><fieldset class="full-span form-grid" data-worktime-override-fields hidden disabled><legend>Individuelle Regel</legend>' . $this->ruleFields() . '</fieldset>' . $this->exampleFields() . '</form></section>';
        }
        $content .= '<section class="card stack"><h2>Unveränderliche Regelversionen</h2><div class="table-scroll"><table><thead><tr><th>Vorlage</th><th>Version-ID</th><th>Früher Check-in</th><th>Erstellt</th></tr></thead><tbody>' . ($templateRows ?: '<tr><td colspan="4">Noch keine Vorlagen.</td></tr>') . '</tbody></table></div></section>';
        $assignmentRows = '';
        foreach ($assignments as $assignment) {
            $endForm = '';
            if ($this->auth->hasPermission('work_time_policies.manage')) {
                $endForm = '<form method="post" action="/admin/work-time/assignments/' . (int) $assignment['id'] . '/end"><input type="hidden" name="csrf_token" value="' . $csrf . '"><label><span>Letzter Gültigkeitstag</span><input name="effective_to" type="date" min="' . date('Y-m-d') . '" required></label><label><span>Grund</span><input name="reason" required minlength="3"></label><button class="button button-secondary">Zuordnung beenden</button></form>';
            }
            $assignmentRows .= '<tr><td><a href="/admin/work-time?user_id=' . (int) $assignment['user_id'] . '">' . $this->e((string) ($assignment['employee_name'] ?? ('Mitarbeiter #' . $assignment['user_id']))) . '</a></td><td>' . (int) $assignment['policy_version_id'] . '</td><td>' . $this->e((string) $assignment['effective_from']) . '</td><td>' . $this->e((string) ($assignment['effective_to'] ?? 'offen')) . $endForm . '</td></tr>';
        }
        $content .= '<section class="card stack"><h2>Mitarbeiterzuordnungen</h2><div class="table-scroll"><table><thead><tr><th>Mitarbeiter-ID</th><th>Regelversion</th><th>Ab</th><th>Bis</th></tr></thead><tbody>' . ($assignmentRows ?: '<tr><td colspan="4">Keine zugeordneten Mitarbeiter.</td></tr>') . '</tbody></table></div></section>';
        $content .= '<section class="card stack"><h2>Mitarbeiter prüfen</h2><form method="get" class="form-grid"><label><span>Mitarbeiter</span><select name="user_id" required><option value="">Bitte wählen</option>' . $this->userOptions($users, (int) $request->query('user_id', 0)) . '</select></label><button class="button button-secondary">Informationen laden</button></form></section>';
        $content .= $this->employeePreviewPanel($request, $actor);
        $content .= $this->pauseClarificationPanel($request, $actor, $csrf);
        $content .= $this->deviationPanel($request, $actor, $csrf);
        $content .= $this->notificationPanel($request, $actor);
        return Response::html($this->view->render('Kernarbeitszeit', $content, '<script src="/assets/js/admin-work-time.js"></script>'));
    }

    public function preview(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['ok' => false, 'message' => 'Bitte die Seite neu laden.'], 403);
        }
        try {
            $payload = $this->normalizedPayload($request);
            $result = $this->policies->preview($this->examplePayload($request, $payload['policy']));
            return Response::json(['ok' => true, 'data' => $result, 'preview_token' => $this->previewToken($payload)]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function save(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return $this->error('Bitte die Seite neu laden.');
        }
        try {
            $payload = $this->normalizedPayload($request);
            if (!hash_equals($this->previewToken($payload), (string) $request->input('preview_token', ''))) {
                throw new InvalidArgumentException('Bitte die Regel mit einer Beispielbuchung prüfen, bevor Sie speichern.');
            }
            $actor = $this->auth->currentUser() ?? [];
            if ($payload['intent'] === 'assignment') {
                if (!$this->access->canManageUser($actor, (int) $payload['user_id'])) {
                    return Response::html('Keine Berechtigung für diesen Mitarbeiter.', 403);
                }
                if (!$this->access->isAdministrator($actor)) {
                    $payload['supervisor_user_ids'] = [(int) $actor['id']];
                }
                $this->policies->assign($payload, (int) $actor['id']);
            } elseif ($payload['template_id'] > 0) {
                $this->policies->addVersion($payload['template_id'], $payload['policy'], (int) $actor['id']);
            } else {
                $this->policies->createTemplate($payload, (int) $actor['id']);
            }
            return Response::redirect('/admin/work-time?saved=1');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
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
            return $this->error('Bitte die Seite neu laden.');
        }
        $actor = $this->auth->currentUser() ?? [];
        $id = (int) ($params['id'] ?? 0);
        $rows = array_values(array_filter($this->policies->listAssignments(), static fn (array $row): bool => (int) $row['id'] === $id));
        if ($rows === [] || !$this->access->canManageUser($actor, (int) $rows[0]['user_id'], (string) $rows[0]['effective_from'])) {
            return Response::html('Keine Berechtigung für diese Mitarbeiterzuordnung.', 403);
        }
        try {
            $this->policies->endAssignment($id, (string) $request->input('effective_to'), (int) $actor['id'], (string) $request->input('reason'));
            return Response::redirect('/admin/work-time?saved=1');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage());
        }
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
        if ($this->pauseCorrections === null || !$this->policies->enabled() || !$this->auth->hasPermission('work_time_deviations.view') || $userId <= 0 || !$this->access->canManageUser($actor, $userId)) {
            return '';
        }
        $monthInput = $request->query('pause_month', date('Y-m'));
        $month = is_string($monthInput) ? $monthInput : date('Y-m');
        if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $month)) {
            $month = date('Y-m');
        }
        $firstDay = new DateTimeImmutable($month . '-01', new DateTimeZone('Europe/Berlin'));
        $html = '<section class="card stack" aria-label="Pausenlage klären"><h2>Pausenlage klären</h2><p>Gespeicherte Pausendauer ist kein Nachweis ihrer tatsächlichen Lage. Hier werden ausschließlich belegte, bislang fehlende Intervalle ergänzt. Vorhandene Intervalle sowie Beginn, Ende und Nettoarbeitszeit bleiben erhalten.</p><form method="get" class="form-grid"><input type="hidden" name="user_id" value="' . $userId . '"><label><span>Prüfmonat</span><input type="month" name="pause_month" value="' . $month . '" required></label><button class="button button-secondary">Pausen prüfen</button></form>';
        $rows = $this->pauseCorrections->listForUser($userId, $firstDay->format('Y-m-d'), $firstDay->modify('last day of this month')->format('Y-m-d'));
        if ($rows === []) {
            return $html . '<p>Keine klärungsbedürftigen Pausen oder Nachträge unter den letzten 100 Arbeitsbuchungen dieses Monats.</p></section>';
        }
        foreach ($rows as $details) {
            $entry = $details['timesheet'];
            $id = (int) $entry['id'];
            $html .= '<article class="card stack" data-pause-evidence="' . $id . '"><h3>Arbeitsbuchung #' . $id . ' · ' . $this->e((string) $entry['work_date']) . '</h3><p>' . $this->e((string) $entry['start_time']) . '–' . $this->e((string) $entry['end_time']) . ' · Gespeicherte Pause: <strong>' . (int) $details['stored_break_minutes'] . ' Min.</strong> · Dokumentiert: ' . $this->e((string) $details['documented_break_minutes']) . ' Min. · Fehlende Lage: ' . $this->e((string) $details['missing_break_minutes']) . ' Min.</p><p>' . $this->e((string) $details['clarification_reason']) . '</p><ul>';
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
                'overrides' => $override ? $policy : [], 'supervisor_user_ids' => array_map('intval', (array) $request->input('supervisor_user_ids', [])), 'policy' => $override ? $policy : $base];
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
        $preview = $this->preview->forUserPeriod($userId, $today->format('Y-m-01'), $today->format('Y-m-d'));
        $html = '<section class="card stack"><h2>Mitarbeiter #' . $userId . ' · Monatsvorschau</h2><p>Stand ' . $today->format('d.m.Y') . '. Separate Vorschau; laufende oder ungeklärte Anteile werden nicht als abschließend berechnete Arbeitszeit ausgegeben.</p><dl>';
        foreach (['actual_work_minutes' => 'Tatsächliche Arbeit', 'inside_window_minutes' => 'Im Fenster', 'outside_before_minutes' => 'Vor dem Fenster', 'outside_after_minutes' => 'Nach dem Fenster', 'outside_gap_minutes' => 'Zwischen Fenstern', 'outside_pending_minutes' => 'Außenzeit offen', 'outside_approved_minutes' => 'Außenzeit genehmigt', 'outside_rejected_minutes' => 'Außenzeit abgelehnt', 'outside_disputed_minutes' => 'Außenzeit strittig', 'accountable_work_minutes' => 'Vorläufig anrechenbar'] as $key => $label) {
            $html .= '<dt>' . $label . '</dt><dd>' . (isset($preview[$key]) ? (int) $preview[$key] . ' Min.' : 'Klärungsbedürftig / laufend') . '</dd>';
        }
        return $html . '</dl><p>Status: ' . $this->e((string) $preview['calculation_status']) . '</p></section>';
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
        return ['windows' => $windows, 'timezone' => 'Europe/Berlin', 'early_check_in_mode' => (string) $request->input('early_check_in_mode', 'work_started'),
            'checkout_proposals_enabled' => (bool) $request->input('checkout_proposals_enabled', false), 'checkout_delay_minutes' => (int) $request->input('checkout_delay_minutes', 0),
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
        $html .= '<label><span>Früher Check-in</span><select name="early_check_in_mode"><option value="work_started">Arbeit beginnt tatsächlich</option><option value="presence_only">Nur anwesend; Arbeitsbeginn separat bestätigen</option></select></label><label><span>Verzögerung Endvorschlag (Minuten)</span><input name="checkout_delay_minutes" type="number" min="0" max="1440" value="0"></label>';
        $html .= '<label><span>Verzögerung bei Arbeitsbeginn nach Fensterende (Minuten)</span><input name="outside_window_delay_minutes" type="number" min="0" max="1440" value="5"></label>';
        foreach (['checkout_proposals_enabled' => 'Arbeitsende zur Bestätigung vorschlagen', 'outside_window_notifications_enabled' => 'Arbeitsbeginn nach Fensterende unabhängig melden', 'notify_employee_push' => 'Mitarbeiter: PWA-Push', 'notify_supervisor_push' => 'Zugeordnete Vorgesetzte: PWA-Push', 'notify_employee_sms' => 'Mitarbeiter: SMS (Testkanal)', 'notify_supervisor_sms' => 'Vorgesetzte: SMS (Testkanal)'] as $key => $label) {
            $html .= '<label><span><input type="checkbox" name="' . $key . '" value="1"> ' . $label . '</span></label>';
        }
        return $html;
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
