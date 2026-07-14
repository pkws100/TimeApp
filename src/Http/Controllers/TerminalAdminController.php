<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Projects\ProjectService;
use App\Domain\Terminals\NfcTagService;
use App\Domain\Terminals\TerminalService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use RuntimeException;

final class TerminalAdminController
{
    public function __construct(
        private AdminView $view,
        private TerminalService $terminalService,
        private NfcTagService $nfcTagService,
        private UserService $userService,
        private ProjectService $projectService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->terminalService->featureEnabled()) {
            return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Terminal-Funktion ist deaktiviert.'));
        }

        $csrfToken = $this->e($this->csrfService->token());
        $terminals = $this->terminalService->list('active');
        $tags = $this->nfcTagService->list('all');
        $projects = $this->projectService->list('active');
        $users = $this->userService->list('active');
        $notice = $this->notice($request);
        $tokenNotice = $this->tokenNotice();

        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">NFC Zeiterfassung</p>
        <h1>Terminals</h1>
        <p>Stationaere LAN-Terminals, Token, Anlernmodus und NFC-Tag-Zuordnung.</p>
    </div>
</header>

{$notice}
{$tokenNotice}

<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Terminal anlegen</h2>
            <p class="muted">Der Token wird nur einmalig nach dem Speichern angezeigt.</p>
        </div>
    </div>
    <form method="post" action="/admin/terminals" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label><span>Terminal-ID</span><input name="terminal_identifier" placeholder="terminal-empfang" required></label>
        <label><span>Name</span><input name="name" placeholder="Empfang" required></label>
        <label><span>IP-Allowlist</span><input name="ip_allowlist" placeholder="192.168.1.50"></label>
        <label><span>Willkommenstext</span><input name="welcome_text" value="Willkommen"></label>
        <label><span>Projekt</span>{$this->projectSelect('default_project_id', null, $projects, true)}</label>
        <label><span>Aktiv</span><select name="is_active"><option value="1">Aktiv</option><option value="0">Inaktiv</option></select></label>
        <label class="full-span"><span>Settings JSON</span><textarea name="settings_json" rows="4" placeholder='{"lcd_hold_ms":15000}'></textarea></label>
        <div class="toolbar-actions full-span"><button class="button" type="submit">Terminal anlegen</button></div>
    </form>
</section>

<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Terminals</h2>
            <p class="muted">Terminal-Projekt hat Vorrang vor dem NFC-Tag-Projekt.</p>
        </div>
    </div>
    {$this->terminalTable($terminals, $projects, $csrfToken)}
</section>

<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>NFC-Tags</h2>
            <p class="muted">UIDs werden gehasht gespeichert; sichtbar bleibt nur eine maskierte Darstellung.</p>
        </div>
    </div>
    {$this->tagTable($tags, $users, $projects, $csrfToken)}
</section>
HTML;

