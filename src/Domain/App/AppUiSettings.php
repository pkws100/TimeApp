<?php

declare(strict_types=1);

namespace App\Domain\App;

final class AppUiSettings
{
    public const FLAGS = [
        'show_today_total_minutes' => 'Heute gesamt',
        'show_project_today_minutes' => 'Heute aktuelles Projekt',
        'show_current_shift_minutes' => 'Zusatzkachel Aktueller Einsatz',
        'show_month_summary' => 'Monatszusammenfassung',
        'show_history' => 'Historie',
        'show_project_files' => 'Projektdateien',
        'show_project_work_instructions' => 'Projekt-Arbeitsanweisungen',
        'show_project_materials' => 'Projekt-Material',
        'show_timesheet_files' => 'Zeiteintragsanhaenge',
        'show_geo_section' => 'GEO-Bereich',
        'show_customer_signature' => 'Kundenbestaetigung',
        'show_project_total_minutes' => 'Projekt-Gesamtsummen',
        'show_personnel_overview' => 'Personal: Labels und Events',
    ];

    public const ADMIN_FLAGS = [
        'show_today_total_minutes',
        'show_project_today_minutes',
        'show_current_shift_minutes',
        'show_month_summary',
        'show_history',
        'show_project_files',
        'show_project_work_instructions',
        'show_project_materials',
        'show_timesheet_files',
        'show_geo_section',
        'show_customer_signature',
        'show_personnel_overview',
    ];

    public const MANDATORY_WIDGETS = [
        'day_status',
        'start_time',
        'end_time',
        'breaks',
        'current_net_minutes',
        'current_project',
        'time_actions',
    ];

    public static function defaults(): array
    {
        return array_fill_keys(array_keys(self::FLAGS), true);
    }

    public static function normalize(mixed $settings): array
    {
        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
            $settings = is_array($decoded) ? $decoded : [];
        }

        $settings = is_array($settings) ? $settings : [];
        $normalized = self::defaults();

        foreach (array_keys(self::FLAGS) as $flag) {
            if (array_key_exists($flag, $settings)) {
                $normalized[$flag] = self::normalizeBoolean($settings[$flag]);
            }
        }

        return $normalized;
    }

    public static function encode(mixed $settings): string
    {
        return (string) json_encode(self::normalize($settings), JSON_THROW_ON_ERROR);
    }

    private static function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
