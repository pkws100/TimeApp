<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assets\AssetService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Users\RoleService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;
use App\Presentation\Admin\BookingModalRenderer;
use InvalidArgumentException;
use RuntimeException;

final class AdminManagementController
{
    public function __construct(
        private AdminView $view,
        private ProjectService $projectService,
        private UserService $userService,
        private RoleService $roleService,
        private AssetService $assetService,
        private FileAttachmentService $fileAttachmentService,
        private AdminBookingService $bookingService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function projects(Request $request): Response
    {
        $scope = $this->scope($request);
        $projects = $this->projectService->list($scope);

        $rows = '';

        foreach ($projects as $project) {
            $trackedNetMinutes = max(0, (int) ($project['tracked_net_minutes'] ?? 0));
            $rows .= '<tr>'
                . '<td>' . $this->e((string) $project['project_number']) . '</td>'
                . '<td>' . $this->e((string) $project['name']) . '</td>'
                . '<td>' . $this->e((string) ($project['customer_name'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($project['status'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($project['city'] ?? '')) . '</td>'
                . '<td data-sort-value="' . $trackedNetMinutes . '">' . $this->e($this->formatProjectHours($trackedNetMinutes)) . '</td>'
                . '<td class="table-actions" data-search="false">'
                . '<a class="button" href="/admin/projects/' . (int) $project['id'] . '/edit">Bearbeiten</a>'
                . $this->projectLifecycleForm(
                    '/admin/projects/' . (int) $project['id'],
                    '/admin/projects/' . (int) $project['id'] . '/restore',
                    (int) ($project['is_deleted'] ?? 0) === 1
                )
                . '</td>'
                . '</tr>';
        }

        $content = $this->renderIndexLayout(
            'Projekte und Baustellen',
            'Projektverzeichnis',
            '/admin/projects/create',
            '/admin/projects',
            $scope,
            $this->notice($request),
            '<div class="table-scroll"><table data-admin-table="projects" data-table-label="Projekte"><thead><tr><th>Nummer</th><th>Name</th><th>Kunde</th><th>Status</th><th>Ort</th><th data-sort-type="number">Stunden</th><th data-search="false" data-sort="false">Aktionen</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="7" class="table-empty">Keine Projekte im aktuellen Filter.</td></tr>')
            . '</tbody></table></div>'
        );

        return Response::html($this->view->render('Projekte', $content));
    }

    public function projectCreate(Request $request): Response
    {
        return Response::html($this->view->render('Projekt anlegen', $this->renderProjectForm('/admin/projects', 'Projekt anlegen')));
    }

    public function projectEdit(Request $request, array $params): Response
    {
        $project = $this->projectService->find((int) $params['id']);

        if ($project === null) {
            return Response::html($this->notFoundMarkup('Projekt'), 404);
        }

        $files = $this->fileAttachmentService->listForProject((int) $project['id'], 'all');
        $bookings = [];
        $allProjects = [];
        $activeUsers = [];

        if ($this->authService->hasPermission('timesheets.view')) {
            $bookingFilters = $this->bookingService->normalizeFilters($request->query(), (int) $project['id']);
            $bookings = $this->bookingService->list($bookingFilters);
            $allProjects = $this->projectService->list('all');

            if ($this->authService->hasPermission('timesheets.manage')) {
                $activeUsers = $this->userService->list('active');
            }
        }

        $content = $this->renderProjectForm('/admin/projects/' . (int) $project['id'], 'Projekt bearbeiten', $project)
            . $this->renderProjectBookingsSection($project, $bookings, $allProjects, $activeUsers, $request)
            . $this->renderAttachmentSection('Projektanhaenge', '/admin/projects/' . (int) $project['id'] . '/files', $files, 'project');

        return Response::html($this->view->render('Projekt bearbeiten', $content));
    }

    public function projectStore(Request $request): Response
    {
        $project = $this->projectService->create($request->input());

        return Response::redirect('/admin/projects/' . (int) $project['id'] . '/edit?notice=created');
    }

    public function projectUpdate(Request $request, array $params): Response
    {
        $this->projectService->update((int) $params['id'], $request->input());

        return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?notice=updated');
    }

    public function projectArchive(Request $request, array $params): Response
    {
        $this->projectService->archive((int) $params['id'], $this->currentUserId());

        return Response::redirect('/admin/projects?notice=archived');
    }

    public function projectRestore(Request $request, array $params): Response
    {
        $this->projectService->restore((int) $params['id'], $this->currentUserId());

        return Response::redirect('/admin/projects?notice=restored');
    }

    public function projectBookingStore(Request $request, array $params): Response
    {
        $projectId = (int) ($params['id'] ?? 0);
        $returnTo = '/admin/projects/' . $projectId . '/edit';

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($returnTo . '?error=csrf');
        }

        try {
            $this->bookingService->createManual($request->input(), (int) ($this->currentUserId() ?? 0), $projectId);

            return Response::redirect($returnTo . '?notice=booking-created');
        } catch (InvalidArgumentException) {
            return Response::redirect($returnTo . '?error=booking-validation');
        }
    }

    public function projectFileStore(Request $request, array $params): Response
    {
        try {
            $file = $request->files()['file'] ?? null;

            if (!is_array($file)) {
                return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?error=no-file');
            }

            $this->fileAttachmentService->storeProject($file, (int) $params['id']);

            return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?notice=file-uploaded');
        } catch (RuntimeException) {
            return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?error=file-upload');
        }
    }

    public function projectFileArchive(Request $request, array $params): Response
    {
        $file = $this->fileAttachmentService->findProjectFile((int) $params['id']);

        if ($file !== null) {
            $this->fileAttachmentService->archiveProjectFile((int) $params['id']);

            return Response::redirect('/admin/projects/' . (int) $file['project_id'] . '/edit?notice=file-archived');
        }

        return Response::redirect('/admin/projects?error=file-not-found');
    }

    public function assets(Request $request): Response
    {
        $scope = $this->scope($request);
        $assets = $this->assetService->list($scope);
        $rows = '';

        foreach ($assets as $asset) {
            $rows .= '<tr>'
                . '<td>' . $this->e((string) $asset['asset_type']) . '</td>'
                . '<td>' . $this->e((string) $asset['name']) . '</td>'
                . '<td>' . $this->e((string) $asset['identifier']) . '</td>'
                . '<td>' . $this->e((string) ($asset['status'] ?? '')) . '</td>'
                . '<td class="table-actions">'
                . '<a class="button" href="/admin/assets/' . (int) $asset['id'] . '/edit">Bearbeiten</a>'
                . $this->archiveForm('/admin/assets/' . (int) $asset['id'], (int) ($asset['is_deleted'] ?? 0) === 1)
                . '</td>'
                . '</tr>';
        }

        $content = $this->renderIndexLayout(
            'Geraete und Fahrzeuge',
            'Geraeteverwaltung',
            '/admin/assets/create',
            '/admin/assets',
            $scope,
            $this->notice($request),
            '<table><thead><tr><th>Typ</th><th>Name</th><th>Identifier</th><th>Status</th><th>Aktionen</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="5" class="table-empty">Keine Geraete im aktuellen Filter.</td></tr>')
            . '</tbody></table>'
        );

        return Response::html($this->view->render('Geraete', $content));
    }

    public function assetCreate(Request $request): Response
    {
        return Response::html($this->view->render('Geraet anlegen', $this->renderAssetForm('/admin/assets', 'Geraet anlegen')));
    }

    public function assetEdit(Request $request, array $params): Response
    {
        $asset = $this->assetService->find((int) $params['id']);

        if ($asset === null) {
            return Response::html($this->notFoundMarkup('Geraet'), 404);
        }

        $files = $this->fileAttachmentService->listForAsset((int) $asset['id'], 'all');
        $content = $this->renderAssetForm('/admin/assets/' . (int) $asset['id'], 'Geraet bearbeiten', $asset)
            . $this->renderAttachmentSection('Geraeteanhaenge', '/admin/assets/' . (int) $asset['id'] . '/files', $files, 'asset');

        return Response::html($this->view->render('Geraet bearbeiten', $content));
    }

    public function assetStore(Request $request): Response
    {
        $asset = $this->assetService->create($request->input());

        return Response::redirect('/admin/assets/' . (int) $asset['id'] . '/edit?notice=created');
    }

    public function assetUpdate(Request $request, array $params): Response
    {
        $this->assetService->update((int) $params['id'], $request->input());

        return Response::redirect('/admin/assets/' . (int) $params['id'] . '/edit?notice=updated');
    }

    public function assetArchive(Request $request, array $params): Response
    {
        $this->assetService->archive((int) $params['id']);

        return Response::redirect('/admin/assets?notice=archived');
    }

    public function assetFileStore(Request $request, array $params): Response
    {
        try {
            $file = $request->files()['file'] ?? null;

            if (!is_array($file)) {
                return Response::redirect('/admin/assets/' . (int) $params['id'] . '/edit?error=no-file');
            }

            $this->fileAttachmentService->storeAsset($file, (int) $params['id']);

            return Response::redirect('/admin/assets/' . (int) $params['id'] . '/edit?notice=file-uploaded');
        } catch (RuntimeException) {
            return Response::redirect('/admin/assets/' . (int) $params['id'] . '/edit?error=file-upload');
        }
    }

    public function assetFileArchive(Request $request, array $params): Response
    {
        $file = $this->fileAttachmentService->findAssetFile((int) $params['id']);

        if ($file !== null) {
            $this->fileAttachmentService->archiveAssetFile((int) $params['id']);

            return Response::redirect('/admin/assets/' . (int) $file['asset_id'] . '/edit?notice=file-archived');
        }

        return Response::redirect('/admin/assets?error=file-not-found');
    }

    public function users(Request $request): Response
    {
        $scope = $this->scope($request);
        $users = $this->userService->list($scope);
        $rows = '';

        foreach ($users as $user) {
            $timeTrackingRequired = (int) ($user['time_tracking_required'] ?? 1) === 1;
            $timeTrackingBadge = $timeTrackingRequired
                ? '<span class="badge ok">Pflicht</span>'
                : '<span class="badge warn">freiwillig</span>';

            $rows .= '<tr>'
                . '<td>' . $this->e(trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')))) . '</td>'
                . '<td>' . $this->e((string) ($user['email'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($user['phone'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($user['employment_status'] ?? '')) . '</td>'
                . '<td>' . $timeTrackingBadge . '</td>'
                . '<td>' . $this->e((string) ($user['role_names'] ?? '')) . '</td>'
                . '<td class="table-actions">'
                . '<a class="button" href="/admin/users/' . (int) $user['id'] . '/edit">Bearbeiten</a>'
                . $this->archiveForm('/admin/users/' . (int) $user['id'], (int) ($user['is_deleted'] ?? 0) === 1)
                . '</td>'
                . '</tr>';
        }

        $content = $this->renderIndexLayout(
            'User und Mitarbeiter',
            'Benutzerverwaltung',
            '/admin/users/create',
            '/admin/users',
            $scope,
            $this->notice($request),
            '<div class="table-scroll"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Telefon</th><th>Status</th><th>Zeiterfassung</th><th>Rollen</th><th>Aktionen</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="7" class="table-empty">Keine User im aktuellen Filter.</td></tr>')
            . '</tbody></table></div>'
        );

        return Response::html($this->view->render('User', $content));
    }

    public function userCreate(Request $request): Response
    {
        $roles = $this->roleService->list('active');

        return Response::html($this->view->render('User anlegen', $this->renderUserForm('/admin/users', 'User anlegen', null, $roles)));
    }

    public function userEdit(Request $request, array $params): Response
    {
        $user = $this->userService->find((int) $params['id']);

        if ($user === null) {
            return Response::html($this->notFoundMarkup('User'), 404);
        }

        $roles = $this->roleService->list('active');

        return Response::html($this->view->render('User bearbeiten', $this->renderUserForm('/admin/users/' . (int) $user['id'], 'User bearbeiten', $user, $roles)));
    }

    public function userStore(Request $request): Response
    {
        try {
            $user = $this->userService->create($request->input());

            return Response::redirect('/admin/users/' . (int) $user['id'] . '/edit?notice=created');
        } catch (RuntimeException|\InvalidArgumentException) {
            return Response::redirect('/admin/users/create?error=validation');
        }
    }

    public function userUpdate(Request $request, array $params): Response
    {
        $this->userService->update((int) $params['id'], $request->input());

        return Response::redirect('/admin/users/' . (int) $params['id'] . '/edit?notice=updated');
    }

    public function userArchive(Request $request, array $params): Response
    {
        $this->userService->archive((int) $params['id']);

        return Response::redirect('/admin/users?notice=archived');
    }

    public function roles(Request $request): Response
    {
        $scope = $this->scope($request);
        $roles = $this->roleService->list($scope);
        $rows = '';

        foreach ($roles as $role) {
            $rows .= '<tr>'
                . '<td>' . $this->e((string) ($role['slug'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($role['name'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($role['description'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($role['permission_codes'] ?? '')) . '</td>'
                . '<td>' . (((int) ($role['is_system_role'] ?? 0) === 1) ? '<span class="badge ok">Systemrolle</span>' : '<span class="badge warn">Custom</span>') . '</td>'
                . '<td class="table-actions">'
                . '<a class="button" href="/admin/roles/' . (int) $role['id'] . '/edit">Bearbeiten</a>'
                . (((int) ($role['is_system_role'] ?? 0) === 1) ? '<span class="muted">Nicht archivieren</span>' : $this->archiveForm('/admin/roles/' . (int) $role['id'], (int) ($role['is_deleted'] ?? 0) === 1))
                . '</td>'
                . '</tr>';
        }

        $content = $this->renderIndexLayout(
            'Rollen und Rechte',
            'Rollenverwaltung',
            '/admin/roles/create',
            '/admin/roles',
            $scope,
            $this->notice($request),
            '<table><thead><tr><th>Slug</th><th>Name</th><th>Beschreibung</th><th>Rechte</th><th>Typ</th><th>Aktionen</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="6" class="table-empty">Keine Rollen im aktuellen Filter.</td></tr>')
            . '</tbody></table>'
        );

        return Response::html($this->view->render('Rollen', $content));
    }

    public function roleCreate(Request $request): Response
    {
        return Response::html($this->view->render('Rolle anlegen', $this->renderRoleForm('/admin/roles', 'Rolle anlegen')));
    }

    public function roleEdit(Request $request, array $params): Response
    {
        $role = $this->roleService->find((int) $params['id']);

        if ($role === null) {
            return Response::html($this->notFoundMarkup('Rolle'), 404);
        }

        return Response::html($this->view->render('Rolle bearbeiten', $this->renderRoleForm('/admin/roles/' . (int) $role['id'], 'Rolle bearbeiten', $role)));
    }

    public function roleStore(Request $request): Response
    {
        $role = $this->roleService->create($request->input());

        return Response::redirect('/admin/roles/' . (int) $role['id'] . '/edit?notice=created');
    }

    public function roleUpdate(Request $request, array $params): Response
    {
        $this->roleService->update((int) $params['id'], $request->input());

        return Response::redirect('/admin/roles/' . (int) $params['id'] . '/edit?notice=updated');
    }

    public function roleArchive(Request $request, array $params): Response
    {
        try {
            $this->roleService->archive((int) $params['id']);

            return Response::redirect('/admin/roles?notice=archived');
        } catch (RuntimeException) {
            return Response::redirect('/admin/roles?error=system-role');
        }
    }

    private function renderIndexLayout(
        string $title,
        string $eyebrow,
        string $createUrl,
        string $baseUrl,
        string $scope,
        string $notice,
        string $table
    ): string {
        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">{$this->e($eyebrow)}</p>
        <h1>{$this->e($title)}</h1>
        <p>Eintragen, bearbeiten und GoBD-konform archivieren.</p>
    </div>
    <div class="toolbar-actions">
        <a class="button" href="{$this->e($createUrl)}">Neu anlegen</a>
    </div>
</header>
{$notice}
<section class="section-toolbar">
    <div class="scope-switch">
        {$this->scopeLink($baseUrl, 'active', 'Aktiv', $scope)}
        {$this->scopeLink($baseUrl, 'archived', 'Archiviert', $scope)}
        {$this->scopeLink($baseUrl, 'all', 'Alle', $scope)}
    </div>
</section>
<section class="card">{$table}</section>
HTML;
    }

    private function renderProjectForm(string $action, string $title, ?array $project = null): string
    {
        $isEdit = $project !== null;
        $method = $isEdit ? '<input type="hidden" name="_method" value="PUT">' : '';
        $hint = $isEdit ? '' : '<p class="muted">Dateianhaenge koennen direkt nach dem ersten Speichern zugewiesen werden.</p>';

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Projektpflege</p>
        <h1>{$this->e($title)}</h1>
        {$hint}
    </div>
    <a class="button" href="/admin/projects">Zur Liste</a>
</header>
{$this->noticeFromCurrentQuery()}
<form method="post" action="{$this->e($action)}" class="card form-grid">
    {$method}
    <label><span>Projektnummer</span><input name="project_number" value="{$this->field($project, 'project_number')}" required></label>
    <label><span>Name</span><input name="name" value="{$this->field($project, 'name')}" required></label>
    <label><span>Kunde</span><input name="customer_name" value="{$this->field($project, 'customer_name')}"></label>
    <label><span>Status</span>{$this->select('status', ['planning' => 'Planung', 'active' => 'Aktiv', 'paused' => 'Pausiert', 'completed' => 'Abgeschlossen', 'archived' => 'Archiviert'], $project['status'] ?? 'planning')}</label>
    <label><span>Adresse</span><input name="address_line_1" value="{$this->field($project, 'address_line_1')}"></label>
    <label><span>PLZ</span><input name="postal_code" value="{$this->field($project, 'postal_code')}"></label>
    <label><span>Ort</span><input name="city" value="{$this->field($project, 'city')}"></label>
    <label><span>Start</span><input type="date" name="starts_on" value="{$this->field($project, 'starts_on')}"></label>
    <label><span>Ende</span><input type="date" name="ends_on" value="{$this->field($project, 'ends_on')}"></label>
    <button class="button" type="submit">Speichern</button>
</form>
HTML;
    }

    private function renderAssetForm(string $action, string $title, ?array $asset = null): string
    {
        $isEdit = $asset !== null;
        $method = $isEdit ? '<input type="hidden" name="_method" value="PUT">' : '';
        $hint = $isEdit ? '' : '<p class="muted">Dateianhaenge koennen direkt nach dem ersten Speichern zugewiesen werden.</p>';

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Geraetepflege</p>
        <h1>{$this->e($title)}</h1>
        {$hint}
    </div>
    <a class="button" href="/admin/assets">Zur Liste</a>
</header>
{$this->noticeFromCurrentQuery()}
<form method="post" action="{$this->e($action)}" class="card form-grid">
    {$method}
    <label><span>Typ</span>{$this->select('asset_type', ['vehicle' => 'Fahrzeug', 'equipment' => 'Geraet'], $asset['asset_type'] ?? 'equipment')}</label>
    <label><span>Name</span><input name="name" value="{$this->field($asset, 'name')}" required></label>
    <label><span>Identifier</span><input name="identifier" value="{$this->field($asset, 'identifier')}" required></label>
    <label><span>Status</span>{$this->select('status', ['available' => 'Verfuegbar', 'assigned' => 'Zugewiesen', 'maintenance' => 'Wartung', 'retired' => 'Ausgemustert'], $asset['status'] ?? 'available')}</label>
    <label class="full-span"><span>Notizen</span><textarea name="notes" rows="5">{$this->field($asset, 'notes')}</textarea></label>
    <button class="button" type="submit">Speichern</button>
</form>
HTML;
    }

    private function renderUserForm(string $action, string $title, ?array $user, array $roles): string
    {
        $isEdit = $user !== null;
        $method = $isEdit ? '<input type="hidden" name="_method" value="PUT">' : '';
        $roleIds = $user['role_ids'] ?? [];
        $roleCheckboxes = '';
        $timeTrackingChecked = ((int) ($user['time_tracking_required'] ?? 1) === 1) ? 'checked' : '';

        foreach ($roles as $role) {
            $roleId = (int) ($role['id'] ?? 0);
            $checked = in_array($roleId, $roleIds, true) ? 'checked' : '';
            $roleCheckboxes .= '<label class="checkbox-item"><input type="checkbox" name="role_ids[]" value="' . $roleId . '" ' . $checked . '> <span>' . $this->e((string) ($role['name'] ?? '')) . '</span></label>';
        }

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Benutzerpflege</p>
        <h1>{$this->e($title)}</h1>
        <p>Mehrere Rollen pro Benutzer sind moeglich.</p>
    </div>
    <a class="button" href="/admin/users">Zur Liste</a>
</header>
{$this->noticeFromCurrentQuery()}
<form method="post" action="{$this->e($action)}" class="card form-grid">
    {$method}
    <label><span>Mitarbeiternummer</span><input name="employee_number" value="{$this->field($user, 'employee_number')}"></label>
    <label><span>Vorname</span><input name="first_name" value="{$this->field($user, 'first_name')}" required></label>
    <label><span>Nachname</span><input name="last_name" value="{$this->field($user, 'last_name')}" required></label>
    <label><span>E-Mail</span><input name="email" type="email" value="{$this->field($user, 'email')}" required></label>
    <label><span>Telefon</span><input name="phone" value="{$this->field($user, 'phone')}"></label>
    <label><span>Passwort</span><input name="password" type="password" {$this->required(!$isEdit)}></label>
    <label><span>Status</span>{$this->select('employment_status', ['active' => 'Aktiv', 'inactive' => 'Inaktiv', 'terminated' => 'Ausgeschieden'], $user['employment_status'] ?? 'active')}</label>
    <label><span>Sollstunden / Monat</span><input name="target_hours_month" type="number" step="0.01" value="{$this->field($user, 'target_hours_month')}"></label>
    <label><span>Notfallkontakt</span><input name="emergency_contact_name" value="{$this->field($user, 'emergency_contact_name')}"></label>
    <label><span>Notfalltelefon</span><input name="emergency_contact_phone" value="{$this->field($user, 'emergency_contact_phone')}"></label>
    <div class="full-span field-group">
        <span>Zeiterfassung</span>
        <input type="hidden" name="time_tracking_required" value="0">
        <label class="checkbox-item"><input type="checkbox" name="time_tracking_required" value="1" {$timeTrackingChecked}> <span>Zeiterfassung erforderlich</span></label>
        <p class="muted">Bei deaktivierter Pflicht bleiben User aktiv und koennen freiwillig buchen, werden aber nicht als fehlend gewertet.</p>
    </div>
    <div class="full-span field-group">
        <span>Rollen</span>
        <div class="checkbox-grid">{$roleCheckboxes}</div>
    </div>
    <button class="button" type="submit">Speichern</button>
</form>
HTML;
    }

    private function renderRoleForm(string $action, string $title, ?array $role = null): string
    {
        $isEdit = $role !== null;
        $method = $isEdit ? '<input type="hidden" name="_method" value="PUT">' : '';
        $permissionIds = $role['permission_ids'] ?? [];
        $permissionCheckboxes = '';

        foreach ($this->roleService->availablePermissions() as $permission) {
            $permissionId = (int) ($permission['id'] ?? 0);
            $checked = in_array($permissionId, $permissionIds, true) ? 'checked' : '';
            $permissionCheckboxes .= '<label class="checkbox-item"><input type="checkbox" name="permission_ids[]" value="' . $permissionId . '" ' . $checked . '> <span>' . $this->e((string) ($permission['label'] ?? $permission['code'])) . ' <small class="muted">(' . $this->e((string) ($permission['code'] ?? '')) . ')</small></span></label>';
        }

        $systemNotice = ($isEdit && (int) ($role['is_system_role'] ?? 0) === 1)
            ? '<p class="notice info">Diese Rolle ist eine Systemrolle: bearbeiten ja, archivieren nein.</p>'
            : '';

        return <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Rechteverwaltung</p>
        <h1>{$this->e($title)}</h1>
        {$systemNotice}
    </div>
    <a class="button" href="/admin/roles">Zur Liste</a>
</header>
{$this->noticeFromCurrentQuery()}
<form method="post" action="{$this->e($action)}" class="card form-grid">
    {$method}
    <label><span>Slug</span><input name="slug" value="{$this->field($role, 'slug')}"></label>
    <label><span>Name</span><input name="name" value="{$this->field($role, 'name')}" required></label>
    <label class="full-span"><span>Beschreibung</span><textarea name="description" rows="4">{$this->field($role, 'description')}</textarea></label>
    <div class="full-span field-group">
        <span>Berechtigungen</span>
        <div class="checkbox-grid">{$permissionCheckboxes}</div>
    </div>
    <button class="button" type="submit">Speichern</button>
</form>
HTML;
    }

    private function renderAttachmentSection(string $title, string $uploadAction, array $files, string $type): string
    {
        $rows = '';

        foreach ($files as $file) {
            $archiveAction = $type === 'project'
                ? '/admin/project-files/' . (int) $file['id']
                : '/admin/asset-files/' . (int) $file['id'];

            $rows .= '<tr>'
                . '<td>' . $this->e((string) $file['original_name']) . '</td>'
                . '<td>' . $this->e((string) $file['mime_type']) . '</td>'
                . '<td>' . $this->e((string) $file['size_bytes']) . '</td>'
                . '<td>' . $this->e((string) $file['uploaded_at']) . '</td>'
                . '<td>' . (((int) ($file['is_deleted'] ?? 0) === 1) ? '<span class="badge warn">Archiviert</span>' : '<span class="badge ok">Aktiv</span>') . '</td>'
                . '<td class="table-actions">' . $this->archiveForm($archiveAction, (int) ($file['is_deleted'] ?? 0) === 1) . '</td>'
                . '</tr>';
        }

        return <<<HTML
<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>{$this->e($title)}</h2>
            <p class="muted">Dateien bleiben revisionssicher gespeichert und werden nur archiviert.</p>
        </div>
    </div>
    <form method="post" action="{$this->e($uploadAction)}" enctype="multipart/form-data" class="inline-form">
        <input type="file" name="file" required>
        <button class="button" type="submit">Datei hochladen</button>
    </form>
    <table>
        <thead><tr><th>Datei</th><th>MIME</th><th>Bytes</th><th>Hochgeladen</th><th>Status</th><th>Aktionen</th></tr></thead>
        <tbody>{$rows}</tbody>
    </table>
</section>
HTML;
    }

    private function renderProjectBookingsSection(array $project, array $bookings, array $projects, array $users, Request $request): string
    {
        if (!$this->authService->hasPermission('timesheets.view')) {
            return '';
        }

        $returnTo = $this->sanitizeReturnTo(
            (string) $request->server('REQUEST_URI', '/admin/projects/' . (int) $project['id'] . '/edit'),
            '/admin/projects/' . (int) $project['id'] . '/edit'
        );
        $exportBase = '/admin/bookings/export?project_id=' . (int) $project['id'] . '&scope=all';
        $csrfToken = $this->csrfService->token();
        $renderer = new BookingModalRenderer();
        $canManageBookings = $this->authService->hasPermission('timesheets.manage');
        $canArchiveBookings = $this->authService->hasPermission('timesheets.archive');
        $manualCreateForm = $canManageBookings
            ? $this->renderManualProjectBookingForm((int) $project['id'], $users, $this->bookingService->entryTypeOptions(), $csrfToken)
            : '';
        $table = $renderer->renderTable(
            $bookings,
            $projects,
            $this->bookingService->entryTypeOptions(),
            [
                'show_selection' => false,
                'empty_message' => 'Fuer dieses Projekt sind im aktuellen Filter noch keine Buchungen vorhanden.',
                'can_manage' => $canManageBookings,
                'can_archive' => $canArchiveBookings,
                'open_booking_location' => $returnTo,
            ]
        );
        $modal = $renderer->renderModal(
            $projects,
            $this->bookingService->entryTypeOptions(),
            [
                'return_to' => $returnTo,
                'csrf_token' => $csrfToken,
                'can_manage' => $canManageBookings,
                'can_archive' => $canArchiveBookings,
                'selected_booking' => $this->selectedProjectBooking($request, (int) $project['id']),
            ]
        );

        $exportButtons = $this->authService->hasPermission('timesheets.export')
            ? '<a class="button" href="' . $this->e($exportBase . '&format=csv') . '">CSV</a>'
                . '<a class="button" href="' . $this->e($exportBase . '&format=xlsx') . '">Excel</a>'
                . '<a class="button" href="' . $this->e($exportBase . '&format=pdf') . '">PDF</a>'
            : '';

        return <<<HTML
<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Geleistete Arbeitszeiten</h2>
            <p class="muted">Die Buchungen dieses Projekts koennen nacherfasst, im Modal bearbeitet, archiviert und exportiert werden.</p>
        </div>
        <div class="toolbar-actions">
            <a class="button" href="/admin/bookings?project_id={$this->e((string) $project['id'])}&scope=all">Vollansicht Buchungen</a>
            {$exportButtons}
        </div>
    </div>
    {$manualCreateForm}
    {$table}
    {$modal}
</section>
HTML;
    }

    private function renderManualProjectBookingForm(int $projectId, array $users, array $entryTypeOptions, string $csrfToken): string
    {
        $userOptions = $this->activeUserOptions($users);
        $entryOptions = $this->selectOptions($entryTypeOptions, 'work');

        return <<<HTML
<section class="geo-map-panel">
    <div>
        <h3>Buchung nacherfassen</h3>
        <p class="muted">Nacherfasste Buchungen werden als Admin-Nacherfassung markiert und direkt diesem Projekt zugeordnet.</p>
    </div>
    <form method="post" action="/admin/projects/{$projectId}/bookings" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
        <label><span>Mitarbeiter</span><select name="user_id" required>{$userOptions}</select></label>
        <label><span>Datum</span><input type="date" name="work_date" required></label>
        <label><span>Typ</span><select name="entry_type">{$entryOptions}</select></label>
        <label><span>Start</span><input type="time" name="start_time"></label>
        <label><span>Ende</span><input type="time" name="end_time"></label>
        <label><span>Pause in Minuten</span><input type="number" name="break_minutes" min="0" step="1" value="0"></label>
        <label class="full-span"><span>Notiz</span><textarea name="note" rows="3"></textarea></label>
        <label class="full-span"><span>Begruendung</span><textarea name="change_reason" rows="3" required placeholder="Warum wird diese Buchung im Backend nacherfasst?"></textarea></label>
        <button type="submit" class="button">Buchung nacherfassen</button>
    </form>
</section>
HTML;
    }

    private function notice(Request $request): string
    {
        $notice = (string) $request->query('notice', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            $message = match ($error) {
                'system-role' => 'Systemrollen koennen nicht archiviert werden.',
                'file-upload' => 'Die Datei konnte nicht hochgeladen werden.',
                'file-not-found' => 'Die Datei wurde nicht gefunden.',
                'no-file' => 'Bitte waehlen Sie zuerst eine Datei aus.',
                'validation' => 'Bitte pruefen Sie die Eingaben im Formular.',
                'booking-validation' => 'Die Buchung konnte nicht nacherfasst werden. Bitte Mitarbeiter, Datum, Zeiten und Begruendung pruefen.',
                'csrf' => 'Die Sicherheitspruefung ist abgelaufen. Bitte erneut versuchen.',
                default => 'Beim Vorgang ist ein Fehler aufgetreten.',
            };

            return '<p class="notice error">' . $this->e($message) . '</p>';
        }

        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'created' => 'Datensatz erfolgreich angelegt.',
            'updated' => 'Datensatz erfolgreich gespeichert.',
            'archived' => 'Datensatz erfolgreich archiviert.',
            'restored' => 'Projekt erfolgreich wiederhergestellt.',
            'booking-created' => 'Buchung erfolgreich nacherfasst.',
            'file-uploaded' => 'Datei erfolgreich zugewiesen.',
            'file-archived' => 'Datei erfolgreich archiviert.',
            default => 'Vorgang erfolgreich ausgefuehrt.',
        };

        return '<p class="notice success">' . $this->e($message) . '</p>';
    }

    private function noticeFromCurrentQuery(): string
    {
        return $this->notice(Request::capture());
    }

    private function sanitizeReturnTo(string $location, string $fallback): string
    {
        if (!str_starts_with($location, '/admin')) {
            return $fallback;
        }

        $path = parse_url($location, PHP_URL_PATH) ?: $fallback;
        $queryString = (string) parse_url($location, PHP_URL_QUERY);
        parse_str($queryString, $query);
        unset($query['notice'], $query['error'], $query['booking_id'], $query['modal']);

        $query = array_filter(
            $query,
            static fn ($value): bool => $value !== '' && $value !== null
        );

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    private function selectedProjectBooking(Request $request, int $projectId): ?array
    {
        $bookingId = (int) $request->query('booking_id', 0);

        if ($bookingId <= 0 || (string) $request->query('modal', '') !== 'edit') {
            return null;
        }

        $booking = $this->bookingService->find($bookingId);

        if ($booking === null) {
            return null;
        }

        return (int) ($booking['project_id'] ?? 0) === $projectId ? $booking : null;
    }

    private function archiveForm(string $action, bool $alreadyArchived): string
    {
        if ($alreadyArchived) {
            return '<span class="muted">Bereits archiviert</span>';
        }

        return '<form method="post" action="' . $this->e($action) . '" class="inline-form">'
            . '<input type="hidden" name="_method" value="DELETE">'
            . '<button type="submit">Archivieren</button>'
            . '</form>';
    }

    private function projectLifecycleForm(string $archiveAction, string $restoreAction, bool $alreadyArchived): string
    {
        if ($alreadyArchived) {
            return '<form method="post" action="' . $this->e($restoreAction) . '" class="inline-form">'
                . '<button type="submit" class="button button-secondary">Wiederherstellen</button>'
                . '</form>';
        }

        return '<form method="post" action="' . $this->e($archiveAction) . '" class="inline-form" onsubmit="return confirm(\'Projekt wirklich archivieren?\')">'
            . '<input type="hidden" name="_method" value="DELETE">'
            . '<button type="submit">Archivieren</button>'
            . '</form>';
    }

    private function currentUserId(): ?int
    {
        $userId = $this->authService->currentUser()['id'] ?? null;

        return is_numeric($userId) ? (int) $userId : null;
    }

    private function scope(Request $request): string
    {
        $scope = (string) $request->query('scope', 'active');

        return in_array($scope, ['active', 'archived', 'all'], true) ? $scope : 'active';
    }

    private function scopeLink(string $baseUrl, string $scope, string $label, string $currentScope): string
    {
        $class = $scope === $currentScope ? 'scope-link is-active' : 'scope-link';

        return '<a class="' . $class . '" href="' . $this->e($baseUrl) . '?scope=' . $this->e($scope) . '">' . $this->e($label) . '</a>';
    }

    private function select(string $name, array $options, string $selected): string
    {
        $html = '<select name="' . $this->e($name) . '">';

        foreach ($options as $value => $label) {
            $isSelected = $value === $selected ? ' selected' : '';
            $html .= '<option value="' . $this->e($value) . '"' . $isSelected . '>' . $this->e($label) . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    private function selectOptions(array $options, string $selected): string
    {
        $html = '';

        foreach ($options as $value => $label) {
            $isSelected = (string) $value === $selected ? ' selected' : '';
            $html .= '<option value="' . $this->e((string) $value) . '"' . $isSelected . '>' . $this->e((string) $label) . '</option>';
        }

        return $html;
    }

    private function activeUserOptions(array $users): string
    {
        if ($users === []) {
            return '<option value="">Keine aktiven Mitarbeiter vorhanden</option>';
        }

        $html = '<option value="">Bitte waehlen</option>';

        foreach ($users as $user) {
            if ((int) ($user['is_deleted'] ?? 0) === 1 || (string) ($user['employment_status'] ?? 'active') !== 'active') {
                continue;
            }

            $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $number = trim((string) ($user['employee_number'] ?? ''));
            $label = trim($name . ($number !== '' ? ' (' . $number . ')' : ''));

            if ($label === '') {
                $label = (string) ($user['email'] ?? ('User #' . (int) ($user['id'] ?? 0)));
            }

            $html .= '<option value="' . (int) ($user['id'] ?? 0) . '">' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function field(?array $record, string $key): string
    {
        return $this->e((string) ($record[$key] ?? ''));
    }

    private function projectAssignmentOptions(array $projects, string $selected): string
    {
        $html = '<option value="__none__"' . ($selected === '__none__' ? ' selected' : '') . '>Nicht zugeordnet</option>';

        foreach ($projects as $project) {
            $id = (string) ($project['id'] ?? '');
            $label = trim((string) ($project['project_number'] ?? '') . ' ' . (string) ($project['name'] ?? ''));

            if ((int) ($project['is_deleted'] ?? 0) === 1) {
                $label .= ' (archiviert)';
            }

            $html .= '<option value="' . $this->e($id) . '"' . ($selected === $id ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function entryTypeOptions(string $selected): string
    {
        $html = '';

        foreach ($this->bookingService->entryTypeOptions() as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '"' . ($selected === $value ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function timeField(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return $this->e(substr($value, 0, 5));
    }

    private function formatProjectHours(int $netMinutes): string
    {
        return number_format(max(0, $netMinutes) / 60, 2, ',', '.') . ' h';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function required(bool $required): string
    {
        return $required ? 'required' : '';
    }

    private function notFoundMarkup(string $entity): string
    {
        return '<section class="card"><h1>' . $this->e($entity) . ' nicht gefunden</h1><p>Der angeforderte Datensatz ist nicht vorhanden.</p></section>';
    }
}
