<?php

declare(strict_types=1);

use App\Domain\WorkTime\WorkTimePolicyService;
use App\Domain\WorkTime\WorkTimePreviewService;
use App\Domain\WorkTime\WorkTimeReconciliationQueue;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../../bootstrap/autoload.php';
date_default_timezone_set('Europe/Berlin');
$config = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!str_starts_with((string) ($config['database'] ?? ''), 'timeapp_test_')) { throw new RuntimeException('Scratch database required.'); }
$connection = new DatabaseConnection($config);
$policies = new WorkTimePolicyService($connection, true);
if (($argv[3] ?? '') === 'assign') {
    $payload = json_decode((string) base64_decode($argv[4], true), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($argv[2], 'starting');
    $result = $policies->assign($payload, (int) $payload['user_id']);
    fwrite(STDOUT, json_encode(['ok' => true, 'id' => $result['id']], JSON_THROW_ON_ERROR));
    exit(0);
}
$queue = new WorkTimeReconciliationQueue($connection, new WorkTimePreviewService($connection, $policies), $policies, static function () use ($argv): void {
    file_put_contents($argv[2], 'ready');
    for ($i = 0; $i < 1000; ++$i) { usleep(10000); }
    throw new RuntimeException('Test worker was not interrupted.');
});
$queue->processBatch(false, null, 1);
