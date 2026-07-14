<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Terminals\TerminalPunchService;
use App\Domain\Terminals\TerminalService;
use App\Domain\Terminals\TerminalTrustBundleService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class TerminalApiController
{
    public function __construct(
        private TerminalService $terminalService,
        private TerminalPunchService $punchService,
        private TerminalTrustBundleService $trustBundleService
    ) {
    }

    public function config(Request $request): Response
    {
        try {
            $terminal = $this->terminalService->authenticate($request);

            return Response::json($this->punchService->config($terminal));
        } catch (\Throwable $exception) {
            return Response::json($this->errorPayload($exception->getMessage()), $this->authStatus($exception->getMessage()));
        }
    }

    /** This endpoint intentionally does not authenticate: its response is public, signed and contains no tenant data. */
    public function trustBundle(Request $request): Response
    {
        $bundle = $this->trustBundleService->publicBundle();

        if ($bundle === null) {
            return Response::json(['ok' => false, 'code' => 'trust_bundle_unavailable'], 404, ['Cache-Control' => 'public, max-age=300']);
        }

        $json = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Response::json($bundle, 200, [
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400',
            'ETag' => '"' . hash('sha256', $json) . '"',
        ]);
    }

    public function scan(Request $request): Response
    {
        try {
            $terminal = $this->terminalService->authenticate($request);
            $response = $this->punchService->scan($terminal, $request->input());
            $status = (int) ($response['http_status'] ?? 200);
            unset($response['http_status']);

            return Response::json($response, $status);
        } catch (\Throwable $exception) {
            return Response::json($this->errorPayload($exception->getMessage()), $this->authStatus($exception->getMessage()));
        }
    }

    private function errorPayload(string $code): array
    {
        $message = match ($code) {
            'terminal_disabled' => 'Terminal-Funktion ist deaktiviert.',
            'terminal_auth_required' => 'Terminal-ID oder Token fehlt.',
            'terminal_auth_failed' => 'Terminal-Authentifizierung fehlgeschlagen.',
            'terminal_ip_denied' => 'Terminal-IP ist nicht freigegeben.',
            'terminal_storage_missing' => 'Terminal-Tabellen fehlen.',
            default => 'Terminal-Anfrage fehlgeschlagen.',
        };

        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'display' => [
                'lines' => $this->lcdLines(['Fehler', $message, 'Bitte Admin', 'informieren']),
                'hold_ms' => 15000,
            ],
            'signal' => ['led' => 'red', 'beep' => 'error'],
            'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function authStatus(string $code): int
    {
        return match ($code) {
            'terminal_disabled' => 403,
            'terminal_auth_required', 'terminal_auth_failed' => 401,
            'terminal_ip_denied' => 403,
            default => 422,
        };
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function lcdLines(array $lines): array
    {
        $normalized = array_slice(array_map(
            static fn (string $line): string => mb_substr(trim(preg_replace('/\s+/', ' ', $line) ?? ''), 0, 20),
            $lines
        ), 0, 4);

        while (count($normalized) < 4) {
            $normalized[] = '';
        }

        return $normalized;
    }
}
