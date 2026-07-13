<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\MariaDbScratchConfig;

final class MariaDbScratchConfigTest extends TestCase
{
    public function testExplicitHostUsesTcpWhenSocketIsUnsetOrEmpty(): void
    {
        $previousHost = getenv('TIMEAPP_TEST_DB_HOST');
        $previousSocket = getenv('TIMEAPP_TEST_DB_SOCKET');

        try {
            putenv('TIMEAPP_TEST_DB_HOST=db.example.test');
            putenv('TIMEAPP_TEST_DB_SOCKET');
            $unsetSocket = MariaDbScratchConfig::server();
            self::assertSame('', $unsetSocket['socket']);
            self::assertStringContainsString('host=db.example.test', $unsetSocket['dsn']);

            putenv('TIMEAPP_TEST_DB_SOCKET=');
            $emptySocket = MariaDbScratchConfig::server();
            self::assertSame('', $emptySocket['socket']);
            self::assertStringContainsString('host=db.example.test', $emptySocket['dsn']);
        } finally {
            $this->restoreEnvironment('TIMEAPP_TEST_DB_HOST', $previousHost);
            $this->restoreEnvironment('TIMEAPP_TEST_DB_SOCKET', $previousSocket);
        }
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}
