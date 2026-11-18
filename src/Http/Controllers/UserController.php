<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Personnel\Compliance\PersonnelDelegationDenied;
use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

final class UserController
{
    public function __construct(private UserService $userService, private AuthService $authService)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->userService->list((string) $request->query('scope', 'active'))]);
    }

    public function show(Request $request, array $params): Response
    {
        $user = $this->userService->find((int) $params['id']);

        return $user === null
            ? Response::json(['error' => 'User nicht gefunden.'], 404)
            : Response::json(['data' => $user]);
    }

    public function store(Request $request): Response
    {
        try {
            return Response::json(['data' => $this->userService->create($request->input(), $request->csrfToken())], 201);
        } catch (PersonnelDelegationDenied $exception) {
            return Response::json(['error' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request, array $params): Response
    {
        try {
            $user = $this->userService->update((int) $params['id'], $request->input(), $request->csrfToken());
        } catch (PersonnelDelegationDenied $exception) {
            return Response::json(['error' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }

        return $user === null
            ? Response::json(['error' => 'User nicht gefunden.'], 404)
            : Response::json(['data' => $user]);
    }

    public function archive(Request $request, array $params): Response
    {
        $actor = $this->authService->currentUser();
        if ($actor === null) {
            return Response::json(['error' => 'Anmeldung erforderlich.'], 401);
        }

        try {
            $this->userService->archive((int) $params['id'], (int) $actor['id'], $request->csrfToken());
        } catch (PersonnelDelegationDenied $exception) {
            return Response::json(['error' => $exception->getMessage()], 403);
        }

        return Response::json(['message' => 'User archiviert.']);
    }
}
