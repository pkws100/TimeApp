<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

final class ProjectController
{
    public function __construct(
        private ProjectService $projectService,
        private AuthService $authService,
        private ProjectAccessService $projectAccessService
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['error' => 'Bitte erneut anmelden.', 'message' => 'Bitte erneut anmelden.'], 401);
        }

        return Response::json([
            'data' => !$this->projectAccessService->hasGlobalAccess($user)
                ? $this->projectService->listForUser($user)
                : $this->projectService->list((string) $request->query('scope', 'active')),
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $projectId = (int) $params['id'];
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['error' => 'Bitte erneut anmelden.', 'message' => 'Bitte erneut anmelden.'], 401);
        }

        $project = $this->projectAccessService->canAccess($user, $projectId)
            ? $this->projectService->find($projectId)
            : null;

        return $project === null
            ? Response::json(['error' => 'Projekt nicht gefunden.'], 404)
            : Response::json(['data' => $project]);
    }

    public function store(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['error' => 'Bitte erneut anmelden.', 'message' => 'Bitte erneut anmelden.'], 401);
        }

        try {
            $project = $this->projectService->create(
                $request->input(),
                (int) ($user['id'] ?? 0)
            );
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage(), 'message' => $exception->getMessage()], 422);
        }

        return Response::json(['data' => $project], 201);
    }

    public function update(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['error' => 'Bitte erneut anmelden.', 'message' => 'Bitte erneut anmelden.'], 401);
        }

        try {
            $project = $this->projectService->update(
                (int) $params['id'],
                $request->input(),
                (int) ($user['id'] ?? 0)
            );
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage(), 'message' => $exception->getMessage()], 422);
        }

        return $project === null
            ? Response::json(['error' => 'Projekt nicht gefunden.'], 404)
            : Response::json(['data' => $project]);
    }

    public function archive(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['error' => 'Bitte erneut anmelden.', 'message' => 'Bitte erneut anmelden.'], 401);
        }

        $this->projectService->archive((int) $params['id'], (int) ($user['id'] ?? 0));

        return Response::json(['message' => 'Projekt archiviert.']);
    }
}
