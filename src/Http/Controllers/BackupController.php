<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Backup\BackupService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class BackupController
{
    public function __construct(private BackupService $backupService)
    {
    }

    public function export(Request $request): Response
    {
        try {
            $export = $this->backupService->export();

            return new Response((string) $export['content'], 200, $export['headers']);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 503);
        }
    }

    public function validateImport(Request $request): Response
    {
        try {
            $file = $request->files()['backup'] ?? null;

            if (!is_array($file)) {
                return Response::json(['error' => 'Bitte eine Backup-Datei im Feld "backup" hochladen.'], 422);
            }

            return Response::json($this->backupService->validateImport($file));
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }
}
