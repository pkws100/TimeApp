<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Timesheets\TimesheetSignatureException;
use App\Domain\Timesheets\TimesheetSignatureService;
use App\Http\Request;
use App\Http\Response;

final class AppTimesheetSignatureController
{
    public function __construct(
        private TimesheetSignatureService $signatureService,
        private AuthService $authService
    ) {
    }

    public function status(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return $this->authRequired();
        }

        try {
            return Response::json([
                'ok' => true,
                'data' => $this->signatureService->statusForApp((int) ($params['id'] ?? 0), (int) $user['id']),
            ]);
        } catch (TimesheetSignatureException $exception) {
            return $this->error($exception);
        }
    }

    public function store(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return $this->authRequired();
        }

        try {
            $data = $this->signatureService->storeForApp(
                (int) ($params['id'] ?? 0),
                (int) $user['id'],
                $request->input(),
                [
                    'ip' => (string) $request->server('REMOTE_ADDR', ''),
                    'user_agent' => (string) $request->server('HTTP_USER_AGENT', ''),
                ]
            );

            return Response::json([
                'ok' => true,
                'message' => 'Kundenbestaetigung gespeichert.',
                'data' => $data,
            ], (bool) ($data['idempotent'] ?? false) ? 200 : 201);
        } catch (TimesheetSignatureException $exception) {
            return $this->error($exception);
        }
    }

    public function image(Request $request, array $params): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return $this->authRequired();
        }

        $file = $this->signatureService->findImageForApp((int) ($params['id'] ?? 0), (int) $user['id']);

        if ($file === null) {
            return new Response('Unterschrift nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $this->imageResponse($file);
    }

    private function authRequired(): Response
    {
        return Response::json([
            'ok' => false,
            'code' => 'auth_required',
            'error' => 'Nicht authentifiziert.',
            'message' => 'Bitte erneut anmelden.',
        ], 401);
    }

    private function error(TimesheetSignatureException $exception): Response
    {
        return Response::json([
            'ok' => false,
            'error' => $exception->getMessage(),
            'message' => $exception->getMessage(),
        ], $exception->statusCode());
    }

    private function imageResponse(array $file): Response
    {
        $content = file_get_contents((string) $file['path']);

        if ($content === false) {
            return new Response('Unterschrift nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response($content, 200, [
            'Content-Type' => (string) ($file['mime_type'] ?? 'image/png'),
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'inline; filename="' . (string) ($file['filename'] ?? 'kundenbestaetigung.png') . '"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
