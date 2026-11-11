<?php

declare(strict_types=1);

use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../../bootstrap/autoload.php';

try {
    $config = json_decode((string) file_get_contents($argv[1] ?? ''), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !str_starts_with((string) ($config['database'] ?? ''), 'timeapp_test_pauseevidenceprotectiondatabasetest_')) {
        throw new RuntimeException('Only the dedicated disposable pause-evidence database is allowed.');
    }
    $connection = new DatabaseConnection($config);
    $timesheetId = filter_var($argv[3] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $actorId = filter_var($argv[4] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($timesheetId === false || $actorId === false) { throw new RuntimeException('Synthetic row and actor are required.'); }
    file_put_contents($argv[2], (string) $connection->fetchColumn('SELECT CONNECTION_ID()'));
    try {
        (new AdminBookingService($connection, new TimesheetCalculator()))->update($timesheetId, ['break_minutes' => 60], $actorId, 'Concurrent synthetic legacy pause change');
        $result = ['status' => 'changed'];
    } catch (InvalidArgumentException $error) {
        $result = ['status' => 'blocked', 'message' => $error->getMessage()];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . PHP_EOL);
    exit(1);
}
