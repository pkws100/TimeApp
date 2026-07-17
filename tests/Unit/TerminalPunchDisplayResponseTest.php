<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Terminals\TerminalPunchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TerminalPunchDisplayResponseTest extends TestCase
{
    public function testConfiguredCheckInAndCheckOutResponsesRenderTemplatesAndSuccessDuration(): void
    {
        $terminal = $this->terminalWithDisplaySettings();
        $user = ['first_name' => 'Ada'];
        $monthly = ['target_minutes' => 480];

        $checkIn = $this->invoke('successResponse', [$terminal, $user, 'check_in', [], $monthly]);
        $checkOut = $this->invoke('successResponse', [$terminal, $user, 'check_out', [], $monthly]);

        self::assertSame(['Start Ada', 'Arbeitsbeginn'], array_slice($checkIn['display']['lines'], 0, 2));
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $checkIn['display']['lines'][2]);
        self::assertSame('Soll 8:00', $checkIn['display']['lines'][3]);
        self::assertSame(5000, $checkIn['display']['hold_ms']);
        self::assertSame(['led' => 'green', 'beep' => 'success'], $checkIn['signal']);

        self::assertSame(['Ende Ada', 'Feierabend'], array_slice($checkOut['display']['lines'], 0, 2));
        self::assertSame(5000, $checkOut['display']['hold_ms']);
        self::assertSame(['led' => 'green', 'beep' => 'success'], $checkOut['signal']);
    }

    public function testLearningAndFailureResponsesUseTheirConfiguredDurationsAndFixedSignals(): void
    {
        $terminal = $this->terminalWithDisplaySettings();

        $learning = $this->invoke('learningResponse', [$terminal, ['uid_masked' => 'ABCD']]);
        $failure = $this->invoke('failure', ['unknown_tag', 'NFC-Tag unbekannt.', 404, $terminal, null, null, null, null, ['request_id' => '']]);

        self::assertSame(9000, $learning['display']['hold_ms']);
        self::assertSame(['led' => 'yellow', 'beep' => 'ready'], $learning['signal']);
        self::assertSame(7000, $failure['display']['hold_ms']);
        self::assertSame(['led' => 'red', 'beep' => 'error'], $failure['signal']);
    }

    /** @return array<string,mixed> */
    private function terminalWithDisplaySettings(): array
    {
        return [
            'id' => 7,
            'terminal_identifier' => 'terminal-nord',
            'name' => 'Terminal Nord',
            'welcome_text' => 'Willkommen Nord',
            'settings_json' => json_encode([
                'display' => [
                    'ready_lines' => ['Willkommen Nord', 'Tag vorhalten', 'Bereit'],
                    'check_in_lines' => ['Start {vorname}', 'Arbeitsbeginn', '{zeit}', 'Soll {sollzeit}'],
                    'check_out_lines' => ['Ende {vorname}', 'Feierabend', '{zeit}', 'Soll {sollzeit}'],
                    'hold_ms' => ['success' => 5000, 'error' => 7000, 'learning' => 9000],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** @param list<mixed> $arguments @return array<string,mixed> */
    private function invoke(string $method, array $arguments): array
    {
        $reflection = new ReflectionClass(TerminalPunchService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        return $reflection->getMethod($method)->invoke($service, ...$arguments);
    }
}
