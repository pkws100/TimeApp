<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Dashboard\DashboardService;
use App\Http\Request;
use App\Http\Response;

final class DashboardController
{
    public function __construct(private DashboardService $dashboardService)
    {
    }

    public function overview(Request $request): Response
    {
        return Response::json($this->dashboardService->overview());
    }

    public function charts(Request $request): Response
    {
        return Response::json($this->dashboardService->charts((string) $request->query('period', 'month')));
    }
}
