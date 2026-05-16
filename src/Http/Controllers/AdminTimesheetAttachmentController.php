<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Files\FileAttachmentService;
use App\Http\Request;
use App\Http\Response;

final class AdminTimesheetAttachmentController
{
    public function __construct(
        private FileAttachmentService $fileAttachmentService,
        private AuthService $authService,
        private CsrfService $csrfService
    ) {
    }

    public function download(Request $request, array $params): Response
    {
        $fileId = (int) ($params['id'] ?? 0);
        $file = $this->fileAttachmentService->downloadableTimesheetFile($fileId);

        if ($file === null) {
            return new Response('Datei nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $content = file_get_contents((string) $file['path']);

        if ($content === false) {
            return new Response('Datei nicht gefunden.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', (string) $file['original_name']) ?: 'download.bin';
        $disposition = ((bool) ($file['is_image'] ?? false) ? 'inline' : 'attachment') . '; filename="' . $filename . '"';

        return new Response($content, 200, [
            'Content-Type' => (string) $file['mime_type'],
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function archive(Request $request, array $params): Response
    {
        $returnTo = $this->returnTo($request);
        $fileId = (int) ($params['id'] ?? 0);

        if (!$this->csrfService->isValid((string) $request->input('csrf_token', ''))) {
            return Response::redirect($this->withQueryValue($returnTo, 'error', 'attachment-csrf'));
        }

        $file = $this->fileAttachmentService->findTimesheetFile($fileId);

        if ($file === null) {
            return Response::redirect($this->withQueryValue($returnTo, 'error', 'attachment-missing'));
        }

        $user = $this->authService->currentUser();
        $this->fileAttachmentService->archiveTimesheetFile($fileId, $user !== null ? (int) $user['id'] : null);
        $bookingId = (int) $request->input('booking_id', (int) ($file['timesheet_id'] ?? 0));
        $location = $this->withQueryValue($returnTo, 'notice', 'attachment-archived');

        return Response::redirect($bookingId > 0 ? $this->withBookingContext($location, $bookingId) : $location);
    }

    private function returnTo(Request $request): string
    {
        $returnTo = trim((string) $request->input('return_to', '/admin/bookings'));

        return $this->sanitizeReturnTo($returnTo, '/admin/bookings');
    }

    private function sanitizeReturnTo(string $location, string $fallback): string
    {
        if (!str_starts_with($location, '/admin')) {
            return $fallback;
        }

        $path = parse_url($location, PHP_URL_PATH) ?: $fallback;
        $queryString = (string) parse_url($location, PHP_URL_QUERY);
        parse_str($queryString, $query);
        unset($query['notice'], $query['error'], $query['booking_id'], $query['modal']);

        $query = array_filter(
            $query,
            static fn ($value): bool => $value !== '' && $value !== null
        );

        return $query === [] ? $path : $path . '?' . http_build_query($query);
    }

    private function withQueryValue(string $location, string $key, string $value): string
    {
        $separator = str_contains($location, '?') ? '&' : '?';

        return $location . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }

    private function withBookingContext(string $location, int $bookingId): string
    {
        $location = $this->withQueryValue($location, 'booking_id', (string) $bookingId);

        return $this->withQueryValue($location, 'modal', 'edit');
    }
}
