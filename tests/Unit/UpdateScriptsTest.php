<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UpdateScriptsTest extends TestCase
{
    public function testProductionUpdateRunsMigrationsSeedAndVerify(): void
    {
        $script = $this->readProjectFile('bin/update-prod.sh');

        self::assertStringContainsString('vendor/bin/phinx migrate -c phinx.php', $script);
        self::assertStringContainsString('vendor/bin/phinx seed:run -c phinx.php -s InitialReferenceSeeder', $script);
        self::assertStringContainsString('php bin/verify-update.php', $script);
    }

    public function testNativeUpdateRunsMigrationsSeedAndVerify(): void
    {
        $script = $this->readProjectFile('bin/update-native.sh');

        self::assertStringContainsString('COMPOSER_PATH=$(resolve_command "$COMPOSER_BIN")', $script);
        self::assertStringContainsString('"$PHP_BIN" "$COMPOSER_PATH" install', $script);
        self::assertStringContainsString('"$PHP_BIN" "$PHINX_BIN" migrate -c phinx.php', $script);
        self::assertStringContainsString('"$PHP_BIN" "$PHINX_BIN" seed:run -c phinx.php -s InitialReferenceSeeder', $script);
        self::assertStringContainsString('"$PHP_BIN" bin/verify-update.php', $script);
    }

    public function testVerifyUpdateChecksPendingMigrationsAndConfiguredPermissions(): void
    {
        $script = $this->readProjectFile('bin/verify-update.php');

        self::assertStringContainsString("glob(base_path('migrations') . '/*.php')", $script);
        self::assertStringContainsString("SELECT version FROM phinxlog", $script);
        self::assertStringContainsString("Migration ausstehend:", $script);
        self::assertStringContainsString("\$config->get('permissions.available', [])", $script);
        self::assertStringContainsString('Permission aus config/permissions.php fehlt:', $script);
        self::assertStringContainsString("\$config->get('permissions.roles', [])", $script);
        self::assertStringContainsString('Permission-Zuordnung aus config/permissions.php fehlt:', $script);
    }

    public function testPhinxLoadsDotEnvBeforeResolvingDatabaseConfig(): void
    {
        $config = $this->readProjectFile('phinx.php');

        self::assertStringContainsString('use App\Config\EnvironmentLoader;', $config);
        self::assertStringContainsString("(new EnvironmentLoader())->load(__DIR__ . '/.env');", $config);
        self::assertLessThan(
            strpos($config, '$database = require __DIR__ . \'/config/database.php\';'),
            strpos($config, "(new EnvironmentLoader())->load(__DIR__ . '/.env');")
        );
    }

    private function readProjectFile(string $path): string
    {
        $fullPath = dirname(__DIR__, 2) . '/' . $path;
        $contents = file_get_contents($fullPath);

        self::assertIsString($contents, sprintf('Could not read %s', $path));

        return $contents;
    }
}