        return Response::html($this->view->render('Terminals', $content));
    }

    public function store(Request $request): Response
    {
        if (!$this->featureEnabled()) {
            return $this->disabledRedirect();
        }

        if (!$this->validCsrf($request)) {
            return $this->redirectError('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.');
        }

        try {
            $result = $this->terminalService->create($request->input());
            $_SESSION['terminal_plain_token'] = (string) ($result['plain_token'] ?? '');

            return Response::redirect('/admin/terminals?notice=created');
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    public function update(Request $request, array $params): Response
    {
        if (!$this->featureEnabled()) {
            return $this->disabledRedirect();
        }

        if (!$this->validCsrf($request)) {
            return $this->redirectError('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.');
        }

        try {
            $this->terminalService->update((int) ($params['id'] ?? 0), $request->input());

            return Response::redirect('/admin/terminals?notice=updated');
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    public function archive(Request $request, array $params): Response
    {
        if (!$this->featureEnabled()) {
            return $this->disabledRedirect();
        }

        if (!$this->validCsrf($request)) {
            return $this->redirectError('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.');
        }

        $this->terminalService->archive((int) ($params['id'] ?? 0), $this->currentUserId());

        return Response::redirect('/admin/terminals?notice=archived');
    }

    public function resetToken(Request $request, array $params): Response
    {
        if (!$this->featureEnabled()) {
            return $this->disabledRedirect();
        }

        if (!$this->validCsrf($request)) {
            return $this->redirectError('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.');
        }

        try {
            $_SESSION['terminal_plain_token'] = $this->terminalService->resetToken((int) ($params['id'] ?? 0));

            return Response::redirect('/admin/terminals?notice=token-reset');
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    public function learn(Request $request, array $params): Response
    {
        if (!$this->featureEnabled()) {
            return $this->disabledRedirect();
        }

        if (!$this->validCsrf($request)) {
            return $this->redirectError('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.');
        }

        try {
            $this->nfcTagService->startLearnSession((int) ($params['id'] ?? 0), $this->currentUserId());

            return Response::redirect('/admin/terminals?notice=learn-started');
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    public function updateTag(Request $request, array $params): Response
    {
        if (!$this->featureEnabled()) {
            return $this->disabledRedirect();
        }

        if (!$this->validCsrf($request)) {
            return $this->redirectError('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.');
        }

        try {
            $this->nfcTagService->updateAssignment((int) ($params['id'] ?? 0), $request->input());

            return Response::redirect('/admin/terminals?notice=tag-updated');
        } catch (\Throwable $exception) {
            return $this->redirectError($exception->getMessage());
        }
    }

    public function archiveTag(Request $request, array $params): Response
    {
        if (!$this->featureEnabled()) {
            return $this->disabledRedirect();
        }

        if (!$this->validCsrf($request)) {
            return $this->redirectError('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.');
        }

        $this->nfcTagService->archive((int) ($params['id'] ?? 0), $this->currentUserId());

        return Response::redirect('/admin/terminals?notice=tag-archived');
    }

    private function terminalTable(array $terminals, array $projects, string $csrfToken): string
    {
        if ($terminals === []) {
            return '<p class="table-empty">Noch keine aktiven Terminals angelegt.</p>';
        }

        $rows = '';

        foreach ($terminals as $terminal) {
            $id = (int) ($terminal['id'] ?? 0);
            $rows .= '<tr><td>'
                . '<form method="post" action="/admin/terminals/' . $id . '" class="terminal-row-form">'
                . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                . '<div class="terminal-row-grid">'
                . '<label><span>ID</span><input name="terminal_identifier" value="' . $this->e((string) ($terminal['terminal_identifier'] ?? '')) . '" required></label>'
                . '<label><span>Name</span><input name="name" value="' . $this->e((string) ($terminal['name'] ?? '')) . '" required></label>'
                . '<label><span>IP-Allowlist</span><input name="ip_allowlist" value="' . $this->e((string) ($terminal['ip_allowlist'] ?? '')) . '"></label>'
                . '<label><span>Willkommen</span><input name="welcome_text" value="' . $this->e((string) ($terminal['welcome_text'] ?? 'Willkommen')) . '"></label>'
                . '<label><span>Projekt</span>' . $this->projectSelect('default_project_id', isset($terminal['default_project_id']) ? (int) $terminal['default_project_id'] : null, $projects, true) . '</label>'
                . '<label><span>Status</span><select name="is_active">' . $this->option('1', 'Aktiv', (int) ($terminal['is_active'] ?? 1) === 1) . $this->option('0', 'Inaktiv', (int) ($terminal['is_active'] ?? 1) !== 1) . '</select></label>'
                . '<label class="full-span"><span>Settings JSON</span><textarea name="settings_json" rows="3">' . $this->e((string) ($terminal['settings_json'] ?? '')) . '</textarea></label>'
                . '</div>'
                . '<p class="muted">Letzter Kontakt: ' . $this->e((string) ($terminal['last_seen_at'] ?? 'nie')) . ' ' . $this->e((string) ($terminal['last_seen_ip'] ?? '')) . '<br>Firmware/Transport: ' . $this->e((string) ($terminal['last_firmware_version'] ?? '-')) . ' / ' . $this->e((string) ($terminal['last_transport'] ?? '-')) . '<br>TLS/Trust: ' . $this->e((string) ($terminal['last_tls_state'] ?? '-')) . ' / v' . $this->e((string) ($terminal['active_trust_bundle_version'] ?? '0')) . ' (' . $this->e((string) ($terminal['trust_warning_state'] ?? '-')) . '), geprüft: ' . $this->e((string) ($terminal['last_trust_checked_at'] ?? 'nie')) . '<br>Offline-Queue: ' . $this->e((string) ($terminal['offline_queue_depth'] ?? '-')) . '; HTTPS zuletzt: ' . $this->e((string) ($terminal['last_https_success_at'] ?? 'nie')) . '; Recovery: ' . $this->e((string) ($terminal['last_recovery_status'] ?? '-')) . '</p>'
                . '<button class="button" type="submit">Speichern</button>'
                . '</form>'
                . '<div class="table-actions">'
                . '<form method="post" action="/admin/terminals/' . $id . '/learn"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><button class="button button-secondary" type="submit">Tag anlernen</button></form>'
                . '<form method="post" action="/admin/terminals/' . $id . '/token-reset"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><button class="button button-secondary" type="submit">Token resetten</button></form>'
                . '<form method="post" action="/admin/terminals/' . $id . '/archive"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><button class="button button-danger" type="submit">Archivieren</button></form>'
                . '</div>'
                . '</td></tr>';
        }

        return '<div class="table-scroll"><table><thead><tr><th>Terminal</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function tagTable(array $tags, array $users, array $projects, string $csrfToken): string
    {
        if ($tags === []) {
            return '<p class="table-empty">Noch keine NFC-Tags erfasst.</p>';
        }

        $rows = '';

        foreach ($tags as $tag) {
            $id = (int) ($tag['id'] ?? 0);
            $deleted = (int) ($tag['is_deleted'] ?? 0) === 1;
            $rows .= '<tr>'
                . '<td>' . $this->e((string) ($tag['uid_masked'] ?? '')) . '<br><span class="muted">' . $this->e((string) ($tag['learned_terminal_name'] ?? '')) . '</span></td>'
                . '<td><form method="post" action="/admin/terminals/tags/' . $id . '" class="stack">'
                . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                . '<label><span>Label</span><input name="label" value="' . $this->e((string) ($tag['label'] ?? '')) . '" placeholder="Buerotag Max"></label>'
                . '<label><span>User</span>' . $this->userSelect('user_id', isset($tag['user_id']) ? (int) $tag['user_id'] : null, $users) . '</label>'
                . '<label><span>Projekt</span>' . $this->projectSelect('project_id', isset($tag['project_id']) ? (int) $tag['project_id'] : null, $projects, true) . '</label>'
                . '<label><span>Status</span><select name="status">' . $this->statusOptions((string) ($tag['status'] ?? 'pending')) . '</select></label>'
                . '<button class="button" type="submit">Speichern</button></form><div class="table-actions">'
                . ($deleted ? '<span class="badge warn">Archiviert</span>' : '<form method="post" action="/admin/terminals/tags/' . $id . '/archive"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><button class="button button-danger" type="submit">Archivieren</button></form>')
                . '</div></td>'
                . '</tr>';
        }

        return '<div class="table-scroll"><table><thead><tr><th>Tag</th><th>Zuordnung</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    private function userSelect(string $name, ?int $selectedId, array $users): string
    {
        $html = '<select name="' . $this->e($name) . '"><option value="">User waehlen</option>';

        foreach ($users as $user) {
            $label = trim((string) ($user['employee_number'] ?? '') . ' ' . (string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $html .= $this->option((string) (int) $user['id'], $label, $selectedId === (int) $user['id']);
        }

        return $html . '</select>';
    }

    private function projectSelect(string $name, ?int $selectedId, array $projects, bool $allowEmpty): string
    {
        $html = '<select name="' . $this->e($name) . '">';

        if ($allowEmpty) {
            $html .= '<option value="">Kein Projekt</option>';
        }

        foreach ($projects as $project) {
            $label = trim((string) ($project['project_number'] ?? '') . ' ' . (string) ($project['name'] ?? ''));
            $html .= $this->option((string) (int) $project['id'], $label, $selectedId === (int) $project['id']);
        }

        return $html . '</select>';
    }

    private function statusOptions(string $selected): string
    {
        return $this->option('pending', 'Offen', $selected === 'pending')
            . $this->option('active', 'Aktiv', $selected === 'active')
            . $this->option('disabled', 'Gesperrt', $selected === 'disabled');
    }

    private function option(string $value, string $label, bool $selected): string
    {
        return '<option value="' . $this->e($value) . '"' . ($selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
    }

    private function tokenNotice(): string
    {
        $token = (string) ($_SESSION['terminal_plain_token'] ?? '');
        unset($_SESSION['terminal_plain_token']);

        if ($token === '') {
            return '';
        }

        return '<p class="notice success"><strong>Terminal-Token:</strong> <code>' . $this->e($token) . '</code> Jetzt in die Terminal-Firmware uebernehmen; er wird nicht erneut angezeigt.</p>';
    }

    private function notice(Request $request): string
    {
        $error = trim((string) $request->query('error', ''));

        if ($error !== '') {
            return '<p class="notice error">' . $this->e(urldecode($error)) . '</p>';
        }

        return match ((string) $request->query('notice', '')) {
            'created' => '<p class="notice success">Terminal wurde angelegt.</p>',
            'updated' => '<p class="notice success">Terminal wurde gespeichert.</p>',
            'archived' => '<p class="notice success">Terminal wurde archiviert.</p>',
            'token-reset' => '<p class="notice success">Token wurde erneuert.</p>',
            'learn-started' => '<p class="notice success">Anlernmodus ist fuer 2 Minuten aktiv. Bitte NFC-Tag am Terminal vorhalten.</p>',
            'tag-updated' => '<p class="notice success">NFC-Tag wurde gespeichert.</p>',
            'tag-archived' => '<p class="notice success">NFC-Tag wurde archiviert.</p>',
            default => '',
        };
    }

    private function redirectError(string $message): Response
    {
        return Response::redirect('/admin/terminals?error=' . rawurlencode($message));
    }

    private function featureEnabled(): bool
    {
        return $this->terminalService->featureEnabled();
    }

    private function disabledRedirect(): Response
    {
        return Response::redirect('/admin/settings/company?error=' . rawurlencode('Die Terminal-Funktion ist deaktiviert.'));
    }

    private function validCsrf(Request $request): bool
    {
        return $this->csrfService->isValid((string) $request->input('csrf_token', ''));
    }

    private function currentUserId(): ?int
    {
        $user = $this->authService->currentUser();

        return is_array($user) ? (int) ($user['id'] ?? 0) : null;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
