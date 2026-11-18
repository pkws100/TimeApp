<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Personnel\Compliance\PersonnelDelegationDenied;
use App\Domain\Users\RoleService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class RoleController
{
    public function __construct(private RoleService $roleService, private AuthService $authService)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json([
            'data' => $this->roleService->list((string) $request->query('scope', 'active')),
            'permissions' => $this->roleService->availablePermissions(),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $role = $this->roleService->find((int) $params['id']);

        return $role === null
            ? Response::json(['error' => 'Rolle nicht gefunden.'], 404)
            : Response::json(['data' => $role, 'permissions' => $this->roleService->availablePermissions()]);
    }

    public function store(Request $request): Response
    {
        try {
            return Response::json(['data' => $this->roleService->create($request->input(), $request->csrfToken())], 201);
        } catch (PersonnelDelegationDenied $exception) {
            return Response::json(['error' => $exception->getMessage()], 403);
        }
    }

    public function update(Request $request, array $params): Response
    {
        try {
            $role = $this->roleService->update((int) $params['id'], $request->input(), $request->csrfToken());
        } catch (PersonnelDelegationDenied $exception) {
            return Response::json(['error' => $exception->getMessage()], 403);
        }

        return $role === null
            ? Response::json(['error' => 'Rolle nicht gefunden.'], 404)
            : Response::json(['data' => $role]);
    }

    public function archive(Request $request, array $params): Response
    {
        $actor = $this->authService->currentUser();
        if ($actor === null) {
            return Response::json(['error' => 'Anmeldung erforderlich.'], 401);
        }

        try {
            $this->roleService->archive((int) $params['id'], (int) $actor['id'], $request->csrfToken());

            return Response::json(['message' => 'Rolle archiviert.']);
        } catch (PersonnelDelegationDenied $exception) {
            return Response::json(['error' => $exception->getMessage()], 403);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }
}
