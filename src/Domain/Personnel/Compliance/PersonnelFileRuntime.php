<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Compliance;

use App\Domain\Personnel\Storage\ClamAvPersonnelScanner;
use App\Domain\Personnel\Storage\PersonnelFileValidator;
use App\Domain\Personnel\Storage\PersonnelKeyring;
use App\Domain\Personnel\Storage\PersonnelMalwareScanner;
use App\Domain\Personnel\Storage\PersonnelPrivatePath;
use App\Domain\Personnel\Storage\PersonnelPrivateStorage;
use App\Domain\Personnel\Storage\PersonnelStreamCipher;
use App\Infrastructure\Database\DatabaseConnection;

final class PersonnelFileRuntime
{
    public const MAX_BYTES = 16 * 1024 * 1024;
    public const STORAGE_FORMAT = 1;

    private function __construct(
        private DatabaseConnection $connection,
        public readonly PersonnelPrivateStorage $storage,
        public readonly PersonnelStreamCipher $cipher,
        public readonly PersonnelFileValidator $validator,
        public readonly PersonnelMalwareScanner $scanner,
    ) {
    }

    public static function fromConfiguration(DatabaseConnection $connection): self
    {
        self::assertSyntheticEnvironment($connection);
        $config = require config_path('personnel_storage.php');
        $forbidden = array_values(array_filter($config['forbidden_roots']));
        PersonnelPrivatePath::requireSeparate($config['keyring'], [...$forbidden, $config['root']]);
        $storage = new PersonnelPrivateStorage($config['root'], $forbidden, self::MAX_BYTES);
        $keys = PersonnelKeyring::fromFile($config['keyring']);
        $keys->assertSeparateFromSecrets((string) env('APP_SECRET', ''), (string) env('SETTINGS_ENCRYPTION_KEY', ''));
        $scanner = new ClamAvPersonnelScanner($config['scanner_socket']);
        $validator = new PersonnelFileValidator($config['qpdf']);
        $validator->assertReady();
        $scanner->assertReady();

        return new self($connection, $storage, new PersonnelStreamCipher($keys), $validator, $scanner);
    }

    public static function forSyntheticTests(
        DatabaseConnection $connection,
        PersonnelPrivateStorage $storage,
        PersonnelStreamCipher $cipher,
        PersonnelFileValidator $validator,
        PersonnelMalwareScanner $scanner,
    ): self {
        self::assertSyntheticEnvironment($connection);

        return new self($connection, $storage, $cipher, $validator, $scanner);
    }

    public function assertAvailable(DatabaseConnection $connection): void
    {
        if ($connection !== $this->connection) {
            throw new PersonnelFileUnavailable();
        }
        self::assertSyntheticEnvironment($connection);
    }

    public static function objectContext(string $storageKey): string
    {
        return 'personnel.object.v1:' . $storageKey;
    }

    private static function assertSyntheticEnvironment(DatabaseConnection $connection): void
    {
        if (PHP_SAPI !== 'cli' || env('APP_ENV') !== 'test' || env('PERSONNEL_FILE_MODE') !== 'synthetic'
            || preg_match('/^timeapp_(?:test_[a-z0-9_]+_[a-f0-9]{8}|personnel_restore_[a-f0-9]{12})$/D',
                (string) $connection->fetchColumn('SELECT DATABASE()')) !== 1) {
            throw new PersonnelFileUnavailable();
        }
    }
}
