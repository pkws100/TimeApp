<?php

declare(strict_types=1);

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePresenceService;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../../bootstrap/autoload.php';
date_default_timezone_set('Europe/Berlin');
$config = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!str_starts_with((string) ($config['database'] ?? ''), 'timeapp_test_')) {
    throw new RuntimeException('Scratch database required.');
}
$payload = json_decode(base64_decode($argv[2], true), true, 512, JSON_THROW_ON_ERROR);
for ($attempt = 0; $attempt < 1000 && !is_file($argv[3]); $attempt++) {
    usleep(10000);
}
if (!is_file($argv[3])) {
    throw new RuntimeException('Test start barrier timed out.');
}
$connection = new DatabaseConnection($config);
$policies = new WorkTimePolicyService($connection, true);
$sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, []), new WorkdayStateCalculator());
$sync->setWorkTimePresenceService(new WorkTimePresenceService($connection, $policies));
try {
    $result = $payload['mode'] === 'confirm'
        ? (new WorkTimeEventService($connection, $policies, $sync))->respond((int) $payload['user_id'], (int) $payload['proposal_id'], 'confirm', null, 'parallel-confirm')
        : $sync->sync((int) $payload['user_id'], ['client_request_id' => 'parallel-terminal', 'action' => 'check_out', 'work_date' => $payload['work_date'], 'end_time' => '16:35', 'expected_timesheet_id' => (int) $payload['timesheet_id'], 'source' => 'terminal']);
    fwrite(STDOUT, json_encode(['ok' => true, 'status' => $result['status']], JSON_THROW_ON_ERROR));
} catch (InvalidArgumentException|RuntimeException $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'status' => 'conflict'], JSON_THROW_ON_ERROR));
}
