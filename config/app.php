<?php

declare(strict_types=1);

return [
    'name' => (string) env('APP_NAME', 'Baustellen Zeiterfassung'),
    'env' => (string) env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => (string) env('APP_URL', 'http://localhost'),
    'timezone' => (string) env('APP_TIMEZONE', 'Europe/Berlin'),
    'locale' => (string) env('APP_LOCALE', 'de'),
    'fallback_locale' => (string) env('APP_FALLBACK_LOCALE', 'de'),
    'storage_root' => storage_path(),
];

