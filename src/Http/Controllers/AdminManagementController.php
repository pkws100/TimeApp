<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assets\AssetService;
use App\Domain\App\AppUiSettings;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Files\DocumentStatusService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Personnel\PersonnelEventService;
use App\Domain\Personnel\PersonnelLabelService;
use App\Domain\Projects\ProjectService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetSignatureService;
use App\Domain\Users\RoleService;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use App\Http\UploadSizeGuard;
use App\Presentation\Admin\AdminView;
use App\Presentation\Admin\BookingModalRenderer;
use App\Presentation\Admin\PersonnelIconRenderer;
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
        private DocumentStatusService $documentStatusService,
        private AdminBookingService $bookingService,
        private AuthService $authService,
        private CsrfService $csrfService,
        private ?TimesheetSignatureService $timesheetSignatureService = null,
        private ?PersonnelLabelService $personnelLabelService = null,
        private ?PersonnelEventService $personnelEventService = null
    ) {
    }

    public function projects(Request $request): Response
    {
        $scope = $this->scope($request);
        $projects = $this->projectService->list($scope);

        $rows = '';

        foreach ($projects as $project) {
            $projectId = (int) ($project['id'] ?? 0);
            $editUrl = '/admin/projects/' . $projectId . '/edit';
            $trackedNetMinutes = max(0, (int) ($project['tracked_net_minutes'] ?? 0));
            $rows .= '<tr data-row-selectable="true" data-edit-url="' . $this->e($editUrl) . '">'
                . '<td>' . $this->e((string) $project['project_number']) . '</td>'
                . '<td>' . $this->e((string) $project['name']) . '</td>'
                . '<td>' . $this->e((string) ($project['customer_name'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($project['status'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($project['city'] ?? '')) . '</td>'
                . '<td data-sort-value="' . $trackedNetMinutes . '">' . $this->e($this->formatProjectHours($trackedNetMinutes)) . '</td>'
                . '<td class="table-actions" data-search="false">'
                . '<a class="button" href="' . $this->e($editUrl) . '">Bearbeiten</a>'
                . $this->projectLifecycleForm(
                    '/admin/projects/' . $projectId,
                    '/admin/projects/' . $projectId . '/restore',
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
        $activeUsers = $this->activeAssignableUsers();
        $bookingUsers = [];
        $membershipUserIds = $this->projectService->membershipUserIds((int) $project['id']);

        if ($this->authService->hasPermission('timesheets.view')) {
            $bookingFilters = $this->bookingService->normalizeFilters($request->query(), (int) $project['id']);
            $bookings = $this->withCustomerSignatures($this->bookingService->list($bookingFilters));
            $allProjects = $this->projectService->list('all');

            if ($this->authService->hasPermission('timesheets.manage')) {
                $bookingUsers = $activeUsers;
            }
        }

        $content = $this->renderProjectForm('/admin/projects/' . (int) $project['id'], 'Projekt bearbeiten', $project)
            . $this->renderProjectMembershipSection($project, $activeUsers, $membershipUserIds)
            . $this->renderProjectBookingsSection($project, $bookings, $allProjects, $bookingUsers, $request)
            . $this->renderAttachmentSection('Projektanhaenge', '/admin/projects/' . (int) $project['id'] . '/files', $files, 'project');

        return Response::html($this->view->render('Projekt bearbeiten', $content));
    }

    public function projectStore(Request $request): Response
    {
        $payload = $request->input();

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return $this->projectCreateFailureResponse(
                $payload,
                ['csrf_token' => ['Die Sicherheitspruefung ist abgelaufen. Bitte erneut versuchen.']]
            );
        }

        try {
            $errors = $this->validateProjectCreatePayload($payload);
        } catch (RuntimeException|\InvalidArgumentException $exception) {
            return $this->projectCreateFailureResponse(
                $payload,
                ['_form' => [$this->projectCreateStorageErrorMessage($exception)]]
            );
        }

        if ($errors !== []) {
            return $this->projectCreateFailureResponse($payload, $errors);
        }

        try {
            $project = $this->projectService->create($payload);
        } catch (RuntimeException|\InvalidArgumentException $exception) {
            return $this->projectCreateFailureResponse(
                $payload,
                ['_form' => [$this->projectCreateStorageErrorMessage($exception)]]
            );
        }

        return Response::redirect('/admin/projects/' . (int) $project['id'] . '/edit?notice=created');
    }

    public function projectUpdate(Request $request, array $params): Response
    {
        $this->projectService->update((int) $params['id'], $request->input());

        return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?notice=updated');
    }

    public function projectMembershipUpdate(Request $request, array $params): Response
    {
        $projectId = (int) ($params['id'] ?? 0);
        $returnTo = '/admin/projects/' . $projectId . '/edit';
        $project = $this->projectService->find($projectId);

        if ($project === null) {
            return Response::html($this->notFoundMarkup('Projekt'), 404);
        }

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($returnTo . '?error=csrf');
        }

        $this->projectService->syncMemberships($projectId, $request->input('user_ids', []));

        return Response::redirect($returnTo . '?notice=memberships-updated');
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
                if (UploadSizeGuard::exceedsPostMaxSize($request)) {
                    return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?error=file-upload&error_detail=' . rawurlencode(UploadSizeGuard::message()));
                }

                return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?error=no-file');
            }

            $this->fileAttachmentService->storeProject($file, (int) $params['id']);

            return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?notice=file-uploaded');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/projects/' . (int) $params['id'] . '/edit?error=file-upload&error_detail=' . rawurlencode($exception->getMessage()));
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

    public function projectFileStatus(Request $request, array $params): Response
    {
        $file = $this->fileAttachmentService->findProjectFile((int) $params['id']);

        if ($file === null) {
            return Response::redirect('/admin/projects?error=file-not-found');
        }

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect('/admin/projects/' . (int) $file['project_id'] . '/edit?error=csrf');
        }

        if ((int) ($file['is_deleted'] ?? 0) === 1) {
            return Response::redirect('/admin/projects/' . (int) $file['project_id'] . '/edit?error=file-status');
        }

        try {
            if (!$this->fileAttachmentService->updateProjectFileStatus((int) $params['id'], $this->nullableStatusId($request))) {
                return Response::redirect('/admin/projects/' . (int) $file['project_id'] . '/edit?error=file-status');
            }

            return Response::redirect('/admin/projects/' . (int) $file['project_id'] . '/edit?notice=file-status-updated');
        } catch (RuntimeException) {
            return Response::redirect('/admin/projects/' . (int) $file['project_id'] . '/edit?error=file-status');
        }
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
                if (UploadSizeGuard::exceedsPostMaxSize($request)) {
                    return Response::redirect('/admin/assets/' . (int) $params['id'] . '/edit?error=file-upload&error_detail=' . rawurlencode(UploadSizeGuard::message()));
                }

                return Response::redirect('/admin/assets/' . (int) $params['id'] . '/edit?error=no-file');
            }

            $this->fileAttachmentService->storeAsset($file, (int) $params['id']);

            return Response::redirect('/admin/assets/' . (int) $params['id'] . '/edit?notice=file-uploaded');
        } catch (RuntimeException $exception) {
            return Response::redirect('/admin/assets/' . (int) $params['id'] . '/edit?error=file-upload&error_detail=' . rawurlencode($exception->getMessage()));
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

    public function assetFileStatus(Request $request, array $params): Response
    {
        $file = $this->fileAttachmentService->findAssetFile((int) $params['id']);

        if ($file === null) {
            return Response::redirect('/admin/assets?error=file-not-found');
        }

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect('/admin/assets/' . (int) $file['asset_id'] . '/edit?error=csrf');
        }

        if ((int) ($file['is_deleted'] ?? 0) === 1) {
            return Response::redirect('/admin/assets/' . (int) $file['asset_id'] . '/edit?error=file-status');
        }

        try {
            if (!$this->fileAttachmentService->updateAssetFileStatus((int) $params['id'], $this->nullableStatusId($request))) {
                return Response::redirect('/admin/assets/' . (int) $file['asset_id'] . '/edit?error=file-status');
            }

            return Response::redirect('/admin/assets/' . (int) $file['asset_id'] . '/edit?notice=file-status-updated');
        } catch (RuntimeException) {
            return Response::redirect('/admin/assets/' . (int) $file['asset_id'] . '/edit?error=file-status');
        }
    }

    public function users(Request $request): Response
    {
        $scope = $this->scope($request);
        $users = $this->userService->list($scope);
        $userIds = array_map(static fn (array $user): int => (int) ($user['id'] ?? 0), $users);
        $canViewPersonnel = $this->authService->hasPermission('personnel.view');
        $labelsByUser = $canViewPersonnel ? ($this->personnelLabelService?->labelsForUsersGrouped($userIds) ?? []) : [];
        $eventsByUser = $canViewPersonnel ? ($this->personnelEventService?->upcomingForUsersGrouped($userIds) ?? []) : [];
        $rows = '';

        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);
            $timeTrackingRequired = (int) ($user['time_tracking_required'] ?? 1) === 1;
            $timeTrackingBadge = $timeTrackingRequired
                ? '<span class="badge ok">Pflicht</span>'
                : '<span class="badge warn">freiwillig</span>';
            $personnelCells = $canViewPersonnel
                ? '<td>' . $this->renderPersonnelLabelBadges($labelsByUser[$userId] ?? []) . '</td>'
                    . '<td>' . $this->renderNextPersonnelEvent($eventsByUser[$userId][0] ?? null) . '</td>'
                : '';

            $editUrl = '/admin/users/' . $userId . '/edit';

            $rows .= '<tr data-row-selectable="true" data-edit-url="' . $this->e($editUrl) . '">'
                . '<td>' . $this->e((string) ($user['employee_number'] ?? '')) . '</td>'
                . '<td>' . $this->e(trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')))) . '</td>'
                . '<td>' . $this->e((string) ($user['email'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($user['phone'] ?? '')) . '</td>'
                . '<td>' . $this->e((string) ($user['employment_status'] ?? '')) . '</td>'
                . '<td>' . $timeTrackingBadge . '</td>'
                . $personnelCells
                . '<td>' . $this->e((string) ($user['role_names'] ?? '')) . '</td>'
                . '<td class="table-actions">'
                . '<a class="button" href="' . $this->e($editUrl) . '">Bearbeiten</a>'
                . $this->archiveForm('/admin/users/' . $userId, (int) ($user['is_deleted'] ?? 0) === 1)
                . '</td>'
                . '</tr>';
        }

        $personnelHead = $canViewPersonnel ? '<th>Labels</th><th>Naechstes Event</th>' : '';
        $colspan = $canViewPersonnel ? 10 : 8;
        $content = $this->renderIndexLayout(
            'User und Mitarbeiter',
            'Benutzerverwaltung',
            '/admin/users/create',
            '/admin/users',
            $scope,
            $this->notice($request),
            '<div class="table-scroll"><table data-admin-table="users" data-table-label="User"><thead><tr><th>Mitarbeiternummer</th><th>Name</th><th>E-Mail</th><th>Telefon</th><th>Status</th><th>Zeiterfassung</th>' . $personnelHead . '<th>Rollen</th><th data-search="false" data-sort="false">Aktionen</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="' . $colspan . '" class="table-empty">Keine User im aktuellen Filter.</td></tr>')
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
        $canViewPersonnel = $this->authService->hasPermission('personnel.view');
        $canManagePersonnel = $this->authService->hasPermission('personnel.manage');
        $labels = $canManagePersonnel ? ($this->personnelLabelService?->list('active') ?? []) : [];
        $selectedLabels = $canViewPersonnel ? ($this->personnelLabelService?->labelsForUser((int) $user['id']) ?? []) : [];
        $events = $canViewPersonnel ? ($this->personnelEventService?->events(['user_id' => (int) $user['id'], 'scope' => 'active']) ?? []) : [];
        $eventTypes = $canManagePersonnel ? ($this->personnelEventService?->eventTypes('active') ?? []) : [];

        return Response::html($this->view->render(
            'User bearbeiten',
            $this->renderUserForm('/admin/users/' . (int) $user['id'], 'User bearbeiten', $user, $roles, $labels, array_column($selectedLabels, 'id'))
            . ($canViewPersonnel ? $this->renderUserPersonnelEventsSection($user, $events, $eventTypes, $canManagePersonnel) : '')
        ));
    }

    public function userStore(Request $request): Response
    {
        $payload = $request->input();

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return $this->userCreateFailureResponse(
                $payload,
                ['csrf_token' => ['Die Sicherheitspruefung ist abgelaufen. Bitte erneut versuchen.']]
            );
        }

        $roles = $this->roleService->list('active');
        $errors = $this->validateUserCreatePayload($payload, $roles);

        if ($errors !== []) {
            return $this->userCreateFailureResponse($payload, $errors);
        }

        try {
            $user = $this->userService->create($payload);

            return Response::redirect('/admin/users/' . (int) $user['id'] . '/edit?notice=created');
        } catch (RuntimeException|\InvalidArgumentException $exception) {
            return $this->userCreateFailureResponse(
                $payload,
                ['_form' => [$this->userCreateStorageErrorMessage($exception)]]
            );
        }
    }

    public function userUpdate(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect('/admin/users/' . $userId . '/edit?error=csrf');
        }

        $roles = $this->roleService->list('active');
        $errors = $this->validateUserUpdatePayload($request->input(), $roles, $userId);

        if ($errors !== []) {
            return $this->userEditFailureResponse($userId, $request->input(), $errors);
        }

        try {
            $this->userService->update($userId, $request->input());
        } catch (RuntimeException|\InvalidArgumentException $exception) {
            return $this->userEditFailureResponse(
                $userId,
                $request->input(),
                ['_form' => [$this->userUpdateStorageErrorMessage($exception)]]
            );
        }

        if ($this->authService->hasPermission('personnel.manage')
            && $this->personnelLabelService instanceof PersonnelLabelService
            && (string) $request->input('personnel_labels_submitted', '') === '1') {
            $this->personnelLabelService->syncUserLabels($userId, $request->input('label_ids', []), $this->currentUserId());
        }

        return Response::redirect('/admin/users/' . $userId . '/edit?notice=updated');
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

    private function renderProjectForm(string $action, string $title, ?array $project = null, array $formErrors = []): string
    {
        $isEdit = $project !== null && array_key_exists('id', $project);
        $method = $isEdit ? '<input type="hidden" name="_method" value="PUT">' : '';
        $hint = $isEdit ? '' : '<p class="muted">Dateianhaenge koennen direkt nach dem ersten Speichern zugewiesen werden.</p>';
        $signatureRequiredChecked = (int) ($project['customer_signature_required'] ?? 0) === 1 ? 'checked' : '';
        $errorSummary = $this->renderFormErrorSummary($formErrors, 'Projekt konnte nicht angelegt werden.');
        $csrfToken = $this->e($this->csrfService->token());

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
{$errorSummary}
<form method="post" action="{$this->e($action)}" class="card form-grid">
    {$method}
    <input type="hidden" name="csrf_token" value="{$csrfToken}">
    <div{$this->fieldWrapperClass($formErrors, 'project_number')}><label for="project_number">Projektnummer</label><input id="project_number" name="project_number" value="{$this->field($project, 'project_number')}" required{$this->fieldInvalidAttribute($formErrors, 'project_number')}>{$this->fieldErrorMarkup($formErrors, 'project_number')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'name')}><label for="name">Name</label><input id="name" name="name" value="{$this->field($project, 'name')}" required{$this->fieldInvalidAttribute($formErrors, 'name')}>{$this->fieldErrorMarkup($formErrors, 'name')}</div>
    <div class="form-field"><label for="customer_name">Kunde</label><input id="customer_name" name="customer_name" value="{$this->field($project, 'customer_name')}"></div>
    <label class="checkbox-item full-span"><input type="checkbox" name="customer_signature_required" value="1" {$signatureRequiredChecked}> <span>Kundenunterschrift bei Abschluss anfordern</span></label>
    <div class="form-field"><label for="customer_signature_name">Standardname für Kundenbestätigung</label><input id="customer_signature_name" name="customer_signature_name" value="{$this->field($project, 'customer_signature_name')}"></div>
    <div{$this->fieldWrapperClass($formErrors, 'status')}><label for="status">Status</label>{$this->select('status', ['planning' => 'Planung', 'active' => 'Aktiv', 'paused' => 'Pausiert', 'completed' => 'Abgeschlossen', 'archived' => 'Archiviert'], (string) ($project['status'] ?? 'planning'), $this->fieldInvalidAttribute($formErrors, 'status'))}{$this->fieldErrorMarkup($formErrors, 'status')}</div>
    <div class="form-field"><label for="address_line_1">Adresse</label><input id="address_line_1" name="address_line_1" value="{$this->field($project, 'address_line_1')}"></div>
    <div class="form-field"><label for="postal_code">PLZ</label><input id="postal_code" name="postal_code" value="{$this->field($project, 'postal_code')}"></div>
    <div class="form-field"><label for="city">Ort</label><input id="city" name="city" value="{$this->field($project, 'city')}"></div>
    <div{$this->fieldWrapperClass($formErrors, 'starts_on')}><label for="starts_on">Start</label><input id="starts_on" type="date" name="starts_on" value="{$this->field($project, 'starts_on')}"{$this->fieldInvalidAttribute($formErrors, 'starts_on')}>{$this->fieldErrorMarkup($formErrors, 'starts_on')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'ends_on')}><label for="ends_on">Ende</label><input id="ends_on" type="date" name="ends_on" value="{$this->field($project, 'ends_on')}"{$this->fieldInvalidAttribute($formErrors, 'ends_on')}>{$this->fieldErrorMarkup($formErrors, 'ends_on')}</div>
    <button class="button" type="submit">Speichern</button>
