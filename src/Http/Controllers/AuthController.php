<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\RouteGuard;
use App\Http\Request;
use App\Http\Response;

final class AuthController
{
    public function __construct(private AuthService $authService)
    {
    }

    public function login(Request $request): Response
    {
        $result = $this->authService->login(
            (string) $request->input('email', ''),
            (string) $request->input('password', '')
        );

        return Response::json($result, $result['ok'] ? 200 : 401, ['Cache-Control' => 'private, no-store']);
    }

    public function logout(Request $request): Response
    {
        $accountBound = $request->server('HTTP_X_TIMEAPP_USER_ID') !== null;
        if ($accountBound) {
            // Session startup can issue a cookie if a newer login already replaced this session.
            header_remove('Set-Cookie');
        }
        $user = $this->authService->currentUser();
        if ($user !== null && !RouteGuard::matchesExpectedAccount($request, (int) $user['id'])) {
            return RouteGuard::sessionChangedResponse();
        }
        if ($user !== null || !$accountBound) {
            $this->authService->logout(regenerateSessionId: !$accountBound);
        }

        return Response::json(['ok' => true, 'message' => 'Logout erfolgreich.'], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function session(Request $request): Response
    {
        return Response::json($this->authService->sessionPayload(), 200, ['Cache-Control' => 'private, no-store']);
    }
}
