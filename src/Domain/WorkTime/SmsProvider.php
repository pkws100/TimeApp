<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

interface SmsProvider
{
    public function enabled(): bool;

    /**
     * ok means provider acceptance, never confirmed device delivery. Only an
     * explicit definitive_not_accepted result permits a retry; exceptions and
     * bare failures remain uncertain. Provider keys must remain stable on retry.
     * @return array{ok: bool, message?: string, definitive_not_accepted?: bool, retryable?: bool, not_configured?: bool, provider_message_id?: string, simulated?: bool}
     */
    public function send(string $e164PhoneNumber, string $message, string $eventKey): array;
}
