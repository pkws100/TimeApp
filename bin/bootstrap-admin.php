<?php

declare(strict_types=1);

use App\Config\ConfigRepository;
use App\Config\EnvironmentLoader;
use App\Domain\Auth\AdminBootstrapService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../bootstrap/autoload.php';

(new EnvironmentLoader())->load(base_path('.env'));

$options = getopt('', [
    'email:',
    'password:',
    'first-name:',
    'last-name:',
    'employee-number::',
    'force-password-reset',
]);

$required = ['email', 'password', 'first-name', 'last-name'];
$missing = array_values(array_filter($required, static fn (string $key): bool => !isset($options[$key]) || trim((string) $options[$key]) === ''));

if ($missing !== []) {
    fwrite(STDERR, "Fehlende Parameter: " . implode(', ', $missing) . PHP_EOL);
    fwrite(STDERR, "Verwendung:" . PHP_EOL);
    fwrite(STDERR, "  php bin/bootstrap-admin.php --email=admin@example.invalid --password='...' --first-name=Admin --last-name=Benutzer [--employee-number=ADM-0001] [--force-password-reset]" . PHP_EOL);

    exit(1);
}

$config = ConfigRepository::load(['database']);
$databaseSettings = new DatabaseSettingsManager(
    $config->get('database.connections.mysql', []),
    (string) $config->get('database.override_file')
);
$connection = new DatabaseConnection($databaseSettings->current());
$bootstrapService = new AdminBootstrapService($connection);

try {
    $result = $bootstrapService->createInitialAdministrator([
        'email' => (string) $options['email'],
        'password' => (string) $options['password'],
        'first_name' => (string) $options['first-name'],
        'last_name' => (string) $options['last-name'],
        'employee_number' => $options['employee-number'] ?? null,
        'force_password_reset' => array_key_exists('force-password-reset', $options),
    ]);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);

    exit(1);
}

fwrite(STDOUT, $result['message'] . PHP_EOL);
fwrite(STDOUT, 'Benutzer: ' . $result['email'] . ' (ID ' . $result['user_id'] . ')' . PHP_EOL);

exit(0);
