<?php

declare(strict_types=1);

use App\Domain\Personnel\Storage\ClamAvPersonnelScanner;
use App\Domain\Personnel\Storage\PersonnelStorageException;
use Tests\Support\PersonnelFileTestFixture;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    exit(2);
}

[$script, $operation, $path] = $argv;
if ($operation === 'signatures') {
    $samples = [
        'TimeApp.Marker' => 'synthetic-file-for-signature-guard',
        'TimeApp.Synthetic' => PersonnelFileTestFixture::scannerRejectedPng(),
    ];
    foreach ($samples as $name => $bytes) {
        printf("%s:%d:%s\n", hash('sha256', $bytes), strlen($bytes), $name);
    }
    exit;
}

$scanner = new ClamAvPersonnelScanner($path, timeoutSeconds: 1);
try {
    if ($operation === 'ready') {
        $scanner->assertReady();
        exit;
    }
    $bytes = match ($operation) {
        'clean' => PersonnelFileTestFixture::png(),
        'signature' => PersonnelFileTestFixture::scannerRejectedPng(),
        default => null,
    };
    if ($bytes === null) {
        exit(2);
    }
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, $bytes);
    rewind($stream);
    try {
        $scanner->scan($stream);
    } finally {
        fclose($stream);
    }
} catch (PersonnelStorageException $exception) {
    if ($operation === 'signature'
        && $exception->getMessage() === 'Die private Datei wurde von der Schadsoftwareprüfung zurückgewiesen.') {
        exit;
    }
    exit(1);
}

exit($operation === 'clean' ? 0 : 1);
