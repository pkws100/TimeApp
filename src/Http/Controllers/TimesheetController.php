<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Timesheets\TimesheetService;
use App\Http\Request;
use App\Http\Response;
use InvalidArgumentException;

final class TimesheetController
{
    public function __construct(private TimesheetService $timesheetService)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json(['data' => $this->timesheetService->list()]);
    }

    public function calculate(Request $request): Response
    {
        try {
            return Response::json(['data' => $this->timesheetService->calculate($request->input())]);
        } catch (InvalidArgumentException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }
}

