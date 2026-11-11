<?php

declare(strict_types=1);

namespace App\Domain\WorkTime;

/** No network I/O and no credentials. Only explicitly injected tests enable it. */
final class MockSmsProvider implements SmsProvider
{
    private array $messages = [];

    public function __construct(private bool $isEnabled = false, private bool $shouldFail = false)
    {
    }

    public function enabled(): bool
    {
        return $this->isEnabled;
    }

    public function send(string $e164PhoneNumber, string $message, string $eventKey): array
    {
        if (!$this->isEnabled) {
            return ['ok' => false, 'not_configured' => true, 'message' => 'SMS ist deaktiviert.'];
        }
        if ($this->shouldFail) {
            return ['ok' => false, 'simulated' => true, 'simulation_failed' => true, 'message' => 'SMS-Testfehler ohne echten Versand.'];
        }
        $this->messages[$eventKey] = ['phone' => $e164PhoneNumber, 'message' => $message];

        return ['ok' => true, 'simulated' => true];
    }

    public function messages(): array
    {
        return $this->messages;
    }
}
