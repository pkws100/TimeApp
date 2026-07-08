<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Http\Request;
use App\Http\Response;

final class AppTimeAccountController
{
    public function __construct(
        private TimeAccountService $timeAccountService,
        private AuthService $authService
    ) {
    }

    public function summary(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json(['ok' => false, 'message' => 'Bitte erneut anmelden.'], 401);
        }

        $year = (int) $request->query('year', (int) date('Y'));
        $month = (int) $request->query('month', (int) date('m'));
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');
        $month = $month >= 1 && $month <= 12 ? $month : (int) date('m');

        return Response::json([
            'ok' => true,
            'data' => $this->timeAccountService->monthlyAccount((int) $user['id'], $year, $month),
        ]);
    }
}
