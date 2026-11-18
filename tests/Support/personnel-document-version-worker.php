<?php

declare(strict_types=1);

use App\Domain\Auth\AuthService;
use App\Domain\Personnel\Compliance\PersonnelAccessDenied;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelConflict;
use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Users\PermissionMatrix;
use App\Infrastructure\Database\DatabaseConnection;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

try {
    $input = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (!str_starts_with($input['connection']['database'] ?? '', 'timeapp_test_personneldocumentdatabasetest_')) {
        throw new RuntimeException('Expected an isolated document test database.');
    }
    $connection = new DatabaseConnection($input['connection']);
    $permissions = require base_path('config/permissions.php');
    $_SESSION = ['auth' => [
        'user_id' => $input['actor'],
        'logged_in_at' => time(),
        'database_realm' => $connection->authenticationRealm(),
    ]];
    $auth = new AuthService($connection, new PermissionMatrix($permissions['roles'], $permissions['available']));
    $documents = new PersonnelDocumentService(
        $connection,
        new PersonnelAccessService($connection, $auth)
    );

    fwrite(STDOUT, "ready\n");
    $documents->addVersion($input['document_id'], 0, 'metadata_recorded', 'parallel-write');
    fwrite(STDOUT, "created\n");
} catch (PersonnelConflict) {
    fwrite(STDOUT, "conflict\n");
} catch (PersonnelAccessDenied) {
    fwrite(STDOUT, "denied\n");
} catch (Throwable) {
    fwrite(STDOUT, "error\n");
    exit(1);
}
