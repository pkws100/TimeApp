<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Users\UserService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

final class UserController
{
    public function __construct(private UserService $userService)
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
            return Response::json(['data' => $this->userService->create($request->input())], 201);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request, array $params): Response
    {
        $user = $this->userService->update((int) $params['id'], $request->input());

        return $user === null
            ? Response::json(['error' => 'User nicht gefunden.'], 404)
            : Response::json(['data' => $user]);
    }

    public function archive(Request $request, array $params): Response
    {
        $this->userService->archive((int) $params['id']);

        return Response::json(['message' => 'User archiviert.']);
    }
}
