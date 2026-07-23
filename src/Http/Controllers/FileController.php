<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Files\FileAttachmentService;
use App\Domain\Projects\ProjectAccessService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class FileController
{
    public function __construct(
        private FileAttachmentService $fileAttachmentService,
        private AuthService $authService,
        private ProjectAccessService $projectAccessService
    ) {
    }

    public function listProjectFiles(Request $request, array $params): Response
    {
        if (!$this->canAccessProject((int) $params['id'])) {
            return Response::json(['error' => 'Projekt nicht gefunden.', 'message' => 'Keine Berechtigung.'], 404);
        }

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
            if (!$this->canAccessProject((int) $params['id'])) {
                return Response::json(['error' => 'Projekt nicht gefunden.', 'message' => 'Keine Berechtigung.'], 404);
            }

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
        $file = $this->fileAttachmentService->findProjectFile((int) $params['id']);

        if ($file === null || !$this->canAccessProject((int) ($file['project_id'] ?? 0))) {
            return Response::json(['error' => 'Projektdatei nicht gefunden.'], 404);
        }

        $this->fileAttachmentService->archiveProjectFile((int) $params['id']);

        return Response::json(['message' => 'Projektdatei archiviert.']);
    }

    public function archiveAssetFile(Request $request, array $params): Response
    {
        $this->fileAttachmentService->archiveAssetFile((int) $params['id']);

        return Response::json(['message' => 'Geraetedatei archiviert.']);
    }

    private function canAccessProject(int $projectId): bool
    {
        $user = $this->authService->currentUser();

        return $user !== null && $this->projectAccessService->canAccess($user, $projectId);
    }
}
