<?php

declare(strict_types=1);

use App\Config\ConfigRepository;
use App\Config\EnvironmentLoader;
use App\Domain\Personnel\PersonnelEventService;
use App\Domain\Personnel\PersonnelReminderService;
use App\Domain\Push\PushNotificationService;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Domain\Settings\SettingsSecretService;
use App\Domain\Settings\SmtpMailService;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../bootstrap/autoload.php';

(new EnvironmentLoader())->load(base_path('.env'));

$options = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $options);
$config = ConfigRepository::load(['app', 'database', 'uploads', 'push']);
$databaseSettings = new DatabaseSettingsManager(
    $config->get('database.connections.mysql', []),
    (string) $config->get('database.override_file')
);
$connection = new DatabaseConnection($databaseSettings->current());
$settingsSecretService = new SettingsSecretService((string) $config->get('app.settings_encryption_key', ''));
$companySettingsService = new CompanySettingsService($connection, $config->get('uploads', []), $settingsSecretService);
$pushSettingsService = new PushSettingsService($connection, $config->get('push', []));
$pushSubscriptionService = new PushSubscriptionService($connection);
$pushNotificationService = new PushNotificationService($connection, $pushSettingsService, $pushSubscriptionService);
$eventService = new PersonnelEventService($connection);
$reminderService = new PersonnelReminderService(
    $eventService,
    $pushSubscriptionService,
    $pushNotificationService,
    $companySettingsService,
    new SmtpMailService(),
    (string) $config->get('app.timezone', 'Europe/Berlin')
);

$summary = $reminderService->sendDueReminders(null, $dryRun);
fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);

exit(($summary['errors'] ?? 0) > 0 ? 1 : 0);
