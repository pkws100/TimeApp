<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Projects\ProjectService;
use App\Http\Request;
use App\Http\Response;

final class ProjectController
{
    public function __construct(private ProjectService $projectService)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->projectService->list((string) $request->query('scope', 'active'))]);
    }

    public function show(Request $request, array $params): Response
    {
        $project = $this->projectService->find((int) $params['id']);

        return $project === null
            ? Response::json(['error' => 'Projekt nicht gefunden.'], 404)
            : Response::json(['data' => $project]);
    }

    public function store(Request $request): Response
    {
        $project = $this->projectService->create($request->input());

        return Response::json(['data' => $project], 201);
    }

    public function update(Request $request, array $params): Response
    {
        $project = $this->projectService->update((int) $params['id'], $request->input());

        return $project === null
            ? Response::json(['error' => 'Projekt nicht gefunden.'], 404)
            : Response::json(['data' => $project]);
    }

    public function archive(Request $request, array $params): Response
    {
        $this->projectService->archive((int) $params['id']);

        return Response::json(['message' => 'Projekt archiviert.']);
    }
}
