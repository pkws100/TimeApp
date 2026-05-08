<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settings\DatabaseSettingsManager;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Database\DatabaseConnection;

final class SettingsController
{
    public function __construct(private DatabaseSettingsManager $databaseSettingsManager)
    {
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->databaseSettingsManager->current()]);
    }

    public function store(Request $request): Response
    {
        $sanitized = $this->databaseSettingsManager->sanitize($request->input());
        $connection = new DatabaseConnection($sanitized);
        $result = $connection->test($sanitized);

        if (!$result['ok']) {
            return Response::json(['error' => $result['message']], 422);
        }

        $saved = $this->databaseSettingsManager->save($sanitized);

        return Response::json([
            'message' => 'Datenbankverbindung erfolgreich gespeichert.',
            'data' => $saved,
        ]);
    }

    public function saveFromAdmin(Request $request): Response
    {
        $sanitized = $this->databaseSettingsManager->sanitize($request->input());
        $connection = new DatabaseConnection($sanitized);
        $result = $connection->test($sanitized);

        if ($result['ok']) {
            $this->databaseSettingsManager->save($sanitized);

            return Response::redirect('/admin/settings/database?notice=saved');
        }

        return Response::redirect('/admin/settings/database?error=' . rawurlencode((string) $result['message']));
    }
}
