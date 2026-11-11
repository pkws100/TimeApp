<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

interface SmsProvider
{
    public function enabled(): bool;

    /** @return array{ok: bool, message?: string} */
    public function send(string $e164PhoneNumber, string $message, string $eventKey): array;
}
