<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Domain\Auth\AuthService;
use App\Infrastructure\Database\DatabaseConnection;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class PersonnelStorageRecoveryService
{
    public function __construct(
        private DatabaseConnection $connection,
        private AuthService $auth,
        private PersonnelFileRuntime $files,
    ) {
        if (!$auth->usesConnection($connection)) {
            throw new LogicException('Authentifizierung und Personalaktendateien benötigen dieselbe Datenbankverbindung.');
        }
    }

    public function reconcile(bool $purgeExpiredStaging = false, int $retainStagingSeconds = 86400): array
    {
        $this->authorize();
        if ($retainStagingSeconds < 3600) {
            throw new InvalidArgumentException('Die Quarantänefrist muss mindestens eine Stunde betragen.');
        }
        $this->files->assertAvailable($this->connection);

        return $this->files->storage->withLock(true, function () use ($purgeExpiredStaging, $retainStagingSeconds): array {
            $this->authorize();
            $storage = $this->files->storage;
            $unmatched = $storage->listObjectKeys();
            $result = ['verified' => 0, 'unverified_legacy' => 0, 'missing' => 0, 'invalid' => 0, 'orphaned_objects' => 0,
                'expired_staging' => 0, 'expired_quarantine' => 0, 'removed_staging' => 0, 'removed_quarantine' => 0];
            $lastVersion = 0;
            do {
                $versions = $this->connection->fetchAll(
                    'SELECT id, storage_key, storage_key_id, storage_format, sha256, mime_type, size_bytes,
                            format_verified_at, malware_scanned_at FROM employee_document_versions
                     WHERE id > :cursor AND storage_key IS NOT NULL ORDER BY id LIMIT 1000',
                    ['cursor' => $lastVersion]
                );
                foreach ($versions as $version) {
                    $lastVersion = (int) $version['id'];
                    $key = $version['storage_key'];
                    if ($version['storage_format'] === null) {
                        ++$result['unverified_legacy'];
                    } elseif (!isset($unmatched[$key])) {
                        ++$result['missing'];
                    } else {
                        try {
                            PersonnelStoredObject::verifyUnderLock($this->files, $version)->close();
                            ++$result['verified'];
                        } catch (RuntimeException | InvalidArgumentException) {
                            ++$result['invalid'];
                        }
                    }
                    unset($unmatched[$key]);
                }
            } while (count($versions) === 1000);

            // A final object may still belong to a transaction whose outcome is unknown. Preserve it for investigation.
            $result['orphaned_objects'] = count($unmatched);
            $cutoff = time() - $retainStagingSeconds;
            $expired = static fn (int $mtime): bool => $mtime <= $cutoff;
            $staging = array_keys(array_filter($storage->listStagingKeys(), $expired));
            $quarantine = array_keys(array_filter($storage->listQuarantineKeys(), $expired));
            $result['expired_staging'] = count($staging);
            $result['expired_quarantine'] = count($quarantine);
            if ($purgeExpiredStaging) {
                $this->connection->transaction(function () use ($storage, $staging, $quarantine): void {
                    $authority = $this->auth->lockPersonnelAuthority(PersonnelAccessService::DELEGATE);
                    if (empty($authority['system_administrator'])) {
                        throw new PersonnelAccessDenied();
                    }
                    foreach ($staging as $key) {
                        $storage->abortObject((string) $key);
                    }
                    foreach ($quarantine as $key) {
                        $storage->removeQuarantine((string) $key);
                    }
                });
                $result['removed_staging'] = count($staging);
                $result['removed_quarantine'] = count($quarantine);
            } else {
                $this->authorize();
            }

            return $result;
        });
    }

    private function authorize(): void
    {
        if (!$this->auth->isSystemAdministrator() || !$this->auth->hasPermission(PersonnelAccessService::DELEGATE)) {
            throw new PersonnelAccessDenied();
        }
    }
}
