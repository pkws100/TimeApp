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
use App\Domain\WorkTime\WorkTimeReconciliationQueue;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../bootstrap/autoload.php';
(new EnvironmentLoader())->load(base_path('.env'));
$options = getopt('', ['dry-run', 'full-reconcile', 'user-id:', 'batch-size:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/process-worktime-events.php [--dry-run] [--batch-size=100] [--full-reconcile] [--user-id=ID]\nNormal runs process only dirty work in a bounded batch. --full-reconcile explicitly enqueues the relevant complete history. --user-id only scopes that maintenance request. Dry-run performs no writes or sends.\n");
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
$previews = new WorkTimePreviewService($connection, $policies);
$queue = new WorkTimeReconciliationQueue($connection, $previews, $policies);
$service = new WorkTimeEventService($connection, $policies, $sync, new WorkTimePushChannel($subscriptions, $push), null, $previews);
$service->setReconciliationQueue($queue);
try {
    $batchSize = filter_var($options['batch-size'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    $userId = isset($options['user-id']) ? filter_var($options['user-id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    if ($batchSize === false || $userId === false || ($userId !== null && !isset($options['full-reconcile']))) {
        throw new InvalidArgumentException('Invalid maintenance user or batch size; --user-id requires --full-reconcile.');
    }
    $dryRun = array_key_exists('dry-run', $options);
    $full = isset($options['full-reconcile']) ? $queue->enqueueFullReconciliation($userId, $dryRun) : null;
    $summary = $service->process($dryRun, null, $batchSize);
    if ($full !== null) { $summary['full_reconciliation_enqueued'] = $full; }
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(($summary['errors'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0 || ($summary['uncertain'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Work-time worker failed: ' . get_class($exception) . PHP_EOL);
    exit(1);
}
