<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Exports\ReportService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class ReportController
{
    public function __construct(private ReportService $reportService)
    {
    }

    public function export(Request $request): Response
    {
        try {
            $format = (string) $request->query('format', 'csv');
            $period = (string) $request->query('period', 'month');
            $export = $this->reportService->export($format, $period);

            return new Response((string) $export['content'], 200, $export['headers']);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 503);
        }
    }
}

