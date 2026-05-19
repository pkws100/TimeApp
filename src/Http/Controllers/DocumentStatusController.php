<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Files\DocumentStatusService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use InvalidArgumentException;
use RuntimeException;

final class DocumentStatusController
{
    public function __construct(
        private AdminView $view,
        private DocumentStatusService $documentStatusService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function index(Request $request): Response
    {
        $csrfToken = $this->e($this->csrfService->token());
        $rows = $this->statusRows($this->documentStatusService->list('all'), $csrfToken);
        $notice = $this->notice($request);

        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Datei-Settings</p>
        <h1>Dokumentstatusprofile</h1>
        <p>Statusprofile fuer hochgeladene Bilder und Dokumente in Projekten, Geraeten und Buchungen.</p>
    </div>
</header>
{$this->settingsTabs('document-statuses')}
{$notice}
<section class="card stack">
    <div>
        <h2>Neuen Status anlegen</h2>
        <p class="muted">Genau ein aktiver Status ist der Default fuer neue Uploads.</p>
    </div>
    <form method="post" action="/admin/settings/document-statuses" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <label><span>Name</span><input name="label" required placeholder="Unbearbeitet"></label>
        <label><span>Farbe</span><input type="color" name="color" value="#64748b"></label>
        <label><span>Sortierung</span><input type="number" name="sort_order" value="100" step="1"></label>
        <label class="checkbox-item"><input type="checkbox" name="is_default" value="1"> <span>Als Default fuer neue Uploads verwenden</span></label>
        <button class="button" type="submit">Status anlegen</button>
    </form>
</section>
<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Vorhandene Status</h2>
            <p class="muted">Archivierte Status bleiben an alten Dateien lesbar, sind aber nicht mehr fuer neue Auswahl gedacht.</p>
        </div>
    </div>
    <div class="table-scroll">
        <table><thead><tr><th>Status</th><th>Farbe</th><th>Sortierung</th><th>Default</th><th>Archiv</th><th>Aktionen</th></tr></thead><tbody>{$rows}</tbody></table>
    </div>
</section>
HTML;

        return Response::html($this->view->render('Dokumentstatusprofile', $content));
    }

    public function create(Request $request): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/document-statuses?error=csrf');
        }

        try {
            $this->documentStatusService->save($request->input());

            return Response::redirect('/admin/settings/document-statuses?notice=created');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::redirect('/admin/settings/document-statuses?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function update(Request $request, array $params): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/document-statuses?error=csrf');
        }

        try {
            $this->documentStatusService->save($request->input(), (int) ($params['id'] ?? 0));

            return Response::redirect('/admin/settings/document-statuses?notice=updated');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::redirect('/admin/settings/document-statuses?error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function archive(Request $request, array $params): Response
    {
        if (!$this->hasValidCsrfToken($request)) {
            return Response::redirect('/admin/settings/document-statuses?error=csrf');
        }

        try {
            $user = $this->authService->currentUser();
            $this->documentStatusService->archive((int) ($params['id'] ?? 0), $user !== null ? (int) $user['id'] : null);

            return Response::redirect('/admin/settings/document-statuses?notice=archived');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::redirect('/admin/settings/document-statuses?error=' . rawurlencode($exception->getMessage()));
        }
    }

    private function statusRows(array $statuses, string $csrfToken): string
    {
        if ($statuses === []) {
            return '<tr><td colspan="6" class="table-empty">Noch keine Dokumentstatusprofile vorhanden.</td></tr>';
        }

        $rows = '';

        foreach ($statuses as $status) {
            $id = (int) ($status['id'] ?? 0);
            $isArchived = (int) ($status['is_deleted'] ?? 0) === 1;
            $isDefault = (bool) ($status['is_default'] ?? false);
            $badge = '<span class="document-status-badge" style="--document-status-color: ' . $this->e((string) ($status['color'] ?? '#64748b')) . '">' . $this->e((string) ($status['label'] ?? '')) . '</span>';
            $archiveForm = $isArchived
                ? '<span class="muted">Archiviert</span>'
                : '<form method="post" action="/admin/settings/document-statuses/' . $id . '/archive" class="inline-form">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                    . '<button class="button button-danger" type="submit">Archivieren</button>'
                    . '</form>';
            $defaultLabel = $isDefault ? '<span class="badge ok">Default</span>' : '<span class="muted">-</span>';
            $archivedLabel = $isArchived ? '<span class="badge warn">Archiviert</span>' : '<span class="badge ok">Aktiv</span>';
            $disabled = $isArchived ? ' disabled' : '';
            $checked = $isDefault ? ' checked' : '';

            $rows .= '<tr>'
                . '<td>' . $badge . '<br><span class="muted">' . $this->e((string) ($status['slug'] ?? '')) . '</span></td>'
                . '<td><input form="document-status-form-' . $id . '" type="color" name="color" value="' . $this->e((string) ($status['color'] ?? '#64748b')) . '"' . $disabled . '></td>'
                . '<td><input form="document-status-form-' . $id . '" type="number" name="sort_order" value="' . $this->e((string) ($status['sort_order'] ?? 100)) . '" step="1"' . $disabled . '></td>'
                . '<td>' . $defaultLabel . '</td>'
                . '<td>' . $archivedLabel . '</td>'
                . '<td class="table-actions">'
                . '<form id="document-status-form-' . $id . '" method="post" action="/admin/settings/document-statuses/' . $id . '" class="inline-form">'
                . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
                . '<input name="label" value="' . $this->e((string) ($status['label'] ?? '')) . '"' . $disabled . '>'
                . '<label class="checkbox-inline"><input type="checkbox" name="is_default" value="1"' . $checked . $disabled . '> Default</label>'
                . '<button class="button button-secondary" type="submit"' . $disabled . '>Speichern</button>'
                . '</form>'
                . $archiveForm
                . '</td>'
                . '</tr>';
        }

        return $rows;
    }

    private function hasValidCsrfToken(Request $request): bool
    {
        return $this->csrfService->isValid((string) $request->input('csrf_token', ''));
    }

    private function settingsTabs(string $active): string
    {
        $links = [
            'company' => ['/admin/settings/company', 'Settings'],
            'calendar' => ['/admin/settings/calendar', 'Kalender'],
            'document-statuses' => ['/admin/settings/document-statuses', 'Datei-Status'],
            'database' => ['/admin/settings/database', 'Datenbank'],
            'push' => ['/admin/settings/push', 'Push'],
        ];
        $html = '<section class="section-toolbar"><div class="scope-switch">';

        foreach ($links as $key => [$href, $label]) {
            $class = $active === $key ? 'scope-link is-active' : 'scope-link';
            $html .= '<a class="' . $class . '" href="' . $href . '">' . $label . '</a>';
        }

        return $html . '</div></section>';
    }

    private function notice(Request $request): string
    {
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            $message = $error === 'csrf'
                ? 'Die Aktion konnte nicht bestaetigt werden. Bitte Seite neu laden.'
                : urldecode($error);

            return '<p class="notice error">' . $this->e($message) . '</p>';
        }

        return match ((string) $request->query('notice', '')) {
            'created' => '<p class="notice success">Der Dokumentstatus wurde angelegt.</p>',
            'updated' => '<p class="notice success">Der Dokumentstatus wurde gespeichert.</p>',
            'archived' => '<p class="notice success">Der Dokumentstatus wurde archiviert.</p>',
            default => '',
        };
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
