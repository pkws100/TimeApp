<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Exports\AccountingExportService;
use App\Http\Request;
use App\Http\Response;
use RuntimeException;

final class AccountingExportController
{
    public function __construct(private AccountingExportService $service)
    {
    }

    public function export(Request $request): Response
    {
        try {
            $period = (string) $request->query('period', 'month');
            $format = (string) $request->query('format', 'json');
            $profile = (string) $request->query('profile', 'basic');
            $includeArchived = in_array((string) $request->query('include_archived', '0'), ['1', 'true', 'yes'], true);
            $export = $this->service->export($period, $format, $profile, $includeArchived);

            if ($format === 'csv') {
                return new Response((string) $export['content'], 200, $export['headers']);
            }

            return Response::json($export);
        } catch (RuntimeException $exception) {
            return Response::json(['error' => $exception->getMessage()], 422);
        }
    }
}
