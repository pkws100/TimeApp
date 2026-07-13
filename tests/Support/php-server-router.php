<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicFile = base_path('public' . $path);

if ($path !== '/' && is_file($publicFile)) {
    return false;
}

require base_path('public/index.php');
