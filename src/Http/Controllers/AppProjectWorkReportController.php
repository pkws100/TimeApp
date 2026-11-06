<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Projects\ProjectAccessDeniedException;
use App\Domain\Projects\ProjectWorkReportConflictException;
use App\Domain\Projects\ProjectWorkReportService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;
use RuntimeException;

final class AppProjectWorkReportController
{
    public function __construct(
        private ProjectWorkReportService $projectWorkReportService,
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
                'data' => $this->projectWorkReportService->list($user, (int) ($params['id'] ?? 0)),
            ]);
        } catch (ProjectAccessDeniedException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (RuntimeException) {
            return $this->error('Arbeitsberichte konnten nicht geladen werden.', 500);
        }
    }

    public function store(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return $this->error('Bitte erneut anmelden.', 401);
        }

        try {
            $result = $this->projectWorkReportService->create($user, (int) ($params['id'] ?? 0), $request->input());

            return Response::json([
                'ok' => true,
                'message' => $result['replayed'] ? 'Arbeitsbericht wurde bereits gespeichert.' : 'Arbeitsbericht wurde gespeichert.',
                'replayed' => $result['replayed'],
                'data' => $result['entry'],
            ], $result['replayed'] ? 200 : 201);
        } catch (ProjectWorkReportConflictException $exception) {
            return $this->error($exception->getMessage(), 409, 'request_id_conflict');
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (ProjectAccessDeniedException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (RuntimeException) {
            return $this->error('Der Arbeitsbericht konnte nicht gespeichert werden.', 500);
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
                'message' => 'Arbeitsbericht wurde archiviert.',
                'data' => $this->projectWorkReportService->archive($user, (int) ($params['id'] ?? 0)),
            ]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (ProjectAccessDeniedException $exception) {
            return $this->error($exception->getMessage(), 403);
        } catch (RuntimeException) {
            return $this->error('Der Arbeitsbericht konnte nicht archiviert werden.', 500);
        }
    }

    private function error(string $message, int $status, ?string $code = null): Response
    {
        $payload = ['ok' => false, 'error' => $message, 'message' => $message];

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return Response::json($payload, $status);
    }
}
