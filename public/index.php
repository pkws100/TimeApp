<?php

declare(strict_types=1);

[$request, $router] = require __DIR__ . '/../bootstrap/app.php';

$response = $router->dispatch($request);
$response->send($request->method() === 'HEAD');
