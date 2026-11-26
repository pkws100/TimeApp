<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\RouteGuard;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\WorkSessionConflict;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;
use InvalidArgumentException;

final class AppTimesheetController
{
    public function __construct(
        private AppTimesheetSyncService $syncService,
        private AuthService $authService,
        private ProjectAccessService $projectAccess
    ) {
    }

    public function sync(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Nicht authentifiziert.',
                'message' => 'Bitte erneut anmelden.',
            ], 401);
        }

        try {
            $result = $this->syncService->sync($user, $request->input());
            $currentUser = $this->authService->currentUser();
            if ($currentUser === null || $currentUser['id'] !== $user['id']) {
                return RouteGuard::sessionChangedResponse();
            }
            if (is_array($result['timesheet'] ?? null)) {
                $result['timesheet'] = $this->visibleEntry($result['timesheet'], $currentUser);
                if (isset($result['today_state']['work_entry'])) {
                    $result['today_state']['work_entry'] = $result['timesheet'];
                }
            }
            if (is_array($result['presence_session'] ?? null)) {
                $result['presence_session'] = $this->visibleEntry($result['presence_session'], $currentUser);
            }

            return Response::json([
                'ok' => true,
                'message' => (string) ($result['message'] ?? 'Aenderung erfolgreich gespeichert.'),
                'data' => $result,
            ]);
        } catch (WorkSessionConflict $exception) {
            $project = $exception->visibleProject;
            $message = $exception->getMessage();
            if ($project !== null && !$this->projectAccess->canAccess($this->authService->currentUser() ?? [], (int) $project['project_id'])) {
                $project = null;
                $message = WorkSessionConflict::ACTIVE_MESSAGE;
            }
            return Response::json([
                'ok' => false,
                'code' => $exception->reason,
                'error' => $message,
                'message' => $message,
                ...($project === null ? [] : ['data' => ['active_work_session' => $project]]),
            ], 409);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return Response::json([
                'ok' => false,
                'error' => $exception->getMessage(),
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    private function visibleEntry(array $entry, array $user): array
    {
        if (isset($entry['project_id']) && !$this->projectAccess->canAccess($user, (int) $entry['project_id'])) {
            $entry['project_id'] = null;
            $entry['project_unavailable'] = true;
            unset($entry['project_number'], $entry['project_name'], $entry['project_customer_name'],
                $entry['customer_signature_name'], $entry['customer_signature_required']);
        }

        return $entry;
    }
}
