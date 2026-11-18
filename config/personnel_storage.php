<?php

declare(strict_types=1);

$uploads = require __DIR__ . '/uploads.php';

return [
    'root' => (string) env('PERSONNEL_FILE_ROOT', ''),
    'keyring' => (string) env('PERSONNEL_FILE_KEYRING', ''),
    'scanner_socket' => (string) env('PERSONNEL_CLAMD_SOCKET', ''),
    'qpdf' => (string) env('PERSONNEL_QPDF', '/usr/bin/qpdf'),
    'forbidden_roots' => [
        base_path(),
        $uploads['root'],
        base_path((string) env('EXPORT_TEMP_PATH', 'storage/cache/exports')),
        (string) env('PERSONNEL_BACKUP_ROOT', ''),
        (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
        (string) ini_get('upload_tmp_dir'),
        sys_get_temp_dir(),
    ],
];
