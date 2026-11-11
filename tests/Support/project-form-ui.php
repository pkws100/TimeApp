<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../../bootstrap/autoload.php';

if (getenv('UI_ISOLATED_ACCOUNT_RUNNER') !== '1') {
    throw new RuntimeException('The isolated UI runner is required.');
}
$override = getenv('DB_OVERRIDE_FILE');
if (!is_string($override) || !is_file($override)) {
    throw new RuntimeException('A scratch database override is required.');
}
$config = require $override;
if (!is_array($config) || !preg_match('/^timeapp_ui_[a-f0-9]+$/D', (string) ($config['database'] ?? ''))) {
    throw new RuntimeException('Only a synthetic timeapp_ui_ database is allowed.');
}
$id = filter_var(getenv('UI_TEST_FORM_PROJECT_ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$connection = new DatabaseConnection($config);
$project = $id === false ? null : $connection->fetchOne('SELECT * FROM projects WHERE id = :id', ['id' => $id]);
if ($project === null || !str_starts_with((string) $project['project_number'], 'UI-FORM-')) {
    throw new RuntimeException('An explicitly synthetic form project is required.');
}
$result = [
    'project' => $project,
    'memberships' => $connection->fetchAll('SELECT user_id, assigned_from, assigned_until FROM project_memberships WHERE project_id = :id ORDER BY user_id', ['id' => $id]),
    'materials' => $connection->fetchAll('SELECT id, description, is_deleted FROM project_material_entries WHERE project_id = :id ORDER BY id', ['id' => $id]),
];
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
