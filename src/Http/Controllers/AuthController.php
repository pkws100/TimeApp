<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
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

        return Response::json($result, $result['ok'] ? 200 : 401);
    }

    public function logout(Request $request): Response
    {
        $this->authService->logout();

        return Response::json(['ok' => true, 'message' => 'Logout erfolgreich.']);
    }

    public function session(Request $request): Response
    {
        return Response::json($this->authService->sessionPayload());
    }
}
