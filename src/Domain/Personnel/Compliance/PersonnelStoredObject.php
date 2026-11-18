<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Domain\Personnel\Storage\PersonnelKeyring;
use InvalidArgumentException;
use RuntimeException;

final class PersonnelStoredObject
{
    public static function openVerified(PersonnelFileRuntime $files, array $version): PersonnelFileDownload
    {
        try {
            return $files->storage->withLock(false, fn (): PersonnelFileDownload => self::verifyUnderLock($files, $version));
        } catch (RuntimeException | InvalidArgumentException) {
            throw new PersonnelFileUnavailable();
        }
    }

    /** The caller holds the private storage lock until verification completes. */
    public static function verifyUnderLock(PersonnelFileRuntime $files, array $version): PersonnelFileDownload
    {
        $size = (int) ($version['size_bytes'] ?? 0);
        if ((int) ($version['storage_format'] ?? 0) !== PersonnelFileRuntime::STORAGE_FORMAT
            || $size < 1 || $size > PersonnelFileRuntime::MAX_BYTES
            || !in_array($version['mime_type'] ?? null, ['application/pdf', 'image/jpeg', 'image/png'], true)
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($version['sha256'] ?? '')) !== 1
            || !PersonnelKeyring::validIdentifier((string) ($version['storage_key_id'] ?? ''))
            || empty($version['format_verified_at']) || empty($version['malware_scanned_at'])) {
            throw new PersonnelFileUnavailable();
        }

        $key = (string) ($version['storage_key'] ?? '');
        $encrypted = $files->storage->openObject($key);
        $plain = fopen('php://memory', 'w+b');
        if ($plain === false) {
            fclose($encrypted);
            throw new PersonnelFileUnavailable();
        }

        try {
            $files->cipher->decrypt($encrypted, $plain, PersonnelFileRuntime::objectContext($key), $version['storage_key_id'], $size);
            if (ftell($plain) !== $size || !rewind($plain)) {
                throw new PersonnelFileUnavailable();
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $plain) !== $size || !hash_equals($version['sha256'], hash_final($hash)) || !rewind($plain)) {
                throw new PersonnelFileUnavailable();
            }

            $result = new PersonnelFileDownload($plain, $version['mime_type'], $size);
            $plain = null;

            return $result;
        } finally {
            fclose($encrypted);
            if (is_resource($plain)) {
                fclose($plain);
            }
        }
    }
}
