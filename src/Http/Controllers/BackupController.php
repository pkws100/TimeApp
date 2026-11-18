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
        $requireComplete = $request->query('require_complete', '0');
        if (!in_array($requireComplete, ['0', '1'], true)) {
            return Response::json(['ok' => false, 'error' => 'require_complete muss 0 oder 1 sein.'], 422);
        }

        try {
            $export = $this->backupService->export($requireComplete === '1');

            return new Response((string) $export['content'], 200, $export['headers']);
        } catch (RuntimeException $exception) {
            return Response::json(['ok' => false, 'error' => $exception->getMessage()], 503);
        }
    }

    public function validateImport(Request $request): Response
    {
        try {
            $file = $request->files()['backup'] ?? null;

            if (!is_array($file)) {
                return Response::json([
                    'ok' => false,
                    'validation_status' => 'invalid',
                    'apply_supported' => false,
                    'error' => 'Bitte eine Backup-Datei im Feld "backup" hochladen.',
                ], 422);
            }

            return Response::json($this->backupService->validateImport($file), headers: ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $exception) {
            return Response::json([
                'ok' => false,
                'validation_status' => 'invalid',
                'apply_supported' => false,
                'error' => $exception->getMessage(),
            ], 422);
        }
    }
}
