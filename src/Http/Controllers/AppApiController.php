<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\App\MobileAppService;
use App\Domain\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;

final class AppApiController
{
    public function __construct(
        private MobileAppService $mobileAppService,
        private AuthService $authService
    ) {
    }

    public function meDay(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Nicht authentifiziert.',
                'message' => 'Bitte erneut anmelden.',
            ], 401);
        }

        return Response::json([
            'ok' => true,
            'data' => $this->mobileAppService->dayContext($user),
        ]);
    }

    public function meTimesheets(Request $request): Response
    {
        $user = $this->authService->currentUser();

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Nicht authentifiziert.',
                'message' => 'Bitte erneut anmelden.',
            ], 401);
        }

        $scope = (string) $request->query('scope', 'project');
        $projectId = $request->query('project_id');
        $projectId = $projectId === null || $projectId === '' ? null : (int) $projectId;

        return Response::json([
            'ok' => true,
            'data' => $this->mobileAppService->timesheetList($user, $scope, $projectId),
        ]);
    }
}