</form>
HTML;
    }

    private function projectCreateFailureResponse(array $payload, array $errors): Response
    {
        return Response::html(
            $this->view->render(
                'Projekt anlegen',
                $this->renderProjectForm('/admin/projects', 'Projekt anlegen', $this->projectFormPayload($payload), $errors)
            ),
            422
        );
    }

    private function validateProjectCreatePayload(array $payload): array
    {
        $errors = [];
        $projectNumber = trim((string) ($payload['project_number'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $status = trim((string) ($payload['status'] ?? 'planning'));
        $startsOn = trim((string) ($payload['starts_on'] ?? ''));
        $endsOn = trim((string) ($payload['ends_on'] ?? ''));

        if ($projectNumber === '') {
            $errors['project_number'][] = 'Bitte geben Sie eine Projektnummer ein.';
        } elseif ($this->projectService->projectNumberExists($projectNumber)) {
            $errors['project_number'][] = 'Diese Projektnummer ist bereits vergeben.';
        }

        if ($name === '') {
            $errors['name'][] = 'Bitte geben Sie einen Projektnamen ein.';
        }

        if (!in_array($status, ['planning', 'active', 'paused', 'completed', 'archived'], true)) {
            $errors['status'][] = 'Bitte waehlen Sie einen gueltigen Status.';
        }

        $startDate = $startsOn !== '' ? $this->validDate($startsOn) : null;
        $endDate = $endsOn !== '' ? $this->validDate($endsOn) : null;

        if ($startsOn !== '' && $startDate === null) {
            $errors['starts_on'][] = 'Bitte geben Sie ein gueltiges Startdatum ein.';
        }

        if ($endsOn !== '' && $endDate === null) {
            $errors['ends_on'][] = 'Bitte geben Sie ein gueltiges Enddatum ein.';
        }

        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            $errors['ends_on'][] = 'Das Enddatum darf nicht vor dem Startdatum liegen.';
        }

        return $errors;
    }

    private function projectCreateStorageErrorMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        error_log('Project create failed: ' . $message);

        if (stripos($message, 'project_number') !== false || stripos($message, 'Duplicate') !== false) {
            return 'Das Projekt konnte nicht angelegt werden. Bitte pruefen Sie die Projektnummer auf doppelte Werte.';
        }

        return 'Das Projekt konnte nicht angelegt werden. Bitte pruefen Sie die Eingaben oder versuchen Sie es erneut.';
    }

    private function projectFormPayload(array $payload): array
    {
        return [
            'project_number' => (string) ($payload['project_number'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'customer_name' => (string) ($payload['customer_name'] ?? ''),
            'customer_signature_required' => in_array($payload['customer_signature_required'] ?? false, [true, 1, '1', 'on', 'yes'], true) ? 1 : 0,
            'customer_signature_name' => (string) ($payload['customer_signature_name'] ?? ''),
            'status' => (string) ($payload['status'] ?? 'planning'),
            'address_line_1' => (string) ($payload['address_line_1'] ?? ''),
            'postal_code' => (string) ($payload['postal_code'] ?? ''),
            'city' => (string) ($payload['city'] ?? ''),
            'starts_on' => (string) ($payload['starts_on'] ?? ''),
            'ends_on' => (string) ($payload['ends_on'] ?? ''),
        ];
    }

    private function validDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date instanceof \DateTimeImmutable
            && $date->format('Y-m-d') === $value
            && ($errors === false || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0))) {
            return $date;
        }

        return null;
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

    private function renderUserForm(
        string $action,
        string $title,
        ?array $user,
        array $roles,
        array $labels = [],
        array $selectedLabelIds = [],
        array $formErrors = [],
        string $errorHeadline = 'Benutzer konnte nicht angelegt werden.'
    ): string
    {
        $isEdit = $user !== null && array_key_exists('id', $user);
        $method = $isEdit ? '<input type="hidden" name="_method" value="PUT">' : '';
        $csrfToken = $this->e($this->csrfService->token());
        $roleIds = $user['role_ids'] ?? [];
        $roleCheckboxes = '';
        $timeTrackingChecked = ((int) ($user['time_tracking_required'] ?? 1) === 1) ? 'checked' : '';
        $appUiSettings = AppUiSettings::normalize($user['app_ui_settings'] ?? null);
        $appUiSettingsCheckboxes = '';
        $labelCheckboxes = '';
        $errorSummary = $this->renderFormErrorSummary($formErrors, $errorHeadline);
        $targetHoursMode = (string) ($user['target_hours_mode'] ?? 'month');
        $targetHoursMode = $targetHoursMode === 'week' ? 'week' : 'month';
        $workdays = $this->workdaysFromMask($user['workdays_mask'] ?? '1,2,3,4,5');
        $workdayCheckboxes = '';

        foreach ($roles as $role) {
            $roleId = (int) ($role['id'] ?? 0);
            $checked = in_array($roleId, $roleIds, true) ? 'checked' : '';
            $roleCheckboxes .= '<label class="checkbox-item"><input type="checkbox" name="role_ids[]" value="' . $roleId . '" ' . $checked . '> <span>' . $this->e((string) ($role['name'] ?? '')) . '</span></label>';
        }

        foreach (AppUiSettings::ADMIN_FLAGS as $flag) {
            $label = AppUiSettings::FLAGS[$flag] ?? $flag;
            $checked = ($appUiSettings[$flag] ?? true) ? 'checked' : '';
            $appUiSettingsCheckboxes .= '<label class="checkbox-item"><input type="hidden" name="app_ui_settings[' . $this->e($flag) . ']" value="0"><input type="checkbox" name="app_ui_settings[' . $this->e($flag) . ']" value="1" ' . $checked . '> <span>' . $this->e($label) . '</span></label>';
        }

        foreach ($this->weekdayOptions() as $day => $label) {
            $checked = in_array($day, $workdays, true) ? 'checked' : '';
            $workdayCheckboxes .= '<label class="checkbox-item"><input type="checkbox" name="workdays_mask[]" value="' . $day . '" ' . $checked . '> <span>' . $this->e($label) . '</span></label>';
        }

        foreach ($labels as $label) {
            $labelId = (int) ($label['id'] ?? 0);
            $checked = in_array($labelId, array_map('intval', $selectedLabelIds), true) ? 'checked' : '';
            $labelCheckboxes .= '<label class="checkbox-item"><input type="checkbox" name="label_ids[]" value="' . $labelId . '" ' . $checked . '> <span>' . PersonnelIconRenderer::badge($label) . '</span></label>';
        }

        $labelsSection = $labels !== []
            ? '<div class="full-span field-group"><span>Personal-Labels</span><input type="hidden" name="personnel_labels_submitted" value="1"><div class="checkbox-grid">' . $labelCheckboxes . '</div></div>'
            : '';

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
{$errorSummary}
<form method="post" action="{$this->e($action)}" class="card form-grid">
    {$method}
    <input type="hidden" name="csrf_token" value="{$csrfToken}">
    <div{$this->fieldWrapperClass($formErrors, 'employee_number')}><label for="employee_number">Mitarbeiternummer</label><input id="employee_number" name="employee_number" value="{$this->field($user, 'employee_number')}"{$this->fieldInvalidAttribute($formErrors, 'employee_number')}>{$this->fieldErrorMarkup($formErrors, 'employee_number')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'first_name')}><label for="first_name">Vorname</label><input id="first_name" name="first_name" value="{$this->field($user, 'first_name')}" required{$this->fieldInvalidAttribute($formErrors, 'first_name')}>{$this->fieldErrorMarkup($formErrors, 'first_name')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'last_name')}><label for="last_name">Nachname</label><input id="last_name" name="last_name" value="{$this->field($user, 'last_name')}" required{$this->fieldInvalidAttribute($formErrors, 'last_name')}>{$this->fieldErrorMarkup($formErrors, 'last_name')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'email')}><label for="email">E-Mail</label><input id="email" name="email" type="email" value="{$this->field($user, 'email')}" required{$this->fieldInvalidAttribute($formErrors, 'email')}>{$this->fieldErrorMarkup($formErrors, 'email')}</div>
    <div class="form-field"><label for="phone">Telefon</label><input id="phone" name="phone" value="{$this->field($user, 'phone')}"></div>
    <div{$this->fieldWrapperClass($formErrors, 'password')}><label for="password">Passwort</label><input id="password" name="password" type="password" {$this->required(!$isEdit)}{$this->fieldInvalidAttribute($formErrors, 'password')}>{$this->fieldErrorMarkup($formErrors, 'password')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'employment_status')}><label for="employment_status">Status</label>{$this->select('employment_status', ['active' => 'Aktiv', 'inactive' => 'Inaktiv', 'terminated' => 'Ausgeschieden'], (string) ($user['employment_status'] ?? 'active'), $this->fieldInvalidAttribute($formErrors, 'employment_status'))}{$this->fieldErrorMarkup($formErrors, 'employment_status')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'target_hours_mode')}><label for="target_hours_mode">Arbeitszeitmodell</label>{$this->select('target_hours_mode', ['month' => 'Monatssoll', 'week' => 'Wochensoll'], $targetHoursMode, $this->fieldInvalidAttribute($formErrors, 'target_hours_mode'))}{$this->fieldErrorMarkup($formErrors, 'target_hours_mode')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'target_hours_month')}><label for="target_hours_month">Sollstunden / Monat</label><input id="target_hours_month" name="target_hours_month" type="number" step="0.01" value="{$this->field($user, 'target_hours_month')}"{$this->fieldInvalidAttribute($formErrors, 'target_hours_month')}>{$this->fieldErrorMarkup($formErrors, 'target_hours_month')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'target_hours_week')}><label for="target_hours_week">Sollstunden / Woche</label><input id="target_hours_week" name="target_hours_week" type="number" step="0.01" value="{$this->field($user, 'target_hours_week')}"{$this->fieldInvalidAttribute($formErrors, 'target_hours_week')}>{$this->fieldErrorMarkup($formErrors, 'target_hours_week')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'vacation_days_year')}><label for="vacation_days_year">Jahresurlaub</label><input id="vacation_days_year" name="vacation_days_year" type="number" step="0.5" value="{$this->field($user, 'vacation_days_year')}"{$this->fieldInvalidAttribute($formErrors, 'vacation_days_year')}>{$this->fieldErrorMarkup($formErrors, 'vacation_days_year')}</div>
    <div{$this->fieldWrapperClass($formErrors, 'vacation_carryover_days')}><label for="vacation_carryover_days">Urlaubsuebertrag</label><input id="vacation_carryover_days" name="vacation_carryover_days" type="number" step="0.5" value="{$this->field($user, 'vacation_carryover_days')}"{$this->fieldInvalidAttribute($formErrors, 'vacation_carryover_days')}>{$this->fieldErrorMarkup($formErrors, 'vacation_carryover_days')}</div>
    <div class="full-span field-group{$this->fieldGroupErrorClass($formErrors, 'workdays_mask')}" role="group" aria-labelledby="workdays_mask_label"{$this->fieldInvalidAttribute($formErrors, 'workdays_mask')}>
        <span id="workdays_mask_label">Arbeitstage</span>
        <div class="checkbox-grid">{$workdayCheckboxes}</div>
        {$this->fieldErrorMarkup($formErrors, 'workdays_mask')}
    </div>
    <div class="form-field"><label for="emergency_contact_name">Notfallkontakt</label><input id="emergency_contact_name" name="emergency_contact_name" value="{$this->field($user, 'emergency_contact_name')}"></div>
    <div class="form-field"><label for="emergency_contact_phone">Notfalltelefon</label><input id="emergency_contact_phone" name="emergency_contact_phone" value="{$this->field($user, 'emergency_contact_phone')}"></div>
    <div class="full-span field-group">
        <span>Zeiterfassung</span>
        <input type="hidden" name="time_tracking_required" value="0">
        <label class="checkbox-item"><input type="checkbox" name="time_tracking_required" value="1" {$timeTrackingChecked}> <span>Zeiterfassung erforderlich</span></label>
        <p class="muted">Bei deaktivierter Pflicht bleiben User aktiv und koennen freiwillig buchen, werden aber nicht als fehlend gewertet.</p>
    </div>
    <div class="full-span field-group">
        <span>Mitarbeiter-App Anzeige</span>
        <div class="checkbox-grid">{$appUiSettingsCheckboxes}</div>
        <p class="muted">Diese Optionen steuern nur optionale Karten in der mobilen App. Tagesstatus, Start, Ende, Pausen, Nettozeit, Projekt und Zeiterfassungsaktionen bleiben immer sichtbar.</p>
    </div>
    <div class="full-span field-group{$this->fieldGroupErrorClass($formErrors, 'role_ids')}" role="group" aria-labelledby="role_ids_label"{$this->fieldInvalidAttribute($formErrors, 'role_ids')}>
        <span id="role_ids_label">Rollen</span>
        <div class="checkbox-grid">{$roleCheckboxes}</div>
        {$this->fieldErrorMarkup($formErrors, 'role_ids')}
    </div>
    {$labelsSection}
    <button class="button" type="submit">Speichern</button>
