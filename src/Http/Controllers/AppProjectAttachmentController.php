<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Files\FileAttachmentService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class AppProjectAttachmentController
{
    public function __construct(
        private FileAttachmentService $fileAttachmentService,
        private AuthService $authService
    ) {
    }

    public function index(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();
        $projectId = (int) ($params['id'] ?? 0);

        if ($user === null || !$this->canAccessProject($projectId, (int) $user['id'])) {
            return Response::json([
                'ok' => false,
                'error' => 'Keine Berechtigung.',
                'message' => 'Zu diesem Projekt koennen keine Dateien angezeigt werden.',
            ], 403);
        }

        return Response::json([
            'ok' => true,
            'data' => $this->fileAttachmentService->listForProject(
                $projectId,
                (string) $request->query('scope', 'active')
            ),
        ]);
    }

    public function upload(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Nicht authentifiziert.',
                'message' => 'Bitte erneut anmelden.',
            ], 401);
        }

        try {
            $files = $request->files();
            $file = $files['file'] ?? null;

            if (!is_array($file)) {
                return Response::json([
                    'ok' => false,
                    'error' => 'Keine Datei uebergeben.',
                    'message' => 'Bitte zuerst eine Datei auswaehlen.',
                ], 422);
            }

            $projectId = (int) ($params['id'] ?? 0);

            if (!$this->canAccessProject($projectId, (int) $user['id'])) {
                return Response::json([
                    'ok' => false,
                    'error' => 'Keine Berechtigung.',
                    'message' => 'Zu diesem Projekt koennen keine Dateien hochgeladen werden.',
                ], 403);
            }

            $stored = $this->fileAttachmentService->storeProject($file, $projectId, (int) $user['id']);

            return Response::json([
                'ok' => true,
                'message' => 'Datei erfolgreich zum Projekt hinzugefuegt.',
                'data' => [
                    'file' => $stored,
                    'files' => $this->fileAttachmentService->listForProject($projectId),
                ],
            ], 201);
        } catch (RuntimeException $exception) {
            return Response::json([
                'ok' => false,
                'error' => $exception->getMessage(),
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function download(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();
        $fileId = (int) ($params['id'] ?? 0);

        if ($user === null || !$this->canAccessProjectFile($fileId, (int) $user['id'])) {
            return new Response('Datei nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $file = $this->fileAttachmentService->downloadableProjectFile($fileId);

        return $this->downloadResponse($file);
    }

    private function canAccessProject(int $projectId, int $userId): bool
    {
        return $this->authService->hasPermission('projects.manage')
            || $this->authService->hasPermission('files.manage')
            || $this->fileAttachmentService->projectBelongsToUser($projectId, $userId);
    }

    private function canAccessProjectFile(int $fileId, int $userId): bool
    {
        return $this->authService->hasPermission('projects.manage')
            || $this->authService->hasPermission('files.manage')
            || $this->fileAttachmentService->projectFileBelongsToUserProject($fileId, $userId);
    }

    private function downloadResponse(?array $file): Response
    {
        if ($file === null) {
            return new Response('Datei nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $content = file_get_contents((string) $file['path']);

        if ($content === false) {
            return new Response('Datei nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', (string) $file['original_name']) ?: 'download.bin';
        $disposition = ((bool) ($file['is_image'] ?? false) ? 'inline' : 'attachment') . '; filename="' . $filename . '"';

        return new Response($content, 200, [
            'Content-Type' => (string) $file['mime_type'],
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
