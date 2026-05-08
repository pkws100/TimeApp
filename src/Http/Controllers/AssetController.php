<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Assets\AssetService;
use App\Http\Request;
use App\Http\Response;

final class AssetController
{
    public function __construct(private AssetService $assetService)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->assetService->list((string) $request->query('scope', 'active'))]);
    }

    public function show(Request $request, array $params): Response
    {
        $asset = $this->assetService->find((int) $params['id']);

        return $asset === null
            ? Response::json(['error' => 'Geraet nicht gefunden.'], 404)
            : Response::json(['data' => $asset]);
    }

    public function store(Request $request): Response
    {
        return Response::json(['data' => $this->assetService->create($request->input())], 201);
    }

    public function update(Request $request, array $params): Response
    {
        $asset = $this->assetService->update((int) $params['id'], $request->input());

        return $asset === null
            ? Response::json(['error' => 'Geraet nicht gefunden.'], 404)
            : Response::json(['data' => $asset]);
    }

    public function archive(Request $request, array $params): Response
    {
        $this->assetService->archive((int) $params['id']);

        return Response::json(['message' => 'Geraet archiviert.']);
    }
}
