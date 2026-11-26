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

    public function forApi(callable $handler, ?string $permission = null, bool $nativeDownload = false): callable
    {
        return function (Request $request, array $params = []) use ($handler, $permission, $nativeDownload): Response {
            $response = $this->apiAuthorizationResponse($request, $permission, $nativeDownload) ?? $handler($request, $params);

            return $nativeDownload ? $response->withHeaders(['Cache-Control' => 'private, no-store']) : $response;
        };
    }

    public function forAdmin(callable $handler, ?string $permission = null): callable
    {
        return function (Request $request, array $params = []) use ($handler, $permission): Response {
            $response = $this->adminAuthorizationResponse($request, $permission);

            return $response ?? $handler($request, $params);
        };
    }

    public static function matchesExpectedAccount(Request $request, int $userId, bool $nativeDownload = false): bool
    {
        $header = $request->server('HTTP_X_TIMEAPP_USER_ID');
        $query = $nativeDownload ? $request->query('timeapp_user_id') : null;
        $expected = (string) $userId;

        return ($header === null || $header === $expected) && ($query === null || $query === $expected);
    }

    public static function sessionChangedResponse(): Response
    {
        return Response::json([
            'ok' => false,
            'code' => 'session_changed',
            'message' => 'Das angemeldete Konto hat gewechselt. Bitte erneut anmelden.',
        ], 401, ['Cache-Control' => 'private, no-store']);
    }

    private function apiAuthorizationResponse(Request $request, ?string $permission, bool $nativeDownload): ?Response
    {
        $user = $this->authService->currentUser();
        if ($user === null) {
            return Response::json([
                'ok' => false,
                'code' => 'auth_required',
                'error' => 'Nicht authentifiziert.',
                'message' => 'Bitte erneut anmelden.',
            ], 401);
        }

        if (!self::matchesExpectedAccount($request, (int) $user['id'], $nativeDownload)) {
            return self::sessionChangedResponse();
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
