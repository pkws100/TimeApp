<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\ConfigRepository;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Settings\DatabaseSettingsManager;
use App\Infrastructure\Database\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class CalendarPolicyDatabaseTest extends TestCase
{
    public function testCompanyClosureDayPolicyUsesNativePdoPlaceholdersSafely(): void
    {
        $connection = $this->connection();

        if (!$connection->isAvailable()) {
            self::markTestSkipped('Keine Test-Datenbank verfuegbar.');
        }

        if (!$connection->tableExists('company_closures')) {
            self::markTestSkipped('Migration fuer company_closures ist nicht verfuegbar.');
        }

        $pdo = $connection->pdo();
        self::assertInstanceOf(PDO::class, $pdo);

        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare(
                'INSERT INTO company_closures (title, date_from, date_to, year, notes, created_at, updated_at, is_deleted, deleted_at, deleted_by_user_id)
                 VALUES (:title, :date_from, :date_to, :year, :notes, NOW(), NOW(), 0, NULL, NULL)'
            );
            $statement->execute([
                'title' => 'PHPUnit Betriebsurlaub',
                'date_from' => '2026-07-14',
                'date_to' => '2026-07-16',
                'year' => 2026,
                'notes' => null,
            ]);

            $policy = (new CalendarPolicyService($connection))->dayPolicy('2026-07-15');

            self::assertTrue($policy['is_company_closure']);
            self::assertFalse($policy['time_tracking_required']);
            self::assertContains('PHPUnit Betriebsurlaub', $policy['closure_titles']);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function connection(): DatabaseConnection
    {
        $config = ConfigRepository::load(['database']);
        $settings = new DatabaseSettingsManager(
            (array) $config->get('database.connections.mysql', []),
            (string) $config->get('database.override_file')
        );

        return new DatabaseConnection($settings->current());
    }
}
