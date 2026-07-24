<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Timesheets\AbsencePeriodService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

final class AdminAbsencePeriodController
{
    public function __construct(
        private AbsencePeriodService $absencePeriodService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function previewCalendar(Request $request): Response
    {
        return $this->previewCreate($request, 'admin_calendar', 'timesheets.manage');
    }

    public function createCalendar(Request $request): Response
    {
        return $this->create($request, 'admin_calendar', 'timesheets.manage');
    }

    public function previewVacation(Request $request): Response
    {
        return $this->previewCreate($request, 'admin_vacation', 'vacation_requests.manage');
    }

    public function createVacation(Request $request): Response
    {
        return $this->create($request, 'admin_vacation', 'vacation_requests.manage');
    }

    public function show(Request $request, array $params): Response
    {
        $period = $this->absencePeriodService->find((int) ($params['id'] ?? 0));

        if ($period === null || !$this->canView($period)) {
            return Response::json(['ok' => false, 'message' => 'Der Abwesenheitszeitraum wurde nicht gefunden.'], 404);
        }

        return Response::json([
            'ok' => true,
            'data' => [
                ...$period,
                'can_manage' => $this->canManage($period),
                'can_archive' => $this->canArchive($period),
            ],
        ]);
    }

    public function previewExisting(Request $request, array $params): Response
    {
        $periodId = (int) ($params['id'] ?? 0);
        $period = $this->absencePeriodService->find($periodId);

        if ($period === null || !$this->canManage($period)) {
            return Response::json(['ok' => false, 'message' => 'Keine Berechtigung fuer diesen Abwesenheitszeitraum.'], 403);
        }
        if ($this->targetsCalendarVacation($request, $period)
            && !$this->authService->hasPermission('vacation_requests.manage')) {
            return Response::json(['ok' => false, 'message' => 'Direkte Urlaubsbuchungen benoetigen zusaetzlich das Recht zur Urlaubsverwaltung.'], 403);
        }

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::json(['ok' => false, 'message' => 'Die Sicherheitspruefung ist abgelaufen.'], 419);
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->absencePeriodService->preview(
                    $request->input(),
                    $periodId,
                    (string) $period['source']
                ),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request, array $params): Response
    {
        $periodId = (int) ($params['id'] ?? 0);
        $period = $this->absencePeriodService->find($periodId);

        if ($period === null || !$this->canManage($period)) {
            return Response::json(['ok' => false, 'message' => 'Keine Berechtigung fuer diesen Abwesenheitszeitraum.'], 403);
        }
        if ($this->targetsCalendarVacation($request, $period)
            && !$this->authService->hasPermission('vacation_requests.manage')) {
            return Response::json(['ok' => false, 'message' => 'Direkte Urlaubsbuchungen benoetigen zusaetzlich das Recht zur Urlaubsverwaltung.'], 403);
        }

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::json(['ok' => false, 'message' => 'Die Sicherheitspruefung ist abgelaufen.'], 419);
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->absencePeriodService->update(
                    $periodId,
                    $request->input(),
                    (int) ($this->authService->currentUser()['id'] ?? 0)
                ),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function archive(Request $request, array $params): Response
    {
        $periodId = (int) ($params['id'] ?? 0);
        $period = $this->absencePeriodService->find($periodId);

        if ($period === null || !$this->canArchive($period)) {
            return Response::json(['ok' => false, 'message' => 'Keine Berechtigung zum Archivieren dieses Zeitraums.'], 403);
        }

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::json(['ok' => false, 'message' => 'Die Sicherheitspruefung ist abgelaufen.'], 419);
        }

        try {
            $this->absencePeriodService->archive(
                $periodId,
                (int) ($this->authService->currentUser()['id'] ?? 0),
                (string) $request->input('change_reason', ''),
                (int) $request->input('lock_version', 0)
            );

            return Response::json(['ok' => true]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function previewCreate(Request $request, string $source, string $permission): Response
    {
        if (!$this->authService->hasPermission($permission)) {
            return Response::json(['ok' => false, 'message' => 'Keine Berechtigung fuer diese Zeitraumart.'], 403);
        }
        if ($this->targetsCalendarVacation($request)
            && !$this->authService->hasPermission('vacation_requests.manage')) {
            return Response::json(['ok' => false, 'message' => 'Direkte Urlaubsbuchungen benoetigen zusaetzlich das Recht zur Urlaubsverwaltung.'], 403);
        }

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::json(['ok' => false, 'message' => 'Die Sicherheitspruefung ist abgelaufen.'], 419);
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->absencePeriodService->preview($request->input(), null, $source),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function create(Request $request, string $source, string $permission): Response
    {
        if (!$this->authService->hasPermission($permission)) {
            return Response::json(['ok' => false, 'message' => 'Keine Berechtigung fuer diese Zeitraumart.'], 403);
        }
        if ($this->targetsCalendarVacation($request)
            && !$this->authService->hasPermission('vacation_requests.manage')) {
            return Response::json(['ok' => false, 'message' => 'Direkte Urlaubsbuchungen benoetigen zusaetzlich das Recht zur Urlaubsverwaltung.'], 403);
        }

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::json(['ok' => false, 'message' => 'Die Sicherheitspruefung ist abgelaufen.'], 419);
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->absencePeriodService->create(
                    $request->input(),
                    (int) ($this->authService->currentUser()['id'] ?? 0),
                    $source
                ),
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function canView(array $period): bool
    {
        $source = (string) ($period['source'] ?? '');

        if (in_array($source, ['admin_vacation', 'vacation_request'], true)) {
            return $this->authService->hasPermission('vacation_requests.view')
                || $this->authService->hasPermission('vacation_requests.manage');
        }

        return $this->authService->hasPermission('timesheets.view')
            || $this->authService->hasPermission('timesheets.manage')
            || $this->authService->hasPermission('timesheets.archive')
            || (
                (string) ($period['entry_type'] ?? '') === 'vacation'
                && (
                    $this->authService->hasPermission('vacation_requests.view')
                    || $this->authService->hasPermission('vacation_requests.manage')
                )
            );
    }

    private function canManage(array $period): bool
    {
        if (in_array((string) ($period['source'] ?? ''), ['admin_vacation', 'vacation_request'], true)) {
            return $this->authService->hasPermission('vacation_requests.manage');
        }

        return $this->authService->hasPermission('timesheets.manage')
            && (
                (string) ($period['entry_type'] ?? '') !== 'vacation'
                || $this->authService->hasPermission('vacation_requests.manage')
            );
    }

    private function canArchive(array $period): bool
    {
        if (in_array((string) ($period['source'] ?? ''), ['admin_vacation', 'vacation_request'], true)) {
            return $this->authService->hasPermission('vacation_requests.manage');
        }

        return $this->authService->hasPermission('timesheets.archive')
            && (
                (string) ($period['entry_type'] ?? '') !== 'vacation'
                || $this->authService->hasPermission('vacation_requests.manage')
            );
    }

    private function targetsCalendarVacation(Request $request, ?array $period = null): bool
    {
        $source = (string) ($period['source'] ?? 'admin_calendar');
        $fallbackType = (string) ($period['entry_type'] ?? 'vacation');

        return $source === 'admin_calendar'
            && (string) $request->input('entry_type', $fallbackType) === 'vacation';
    }
}
