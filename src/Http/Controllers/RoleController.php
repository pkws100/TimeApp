<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Users\RoleService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class RoleController
{
    public function __construct(private RoleService $roleService)
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
        return Response::json(['data' => $this->roleService->create($request->input())], 201);
    }

    public function update(Request $request, array $params): Response
    {
        $role = $this->roleService->update((int) $params['id'], $request->input());

        return $role === null
            ? Response::json(['error' => 'Rolle nicht gefunden.'], 404)
            : Response::json(['data' => $role]);
    }

    public function archive(Request $request, array $params): Response
    {
        try {
            $this->roleService->archive((int) $params['id']);

            return Response::json(['message' => 'Rolle archiviert.']);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }
}
