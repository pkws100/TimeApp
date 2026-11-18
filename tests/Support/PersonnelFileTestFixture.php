<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\AuthService;
use App\Domain\Personnel\Compliance\PersonnelAccessService;
use App\Domain\Personnel\Compliance\PersonnelDocumentService;
use App\Domain\Personnel\Compliance\PersonnelFileRuntime;
use App\Domain\Personnel\Storage\PersonnelFileValidator;
use App\Domain\Personnel\Storage\PersonnelKeyring;
use App\Domain\Personnel\Storage\PersonnelMalwareScanner;
use App\Domain\Personnel\Storage\PersonnelPrivateStorage;
use App\Domain\Personnel\Storage\PersonnelStreamCipher;
use App\Domain\Users\PermissionMatrix;
use App\Infrastructure\Database\DatabaseConnection;
use RuntimeException;

final class PersonnelFileTestFixture implements PersonnelMalwareScanner
{
    public bool $scanFails = false;

    public function assertReady(): void
    {
    }

    public function scan($stream): void
    {
        if ($this->scanFails || stream_get_contents($stream) === '') {
            throw new RuntimeException('Synthetic scanner failure.');
        }
    }

    public function runtime(DatabaseConnection $connection, string $root): PersonnelFileRuntime
    {
        $keys = new PersonnelKeyring('synthetic', ['synthetic' => hash('sha256', 'phase2b-integration-test-only', true)]);
        return PersonnelFileRuntime::forSyntheticTests(
            $connection,
            new PersonnelPrivateStorage($root, [base_path(), sys_get_temp_dir()]),
            new PersonnelStreamCipher($keys),
            new PersonnelFileValidator('/usr/bin/qpdf'),
            $this,
        );
    }

    public static function auth(DatabaseConnection $connection): AuthService
    {
        $config = require config_path('permissions.php');

        return new AuthService($connection, new PermissionMatrix($config['roles'], $config['available']));
    }

    public static function login(DatabaseConnection $connection, int $actor): void
    {
        $_SESSION = ['auth' => [
            'user_id' => $actor,
            'logged_in_at' => time(),
            'database_realm' => $connection->authenticationRealm(),
        ]];
    }

    public static function documentService(DatabaseConnection $connection, PersonnelFileRuntime $runtime): PersonnelDocumentService
    {
        return new PersonnelDocumentService($connection, new PersonnelAccessService($connection, self::auth($connection)), $runtime);
    }

    public static function png(int $shade = 40): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, $shade, 50, 60));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function upload(PersonnelDocumentService $service, int $id, int $predecessor, string $bytes, string $request, ?string $mime = 'image/png', string $extension = 'png'): array
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            return $service->uploadFile($id, $predecessor, $stream, strlen($bytes), $request, $mime, $extension);
        } finally {
            fclose($stream);
        }
    }
}
