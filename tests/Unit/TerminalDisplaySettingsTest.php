<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Terminals\TerminalDisplaySettings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TerminalDisplaySettingsTest extends TestCase
{
    public function testDefaultsKeepExistingTerminalBehaviour(): void
    {
        $settings = TerminalDisplaySettings::forTerminal(['welcome_text' => 'Empfang']);

        self::assertSame(['Empfang', 'Tag vorhalten', 'Bereit'], $settings['ready_lines']);
        self::assertSame(['Hallo {vorname}', 'Arbeitsbeginn', '{zeit}', 'Soll {sollzeit}'], $settings['check_in_lines']);
        self::assertSame(['Hallo {vorname}', 'Feierabend', '{zeit}', 'Soll {sollzeit}'], $settings['check_out_lines']);
        self::assertSame(['success' => 15000, 'error' => 15000, 'learning' => 15000], $settings['hold_ms']);
    }

    public function testInputMergesDisplaySettingsWithoutDroppingUnknownJsonFields(): void
    {
        $terminal = [
            'welcome_text' => 'Alt',
            'settings_json' => json_encode(['legacy' => ['keep' => true], 'display' => ['future_flag' => 'keep', 'hold_ms' => ['future_flag' => 'keep']]]),
        ];
        $input = [
            'ready_line_1' => 'Willkommen', 'ready_line_2' => 'Tag bitte', 'ready_line_3' => 'Bereit',
            'check_in_line_1' => 'Hallo {vorname}', 'check_in_line_2' => 'Start', 'check_in_line_3' => '{zeit}', 'check_in_line_4' => 'Soll {sollzeit}',
            'check_out_line_1' => 'Bis bald {vorname}', 'check_out_line_2' => 'Ende', 'check_out_line_3' => '{zeit}', 'check_out_line_4' => 'Soll {sollzeit}',
            'hold_success_ms' => '5000', 'hold_error_ms' => '7000', 'hold_learning_ms' => '9000',
        ];

        $merged = TerminalDisplaySettings::mergeInput($terminal, $input);
        $json = json_decode($merged['settings_json'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Willkommen', $merged['welcome_text']);
        self::assertTrue($json['legacy']['keep']);
        self::assertSame('keep', $json['display']['future_flag']);
        self::assertSame('keep', $json['display']['hold_ms']['future_flag']);
        self::assertSame('Bis bald {vorname}', $json['display']['check_out_lines'][0]);
        self::assertSame(['future_flag' => 'keep', 'success' => 5000, 'error' => 7000, 'learning' => 9000], $json['display']['hold_ms']);
    }

    public function testOnlyDocumentedTokensAndDurationsAreAccepted(): void
    {
        $valid = [
            'ready_line_1' => 'Willkommen', 'ready_line_2' => 'Tag', 'ready_line_3' => 'Bereit',
            'check_in_line_1' => '{vorname}', 'check_in_line_2' => '{zeit}', 'check_in_line_3' => '{sollzeit}', 'check_in_line_4' => '',
            'check_out_line_1' => '{vorname}', 'check_out_line_2' => '{zeit}', 'check_out_line_3' => '{sollzeit}', 'check_out_line_4' => '',
            'hold_success_ms' => '1000', 'hold_error_ms' => '60000', 'hold_learning_ms' => '15000',
        ];

        self::assertIsArray(TerminalDisplaySettings::mergeInput([], $valid));

        $invalidToken = $valid;
        $invalidToken['check_in_line_1'] = '{terminal}';
        $this->expectException(RuntimeException::class);
        TerminalDisplaySettings::mergeInput([], $invalidToken);
    }

    public function testInvalidDurationIsRejected(): void
    {
        $input = [
            'ready_line_1' => 'Willkommen', 'ready_line_2' => 'Tag', 'ready_line_3' => 'Bereit',
            'check_in_line_1' => '', 'check_in_line_2' => '', 'check_in_line_3' => '', 'check_in_line_4' => '',
            'check_out_line_1' => '', 'check_out_line_2' => '', 'check_out_line_3' => '', 'check_out_line_4' => '',
            'hold_success_ms' => '999', 'hold_error_ms' => '15000', 'hold_learning_ms' => '15000',
        ];

        $this->expectException(RuntimeException::class);
        TerminalDisplaySettings::mergeInput([], $input);
    }

    public function testTemplatesLongerThanOneLcdLineAreRejected(): void
    {
        $input = [
            'ready_line_1' => str_repeat('x', 21), 'ready_line_2' => 'Tag', 'ready_line_3' => 'Bereit',
            'check_in_line_1' => '', 'check_in_line_2' => '', 'check_in_line_3' => '', 'check_in_line_4' => '',
            'check_out_line_1' => '', 'check_out_line_2' => '', 'check_out_line_3' => '', 'check_out_line_4' => '',
            'hold_success_ms' => '15000', 'hold_error_ms' => '15000', 'hold_learning_ms' => '15000',
        ];

        $this->expectException(RuntimeException::class);
        TerminalDisplaySettings::mergeInput([], $input);
    }

    public function testTemplatesRenderOnlyConfiguredValues(): void
    {
        self::assertSame(
            ['Hallo Ada', '08:15:00', 'Soll 160:00'],
            TerminalDisplaySettings::renderLines(['Hallo {vorname}', '{zeit}', 'Soll {sollzeit}'], [
                '{vorname}' => 'Ada', '{zeit}' => '08:15:00', '{sollzeit}' => '160:00',
            ])
        );
    }

    public function testStoredDurationsAreBoundedToTheFirmwareLimits(): void
    {
        $settings = TerminalDisplaySettings::forTerminal([
            'settings_json' => json_encode(['display' => ['hold_ms' => ['success' => 10, 'error' => 90000, 'learning' => 5000]]]),
        ]);

        self::assertSame(['success' => 1000, 'error' => 60000, 'learning' => 5000], $settings['hold_ms']);
    }
}
