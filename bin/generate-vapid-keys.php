<?php

declare(strict_types=1);

use Minishlink\WebPush\VAPID;

require_once __DIR__ . '/../bootstrap/autoload.php';

$keys = VAPID::createVapidKeys();

fwrite(STDOUT, 'PUSH_VAPID_PUBLIC_KEY=' . $keys['publicKey'] . PHP_EOL);
fwrite(STDOUT, 'PUSH_VAPID_PRIVATE_KEY=' . $keys['privateKey'] . PHP_EOL);
fwrite(STDOUT, 'PUSH_VAPID_SUBJECT=mailto:admin@example.invalid' . PHP_EOL);
