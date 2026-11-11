<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Projects\ProjectAccessDeniedException;
use App\Domain\Projects\ProjectMaterialConflictException;
use App\Domain\Projects\ProjectMaterialService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;
use RuntimeException;

final class AppProjectMaterialController
{
    public function __construct(
        private ProjectMaterialService $projectMaterialService,
        private AuthService $authService
    ) {
    }

    public function index(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return $this->error('Bitte erneut anmelden.', 401);
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->projectMaterialService->list($user, (int) ($params['id'] ?? 0)),
            ]);
        } catch (ProjectAccessDeniedException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (RuntimeException) {
            return $this->error('Materialeintraege konnten nicht geladen werden.', 500);
        }
    }

    public function store(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return $this->error('Bitte erneut anmelden.', 401);
        }

        try {
            $result = $this->projectMaterialService->createWithResult($user, (int) ($params['id'] ?? 0), $request->input());

            return Response::json([
                'ok' => true,
                'message' => $result['replayed'] ? 'Materialeintrag wurde bereits gespeichert.' : 'Materialeintrag wurde gespeichert.',
                'replayed' => $result['replayed'],
                'data' => $result['entry'],
            ], $result['replayed'] ? 200 : 201);
        } catch (ProjectMaterialConflictException $exception) {
            return Response::json(['ok' => false, 'error' => 'request_id_conflict', 'message' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (ProjectAccessDeniedException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (RuntimeException) {
            return $this->error('Der Materialeintrag konnte nicht gespeichert werden.', 500);
        }
    }

    public function archive(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return $this->error('Bitte erneut anmelden.', 401);
        }

        try {
            return Response::json([
                'ok' => true,
                'message' => 'Materialeintrag wurde archiviert.',
                'data' => $this->projectMaterialService->archive($user, (int) ($params['id'] ?? 0)),
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 404);
        } catch (ProjectAccessDeniedException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (RuntimeException) {
            return $this->error('Der Materialeintrag konnte nicht archiviert werden.', 500);
        }
    }

    private function error(string $message, int $status): Response
    {
        return Response::json([
            'ok' => false,
            'error' => $message,
            'message' => $message,
        ], $status);
    }
}
