<?php

declare(strict_types=1);

namespace App\Domain\Terminals;

use RuntimeException;

final class TerminalDisplaySettings
{
    // The terminal LCD renders at most 20 visible characters per line.
    private const MAX_TEMPLATE_LENGTH = 20;
    private const MIN_HOLD_MS = 1000;
    private const MAX_HOLD_MS = 60000;

    /** @var list<string> */
    private const ALLOWED_TOKENS = ['{vorname}', '{zeit}', '{sollzeit}'];

    /** @return array{ready_lines:list<string>, check_in_lines:list<string>, check_out_lines:list<string>, hold_ms:array{success:int,error:int,learning:int}} */
    public static function forTerminal(array $terminal): array
    {
        $settings = self::decode($terminal['settings_json'] ?? null);
        $display = is_array($settings['display'] ?? null) ? $settings['display'] : [];
        $welcome = trim((string) ($terminal['welcome_text'] ?? '')) ?: 'Willkommen';

        $ready = self::normalizeStoredLines($display['ready_lines'] ?? null, [$welcome, 'Tag vorhalten', 'Bereit']);
        $ready[0] = $welcome;

        return [
            'ready_lines' => $ready,
            'check_in_lines' => self::normalizeStoredLines($display['check_in_lines'] ?? null, ['Hallo {vorname}', 'Arbeitsbeginn', '{zeit}', 'Soll {sollzeit}']),
            'check_out_lines' => self::normalizeStoredLines($display['check_out_lines'] ?? null, ['Hallo {vorname}', 'Feierabend', '{zeit}', 'Soll {sollzeit}']),
            'hold_ms' => self::normalizeStoredHoldMs($display['hold_ms'] ?? null),
        ];
    }

    /** @return array{welcome_text:string, settings_json:string} */
    public static function mergeInput(array $terminal, array $input): array
    {
        $settings = self::decode($terminal['settings_json'] ?? null);
        $existingDisplay = is_array($settings['display'] ?? null) ? $settings['display'] : [];

        $ready = self::inputLines($input, 'ready_line_', 3, false);
        $checkIn = self::inputLines($input, 'check_in_line_', 4, true);
        $checkOut = self::inputLines($input, 'check_out_line_', 4, true);

        $existingDisplay['ready_lines'] = $ready;
        $existingDisplay['check_in_lines'] = $checkIn;
        $existingDisplay['check_out_lines'] = $checkOut;
        $existingHoldMs = is_array($existingDisplay['hold_ms'] ?? null) ? $existingDisplay['hold_ms'] : [];
        $existingHoldMs['success'] = self::inputHoldMs($input, 'hold_success_ms');
        $existingHoldMs['error'] = self::inputHoldMs($input, 'hold_error_ms');
        $existingHoldMs['learning'] = self::inputHoldMs($input, 'hold_learning_ms');
        $existingDisplay['hold_ms'] = $existingHoldMs;
        $settings['display'] = $existingDisplay;

        return [
            'welcome_text' => $ready[0] !== '' ? $ready[0] : 'Willkommen',
            'settings_json' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /** @param list<string> $lines @param array<string,string> $values @return list<string> */
    public static function renderLines(array $lines, array $values): array
    {
        return array_map(static fn (string $line): string => strtr($line, $values), $lines);
    }

    /** @return array<string,mixed> */
    private static function decode(mixed $json): array
    {
        $json = trim((string) ($json ?? ''));
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param mixed $value @param list<string> $defaults @return list<string> */
    private static function normalizeStoredLines(mixed $value, array $defaults): array
    {
        if (!is_array($value) || count($value) !== count($defaults)) {
            return $defaults;
        }

        $lines = [];
        foreach ($value as $index => $line) {
            $line = trim((string) $line);
            $lines[] = mb_strlen($line) <= self::MAX_TEMPLATE_LENGTH ? $line : $defaults[$index];
        }

        return $lines;
    }

    /** @return array{success:int,error:int,learning:int} */
    private static function normalizeStoredHoldMs(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'success' => self::clampHoldMs($value['success'] ?? 15000),
            'error' => self::clampHoldMs($value['error'] ?? 15000),
            'learning' => self::clampHoldMs($value['learning'] ?? 15000),
        ];
    }

    /** @return list<string> */
    private static function inputLines(array $input, string $prefix, int $count, bool $allowTokens): array
    {
        $lines = [];
        for ($index = 1; $index <= $count; $index++) {
            $line = trim((string) ($input[$prefix . $index] ?? ''));
            if (mb_strlen($line) > self::MAX_TEMPLATE_LENGTH) {
                throw new RuntimeException('Eine LCD-Vorlage darf höchstens ' . self::MAX_TEMPLATE_LENGTH . ' Zeichen enthalten.');
            }
            self::assertTokens($line, $allowTokens);
            $lines[] = $line;
        }

        return $lines;
    }

    private static function assertTokens(string $line, bool $allowTokens): void
    {
        preg_match_all('/\{[^}]*\}/u', $line, $matches);
        foreach ($matches[0] as $token) {
            if (!$allowTokens || !in_array($token, self::ALLOWED_TOKENS, true)) {
                throw new RuntimeException('Nicht erlaubter Platzhalter in der LCD-Vorlage. Erlaubt sind {vorname}, {zeit} und {sollzeit}.');
            }
        }

        $withoutTokens = preg_replace('/\{[^}]*\}/u', '', $line) ?? '';
        if (str_contains($withoutTokens, '{') || str_contains($withoutTokens, '}')) {
            throw new RuntimeException('LCD-Platzhalter müssen vollständig geschrieben werden.');
        }
    }

    private static function inputHoldMs(array $input, string $field): int
    {
        $raw = trim((string) ($input[$field] ?? ''));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            throw new RuntimeException('Die Anzeigedauer muss eine ganze Millisekunden-Zahl sein.');
        }

        $value = (int) $raw;
        if ($value < self::MIN_HOLD_MS || $value > self::MAX_HOLD_MS) {
            throw new RuntimeException('Die Anzeigedauer muss zwischen ' . self::MIN_HOLD_MS . ' und ' . self::MAX_HOLD_MS . ' ms liegen.');
        }

        return $value;
    }

    private static function clampHoldMs(mixed $value): int
    {
        return max(self::MIN_HOLD_MS, min(self::MAX_HOLD_MS, (int) $value));
    }
}
