<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Users\UserService;
use App\Domain\WorkTime\WorkTimeAccessService;
use App\Domain\WorkTime\WorkTimeNotificationService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use InvalidArgumentException;

final class AdminWorkTimeNotificationController
{
    public function __construct(
        private AdminView $view,
        private AuthService $auth,
        private CsrfService $csrf,
        private UserService $users,
        private WorkTimePolicyService $policies,
        private WorkTimeNotificationService $notifications,
        private WorkTimeAccessService $access
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->auth->currentUser();
        if ($actor === null || !$this->auth->hasPermission('work_time_notifications.manage')) { return Response::html('Keine Berechtigung fuer Versandereignisse.', 403); }
        $input = $request->query('user_id');
        if ($input !== null && (!is_scalar($input) || !ctype_digit((string) $input))) { return Response::html('Ungueltiger Mitarbeiterfilter.', 422); }
        $employeeId = (int) $input;
        if ($employeeId > 0 && !$this->canViewEmployee($actor, $employeeId)) { return Response::html('Keine Berechtigung fuer diesen Mitarbeiter.', 403); }
        $isAdmin = $this->access->isAdministrator($actor);
        $events = $employeeId > 0 || $isAdmin ? $this->notifications->list($employeeId > 0 ? $employeeId : null) : [];
        $events = array_values(array_filter($events, fn (array $event): bool => $isAdmin || $this->access->canManageUser($actor, (int) $event['employee_user_id'], $event['work_date'] ?? null)));
        $options = $isAdmin ? '<option value="">Alle Mitarbeiter</option>' : '<option value="">Mitarbeiter waehlen</option>';
        foreach ($this->users->list('active') as $user) {
            if (!$this->canViewEmployee($actor, (int) $user['id'])) { continue; }
            $options .= '<option value="' . (int) $user['id'] . '"' . ($employeeId === (int) $user['id'] ? ' selected' : '') . '>' . $this->e(trim($user['first_name'] . ' ' . $user['last_name'])) . '</option>';
        }
        $notice = $request->query('saved') ? '<p class="notice notice-success" role="status">Wiederholung wurde protokolliert. Der Worker prueft Quelle und Empfaenger vor einem erneuten Versuch.</p>' : '';
        $error = $request->query('error');
        if (is_string($error) && $error !== '') { $notice .= '<p class="notice notice-error" role="alert">' . $this->e($error) . '</p>'; }
        $content = '<header class="page-header"><div><p class="eyebrow">Kernarbeitszeit</p><h1>Versandereignisse</h1><p>Providerannahme ist kein Nachweis einer Zustellung auf dem Empfaengergeraet.</p></div><a class="button button-secondary" href="/admin/work-time">Zu den Arbeitszeitregeln</a></header>' . $notice;
        $content .= '<section class="card stack"><p>' . $this->e($this->notifications->smsStatus()) . '</p><p>Definitiv nicht angenommene, voruebergehend fehlgeschlagene Versuche werden begrenzt mit Wartezeit wiederholt. Unklare oder bereits angenommene Sendungen werden nicht automatisch erneut verschickt.</p>';
        if (!$this->policies->enabled()) { $content .= '<p class="notice" role="status">Arbeitszeitfenster sind global deaktiviert. Es findet kein WorkTime-Versand statt.</p>'; }
        $content .= '<form method="get" class="form-grid"><label><span>Mitarbeiter</span><select name="user_id">' . $options . '</select></label><button class="button button-secondary">Versandstatus laden</button></form></section>';
        $csrf = $this->e($this->csrf->token());
        $rows = '';
        foreach ($events as $event) {
            $status = WorkTimeNotificationService::statusLabel((string) $event['status']);
            $retry = '';
            if (!empty($event['manual_retry_allowed']) && $this->policies->enabled()) {
                $retry = '<form method="post" action="/admin/work-time/notifications/' . (int) $event['id'] . '/retry" class="stack"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="request_id" value="' . bin2hex(random_bytes(16)) . '"><label><span>Begruendung der Wiederholung</span><input name="reason" minlength="3" maxlength="2000" required></label><button class="button button-secondary">Erneuten Versuch anfordern</button></form>';
            }
            $history = '<details><summary>Versuchs- und Statushistorie</summary><ul>';
            foreach ($event['attempt_history'] ?? [] as $attempt) {
                $history .= '<li>Versuch ' . (int) $attempt['attempt_number'] . ' · ' . $this->e((string) $attempt['created_at']) . ' · ' . $this->e(WorkTimeNotificationService::statusLabel((string) $attempt['status'])) . ($attempt['actor_user_id'] !== null ? ' · Akteur #' . (int) $attempt['actor_user_id'] : ' · Automatisch') . ($attempt['retry_reason'] ? ' · ' . $this->e((string) $attempt['retry_reason']) : '') . '</li>';
            }
            foreach ($event['status_history'] ?? [] as $item) {
                $history .= '<li>' . $this->e((string) $item['created_at']) . ' · ' . $this->e(WorkTimeNotificationService::statusLabel((string) $item['new_status'])) . (!empty($item['actor_user_id']) ? ' · Akteur #' . (int) $item['actor_user_id'] : '') . (!empty($item['reason']) ? ' · ' . $this->e((string) $item['reason']) : '') . '</li>';
            }
            $history .= '</ul></details>';
            $rows .= '<tr><td>' . $this->e(match ($event['event_type']) { 'work_time_outside_window_started' => 'Arbeitsbeginn ausserhalb des Zeitfensters', 'work_time_checkout_proposal' => 'Vorschlag zum Arbeitsabschluss', default => (string) $event['event_type'] }) . '<br><span class="muted">' . $this->e((string) $event['event_key']) . '</span></td><td>' . $this->e((string) ($event['employee_name'] ?? 'Historischer Datensatz')) . '</td><td>' . $this->e((string) $event['recipient_name']) . '</td><td>' . $this->e((string) $event['channel']) . '</td><td>' . $this->e((string) $event['created_at']) . '<br>Letzter Versuch: ' . $this->e((string) ($event['last_attempt_at'] ?? '—')) . '<br>Providerannahme: ' . $this->e((string) ($event['accepted_at'] ?? '—')) . '</td><td>' . (int) $event['attempts'] . '</td><td>' . $this->e($status) . '</td><td>' . $this->e((string) ($event['error_message'] ?? '')) . $retry . $history . '</td></tr>';
        }
        $content .= '<section class="card stack"><h2>Letzte Versandereignisse</h2><div class="table-scroll"><table data-admin-table="work-time-notifications" data-table-label="Versandereignisse" data-table-search-placeholder="Ereignis, Mitarbeiter, Empfaenger, Status"><thead><tr><th>Ereignis</th><th>Mitarbeiter</th><th>Empfaenger</th><th>Kanal</th><th>Zeitpunkt</th><th>Versuche</th><th>Status</th><th>Fehler / Verlauf / Aktion</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="8">Keine Versandereignisse in diesem Bereich.</td></tr>') . '</tbody></table></div></section>';
        return Response::html($this->view->render('WorkTime Versandereignisse', '<div class="work-time-notifications-page">' . $content . '</div>'));
    }

    public function retry(Request $request, array $params): Response
    {
        $actor = $this->auth->currentUser();
        if ($actor === null || !$this->auth->hasPermission('work_time_notifications.manage')) { return Response::html('Keine Berechtigung fuer Versandereignisse.', 403); }
        if (!$this->policies->enabled()) { return Response::html('Arbeitszeitfenster sind deaktiviert.', 403); }
        $token = $request->input('csrf_token');
        if (!is_string($token) || !$this->csrf->isValid($token)) { return Response::html('Bitte die Seite neu laden und die Aktion erneut bestaetigen.', 403); }
        $event = $this->notifications->find((int) ($params['id'] ?? 0));
        if ($event === null) { return Response::html('Versandereignis nicht gefunden.', 404); }
        if (!$this->access->canManageUser($actor, (int) ($event['employee_user_id'] ?? 0), $event['work_date'] ?? null)) { return Response::html('Keine Berechtigung fuer diesen Mitarbeiter.', 403); }
        try {
            $reason = $request->input('reason'); $requestId = $request->input('request_id');
            if (!is_string($reason) || !is_string($requestId)) { throw new InvalidArgumentException('Begruendung und Request-ID muessen Text sein.'); }
            $this->notifications->requestRetry((int) $event['id'], (int) $actor['id'], $reason, $requestId);
            return Response::redirect('/admin/work-time/notifications?user_id=' . (int) $event['employee_user_id'] . '&saved=1');
        } catch (InvalidArgumentException $exception) {
            return Response::redirect('/admin/work-time/notifications?user_id=' . (int) $event['employee_user_id'] . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    private function canViewEmployee(array $actor, int $id): bool
    {
        if ($this->access->canManageUser($actor, $id)) { return true; }
        foreach ($this->policies->listAssignments($id) as $assignment) {
            if ($this->access->canManageUser($actor, $id, (string) $assignment['effective_from'])) { return true; }
        }
        return false;
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}
