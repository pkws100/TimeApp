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
        if (!$this->sender->isConfigured()) { return ['ok' => false, 'not_configured' => true]; }
        $devices = $this->subscriptions->activeSubscriptionsForUser($userId);
        if ($devices === []) { return ['ok' => false, 'not_configured' => true]; }
        $accepted = $rejected = $unknown = 0;
        $retryable = false;
        foreach ($devices as $device) {
            try { $result = $this->sender->send($device, $message, $key . ':' . $device['id']); }
            catch (\Throwable) { $result = ['ok' => false]; }
            if (!empty($result['ok'])) { ++$accepted; }
            elseif (!empty($result['definitive_not_accepted'])) { ++$rejected; $retryable = !empty($result['retryable']) || $retryable; }
            else { ++$unknown; }
        }
        if ($accepted === count($devices)) { return ['ok' => true]; }
        // A partial success must never retry devices which already accepted it.
        if ($accepted > 0 || $unknown > 0) { return ['ok' => false]; }
        return ['ok' => false, 'definitive_not_accepted' => $rejected === count($devices), 'retryable' => $retryable];
    }
}
