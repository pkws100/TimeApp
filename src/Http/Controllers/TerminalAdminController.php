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
        $terminalScope = $this->terminalScope($request);
        $terminals = $this->terminalService->list($terminalScope);
        $tagScope = $this->tagScope($request);
        $tags = $this->nfcTagService->list($tagScope);
        $pendingTagCount = count(array_filter($this->nfcTagService->list('pending'), static fn (array $tag): bool => (int) ($tag['is_deleted'] ?? 0) === 0));
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
            <h2>{$this->terminalHeading($terminalScope)}</h2>
            <p class="muted">Terminal-Projekt hat Vorrang vor dem NFC-Tag-Projekt.</p>
        </div>
        <div class="scope-switch" aria-label="Terminal-Ansicht">
            <a class="button button-secondary{$this->scopeClass($terminalScope, 'active')}" href="/admin/terminals">Aktuelle Terminals</a>
            <a class="button button-secondary{$this->scopeClass($terminalScope, 'archived')}" href="/admin/terminals?terminal_scope=archived">Archivierte Terminals anzeigen</a>
        </div>
    </div>
    {$this->terminalTable($terminals, $projects, $csrfToken, $terminalScope)}
</section>

<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>NFC-Tags{$this->pendingTagHeading($pendingTagCount)}</h2>
            <p class="muted">UIDs werden gehasht gespeichert; sichtbar bleibt nur eine maskierte Darstellung. Offene Tags sind noch nicht terminaltauglich.</p>
        </div>
        <div class="scope-switch" aria-label="NFC-Tag-Ansicht">
            <a class="button button-secondary{$this->scopeClass($tagScope, 'active')}" href="/admin/terminals">Aktuelle Tags</a>
            <a class="button button-secondary{$this->scopeClass($tagScope, 'archived')}" href="/admin/terminals?scope=archived">Archivierte Tags anzeigen</a>
        </div>
    </div>
    {$this->tagTable($tags, $users, $projects, $csrfToken, $tagScope)}
    {$this->tagModal($tags, $users, $projects, $csrfToken, $tagScope, $request)}
