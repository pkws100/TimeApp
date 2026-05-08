<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Http\Request;
use App\Http\Response;

final class RouteGuard
{
    public function __construct(private AuthService $authService)
    {
    }

    public function forApi(callable $handler, ?string $permission = null): callable
    {
        return function (Request $request, array $params = []) use ($handler, $permission): Response {
            $response = $this->apiAuthorizationResponse($permission);

            return $response ?? $handler($request, $params);
        };
    }

    public function forAdmin(callable $handler, ?string $permission = null): callable
    {
        return function (Request $request, array $params = []) use ($handler, $permission): Response {
            $response = $this->adminAuthorizationResponse($request, $permission);

            return $response ?? $handler($request, $params);
        };
    }

    private function apiAuthorizationResponse(?string $permission): ?Response
    {
        if ($this->authService->currentUser() === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Nicht authentifiziert.',
                'message' => 'Bitte erneut anmelden.',
            ], 401);
        }

        if (!$this->authService->hasPermission($permission)) {
            return Response::json([
                'ok' => false,
                'error' => 'Keine Berechtigung.',
                'message' => 'Dafuer fehlt die Berechtigung.',
            ], 403);
        }

        return null;
    }

    private function adminAuthorizationResponse(Request $request, ?string $permission): ?Response
    {
        if ($this->authService->currentUser() === null) {
            $target = rawurlencode($request->path());

            return Response::redirect('/admin/login?next=' . $target);
        }

        if (!$this->authService->hasPermission($permission)) {
            return Response::html(
                '<main style="padding:2rem;font-family:sans-serif"><h1>403</h1><p>Keine Berechtigung fuer diesen Bereich.</p></main>',
                403
            );
        }

        return null;
    }
}
