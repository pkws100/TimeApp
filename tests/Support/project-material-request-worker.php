<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectMaterialService;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../../bootstrap/autoload.php';
date_default_timezone_set('Europe/Berlin');
try {
    $config = json_decode((string) file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (!str_starts_with((string) ($config['database'] ?? ''), 'timeapp_test_')) {
        throw new RuntimeException('Scratch database required.');
    }
    $connection = new DatabaseConnection($config);
    $service = new ProjectMaterialService($connection, new ProjectAccessService($connection));
    file_put_contents($argv[4] . '.ready.' . getmypid(), 'ready');
    for ($i = 0; $i < 500 && !is_file($argv[4]); ++$i) { usleep(10000); }
    if (!is_file($argv[4])) { throw new RuntimeException('Worker barrier timed out.'); }
    $result = $service->createWithResult(['id' => (int) $argv[2], 'permissions' => []], (int) $argv[3], [
        'client_request_id' => '12345678-1234-4234-8234-123456789abc',
        'work_date' => date('Y-m-d'), 'description' => 'Rohr', 'quantity' => '2', 'unit' => 'm', 'note' => 'Entwurf',
    ]);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . PHP_EOL);
    exit(1);
}
