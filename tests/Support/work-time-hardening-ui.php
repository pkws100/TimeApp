<?php

declare(strict_types=1);

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePresenceService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../../bootstrap/autoload.php';
date_default_timezone_set('Europe/Berlin');

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
$connection = new DatabaseConnection($config);
$operation = $argv[1] ?? '';
$userId = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$user = $userId === false ? null : $connection->fetchOne('SELECT employee_number, email FROM users WHERE id = :id', ['id' => $userId]);
if ($user === null || !str_starts_with($user['employee_number'], 'UI-HARDENING-') || !str_ends_with($user['email'], '@example.test')) {
    throw new RuntimeException('An explicitly synthetic hardening employee is required.');
}
$date = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
$policies = new WorkTimePolicyService($connection, true);
$preview = new WorkTimePreviewService($connection, $policies);

if ($operation === 'activate') {
    $assignment = $connection->fetchOne('SELECT * FROM employee_work_time_assignments WHERE user_id = :user_id ORDER BY id DESC LIMIT 1', ['user_id' => $userId]);
    if ($assignment === null || $assignment['effective_from'] <= date('Y-m-d')) {
        throw new RuntimeException('The UI must first create an exclusively future assignment.');
    }
    // A controlled synthetic clock transition, never a production policy operation.
    $connection->execute('UPDATE employee_work_time_assignments SET effective_from = :date_from WHERE id = :id', ['date_from' => $date, 'id' => $assignment['id']]);
    $result = ['activated_fixture_date' => $date, 'previous_effective_from' => $assignment['effective_from']];
} elseif ($operation === 'worker' || $operation === 'worker-disabled') {
    $enabled = $operation !== 'worker-disabled';
    $policies = new WorkTimePolicyService($connection, $enabled);
    $preview = new WorkTimePreviewService($connection, $policies);
    $sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
    $sync->setWorkTimePresenceService(new WorkTimePresenceService($connection, $policies));
    $calls = [];
    $mockPush = static function (int $recipient, array $payload, string $key) use (&$calls): array {
        $calls[] = ['recipient' => $recipient, 'type' => $payload['type'] ?? null, 'key' => $key];
        return ['ok' => true, 'provider_message_id' => 'ui-mock-only'];
    };
    $events = new WorkTimeEventService($connection, $policies, $sync, $mockPush, null, $preview);
    $clock = (string) ($argv[3] ?? '16:05');
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $clock)) {
        throw new RuntimeException('A fixed test clock is required.');
    }
    $summary = $events->process(false, new DateTimeImmutable($date . ' ' . $clock, new DateTimeZone('Europe/Berlin')));
    $result = ['summary' => $summary, 'mock_push_calls' => $calls, 'notifications' => $events->notificationEventsForEmployee($userId), 'mock_push_only' => true];
} elseif ($operation === 'state') {
    $entries = $connection->fetchAll('SELECT *, row_start AS source_revision FROM timesheets WHERE user_id = :user_id ORDER BY id', ['user_id' => $userId]);
    $result = ['preview' => $preview->forUserPeriod($userId, $date, $date), 'entries' => $entries, 'system_versions' => (int) $connection->fetchColumn('SELECT COUNT(*) FROM timesheets FOR SYSTEM_TIME ALL WHERE user_id = :user_id', ['user_id' => $userId]), 'corrections' => $connection->fetchAll('SELECT * FROM work_time_pause_corrections WHERE user_id = :user_id ORDER BY id', ['user_id' => $userId])];
} else {
    throw new RuntimeException('Unsupported isolated test operation.');
}

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
