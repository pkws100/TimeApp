<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Settings\DatabaseSettingsManager;
use PHPUnit\Framework\TestCase;

final class DatabaseSettingsManagerTest extends TestCase
{
    public function testCurrentForOutputRedactsPasswordAndKeepsSetFlag(): void
    {
        $overrideFile = $this->temporaryOverrideFile([
            'host' => 'db',
            'password' => 'db-secret',
        ]);
        $manager = new DatabaseSettingsManager([
            'host' => 'localhost',
            'password' => '',
        ], $overrideFile);

        try {
            $settings = $manager->currentForOutput();

            self::assertSame('', $settings['password']);
            self::assertTrue($settings['password_is_set']);
            self::assertSame('db', $settings['host']);
        } finally {
            @unlink($overrideFile);
            @rmdir(dirname($overrideFile));
        }
    }

    public function testSanitizeKeepsExistingPasswordWhenSubmittedPasswordIsEmpty(): void
    {
        $overrideFile = $this->temporaryOverrideFile(['password' => 'existing-secret']);
        $manager = new DatabaseSettingsManager([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'app',
            'username' => 'app',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'socket' => '',
        ], $overrideFile);

        try {
            $settings = $manager->sanitize([
                'driver' => 'mysql',
                'host' => 'db',
                'port' => '3307',
                'database' => 'prod',
                'username' => 'prod-user',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'socket' => '',
            ]);

            self::assertSame('existing-secret', $settings['password']);
            self::assertSame(3307, $settings['port']);
        } finally {
            @unlink($overrideFile);
            @rmdir(dirname($overrideFile));
        }
    }

    public function testSaveForOutputDoesNotReturnPassword(): void
    {
        $overrideFile = $this->temporaryOverrideFile([]);
        $manager = new DatabaseSettingsManager([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'app',
            'username' => 'app',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'socket' => '',
        ], $overrideFile);

        try {
            $settings = $manager->saveForOutput([
                'driver' => 'mysql',
                'host' => 'db',
                'port' => '3306',
                'database' => 'prod',
                'username' => 'prod-user',
                'password' => 'new-secret',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'socket' => '',
            ]);

            self::assertSame('', $settings['password']);
            self::assertTrue($settings['password_is_set']);
            self::assertStringContainsString('new-secret', (string) file_get_contents($overrideFile));
        } finally {
            @unlink($overrideFile);
            @rmdir(dirname($overrideFile));
        }
    }

    private function temporaryOverrideFile(array $data): string
    {
        $dir = sys_get_temp_dir() . '/database-settings-' . bin2hex(random_bytes(6));

        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Temp-Verzeichnis konnte nicht erstellt werden.');
        }

        $path = $dir . '/database.override.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");

        return $path;
    }
}
