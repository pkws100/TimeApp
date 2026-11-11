<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\App\MobileAppService;
use App\Domain\Auth\AuthService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

final class AppApiController
{
    public function __construct(
        private MobileAppService $mobileAppService,
        private AuthService $authService,
        private ?WorkTimePolicyService $workTimePolicies = null,
        private ?WorkTimeEventService $workTimeEvents = null
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

        $day = $this->mobileAppService->dayContext($user);
        $assigned = false;
        if ($this->workTimePolicies?->enabled()) {
            $events = $this->workTimeEvents?->statusForUser((int) $user['id']) ?? [];
            $assigned = $this->workTimePolicies->policyFor((int) $user['id'], $day['today']) !== null
                || !empty($events['presence_session']) || !empty($events['checkout_proposals'])
                || !empty($events['active_work_entry']) || !empty($events['active_work_entry_conflict']);
        }

        return Response::json([
            'ok' => true,
            'data' => [
                ...$day,
                ...($assigned ? ['work_time_windows_enabled' => true] : []),
            ],
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
        $month = $request->query('month');
        $entryType = (string) $request->query('entry_type', 'all');

        if (!in_array($scope, ['all', 'project'], true)) {
            return Response::json([
                'ok' => false,
                'code' => 'invalid_history_filter',
                'error' => 'Ungueltiger Historienfilter.',
                'message' => 'Bitte einen gueltigen Historienbereich auswaehlen.',
            ], 422);
        }

        if ($projectId !== null && $projectId !== '' && !ctype_digit((string) $projectId)) {
            return Response::json([
                'ok' => false,
                'code' => 'invalid_history_filter',
                'error' => 'Ungueltiger Historienfilter.',
                'message' => 'Bitte ein gueltiges Projekt auswaehlen.',
            ], 422);
        }

        $projectId = $projectId === null || $projectId === '' ? null : (int) $projectId;

        try {
            $data = $this->mobileAppService->timesheetList(
                $user,
                $scope,
                $projectId,
                $month === null ? null : (string) $month,
                $entryType
            );
        } catch (InvalidArgumentException $exception) {
            return Response::json([
                'ok' => false,
                'code' => 'invalid_history_filter',
                'error' => 'Ungueltiger Historienfilter.',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return Response::json([
            'ok' => true,
            'data' => $data,
        ]);
    }
}
