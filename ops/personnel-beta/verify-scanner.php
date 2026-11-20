<?php

declare(strict_types=1);

use App\Domain\Personnel\Storage\ClamAvPersonnelScanner;
use App\Domain\Personnel\Storage\PersonnelFileValidator;
use App\Domain\Personnel\Storage\PersonnelStorageException;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

if (PHP_SAPI !== 'cli' || $argc > 3) {
    exit(2);
}

$socket = $argv[1] ?? '/run/clamav/clamd.ctl';
$qpdf = $argv[2] ?? '/usr/bin/qpdf';
$scanner = new ClamAvPersonnelScanner($socket);
$validator = new PersonnelFileValidator($qpdf);
$stream = fopen('php://memory', 'w+b');
if (!is_resource($stream)) {
    exit(1);
}

try {
    $validator->assertReady();
    $scanner->assertReady();
    fwrite($stream, 'TimeApp parser and scanner clean control');
    rewind($stream);
    $scanner->scan($stream);

    ftruncate($stream, 0);
    rewind($stream);
    fwrite($stream, 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
    rewind($stream);
    try {
        $scanner->scan($stream);
        throw new RuntimeException('The EICAR control was accepted.');
    } catch (PersonnelStorageException $exception) {
        if ($exception->getMessage() !== 'Die private Datei wurde von der Schadsoftwareprüfung zurückgewiesen.') {
            throw $exception;
        }
    }
    fwrite(STDOUT, "qpdf, ClamAV PING, clean scan and EICAR detection passed.\n");
} catch (Throwable) {
    fwrite(STDERR, "The private qpdf or ClamAV readiness check failed.\n");
    exit(1);
} finally {
    fclose($stream);
}
