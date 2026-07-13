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

$report = [];
$temporalCandidates = static function (DatabaseConnection $connection, string $table, array $entry): array {
    return $connection->fetchAll(
        'SELECT id, effective_from, status, created_at
         FROM employee_account_cutovers
         WHERE user_id = :user_id
           AND status IN ("final", "reversed")
           AND effective_from <= :effective_date
           AND created_at <= (SELECT created_at FROM ' . $table . ' WHERE id = :entry_id)
         ORDER BY effective_from, id',
        [
            'user_id' => (int) $entry['user_id'],
            'effective_date' => (string) $entry['effective_date'],
            'entry_id' => (int) $entry['id'],
        ]
    );
};
$directCandidate = static function (DatabaseConnection $connection, int $cutoverId, int $userId): array {
    if ($cutoverId <= 0) {
        return [];
    }

    return $connection->fetchAll(
        'SELECT id, effective_from, status, created_at
         FROM employee_account_cutovers
         WHERE id = :cutover_id
           AND user_id = :user_id
           AND status IN ("final", "reversed")',
        ['cutover_id' => $cutoverId, 'user_id' => $userId]
    );
};

foreach (['time_account_entries' => 'minutes', 'vacation_account_entries' => 'days'] as $table => $amountColumn) {
    if (!$connection->tableExists($table) || !$connection->columnExists($table, 'cutover_id')) {
        continue;
    }

    $rows = $connection->fetchAll(
        'SELECT entries.id, entries.user_id, entries.effective_date, entries.' . $amountColumn . ' AS amount,
                entries.source_type, entries.source_id, entries.reversal_of_id, entries.cutover_id
         FROM ' . $table . ' AS entries
         WHERE entries.cutover_id IS NULL
         ORDER BY entries.user_id, entries.effective_date, entries.id'
    );

    foreach ($rows as $row) {
        $evidence = 'temporal';
        $candidates = [];

        if ((string) ($row['source_type'] ?? '') === 'employee_account_cutover') {
            $evidence = 'direct_source';
            $candidates = $directCandidate($connection, (int) ($row['source_id'] ?? 0), (int) $row['user_id']);
        } elseif ((int) ($row['reversal_of_id'] ?? 0) > 0) {
            $evidence = 'reversal_origin';
            $origin = $connection->fetchOne(
                'SELECT id, user_id, effective_date, source_type, source_id, cutover_id
                 FROM ' . $table . '
                 WHERE id = :origin_id
                 LIMIT 1',
                ['origin_id' => (int) $row['reversal_of_id']]
            );

            if ($origin !== null && (int) $origin['user_id'] === (int) $row['user_id']) {
                if ((int) ($origin['cutover_id'] ?? 0) > 0) {
                    $candidates = $directCandidate($connection, (int) $origin['cutover_id'], (int) $row['user_id']);
                } elseif ((string) ($origin['source_type'] ?? '') === 'employee_account_cutover') {
                    $candidates = $directCandidate($connection, (int) ($origin['source_id'] ?? 0), (int) $row['user_id']);
                } else {
                    $candidates = $temporalCandidates($connection, $table, $origin);
                }
            }
        } else {
            $candidates = $temporalCandidates($connection, $table, $row);
        }

        $report[] = [
            'journal' => $table,
            'journal_id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'effective_date' => (string) $row['effective_date'],
            'amount' => (string) $row['amount'],
            'current_cutover_id' => $row['cutover_id'] !== null ? (int) $row['cutover_id'] : null,
            'source_type' => $row['source_type'],
            'source_id' => $row['source_id'] !== null ? (int) $row['source_id'] : null,
            'reversal_of_id' => $row['reversal_of_id'] !== null ? (int) $row['reversal_of_id'] : null,
            'evidence' => $evidence,
            'possible_cutovers' => array_map(static fn (array $candidate): array => [
                'id' => (int) $candidate['id'],
                'effective_from' => (string) $candidate['effective_from'],
                'status' => (string) $candidate['status'],
            ], $candidates),
        ];
    }
}

if (in_array('--json', $argv, true)) {
    fwrite(STDOUT, json_encode(['unresolved' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($report === [] ? 0 : 2);
}

if ($report === []) {
    fwrite(STDOUT, 'Alle Journalzeilen sind eindeutig einer Stichtagsgeneration zugeordnet.' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, 'Ungeklaerte Journalgenerationen (aktive Salden ignorieren diese Zeilen):' . PHP_EOL);
foreach ($report as $item) {
    $candidateIds = array_map(static fn (array $candidate): string => (string) $candidate['id'], $item['possible_cutovers']);
    fwrite(STDOUT, sprintf(
        ' - %s #%d | User %d | %s | Betrag %s | aktuelle Zuordnung: %s | Beleg: %s | moegliche Stichtage: %s',
        $item['journal'],
        $item['journal_id'],
        $item['user_id'],
        $item['effective_date'],
        $item['amount'],
        $item['current_cutover_id'] === null ? 'keine' : (string) $item['current_cutover_id'],
        $item['evidence'],
        $candidateIds === [] ? 'keine' : implode(', ', $candidateIds)
    ) . PHP_EOL);
}

exit(2);
