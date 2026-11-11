<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

use App\Domain\Push\PushSender;
use App\Domain\Push\PushSubscriptionService;

final class WorkTimePushChannel
{
    public function __construct(private PushSubscriptionService $subscriptions, private PushSender $sender)
    {
    }

    public function __invoke(int $userId, array $message, string $key): array
    {
        $devices = $this->subscriptions->activeSubscriptionsForUser($userId);
        $ok = $devices !== [];
        foreach ($devices as $device) {
            $result = $this->sender->send($device, $message, $key . ':' . $device['id']);
            $ok = !empty($result['ok']) && $ok;
        }
        return ['ok' => $ok];
    }
}
