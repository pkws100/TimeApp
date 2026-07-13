<?php

declare(strict_types=1);

use App\Domain\TimeAccounts\AccountJournalService;
use App\Domain\TimeAccounts\EmployeeAccountCutoverService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../../bootstrap/autoload.php';

$config = json_decode((string) file_get_contents((string) ($argv[1] ?? '')), true);
$payload = json_decode(base64_decode((string) ($argv[2] ?? ''), true) ?: '', true);
$adminId = (int) ($argv[3] ?? 0);
$barrier = (string) ($argv[4] ?? '');

while ($barrier !== '' && !is_file($barrier)) {
    usleep(10000);
}

try {
    $connection = new DatabaseConnection(is_array($config) ? $config : []);
    $journal = new AccountJournalService($connection);
    $service = new EmployeeAccountCutoverService($connection, $journal, new TimesheetWriteGuard($connection));
    $cutover = $service->finalize(is_array($payload) ? $payload : [], $adminId);
    fwrite(STDOUT, json_encode(['ok' => true, 'cutover_id' => (int) ($cutover['id'] ?? 0)]));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $exception->getMessage()]));
}
