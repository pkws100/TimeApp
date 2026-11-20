#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\EnvironmentLoader;
use App\Domain\Personnel\Backup\PersonnelBackupAuthority;
use App\Domain\Personnel\Backup\PersonnelBackupFailure;
use App\Domain\Personnel\Backup\PersonnelLiveBackupSource;
use App\Domain\Personnel\Backup\PersonnelPrivateBackup;
use App\Domain\Personnel\Storage\PersonnelKeyring;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Domain\Personnel\Storage\PersonnelPrivateStorage;
use App\Infrastructure\Database\DatabaseConnection;

require_once __DIR__ . '/../bootstrap/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');

$operation = $argv[1] ?? '';
$create = in_array($operation, ['create', 'create-live'], true);
if ((!$create && !in_array($operation, ['validate', 'restore-scratch'], true)) || count($argv) !== ($create ? 3 : 4)) {
    fwrite(STDERR, "Aufruf: php bin/personnel-private-backup.php {create|create-live} <private-konfiguration.json>\n"
        . "Aufruf: php bin/personnel-private-backup.php {validate|restore-scratch} <private-konfiguration.json> <sicherungs-id>\n");
    exit(2);
}

try {
    $uploads = require config_path('uploads.php');
    $forbidden = [
        base_path(),
        '/var/www',
        $uploads['root'],
        base_path((string) env('EXPORT_TEMP_PATH', 'storage/cache/exports')),
        sys_get_temp_dir(),
    ];
    PersonnelPrivatePath::requireSeparate($argv[2], $forbidden);
    $handle = PersonnelPrivatePath::openSecret($argv[2]);
    try {
        $json = stream_get_contents($handle, 32769);
    } finally {
        fclose($handle);
    }
    if ($json === false || strlen($json) > 32768) {
        throw new PersonnelBackupFailure('invalid_configuration');
    }
    $config = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    $path = static function (string $key) use ($config): string {
        $value = $config[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new PersonnelBackupFailure('invalid_configuration');
        }

        return $value;
    };
    $mode = $config['mode'] ?? null;
    $live = $mode === 'production_beta';
    if ((!$live && $mode !== 'synthetic') || ($create && ($operation === 'create-live') !== $live)
        || !is_int($config['operator_uid'] ?? null)) {
        throw new PersonnelBackupFailure('operator_denied');
    }
    $authority = PersonnelBackupAuthority::forLocalOperator($config['operator_uid']);
    if ($live) {
        $authority->assertLiveOperator();
        (new EnvironmentLoader())->load(base_path('.env'));
        $uploads = require config_path('uploads.php');
        $forbidden = [...$forbidden, $uploads['root'], base_path((string) env('EXPORT_TEMP_PATH', 'storage/cache/exports'))];
    }
    $storagePath = $path($create ? 'source_storage' : 'scratch_storage');
    $archivePath = $path('archive_root');
    $forbidden = [...$forbidden, ...($config['forbidden_roots'] ?? [])];
    $maximum = $config['max_object_bytes'] ?? 16777216;
    if (!is_int($maximum) || $maximum < 1 || $maximum > 33554432) {
        throw new PersonnelBackupFailure('invalid_limits');
    }
    $otherPath = $config[$create ? 'scratch_storage' : 'source_storage'] ?? null;
    if (is_string($otherPath) && $otherPath !== '') {
        $forbidden[] = $otherPath;
    }

    $storageKeyringPath = $path('storage_keyring');
    $backupKeyringPath = $path('backup_keyring');
    foreach ([$argv[2], $storageKeyringPath, $backupKeyringPath] as $secretPath) {
        PersonnelPrivatePath::requireSeparate($secretPath, [...$forbidden, $storagePath, $archivePath]);
    }
    $storageKeys = PersonnelKeyring::fromFile($storageKeyringPath);
    $backupKeys = PersonnelKeyring::fromFile($backupKeyringPath);
    $appSecrets = [(string) env('APP_SECRET', ''), (string) env('SETTINGS_ENCRYPTION_KEY', '')];
    $storageKeys->assertSeparateFromSecrets(...$appSecrets);
    $backupKeys->assertSeparateFromSecrets(...$appSecrets);
    $backup = new PersonnelPrivateBackup(
        $authority,
        $archivePath,
        [...$forbidden, $storagePath],
        $backupKeys,
        $storageKeys,
        maxObjectBytes: $maximum,
    );
    $database = $config[$create ? 'source_database' : 'scratch_database'] ?? null;
    if (!is_array($database)) {
        throw new PersonnelBackupFailure('invalid_configuration');
    }

    if ($operation === 'create-live') {
        if (!is_int($config['source_storage_uid'] ?? null)) {
            throw new PersonnelBackupFailure('invalid_configuration');
        }
        $source = new PersonnelLiveBackupSource($authority, $storagePath, $config['source_storage_uid'],
            $database, [...$forbidden, $archivePath]);
        $report = $backup->createLive($source);
    } else {
        $connection = new DatabaseConnection($database);
        if ($live) {
            $socket = $database['socket'] ?? null;
            if (($database['driver'] ?? null) !== 'mysql' || ($database['host'] ?? null) !== 'localhost'
                || !is_string($socket) || !str_starts_with($socket, '/') || realpath($socket) !== $socket) {
                throw new PersonnelBackupFailure('scratch_target_denied');
            }
            $identity = $connection->fetchOne('SELECT @@skip_networking AS socket_only, @@socket AS socket_path');
            if ((int) ($identity['socket_only'] ?? 0) !== 1 || ($identity['socket_path'] ?? null) !== $socket) {
                throw new PersonnelBackupFailure('scratch_target_denied');
            }
        }
        $storage = new PersonnelPrivateStorage($storagePath, [...$forbidden, $archivePath], maxBytes: $maximum);
        $report = match ($operation) {
            'create' => $backup->create($connection, $storage),
            'validate' => $backup->validate($argv[3], $connection, $storage),
            'restore-scratch' => $backup->restoreToScratch($argv[3], $connection, $storage),
        };
    }
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (PersonnelBackupFailure $failure) {
    fwrite(STDERR, 'Personalaktensicherung abgebrochen (' . $failure->reason . ').' . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, 'Personalaktensicherung abgebrochen (private_configuration_or_runtime_error).' . PHP_EOL);
    exit(1);
}
