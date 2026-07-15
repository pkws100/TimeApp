<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Settings\CompanySettingsService;
use App\Domain\Terminals\TerminalService;
use Tests\Support\MariaDbTestCase;

final class TerminalRestoreDatabaseTest extends MariaDbTestCase
{
    public function testRestoreClearsTerminalArchiveMarkersWithoutChangingOperationalState(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->connection()->execute(
            'INSERT INTO terminals (
                terminal_identifier, name, token_hash, welcome_text, is_active,
                is_deleted, deleted_at, deleted_by_user_id, created_at, updated_at
             ) VALUES (
                :terminal_identifier, :name, :token_hash, "Willkommen", 1,
                1, NOW(), NULL, NOW(), NOW()
             )',
            [
                'terminal_identifier' => 'restore-' . $suffix,
                'name' => 'Archivterminal ' . $suffix,
                'token_hash' => password_hash('test-token', PASSWORD_DEFAULT),
            ]
        );
        $terminalId = $this->connection()->lastInsertId();
        $service = new TerminalService($this->connection(), new CompanySettingsService($this->connection(), []));

        self::assertTrue($service->restore($terminalId));

        $terminal = $this->connection()->fetchOne(
            'SELECT is_deleted, deleted_at, deleted_by_user_id, is_active FROM terminals WHERE id = :id',
            ['id' => $terminalId]
        );
        self::assertNotNull($terminal);
        self::assertSame(0, (int) $terminal['is_deleted']);
        self::assertNull($terminal['deleted_at']);
        self::assertNull($terminal['deleted_by_user_id']);
        self::assertSame(1, (int) $terminal['is_active']);
        self::assertFalse($service->restore($terminalId));
    }
}
