<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Vacation\VacationRequestService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

final class AppVacationRequestController
{
    public function __construct(
        private VacationRequestService $vacationRequestService,
        private AuthService $authService
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        return Response::json([
            'ok' => true,
            'data' => [
                'items' => $this->vacationRequestService->listForUser((int) $user['id']),
            ],
        ]);
    }

    public function preview(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->vacationRequestService->preview((int) $user['id'], $request->query()),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->vacationRequestService->createForUser((int) $user['id'], $request->input()),
            ], 201);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function cancel(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->vacationRequestService->cancelOwnPending((int) ($params['id'] ?? 0), (int) $user['id']),
            ]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
