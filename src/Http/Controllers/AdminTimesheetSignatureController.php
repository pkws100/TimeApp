<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Timesheets\TimesheetSignatureService;
use App\Http\Request;
use App\Http\Response;

final class AdminTimesheetSignatureController
{
    public function __construct(
        private TimesheetSignatureService $signatureService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function image(Request $request, array $params): Response
    {
        $file = $this->signatureService->findImageForAdmin((int) ($params['id'] ?? 0));

        if ($file === null) {
            return new Response('Unterschrift nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

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

    public function archive(Request $request, array $params): Response
    {
        $returnTo = $this->sanitizeReturnTo((string) $request->input('return_to', '/admin/bookings'));

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withQueryValue($returnTo, 'error', 'signature-csrf'));
        }

        $user = $this->authService->currentUser();
        $this->signatureService->archive((int) ($params['id'] ?? 0), $user !== null ? (int) $user['id'] : null);
        $bookingId = (int) $request->input('booking_id', 0);

        if ($bookingId > 0) {
            $returnTo = $this->withQueryValue($returnTo, 'booking_id', (string) $bookingId);
            $returnTo = $this->withQueryValue($returnTo, 'modal', 'edit');
        }

        return Response::redirect($this->withQueryValue($returnTo, 'notice', 'signature-archived'));
    }

    private function sanitizeReturnTo(string $location): string
    {
        if (!str_starts_with($location, '/admin')) {
            return '/admin/bookings';
        }

        $path = parse_url($location, PHP_URL_PATH) ?: '/admin/bookings';
        $queryString = (string) parse_url($location, PHP_URL_QUERY);
        parse_str($queryString, $query);
        unset($query['notice'], $query['error']);
        $query = array_filter($query, static fn ($value): bool => $value !== '' && $value !== null);

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    private function withQueryValue(string $location, string $key, string $value): string
    {
        $separator = str_contains($location, '?') ? '&' : '?';

        return $location . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }
}
