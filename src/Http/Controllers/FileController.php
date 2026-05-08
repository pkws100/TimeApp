<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Files\FileAttachmentService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class FileController
{
    public function __construct(private FileAttachmentService $fileAttachmentService)
    {
    }

    public function listProjectFiles(Request $request, array $params): Response
    {
        return Response::json([
            'data' => $this->fileAttachmentService->listForProject(
                (int) $params['id'],
                (string) $request->query('scope', 'active')
            ),
        ]);
    }

    public function listAssetFiles(Request $request, array $params): Response
    {
        return Response::json([
            'data' => $this->fileAttachmentService->listForAsset(
                (int) $params['id'],
                (string) $request->query('scope', 'active')
            ),
        ]);
    }

    public function uploadProject(Request $request, array $params): Response
    {
        try {
            $files = $request->files();
            $file = $files['file'] ?? null;

            if (!is_array($file)) {
                return Response::json(['error' => 'Keine Datei uebergeben.'], 422);
            }

            $stored = $this->fileAttachmentService->storeProject($file, (int) $params['id']);

            return Response::json(['data' => $stored], 201);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function uploadAsset(Request $request, array $params): Response
    {
        try {
            $files = $request->files();
            $file = $files['file'] ?? null;

            if (!is_array($file)) {
                return Response::json(['error' => 'Keine Datei uebergeben.'], 422);
            }

            $stored = $this->fileAttachmentService->storeAsset($file, (int) $params['id']);

            return Response::json(['data' => $stored], 201);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }

    public function archiveProjectFile(Request $request, array $params): Response
    {
        $this->fileAttachmentService->archiveProjectFile((int) $params['id']);

        return Response::json(['message' => 'Projektdatei archiviert.']);
    }

    public function archiveAssetFile(Request $request, array $params): Response
    {
        $this->fileAttachmentService->archiveAssetFile((int) $params['id']);

        return Response::json(['message' => 'Geraetedatei archiviert.']);
    }
}