</form>
HTML;
    }

    private function userCreateFailureResponse(array $payload, array $errors): Response
    {
        $roles = $this->roleService->list('active');
        $formUser = $this->userFormPayload($payload);

        return Response::html(
            $this->view->render(
                'User anlegen',
                $this->renderUserForm('/admin/users', 'User anlegen', $formUser, $roles, [], [], $errors)
            ),
            422
        );
    }

    private function userEditFailureResponse(int $userId, array $payload, array $errors): Response
    {
        $existingUser = $this->userService->find($userId);

        if ($existingUser === null) {
            return Response::html($this->notFoundMarkup('User'), 404);
        }

        $roles = $this->roleService->list('active');
        $formUser = $this->userFormPayload($payload);
        $formUser['id'] = $userId;
        $canViewPersonnel = $this->authService->hasPermission('personnel.view');
        $canManagePersonnel = $this->authService->hasPermission('personnel.manage');
        $labels = $canManagePersonnel ? ($this->personnelLabelService?->list('active') ?? []) : [];
        $selectedLabels = $canViewPersonnel ? ($this->personnelLabelService?->labelsForUser($userId) ?? []) : [];
        $selectedLabelIds = (string) ($payload['personnel_labels_submitted'] ?? '') === '1'
            ? $this->selectedRoleIds($payload['label_ids'] ?? [])
            : array_column($selectedLabels, 'id');
        $events = $canViewPersonnel ? ($this->personnelEventService?->events(['user_id' => $userId, 'scope' => 'active']) ?? []) : [];
        $eventTypes = $canManagePersonnel ? ($this->personnelEventService?->eventTypes('active') ?? []) : [];

        return Response::html(
            $this->view->render(
                'User bearbeiten',
                $this->renderUserForm(
                    '/admin/users/' . $userId,
                    'User bearbeiten',
                    $formUser,
                    $roles,
                    $labels,
                    $selectedLabelIds,
                    $errors,
                    'Benutzer konnte nicht gespeichert werden.'
                )
                . ($canViewPersonnel ? $this->renderUserPersonnelEventsSection($existingUser, $events, $eventTypes, $canManagePersonnel) : '')
            ),
            422
        );
    }

    private function validateUserCreatePayload(array $payload, array $roles): array
    {
        return $this->validateUserPayload($payload, $roles, true);
    }

    private function validateUserUpdatePayload(array $payload, array $roles, int $userId): array
    {
        return $this->validateUserPayload($payload, $roles, false, $userId);
    }

    private function validateUserPayload(array $payload, array $roles, bool $passwordRequired, ?int $excludeUserId = null): array
    {
        $errors = [];
        $email = trim((string) ($payload['email'] ?? ''));
        $employeeNumber = trim((string) ($payload['employee_number'] ?? ''));
        $password = trim((string) ($payload['password'] ?? ''));
        $status = trim((string) ($payload['employment_status'] ?? 'active'));
        $targetHours = trim((string) ($payload['target_hours_month'] ?? ''));
        $targetHoursMode = trim((string) ($payload['target_hours_mode'] ?? 'month'));
        $targetHoursWeek = trim((string) ($payload['target_hours_week'] ?? ''));
        $vacationDaysYear = trim((string) ($payload['vacation_days_year'] ?? ''));
        $vacationCarryoverDays = trim((string) ($payload['vacation_carryover_days'] ?? ''));

        if (trim((string) ($payload['first_name'] ?? '')) === '') {
            $errors['first_name'][] = 'Bitte geben Sie einen Vornamen ein.';
        }

        if (trim((string) ($payload['last_name'] ?? '')) === '') {
            $errors['last_name'][] = 'Bitte geben Sie einen Nachnamen ein.';
        }

        if ($email === '') {
            $errors['email'][] = 'Bitte geben Sie eine E-Mail-Adresse ein.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'Bitte geben Sie eine gueltige E-Mail-Adresse ein.';
        } elseif ($this->userService->emailExists($email, $excludeUserId)) {
            $errors['email'][] = 'Diese E-Mail-Adresse ist bereits einem Benutzer zugeordnet.';
        }

        if ($employeeNumber !== '' && $this->userService->employeeNumberExists($employeeNumber, $excludeUserId)) {
            $errors['employee_number'][] = 'Diese Mitarbeiternummer ist bereits vergeben.';
        }

        if ($passwordRequired && $password === '') {
            $errors['password'][] = 'Bitte vergeben Sie ein Passwort.';
        }

        if (!in_array($status, ['active', 'inactive', 'terminated'], true)) {
            $errors['employment_status'][] = 'Bitte waehlen Sie einen gueltigen Status.';
        }

        if ($targetHours !== '' && (!is_numeric($targetHours) || (float) $targetHours < 0)) {
            $errors['target_hours_month'][] = 'Sollstunden muessen eine Zahl groesser oder gleich 0 sein.';
        }

        if (!in_array($targetHoursMode, ['month', 'week'], true)) {
            $errors['target_hours_mode'][] = 'Bitte waehlen Sie ein gueltiges Arbeitszeitmodell.';
        }

        if ($targetHoursWeek !== '' && (!is_numeric($targetHoursWeek) || (float) $targetHoursWeek < 0)) {
            $errors['target_hours_week'][] = 'Wochensollstunden muessen eine Zahl groesser oder gleich 0 sein.';
        }

        if ($vacationDaysYear !== '' && (!is_numeric($vacationDaysYear) || (float) $vacationDaysYear < 0)) {
            $errors['vacation_days_year'][] = 'Jahresurlaub muss eine Zahl groesser oder gleich 0 sein.';
        }

        if ($vacationCarryoverDays !== '' && (!is_numeric($vacationCarryoverDays) || (float) $vacationCarryoverDays < 0)) {
            $errors['vacation_carryover_days'][] = 'Urlaubsuebertrag muss eine Zahl groesser oder gleich 0 sein.';
        }

        if ($this->workdaysFromMask($payload['workdays_mask'] ?? '1,2,3,4,5') === []) {
            $errors['workdays_mask'][] = 'Bitte mindestens einen Arbeitstag auswaehlen.';
        }

        $activeRoleIds = array_map(static fn (array $role): int => (int) ($role['id'] ?? 0), $roles);
        $activeRoleIds = array_values(array_filter($activeRoleIds, static fn (int $id): bool => $id > 0));

        foreach ($this->selectedRoleIds($payload['role_ids'] ?? []) as $roleId) {
            if ($roleId <= 0 || !in_array($roleId, $activeRoleIds, true)) {
                $errors['role_ids'][] = 'Mindestens eine ausgewaehlte Rolle ist nicht mehr verfuegbar.';
                break;
            }
        }

        return $errors;
    }

    private function userCreateStorageErrorMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        error_log('User create failed: ' . $message);

        if (stripos($message, 'email') !== false || stripos($message, 'Duplicate') !== false) {
            return 'Der Benutzer konnte nicht angelegt werden. Bitte pruefen Sie E-Mail-Adresse und Mitarbeiternummer auf doppelte Werte.';
        }

        return 'Der Benutzer konnte nicht angelegt werden. Bitte pruefen Sie die Eingaben oder versuchen Sie es erneut.';
    }

    private function userUpdateStorageErrorMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        error_log('User update failed: ' . $message);

        if (stripos($message, 'email') !== false || stripos($message, 'employee_number') !== false || stripos($message, 'Duplicate') !== false) {
            return 'Der Benutzer konnte nicht gespeichert werden. Bitte pruefen Sie E-Mail-Adresse und Mitarbeiternummer auf doppelte Werte.';
        }

        return 'Der Benutzer konnte nicht gespeichert werden. Bitte pruefen Sie die Eingaben oder versuchen Sie es erneut.';
    }

    private function userFormPayload(array $payload): array
    {
        return [
            'employee_number' => (string) ($payload['employee_number'] ?? ''),
            'first_name' => (string) ($payload['first_name'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'phone' => (string) ($payload['phone'] ?? ''),
            'employment_status' => (string) ($payload['employment_status'] ?? 'active'),
            'target_hours_month' => (string) ($payload['target_hours_month'] ?? ''),
            'target_hours_mode' => (string) ($payload['target_hours_mode'] ?? 'month'),
            'target_hours_week' => (string) ($payload['target_hours_week'] ?? ''),
            'workdays_mask' => implode(',', $this->workdaysFromMask($payload['workdays_mask'] ?? '1,2,3,4,5')),
            'vacation_days_year' => (string) ($payload['vacation_days_year'] ?? ''),
            'vacation_carryover_days' => (string) ($payload['vacation_carryover_days'] ?? ''),
            'emergency_contact_name' => (string) ($payload['emergency_contact_name'] ?? ''),
            'emergency_contact_phone' => (string) ($payload['emergency_contact_phone'] ?? ''),
            'time_tracking_required' => (string) ($payload['time_tracking_required'] ?? '1') === '1' ? 1 : 0,
            'app_ui_settings' => AppUiSettings::normalize($payload['app_ui_settings'] ?? null),
            'role_ids' => $this->selectedRoleIds($payload['role_ids'] ?? []),
        ];
    }

    private function selectedRoleIds(mixed $roleIds): array
    {
        $roleIds = is_array($roleIds) ? $roleIds : [$roleIds];

        return array_values(array_unique(array_map(static fn (mixed $value): int => (int) $value, $roleIds)));
    }

    private function workdaysFromMask(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[,\s;|]+/', trim((string) ($value ?? '')));
        $days = [];

        foreach ($values ?: [] as $day) {
            $day = (int) $day;

            if ($day >= 1 && $day <= 7) {
                $days[] = $day;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    private function weekdayOptions(): array
    {
        return [
            1 => 'Montag',
            2 => 'Dienstag',
            3 => 'Mittwoch',
            4 => 'Donnerstag',
            5 => 'Freitag',
            6 => 'Samstag',
            7 => 'Sonntag',
        ];
    }

    private function renderFormErrorSummary(array $errors, string $headline = 'Benutzer konnte nicht angelegt werden.'): string
    {
        $messages = $this->flattenFormErrors($errors);

        if ($messages === []) {
            return '';
        }

        $items = '';

        foreach ($messages as $message) {
            $items .= '<li>' . $this->e($message) . '</li>';
        }

        return '<div class="notice error" role="alert" aria-live="polite" tabindex="-1"><strong>' . $this->e($headline) . '</strong><ul class="form-error-list">' . $items . '</ul></div>';
    }

    private function fieldErrorClass(array $errors, string $field): string
    {
        return ($errors[$field] ?? []) !== [] ? ' class="is-invalid"' : '';
    }

    private function fieldWrapperClass(array $errors, string $field): string
    {
        $classes = ['form-field'];

        if (($errors[$field] ?? []) !== []) {
            $classes[] = 'is-invalid';
        }

        return ' class="' . implode(' ', $classes) . '"';
    }

    private function fieldGroupErrorClass(array $errors, string $field): string
    {
        return ($errors[$field] ?? []) !== [] ? ' is-invalid' : '';
    }

    private function fieldInvalidAttribute(array $errors, string $field): string
    {
        return ($errors[$field] ?? []) !== []
            ? ' aria-invalid="true" aria-describedby="' . $this->fieldErrorId($field) . '"'
            : '';
    }

    private function fieldErrorMarkup(array $errors, string $field): string
    {
        $messages = array_values(array_filter(array_map('strval', $errors[$field] ?? [])));

        if ($messages === []) {
            return '';
        }

        return '<small class="field-error" id="' . $this->fieldErrorId($field) . '">' . $this->e(implode(' ', $messages)) . '</small>';
    }

    private function fieldErrorId(string $field): string
    {
        return 'field-error-' . preg_replace('/[^a-z0-9_-]+/i', '-', $field);
    }

    private function flattenFormErrors(array $errors): array
    {
        $messages = [];

        foreach ($errors as $fieldErrors) {
            foreach ((array) $fieldErrors as $message) {
                $message = trim((string) $message);

                if ($message !== '') {
                    $messages[] = $message;
                }
            }
        }

        return array_values(array_unique($messages));
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

    private function renderUserPersonnelEventsSection(array $user, array $events, array $eventTypes, bool $canManage): string
    {
        if (!$this->personnelEventService instanceof PersonnelEventService) {
            return '';
        }

        $userId = (int) ($user['id'] ?? 0);
        $csrfToken = $this->e($this->csrfService->token());
        $eventRows = '';

        foreach ($events as $event) {
            $statusClass = match ((string) ($event['status'] ?? 'ok')) {
                'overdue' => 'error',
                'due_soon' => 'warn',
                'completed' => 'ok',
                default => 'ok',
            };
            $eventRows .= '<tr>'
                . '<td><strong>' . $this->e((string) ($event['display_title'] ?? '')) . '</strong><br><span class="muted">' . $this->e((string) ($event['event_type_name'] ?? '')) . '</span></td>'
                . '<td>' . $this->e((string) ($event['due_on'] ?? '')) . '</td>'
                . '<td><span class="badge ' . $statusClass . '">' . $this->e((string) ($event['status_label'] ?? '')) . '</span></td>'
                . '<td>' . $this->e(implode(', ', $event['reminder_channels_list'] ?? [])) . '</td>'
                . '<td>' . ($canManage ? '<a class="button button-secondary" href="/admin/personnel/events?edit=' . (int) ($event['id'] ?? 0) . '">Bearbeiten</a>' : '<span class="muted">Nur Ansicht</span>') . '</td>'
                . '</tr>';
        }

        $typeOptions = $this->personnelEventTypeOptions($eventTypes);
        $eventRowsHtml = $eventRows !== '' ? $eventRows : '<tr><td colspan="5" class="table-empty">Noch keine aktiven Events.</td></tr>';
        $createForm = $canManage && $eventTypes !== [] ? <<<HTML
    <form method="post" action="/admin/personnel/events" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <input type="hidden" name="user_id" value="{$userId}">
        <label><span>Event-Typ</span><select name="event_type_id" required>{$typeOptions}</select></label>
        <label><span>Titel optional</span><input name="title"></label>
        <label><span>Faelligkeit / Termin</span><input type="date" name="due_on" required></label>
        <label><span>Gueltig bis</span><input type="date" name="valid_until"></label>
        <label><span>Reminder Tage vorher</span><input type="number" name="reminder_days" min="0" placeholder="Standard des Event-Typs"></label>
        <div class="field-group full-span">
            <span>Reminder-Kanaele</span>
            <div class="checkbox-grid">
                <label class="checkbox-item"><input type="checkbox" name="reminder_channels[]" value="admin" checked> <span>Admin-Anzeige</span></label>
                <label class="checkbox-item"><input type="checkbox" name="reminder_channels[]" value="push" checked> <span>Push an Mitarbeiter</span></label>
                <label class="checkbox-item"><input type="checkbox" name="reminder_channels[]" value="email" checked> <span>E-Mail an Mitarbeiter</span></label>
            </div>
        </div>
        <label class="full-span"><span>Notiz</span><textarea name="note" rows="3"></textarea></label>
        <button class="button" type="submit">Event hinzufuegen</button>
    </form>
HTML : '';

        return <<<HTML
<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>Personal-Events</h2>
            <p class="muted">Schulungen, Fuehrerscheinmodule, Gueltigkeiten und Erinnerungen fuer diesen Mitarbeiter.</p>
        </div>
        <a class="button button-secondary" href="/admin/personnel/events?user_id={$userId}">Alle Events</a>
    </div>
    {$createForm}
    <div class="table-scroll">
        <table>
            <thead><tr><th>Event</th><th>Faellig</th><th>Status</th><th>Kanaele</th><th>Aktion</th></tr></thead>
            <tbody>{$eventRowsHtml}</tbody>
        </table>
    </div>
</section>
HTML;
    }

    private function renderPersonnelLabelBadges(array $labels): string
    {
        if ($labels === []) {
            return '<span class="muted">-</span>';
        }

        $badges = '';

        foreach (array_slice($labels, 0, 4) as $label) {
            $badges .= PersonnelIconRenderer::badge($label);
        }

        if (count($labels) > 4) {
            $badges .= '<span class="badge">+' . (count($labels) - 4) . '</span>';
        }

        return '<span class="personnel-label-list">' . $badges . '</span>';
    }

    private function renderNextPersonnelEvent(?array $event): string
    {
        if ($event === null) {
            return '<span class="muted">-</span>';
        }

        $statusClass = match ((string) ($event['status'] ?? 'ok')) {
            'overdue' => 'error',
            'due_soon' => 'warn',
            default => 'ok',
        };

        return '<strong>' . $this->e((string) ($event['display_title'] ?? '')) . '</strong><br>'
            . '<span class="badge ' . $statusClass . '">' . $this->e((string) ($event['due_on'] ?? '')) . ' · ' . $this->e((string) ($event['status_label'] ?? '')) . '</span>';
    }

    private function personnelEventTypeOptions(array $eventTypes): string
    {
        $html = '<option value="">Bitte waehlen</option>';

        foreach ($eventTypes as $type) {
            $html .= '<option value="' . (int) ($type['id'] ?? 0) . '">' . $this->e((string) ($type['name'] ?? '')) . '</option>';
        }

        return $html;
    }

    private function renderProjectMembershipSection(array $project, array $users, array $membershipUserIds): string
    {
        $projectId = (int) ($project['id'] ?? 0);
        $csrfToken = $this->csrfService->token();
        $membershipUserIds = array_map(static fn (mixed $value): int => (int) $value, $membershipUserIds);
        $checkboxes = '';
        $users = array_values(array_filter($users, static function (array $user): bool {
            return ($user['employment_status'] ?? 'active') === 'active'
                && (int) ($user['is_deleted'] ?? 0) === 0;
        }));

        usort($users, static function (array $left, array $right) use ($membershipUserIds): int {
            $leftSelected = in_array((int) ($left['id'] ?? 0), $membershipUserIds, true) ? 0 : 1;
            $rightSelected = in_array((int) ($right['id'] ?? 0), $membershipUserIds, true) ? 0 : 1;

            if ($leftSelected !== $rightSelected) {
                return $leftSelected <=> $rightSelected;
            }

            return strcasecmp(
                trim((string) ($left['last_name'] ?? '') . ' ' . (string) ($left['first_name'] ?? '')),
                trim((string) ($right['last_name'] ?? '') . ' ' . (string) ($right['first_name'] ?? ''))
            );
        });

        foreach ($users as $user) {
            $userId = (int) ($user['id'] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $displayName = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
            $label = $displayName !== '' ? $displayName : (string) ($user['email'] ?? ('User #' . $userId));
            $employeeNumber = trim((string) ($user['employee_number'] ?? ''));
            $roles = trim((string) ($user['role_names'] ?? ''));
            $details = [];

            if ($employeeNumber !== '') {
                $details[] = $employeeNumber;
            }

            $details[] = $roles !== '' ? $roles : 'Keine Rolle';

            $checked = in_array($userId, $membershipUserIds, true) ? 'checked' : '';
            $detailText = '<br><small class="muted">' . $this->e(implode(' · ', $details)) . '</small>';
            $checkboxes .= '<label class="checkbox-item"><input type="checkbox" name="user_ids[]" value="' . $userId . '" ' . $checked . '> <span>' . $this->e($label) . $detailText . '</span></label>';
        }

        if ($checkboxes === '') {
            $checkboxes = '<p class="muted">Keine aktiven Mitarbeiter vorhanden.</p>';
        }

        return <<<HTML
<section class="card stack">
    <div class="section-toolbar">
        <div>
            <h2>App-Projektfreigaben</h2>
            <p class="muted">Ausgewaehlte Mitarbeiter sehen dieses Projekt in der mobilen App und koennen darauf Zeiten buchen. Rollen werden zur Orientierung angezeigt.</p>
        </div>
    </div>
    <form method="post" action="/admin/projects/{$projectId}/memberships" class="form-grid">
        <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
        <div class="full-span field-group">
            <span>Mitarbeiter mit App-Zugriff auf dieses Projekt</span>
            <div class="checkbox-grid">{$checkboxes}</div>
        </div>
        <button class="button" type="submit">Projektfreigaben speichern</button>
    </form>
</section>
HTML;
    }

    private function activeAssignableUsers(): array
    {
        return array_values(array_filter($this->userService->list('active'), static function (array $user): bool {
            return ($user['employment_status'] ?? 'active') === 'active'
                && (int) ($user['is_deleted'] ?? 0) === 0;
        }));
    }

    private function renderAttachmentSection(string $title, string $uploadAction, array $files, string $type): string
    {
        $rows = '';
        $csrfToken = $this->csrfService->token();
        $statusOptions = $this->documentStatusOptions();

        foreach ($files as $file) {
            $archiveAction = $type === 'project'
                ? '/admin/project-files/' . (int) $file['id']
                : '/admin/asset-files/' . (int) $file['id'];
            $statusAction = $archiveAction . '/status';
            $status = is_array($file['document_status'] ?? null) ? $file['document_status'] : null;
            $statusBadge = $status !== null
                ? '<span class="document-status-badge" style="--document-status-color: ' . $this->e((string) ($status['color'] ?? '#64748b')) . '">' . $this->e((string) ($status['label'] ?? 'Unbearbeitet')) . '</span>'
                : '<span class="muted">Kein Status</span>';
            $statusForm = ((int) ($file['is_deleted'] ?? 0) === 0)
                ? '<form method="post" action="' . $this->e($statusAction) . '" class="inline-form">'
                    . '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">'
                    . '<select name="document_status_id">' . $this->markSelectedOption($statusOptions, (string) ($status['id'] ?? '')) . '</select>'
                    . '<button class="button button-secondary" type="submit">Status speichern</button>'
                    . '</form>'
                : '';

            $rows .= '<tr>'
                . '<td>' . $this->e((string) $file['original_name']) . '</td>'
                . '<td>' . $this->e((string) $file['mime_type']) . '</td>'
                . '<td>' . $this->e((string) $file['size_bytes']) . '</td>'
                . '<td>' . $this->e((string) $file['uploaded_at']) . '</td>'
                . '<td>' . $statusBadge . '</td>'
                . '<td>' . (((int) ($file['is_deleted'] ?? 0) === 1) ? '<span class="badge warn">Archiviert</span>' : '<span class="badge ok">Aktiv</span>') . '</td>'
                . '<td class="table-actions">' . $statusForm . $this->archiveForm($archiveAction, (int) ($file['is_deleted'] ?? 0) === 1) . '</td>'
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
        <thead><tr><th>Datei</th><th>MIME</th><th>Bytes</th><th>Hochgeladen</th><th>Dokumentstatus</th><th>Archiv</th><th>Aktionen</th></tr></thead>
        <tbody>{$rows}</tbody>
    </table>
</section>
HTML;
    }

    private function documentStatusOptions(): string
    {
        $html = '<option value="">Kein Status</option>';

        foreach ($this->documentStatusService->activeList() as $status) {
            $html .= '<option value="' . $this->e((string) ($status['id'] ?? '')) . '">' . $this->e((string) ($status['label'] ?? '')) . '</option>';
        }

        return $html;
    }

    private function nullableStatusId(Request $request): ?int
    {
        $value = trim((string) $request->input('document_status_id', ''));

        return $value === '' ? null : (int) $value;
    }

    private function markSelectedOption(string $optionsHtml, string $selectedValue): string
    {
        if ($selectedValue === '') {
            return str_replace('value=""', 'value="" selected', $optionsHtml);
        }

        $quoted = 'value="' . $this->e($selectedValue) . '"';

        return str_replace($quoted, $quoted . ' selected', $optionsHtml);
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
                'document_statuses' => $this->documentStatusService->activeList(),
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
        <label><span>Abwesenheitsgrund</span><select name="absence_reason_code">{$this->absenceReasonOptions()}</select></label>
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
            $detail = $error === 'file-upload' ? trim((string) $request->query('error_detail', '')) : '';

            return '<p class="notice error">' . $this->e($message . ($detail !== '' ? ' ' . $detail : '')) . '</p>';
        }

        if ($notice === '') {
            return '';
        }

        $message = match ($notice) {
            'created' => 'Datensatz erfolgreich angelegt.',
            'updated' => 'Datensatz erfolgreich gespeichert.',
            'archived' => 'Datensatz erfolgreich archiviert.',
            'restored' => 'Projekt erfolgreich wiederhergestellt.',
            'memberships-updated' => 'Projektfreigaben erfolgreich gespeichert.',
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

        if ((int) ($booking['project_id'] ?? 0) !== $projectId) {
            return null;
        }

        return $this->withCustomerSignatures([$booking])[0] ?? $booking;
    }

    private function withCustomerSignatures(array $bookings): array
    {
        $timesheetIds = array_map(static fn (array $booking): int => (int) ($booking['id'] ?? 0), $bookings);
        $signatureByTimesheet = $this->timesheetSignatureService?->listForTimesheetsGrouped($timesheetIds) ?? [];

        foreach ($bookings as $index => $booking) {
            $timesheetId = (int) ($booking['id'] ?? 0);
            $bookings[$index]['customer_signature'] = $signatureByTimesheet[$timesheetId] ?? null;
            $bookings[$index]['customer_signature_present'] = isset($signatureByTimesheet[$timesheetId]);
        }

        return $bookings;
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

    private function select(string $name, array $options, string $selected, string $attributes = ''): string
    {
        $html = '<select id="' . $this->e($name) . '" name="' . $this->e($name) . '"' . $attributes . '>';

        if ($selected !== '' && !array_key_exists($selected, $options)) {
            $html .= '<option value="' . $this->e($selected) . '" selected>Ungueltiger Wert: ' . $this->e($selected) . '</option>';
        }

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

    private function absenceReasonOptions(): string
    {
        $html = '<option value="">Nur bei Abwesenheit</option>';

        foreach ($this->bookingService->absenceReasonOptions() as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '">' . $this->e($label) . '</option>';
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
