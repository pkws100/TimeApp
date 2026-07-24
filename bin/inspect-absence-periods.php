#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\ConfigRepository;
use App\Config\EnvironmentLoader;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../bootstrap/autoload.php';

(new EnvironmentLoader())->load(base_path('.env'));
$config = ConfigRepository::load(['database']);
$settings = new DatabaseSettingsManager(
    (array) $config->get('database.connections.mysql', []),
    (string) $config->get('database.override_file')
);
$connection = new DatabaseConnection($settings->current());

if (!$connection->isAvailable()) {
    fwrite(STDERR, 'Datenbankverbindung fehlgeschlagen: ' . ($connection->lastError() ?? 'unbekannter Fehler') . PHP_EOL);
    exit(1);
}

if (!$connection->tableExists('absence_periods')
    || !$connection->columnExists('timesheets', 'absence_period_id')) {
    fwrite(STDERR, 'Abwesenheitszeitraeume sind noch nicht migriert.' . PHP_EOL);
    exit(1);
}

$issues = [];
$missingPeriods = $connection->fetchAll(
    'SELECT vacation_requests.id, vacation_requests.user_id, vacation_requests.date_from, vacation_requests.date_to
     FROM vacation_requests
     LEFT JOIN absence_periods ON absence_periods.vacation_request_id = vacation_requests.id
     WHERE vacation_requests.status = "approved"
       AND COALESCE(vacation_requests.is_deleted, 0) = 0
       AND absence_periods.id IS NULL
     ORDER BY vacation_requests.id'
);
foreach ($missingPeriods as $row) {
    $issues[] = [
        'code' => 'approved_request_without_period',
        'vacation_request_id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'date_from' => (string) $row['date_from'],
        'date_to' => (string) $row['date_to'],
    ];
}

$periods = $connection->fetchAll(
    'SELECT
        absence_periods.id,
        absence_periods.user_id,
        absence_periods.date_from,
        absence_periods.date_to,
        absence_periods.entry_type,
        absence_periods.absence_reason_code,
        absence_periods.source,
        absence_periods.vacation_request_id,
        absence_periods.booked_day_count,
        absence_periods.is_deleted,
        vacation_requests.user_id AS request_user_id,
        vacation_requests.date_from AS request_date_from,
        vacation_requests.date_to AS request_date_to,
        vacation_requests.status AS request_status,
        COUNT(CASE WHEN timesheets.id IS NOT NULL AND COALESCE(timesheets.is_deleted, 0) = 0 THEN 1 END) AS active_child_count,
        SUM(CASE WHEN timesheets.id IS NOT NULL AND COALESCE(timesheets.is_deleted, 0) = 0
                  AND (
                    timesheets.user_id <> absence_periods.user_id
                    OR timesheets.entry_type <> absence_periods.entry_type
                    OR COALESCE(timesheets.absence_reason_code, "") <> absence_periods.absence_reason_code
                    OR timesheets.work_date < absence_periods.date_from
                    OR timesheets.work_date > absence_periods.date_to
                    OR NOT (timesheets.vacation_request_id <=> absence_periods.vacation_request_id)
                  )
                 THEN 1 ELSE 0 END) AS mismatching_child_count
     FROM absence_periods
     LEFT JOIN timesheets ON timesheets.absence_period_id = absence_periods.id
     LEFT JOIN vacation_requests ON vacation_requests.id = absence_periods.vacation_request_id
     GROUP BY absence_periods.id
     ORDER BY absence_periods.id'
);
foreach ($periods as $period) {
    if ((string) $period['source'] === 'vacation_request'
        && (int) ($period['vacation_request_id'] ?? 0) === 0) {
        $issues[] = [
            'code' => 'vacation_request_period_without_request',
            'absence_period_id' => (int) $period['id'],
        ];
    }

    if ((int) $period['is_deleted'] === 0
        && (int) $period['active_child_count'] !== (int) $period['booked_day_count']) {
        $issues[] = [
            'code' => 'active_child_count_mismatch',
            'absence_period_id' => (int) $period['id'],
            'booked_day_count' => (int) $period['booked_day_count'],
            'active_child_count' => (int) $period['active_child_count'],
        ];
    }

    if ((int) $period['mismatching_child_count'] > 0) {
        $issues[] = [
            'code' => 'child_payload_mismatch',
            'absence_period_id' => (int) $period['id'],
            'mismatching_child_count' => (int) $period['mismatching_child_count'],
        ];
    }

    if ((int) ($period['vacation_request_id'] ?? 0) > 0
        && (
            (string) $period['source'] !== 'vacation_request'
            || (int) ($period['request_user_id'] ?? 0) !== (int) $period['user_id']
            || (string) ($period['request_date_from'] ?? '') !== (string) $period['date_from']
            || (string) ($period['request_date_to'] ?? '') !== (string) $period['date_to']
            || (
                (int) $period['is_deleted'] === 0
                && (string) ($period['request_status'] ?? '') !== 'approved'
            )
            || (
                (int) $period['is_deleted'] === 1
                && (string) ($period['request_status'] ?? '') !== 'cancelled'
            )
        )) {
        $issues[] = [
            'code' => 'vacation_request_period_mismatch',
            'absence_period_id' => (int) $period['id'],
            'vacation_request_id' => (int) $period['vacation_request_id'],
        ];
    }
}

$unlinkedRequestRows = $connection->fetchAll(
    'SELECT id, user_id, work_date, vacation_request_id
     FROM timesheets
     WHERE vacation_request_id IS NOT NULL
       AND absence_period_id IS NULL
     ORDER BY vacation_request_id, work_date, id'
);
foreach ($unlinkedRequestRows as $row) {
    $issues[] = [
        'code' => 'vacation_timesheet_without_period',
        'timesheet_id' => (int) $row['id'],
        'vacation_request_id' => (int) $row['vacation_request_id'],
        'user_id' => (int) $row['user_id'],
        'work_date' => (string) $row['work_date'],
    ];
}

if (in_array('--json', $argv, true)) {
    fwrite(STDOUT, json_encode(['issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit($issues === [] ? 0 : 2);
}

if ($issues === []) {
    fwrite(STDOUT, 'Alle Abwesenheitszeitraeume und verknuepften Tagesbuchungen sind konsistent.' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, 'Gefundene Abwesenheitszeitraum-Hinweise (keine Daten wurden veraendert):' . PHP_EOL);
foreach ($issues as $issue) {
    fwrite(STDOUT, ' - ' . json_encode($issue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

exit(2);
