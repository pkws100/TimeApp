<?php

declare(strict_types=1);

return [
    'vapid' => [
        'public_key' => (string) env('PUSH_VAPID_PUBLIC_KEY', ''),
        'private_key' => (string) env('PUSH_VAPID_PRIVATE_KEY', ''),
        'subject' => (string) env('PUSH_VAPID_SUBJECT', 'mailto:admin@example.invalid'),
    ],
];
