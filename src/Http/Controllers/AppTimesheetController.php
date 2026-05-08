<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class AppTimesheetController
{
    public function __construct(
        private AppTimesheetSyncService $syncService,
        private AuthService $authService
    ) {
    }

    public function sync(Request $request): Response
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
            $result = $this->syncService->sync((int) $user['id'], $request->input());

            return Response::json([
                'ok' => true,
                'message' => (string) ($result['message'] ?? 'Aenderung erfolgreich gespeichert.'),
                'data' => $result,
            ]);
        } catch (RuntimeException $exception) {
            return Response::json([
                'ok' => false,
                'error' => $exception->getMessage(),
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
