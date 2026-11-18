<?php

declare(strict_types=1);

use App\Domain\Personnel\Compliance\PersonnelConflict;
use App\Infrastructure\Database\DatabaseConnection;
use Tests\Support\PersonnelFileTestFixture;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

try {
    $input = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (!str_starts_with($input['connection']['database'] ?? '', 'timeapp_test_personnelfiledatabasetest_')) {
        throw new RuntimeException('Expected the isolated personnel file test database.');
    }
    $_ENV['APP_ENV'] = 'test';
    $_ENV['PERSONNEL_FILE_MODE'] = 'synthetic';
    $connection = new DatabaseConnection($input['connection']);
    $runtime = (new PersonnelFileTestFixture())->runtime($connection, $input['root']);
    $documents = PersonnelFileTestFixture::documentService($connection, $runtime);
    PersonnelFileTestFixture::login($connection, $input['actor']);

    fwrite(STDOUT, "ready\n");
    PersonnelFileTestFixture::upload($documents, $input['document'], 0, PersonnelFileTestFixture::png(), $input['request']);
    fwrite(STDOUT, "created\n");
} catch (PersonnelConflict) {
    fwrite(STDOUT, "conflict\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, "worker failed: " . $exception::class . "\n");
    exit(1);
}
