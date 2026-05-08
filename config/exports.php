<?php

declare(strict_types=1);

return [
    'temp_path' => base_path((string) env('EXPORT_TEMP_PATH', 'storage/cache/exports')),
    'formats' => ['csv', 'xlsx', 'pdf'],
];

