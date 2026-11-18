<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../../bootstrap/autoload.php';

$override = getenv('DB_OVERRIDE_FILE');
if (getenv('UI_ISOLATED_ACCOUNT_RUNNER') !== '1' || !$override || !is_file($override)) {
    throw new RuntimeException('The isolated UI runner is required.');
}
$config = require $override;
if (!is_array($config) || !preg_match('/^timeapp_ui_[a-f0-9]+$/D', (string) ($config['database'] ?? ''))) {
    throw new RuntimeException('Only a synthetic timeapp_ui_ database is allowed.');
}

$connection = new DatabaseConnection($config);
$user = ['id' => (int) getenv('UI_TEST_MATERIAL_USER_ID')];
if ($connection->fetchColumn('SELECT employee_number FROM users WHERE id = :id', $user) !== 'UI-MATERIAL') {
    throw new RuntimeException('The synthetic material workflow employee is required.');
}

$result = [
    'time' => [
        'timesheets' => $connection->fetchAll('SELECT *, ROW_START, ROW_END FROM timesheets FOR SYSTEM_TIME ALL WHERE user_id = :id ORDER BY id, ROW_START', $user),
        'breaks' => $connection->fetchAll('SELECT * FROM timesheet_breaks WHERE user_id = :id ORDER BY id', $user),
        'sync_operations' => $connection->fetchAll('SELECT * FROM app_sync_operations WHERE user_id = :id ORDER BY id', $user),
        'journal' => $connection->fetchAll('SELECT * FROM time_account_entries WHERE user_id = :id ORDER BY id', $user),
    ],
    'materials' => $connection->fetchAll('SELECT * FROM project_material_entries WHERE created_by_user_id = :id ORDER BY id', $user),
];
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
