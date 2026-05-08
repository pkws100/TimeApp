<?php

declare(strict_types=1);

return [
    'disk' => (string) env('UPLOAD_DISK', 'local'),
    'root' => base_path((string) env('UPLOAD_ROOT', 'storage/app/uploads')),
    'max_filesize' => (int) env('UPLOAD_MAX_FILESIZE', 10 * 1024 * 1024),
    'allowed_extensions' => [
        'pdf',
        'png',
        'jpg',
        'jpeg',
        'webp',
        'heic',
        'heif',
        'doc',
        'docx',
        'xls',
        'xlsx',
    ],
    'allowed_mime_types' => [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],
];
