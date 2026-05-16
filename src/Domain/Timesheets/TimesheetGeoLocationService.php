<?php

declare(strict_types=1);

namespace App\Domain\Timesheets;

use App\Infrastructure\Database\DatabaseConnection;

final class TimesheetGeoLocationService
{
    public function __construct(private DatabaseConnection $connection)
    {
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function listForTimesheetsGrouped(array $timesheetIds, ?int $userId = null): array
    {
        $timesheetIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $timesheetIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($timesheetIds === [] || !$this->connection->tableExists('timesheet_geo_records')) {
            return [];
        }

        $placeholders = [];
        $bindings = [];

        foreach ($timesheetIds as $index => $timesheetId) {
            $key = 'timesheet_id_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $timesheetId;
        }

        $sql = 'SELECT id, timesheet_id, latitude, longitude, accuracy_meters, recorded_at, created_at
                FROM timesheet_geo_records
                WHERE timesheet_id IN (' . implode(', ', $placeholders) . ')';

        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
            $bindings['user_id'] = $userId;
        }

        $sql .= ' ORDER BY recorded_at DESC, id DESC';

        $grouped = [];

        foreach ($this->connection->fetchAll($sql, $bindings) as $row) {
            $timesheetId = (int) ($row['timesheet_id'] ?? 0);

            if ($timesheetId <= 0) {
                continue;
            }

            $grouped[$timesheetId][] = $this->publicLocation($row);
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicLocation(array $row): array
    {
        $latitude = (float) ($row['latitude'] ?? 0);
        $longitude = (float) ($row['longitude'] ?? 0);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_meters' => isset($row['accuracy_meters']) ? (int) $row['accuracy_meters'] : null,
            'recorded_at' => self::dateTimeOrNull($row['recorded_at'] ?? null),
            'created_at' => self::dateTimeOrNull($row['created_at'] ?? null),
            'map_url' => sprintf(
                'https://www.openstreetmap.org/?mlat=%.7F&mlon=%.7F#map=18/%.7F/%.7F',
                $latitude,
                $longitude,
                $latitude,
                $longitude
            ),
        ];
    }

    private static function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
