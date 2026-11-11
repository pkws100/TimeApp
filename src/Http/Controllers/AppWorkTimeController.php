<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Http\Request;
use App\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class AppWorkTimeController
{
    public function __construct(
        private AuthService $auth,
        private CsrfService $csrf,
        private WorkTimePolicyService $policies,
        private WorkTimePreviewService $preview,
        private WorkTimeEventService $events
    ) {
    }

    public function status(Request $request): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }
        if (!$this->policies->enabled()) {
            return Response::json(['ok' => true, 'data' => ['enabled' => false]]);
        }
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');

        return Response::json(['ok' => true, 'data' => [
            ...$this->events->statusForUser((int) $user['id']),
            'policy' => $this->policies->policyFor((int) $user['id'], $today),
            'preview' => $this->preview->forUserPeriod((int) $user['id'], $today, $today),
            'csrf_token' => $this->csrf->token(),
        ]], 200, ['Cache-Control' => 'no-store']);
    }

    public function respond(Request $request, array $params): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }
        if (!$this->policies->enabled()) {
            return Response::json(['ok' => false, 'message' => 'Arbeitszeitfenster sind deaktiviert.'], 404);
        }
        $token = $request->input('csrf_token');
        if (!is_string($token) || !$this->csrf->isValid($token)) {
            return Response::json(['ok' => false, 'message' => 'Bitte den aktuellen Stand neu laden.'], 403);
        }
        try {
            $action = $request->input('action', '');
            $actualEnd = $request->input('actual_end_at');
            $requestId = $request->input('request_id');
            if (!is_string($action) || ($actualEnd !== null && !is_string($actualEnd)) || !is_string($requestId)) {
                throw new InvalidArgumentException('Aktion, Endzeit und Request-ID muessen als Text angegeben werden.');
            }
            $data = $this->events->respond(
                (int) $user['id'], (int) ($params['id'] ?? 0),
                $action, $actualEnd ?: null, $requestId ?: null
            );
            return Response::json(['ok' => true, 'data' => $data]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::json(['ok' => false, 'message' => $exception->getMessage()], 409);
        }
    }
}
