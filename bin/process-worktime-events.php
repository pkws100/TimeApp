<?php

declare(strict_types=1);

use App\Config\ConfigRepository;
use App\Config\EnvironmentLoader;
use App\Domain\Push\PushNotificationService;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Domain\Timesheets\AppTimesheetSyncService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Timesheets\WorkdayStateCalculator;
use App\Domain\WorkTime\WorkTimeEventService;
use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePresenceService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Domain\WorkTime\WorkTimePushChannel;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../bootstrap/autoload.php';
(new EnvironmentLoader())->load(base_path('.env'));
$options = getopt('', ['dry-run', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/process-worktime-events.php [--dry-run]\nSeparate minute worker for work-time proposals. Dry-run performs no writes or sends.\n");
    exit(0);
}
$config = ConfigRepository::load(['app', 'database', 'uploads', 'push']);
date_default_timezone_set('Europe/Berlin');
$settings = new DatabaseSettingsManager($config->get('database.connections.mysql', []), (string) $config->get('database.override_file'));
$connection = new DatabaseConnection($settings->current());
$policies = new WorkTimePolicyService($connection, (bool) env('WORK_TIME_WINDOWS_ENABLED', false));
$sync = new AppTimesheetSyncService($connection, new TimesheetCalculator(), new CompanySettingsService($connection, $config->get('uploads', [])), new WorkdayStateCalculator());
$sync->setWorkTimePresenceService(new WorkTimePresenceService($connection, $policies));
$subscriptions = new PushSubscriptionService($connection);
$push = new PushNotificationService($connection, new PushSettingsService($connection, $config->get('push', [])), $subscriptions);
$service = new WorkTimeEventService($connection, $policies, $sync, new WorkTimePushChannel($subscriptions, $push), null, new WorkTimePreviewService($connection, $policies));
try {
    $summary = $service->process(array_key_exists('dry-run', $options));
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(($summary['errors'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0 || ($summary['uncertain'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Work-time worker failed: ' . get_class($exception) . PHP_EOL);
    exit(1);
}
