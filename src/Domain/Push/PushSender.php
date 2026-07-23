<?php

declare(strict_types=1);

namespace App\Domain\Push;

interface PushSender
{
    public function isConfigured(): bool;

    public function send(
        array $subscriptionRow,
        array $message,
        ?string $dedupeKey = null,
        ?string $workDate = null
    ): array;
}
