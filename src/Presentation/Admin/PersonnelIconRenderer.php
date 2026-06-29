<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

final class PersonnelIconRenderer
{
    public static function badge(array $item, string $fallbackColor = '#2563eb', string $fallbackIcon = 'award'): string
    {
        $color = self::safeColor((string) ($item['color'] ?? $fallbackColor), $fallbackColor);
        $name = self::e((string) ($item['name'] ?? ''));
        $icon = self::icon((string) ($item['icon'] ?? $fallbackIcon), $fallbackIcon);

        return '<span class="personnel-label" style="--personnel-label-color:' . self::e($color) . '">'
            . $icon
            . '<span class="personnel-label__name">' . $name . '</span>'
            . '</span>';
    }

    private static function icon(string $icon, string $fallbackIcon): string
    {
        $key = self::normalizeKey($icon);

        if ($key === '') {
            $key = self::normalizeKey($fallbackIcon);
        }

        $shapes = match ($key) {
            'award', 'medal', 'medal-star', 'certificate' => '<circle cx="12" cy="8" r="5"></circle><path d="M8.21 13.89 7 22l5-3 5 3-1.21-8.11"></path>',
            'calendar-check', 'calendar-days' => '<path d="M8 2v4"></path><path d="M16 2v4"></path><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M3 10h18"></path><path d="m9 16 2 2 4-4"></path>',
            'calendar' => '<path d="M8 2v4"></path><path d="M16 2v4"></path><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M3 10h18"></path>',
            'truck' => '<path d="M14 18V6a2 2 0 0 0-2-2H3v14h2"></path><path d="M15 18H9"></path><path d="M19 18h2v-5l-3-4h-4"></path><circle cx="7" cy="18" r="2"></circle><circle cx="17" cy="18" r="2"></circle>',
            'wrench', 'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.6 2.6-3-3 2.6-2.6Z"></path>',
            'shield', 'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.3 8.8a2 2 0 0 1-1.4 0C7.5 20.5 4 18 4 13V5l8-3 8 3Z"></path><path d="m9 12 2 2 4-4"></path>',
            'hard-hat', 'helmet-safety' => '<path d="M2 18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2"></path><path d="M10 10V5a2 2 0 0 1 4 0v5"></path><path d="M4 18v-2a8 8 0 0 1 16 0v2"></path>',
            'id-card', 'badge' => '<rect x="3" y="4" width="18" height="16" rx="2"></rect><circle cx="9" cy="10" r="2"></circle><path d="M15 9h3"></path><path d="M15 13h3"></path><path d="M7 16h4"></path>',
            'graduation-cap' => '<path d="M22 10 12 5 2 10l10 5 10-5Z"></path><path d="M6 12v5c3 2 9 2 12 0v-5"></path>',
            'star' => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8-6.2-3.2L5.8 21 7 14.2 2 9.3l6.9-1L12 2Z"></path>',
            'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="m16 11 2 2 4-4"></path>',
            'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"></path><path d="M2 12h20"></path>',
            default => '<path d="M20.59 13.41 11.17 4H4v7.17l9.41 9.42a2 2 0 0 0 2.83 0l4.35-4.35a2 2 0 0 0 0-2.83Z"></path><path d="M7.5 7.5h.01"></path>',
        };

        return '<span class="personnel-label__icon" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" focusable="false">' . $shapes . '</svg>'
            . '</span>';
    }

    private static function normalizeKey(string $value): string
    {
        $value = strtolower(trim(str_replace('_', '-', $value)));
        $value = preg_replace('/^(?:(?:fa-solid|fa-regular|fa-light|fa-duotone|fa-brands|fas|far|fal|fad|fab|fa)-)+/', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9-]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    private static function safeColor(string $color, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : $fallback;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
