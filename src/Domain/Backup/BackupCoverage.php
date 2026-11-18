<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use RuntimeException;

final class BackupCoverage
{
    public static function describe(
        ?bool $personnelData,
        ?bool $systemVersionedData,
        ?bool $runtimeOverride,
        ?bool $redactedValues,
    ): array {
        $exclusions = [
            'private_personnel_records' => $personnelData,
            'system_versioned_tables' => $systemVersionedData,
            'runtime_database_override' => $runtimeOverride,
            'redacted_database_values' => $redactedValues,
        ];
        $status = match (true) {
            in_array(true, $exclusions, true) => 'partial',
            in_array(null, $exclusions, true) => 'unknown',
            default => 'complete_for_scope',
        };

        return [
            'scope' => 'system_backup',
            'status' => $status,
            'included_areas' => ['standard_database_rows', 'configured_upload_files'],
            'excluded_areas' => array_map(static fn (?bool $present): array => [
                'data_present' => $present,
                'separate_backup_required' => $present !== false,
            ], $exclusions),
        ];
    }

    public static function validate(mixed $coverage, bool $hasPersonnelTables, bool $runtimeOverride, bool $redactedValues): array
    {
        if (!is_array($coverage) || !is_array($coverage['excluded_areas'] ?? null)) {
            throw new RuntimeException('Im Backup-Manifest fehlt ein gueltiger Sicherungsumfang.');
        }

        $personnel = $coverage['excluded_areas']['private_personnel_records']['data_present'] ?? null;
        $versioned = $coverage['excluded_areas']['system_versioned_tables']['data_present'] ?? null;
        if (!is_bool($personnel) || !is_bool($versioned) || ($personnel && !$hasPersonnelTables)) {
            throw new RuntimeException('Der Sicherungsumfang passt nicht zu den Backup-Metadaten.');
        }

        $expected = self::describe($personnel, $versioned, $runtimeOverride, $redactedValues);
        if (!self::matches($expected, $coverage)) {
            throw new RuntimeException('Der Sicherungsumfang passt nicht zu den Backup-Metadaten.');
        }

        return $expected;
    }

    private static function matches(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (is_array($value)) {
                if (!is_array($actual[$key] ?? null) || !self::matches($value, $actual[$key])) {
                    return false;
                }
            } elseif (!array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
