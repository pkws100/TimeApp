<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Files\FileAttachmentService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class AppTimesheetAttachmentController
{
    public function __construct(
        private FileAttachmentService $fileAttachmentService,
        private AuthService $authService
    ) {
    }

    public function index(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Nicht authentifiziert.',
                'message' => 'Bitte erneut anmelden.',
            ], 401);
        }

        $timesheetId = (int) ($params['id'] ?? 0);

        if (!$this->fileAttachmentService->timesheetBelongsToUser($timesheetId, (int) $user['id'])) {
            return Response::json([
                'ok' => false,
                'error' => 'Keine Berechtigung.',
                'message' => 'Zu diesem Zeiteintrag koennen keine Dateien angezeigt werden.',
            ], 403);
        }

        return Response::json([
            'ok' => true,
            'data' => $this->fileAttachmentService->listForTimesheet($timesheetId, (string) $request->query('scope', 'active')),
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

        $timesheetId = (int) ($params['id'] ?? 0);

        if (!$this->fileAttachmentService->timesheetBelongsToUser($timesheetId, (int) $user['id'])) {
            return Response::json([
                'ok' => false,
                'error' => 'Keine Berechtigung.',
                'message' => 'Zu diesem Zeiteintrag koennen keine Dateien hochgeladen werden.',
            ], 403);
        }

        try {
            $files = $request->files();
            $file = $files['file'] ?? null;

            if (!is_array($file)) {
                return Response::json([
                    'ok' => false,
                    'error' => 'Keine Datei uebergeben.',
                    'message' => 'Bitte zuerst ein Bild auswaehlen.',
                ], 422);
            }

            $stored = $this->fileAttachmentService->storeTimesheet($file, $timesheetId, (int) $user['id']);

            return Response::json([
                'ok' => true,
                'message' => 'Bild erfolgreich zum Zeiteintrag hinzugefuegt.',
                'data' => [
                    'file' => $stored,
                    'files' => $this->fileAttachmentService->listForTimesheet($timesheetId),
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

    public function archive(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Nicht authentifiziert.',
                'message' => 'Bitte erneut anmelden.',
            ], 401);
        }

        $fileId = (int) ($params['id'] ?? 0);

        if (!$this->fileAttachmentService->timesheetFileBelongsToUser($fileId, (int) $user['id'])) {
            return Response::json([
                'ok' => false,
                'error' => 'Keine Berechtigung.',
                'message' => 'Diese Datei kann nicht entfernt werden.',
            ], 403);
        }

        $file = $this->fileAttachmentService->findTimesheetFile($fileId);
        $timesheetId = (int) ($file['timesheet_id'] ?? 0);

        $this->fileAttachmentService->archiveTimesheetFile($fileId, (int) $user['id']);

        return Response::json([
            'ok' => true,
            'message' => 'Bild erfolgreich entfernt.',
            'data' => [
                'files' => $timesheetId > 0 ? $this->fileAttachmentService->listForTimesheet($timesheetId) : [],
            ],
        ]);
    }
}