</section>
HTML;

        return Response::html($this->view->render('Terminals', $content, '<script src="/assets/js/admin-terminals.js"></script>'));
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

        return Response::redirect('/admin/terminals?terminal_scope=archived&notice=archived');
    }

    public function restore(Request $request, array $params): Response
    {
        if (!$this->featureEnabled()) {
            return $this->disabledRedirect();
        }

        if (!$this->validCsrf($request)) {
            return $this->redirectError('Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.');
        }

        if (!$this->terminalService->restore((int) ($params['id'] ?? 0))) {
            return $this->redirectError('Das Terminal ist nicht archiviert oder nicht mehr vorhanden.');
        }

        return Response::redirect('/admin/terminals?notice=restored');
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

            return Response::redirect($this->tagRedirect('tag-updated', $this->tagScopeFromInput($request)));
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

        if (!$this->nfcTagService->archive((int) ($params['id'] ?? 0), $this->currentUserId())) {
            return $this->redirectError('Der NFC-Tag ist nicht mehr aktiv oder nicht vorhanden.');
        }

        return Response::redirect($this->tagRedirect('tag-archived', $this->tagScopeFromInput($request)));
    }

    private function terminalTable(array $terminals, array $projects, string $csrfToken, string $scope): string
    {
        $archived = $scope === 'archived';
        if ($terminals === []) {
            return '<p class="table-empty">' . ($archived ? 'Keine archivierten Terminals vorhanden.' : 'Noch keine aktuellen Terminals angelegt.') . '</p>';
        }

        $rows = '';

        foreach ($terminals as $terminal) {
            $id = (int) ($terminal['id'] ?? 0);
            if ($archived) {
                $terminalLabel = trim((string) ($terminal['terminal_identifier'] ?? '') . ' ' . (string) ($terminal['name'] ?? ''));
                $rows .= '<tr><td><strong>' . $this->e((string) ($terminal['terminal_identifier'] ?? '')) . '</strong><br><span class="muted">' . $this->e((string) ($terminal['name'] ?? '')) . '</span><p class="muted">Archiviert am: ' . $this->e((string) ($terminal['deleted_at'] ?? 'unbekannt')) . '</p><div class="table-actions"><form method="post" action="/admin/terminals/' . $id . '/restore"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><button class="button" type="submit" aria-label="' . $this->e('Terminal ' . $terminalLabel . ' wiederherstellen') . '">Wiederherstellen</button></form></div></td></tr>';
                continue;
            }
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

    private function tagTable(array $tags, array $users, array $projects, string $csrfToken, string $scope): string
    {
        $rows = '';
        $archived = $scope === 'archived';

        foreach ($tags as $tag) {
            $id = (int) ($tag['id'] ?? 0);
            $pending = (string) ($tag['status'] ?? '') === 'pending';
            $rowData = $this->tagRowData($tag);
            $editUrl = '/admin/terminals?' . http_build_query(['scope' => $scope, 'tag_id' => $id, 'modal' => 'edit']);
            $status = $archived
                ? '<span class="badge warn">Archiviert</span>'
                : $this->tagStatusBadge((string) ($tag['status'] ?? 'pending'));
            $reuseHint = !$archived && trim((string) ($tag['relearned_from_archive_at'] ?? '')) !== ''
                ? '<br><span class="badge warn">Bereits verwendet</span>'
                : '';
            $userLabel = trim((string) ($tag['employee_number'] ?? '') . ' ' . (string) ($tag['user_name'] ?? ''));
            $projectLabel = trim((string) ($tag['project_number'] ?? '') . ' ' . (string) ($tag['project_name'] ?? ''));
            $learnedAt = (string) ($tag['learned_at'] ?? '');
            $rowClasses = 'terminal-tag-row' . ($pending && !$archived ? ' has-terminal-tag-issue' : '');
            $actions = $archived
                ? '<span class="badge warn">Archiviert</span>'
                : '<a class="button button-secondary" data-terminal-tag-open aria-haspopup="dialog" href="' . $this->e($editUrl) . '">Bearbeiten</a>';
            $openable = $archived ? '' : ' data-terminal-tag-openable="1" tabindex="0"';

            $rows .= '<tr class="' . $rowClasses . '"' . ($archived ? '' : ' data-terminal-tag-row data-terminal-tag="' . $this->dataJson($rowData) . '"') . $openable . '>'
                . '<td><strong>' . $this->e((string) ($tag['uid_masked'] ?? '')) . '</strong></td>'
                . '<td>' . ($this->e((string) ($tag['label'] ?? '')) ?: '<span class="muted">Nicht benannt</span>') . '</td>'
                . '<td>' . $status . $reuseHint . '</td>'
                . '<td>' . ($this->e($userLabel) ?: '<span class="muted">Nicht zugeordnet</span>') . '</td>'
                . '<td>' . ($this->e($projectLabel) ?: '<span class="muted">Kein Projekt</span>') . '</td>'
                . '<td>' . ($this->e((string) ($tag['learned_terminal_name'] ?? 'Unbekannt')) . ($learnedAt !== '' ? '<br><span class="muted">' . $this->e($learnedAt) . '</span>' : '')) . '</td>'
                . '<td class="table-actions" data-search="false" data-sort="false">' . $actions . '</td>'
                . '</tr>';
        }

        $empty = $archived ? 'Keine archivierten NFC-Tags vorhanden.' : 'Noch keine aktuellen NFC-Tags erfasst.';
        $body = $rows !== '' ? $rows : '<tr><td colspan="7" class="table-empty">' . $empty . '</td></tr>';

        return '<div class="table-scroll"><table class="terminal-tag-table" data-admin-table="terminal-tags" data-table-label="NFC-Tags" data-table-search-placeholder="UID, Label, Mitarbeiter, Projekt, Status"><thead><tr><th>Tag</th><th>Label</th><th>Status</th><th>Mitarbeiter</th><th>Projekt</th><th>Angelernt</th><th data-search="false" data-sort="false">Aktionen</th></tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    private function tagModal(array $tags, array $users, array $projects, string $csrfToken, string $scope, Request $request): string
    {
        if ($scope === 'archived') {
            return '';
        }

        $selectedId = (int) $request->query('tag_id', 0);
        $selected = null;

        if ($selectedId > 0 && (string) $request->query('modal', '') === 'edit') {
            foreach ($tags as $tag) {
                if ((int) ($tag['id'] ?? 0) === $selectedId) {
                    $selected = $tag;
                    break;
                }
            }
        }

        $hidden = $selected === null ? ' hidden' : '';
        $ariaHidden = $selected === null ? 'true' : 'false';
        $id = (int) ($selected['id'] ?? 0);
        $uid = $this->e((string) ($selected['uid_masked'] ?? '-'));
        $terminal = $this->e((string) ($selected['learned_terminal_name'] ?? 'Unbekannt'));
        $learnedAt = $this->e((string) ($selected['learned_at'] ?? 'Nicht bekannt'));
        $status = $this->tagStatusBadge((string) ($selected['status'] ?? 'pending'));
        $relearnedAt = trim((string) ($selected['relearned_from_archive_at'] ?? ''));
        $relearnWarning = '<p id="terminalTagModalReuseWarning" class="notice warn" role="status" data-terminal-tag-modal-relearn-warning' . ($relearnedAt === '' ? ' hidden' : '') . '>Dieser NFC-Tag war bereits archiviert und wurde am <strong data-terminal-tag-modal-relearned-at>' . $this->e($relearnedAt !== '' ? $relearnedAt : '-') . '</strong> erneut angelernt. Zuordnung, Projekt und Status bitte bewusst prüfen.</p>';
        $relearnDescribedBy = $relearnedAt !== '' ? ' aria-describedby="terminalTagModalReuseWarning"' : '';
        $disabled = '';
        $save = '<button class="button" type="submit">Speichern</button>';
        $archiveAction = $id > 0 ? '/admin/terminals/tags/' . $id . '/archive' : '';
        $archiveHidden = $id > 0 ? '' : ' hidden';
        $archive = '<div class="admin-modal__divider" data-terminal-tag-archive-section' . $archiveHidden . '></div><form method="post" action="' . $archiveAction . '" data-terminal-tag-archive-form class="admin-modal__danger-actions"' . $archiveHidden . '><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><input type="hidden" name="scope" value="' . $this->e($scope) . '"><p class="muted">Archivierte Tags werden aus der Standardansicht ausgeblendet und sind nicht mehr terminaltauglich.</p><button class="button button-danger" type="submit" data-terminal-tag-archive-button onclick="return confirm(\'NFC-Tag wirklich archivieren?\')">Tag archivieren</button></form>';

        return '<div class="admin-modal" data-terminal-tag-modal' . $hidden . ' aria-hidden="' . $ariaHidden . '">'
            . '<div class="admin-modal__overlay" data-terminal-tag-modal-close></div>'
            . '<div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="terminalTagModalTitle"' . $relearnDescribedBy . '>'
            . '<div class="admin-modal__header"><div><p class="eyebrow">NFC-Tag</p><h2 id="terminalTagModalTitle">Tag bearbeiten</h2><p class="muted" data-terminal-tag-modal-status>' . $status . '</p></div><a href="/admin/terminals" class="button button-secondary admin-modal__close" data-terminal-tag-modal-close>Schliessen</a></div>'
            . '<div class="admin-modal__summary"><div class="admin-modal__summary-card"><span class="muted">Maskierte UID</span><strong data-terminal-tag-modal-uid>' . $uid . '</strong></div><div class="admin-modal__summary-card"><span class="muted">Angelernt am Terminal</span><strong data-terminal-tag-modal-terminal>' . $terminal . '</strong><span class="muted" data-terminal-tag-modal-learned-at>' . $learnedAt . '</span></div></div>'
            . $relearnWarning
            . '<form method="post" action="' . ($id > 0 ? '/admin/terminals/tags/' . $id : '') . '" data-terminal-tag-update-form class="stack"><input type="hidden" name="csrf_token" value="' . $csrfToken . '"><input type="hidden" name="scope" value="' . $this->e($scope) . '"><div class="form-grid"><label><span>Label</span><input name="label" value="' . $this->e((string) ($selected['label'] ?? '')) . '" placeholder="Buerotag Max"' . $disabled . '></label><label><span>User</span>' . $this->userSelect('user_id', isset($selected['user_id']) ? (int) $selected['user_id'] : null, $users) . '</label><label><span>Projekt</span>' . $this->projectSelect('project_id', isset($selected['project_id']) ? (int) $selected['project_id'] : null, $projects, true) . '</label><label><span>Status</span><select name="status"' . $disabled . '>' . $this->statusOptions((string) ($selected['status'] ?? 'pending')) . '</select></label></div><div class="table-actions">' . $save . '</div></form>' . $archive
            . '</div></div>';
    }

    private function tagRowData(array $tag): array
    {
        return [
            'id' => (int) ($tag['id'] ?? 0),
            'uid_masked' => (string) ($tag['uid_masked'] ?? ''),
            'label' => (string) ($tag['label'] ?? ''),
            'user_id' => $tag['user_id'] ?? null,
            'project_id' => $tag['project_id'] ?? null,
            'status' => (string) ($tag['status'] ?? 'pending'),
            'learned_terminal_name' => (string) ($tag['learned_terminal_name'] ?? ''),
            'learned_at' => (string) ($tag['learned_at'] ?? ''),
            'relearned_from_archive_at' => (string) ($tag['relearned_from_archive_at'] ?? ''),
            'is_deleted' => (int) ($tag['is_deleted'] ?? 0),
        ];
    }

    private function tagStatusBadge(string $status): string
    {
        return match ($status) {
            'active' => '<span class="badge ok">Aktiv</span>',
            'disabled' => '<span class="badge warn">Gesperrt</span>',
            default => '<span class="badge warn">Konfiguration erforderlich</span>',
        };
    }

    private function tagScope(Request $request): string
    {
        return (string) $request->query('scope', '') === 'archived' ? 'archived' : 'active';
    }

    private function terminalScope(Request $request): string
    {
        return (string) $request->query('terminal_scope', '') === 'archived' ? 'archived' : 'active';
    }

    private function terminalHeading(string $scope): string
    {
        return $scope === 'archived' ? 'Archivierte Terminals' : 'Terminals';
    }

    private function tagScopeFromInput(Request $request): string
    {
        return (string) $request->input('scope', '') === 'archived' ? 'archived' : 'active';
    }

    private function tagRedirect(string $notice, string $scope): string
    {
        $query = ['notice' => $notice];
        if ($scope === 'archived') {
            $query['scope'] = 'archived';
        }

        return '/admin/terminals?' . http_build_query($query);
    }

    private function pendingTagHeading(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return ' <span class="badge warn">' . $count . ' offen</span>';
    }

    private function scopeClass(string $scope, string $expected): string
    {
        return $scope === $expected ? ' is-active' : '';
    }

    private function dataJson(array $data): string
    {
        return $this->e((string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function userSelect(string $name, ?int $selectedId, array $users, bool $disabled = false): string
    {
        $html = '<select name="' . $this->e($name) . '"' . ($disabled ? ' disabled' : '') . '><option value="">User waehlen</option>';

        foreach ($users as $user) {
            $label = trim((string) ($user['employee_number'] ?? '') . ' ' . (string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $html .= $this->option((string) (int) $user['id'], $label, $selectedId === (int) $user['id']);
        }

        return $html . '</select>';
    }

    private function projectSelect(string $name, ?int $selectedId, array $projects, bool $allowEmpty, bool $disabled = false): string
    {
        $html = '<select name="' . $this->e($name) . '"' . ($disabled ? ' disabled' : '') . '>';

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
            'restored' => '<p class="notice success">Terminal wurde wiederhergestellt.</p>',
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
