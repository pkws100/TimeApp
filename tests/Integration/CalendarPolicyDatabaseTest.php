<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\DailyTargetService;
use PDO;
use Tests\Support\MariaDbTestCase;

final class CalendarPolicyDatabaseTest extends MariaDbTestCase
{
    public function testCompanyClosureDayPolicyUsesNativePdoPlaceholdersSafely(): void
    {
        $connection = parent::connection();

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

    public function testCrossYearClosureIsLoadedByDateOverlapAndCacheIsInvalidated(): void
    {
        $service = new CalendarPolicyService(parent::connection());
        self::assertFalse($service->dayPolicy('2027-01-02')['is_company_closure']);
        $closure = $service->createClosure([
            'title' => 'Jahreswechsel',
            'date_from' => '2026-12-29',
            'date_to' => '2027-01-03',
        ]);

        self::assertTrue($service->dayPolicy('2026-12-30')['is_company_closure']);
        self::assertCount(1, $service->closuresForYear(2027));
        self::assertTrue($service->dayPolicy('2027-01-02')['is_company_closure']);
        self::assertFalse($service->dayPolicy('2027-01-04')['is_company_closure']);
        $service->archiveClosure((int) $closure['id']);
        self::assertFalse($service->dayPolicy('2027-01-02')['is_company_closure']);
    }

    public function testHolidayAndClosureDoNotReduceTargetTwice(): void
    {
        $service = new CalendarPolicyService(parent::connection());
        $service->saveRegion('NW');
        $service->createClosure([
            'title' => 'Neujahrsschliessung',
            'date_from' => '2027-01-01',
            'date_to' => '2027-01-01',
        ]);
        $stats = (new DailyTargetService($service))->stats([
            'id' => 7,
            'target_hours_mode' => 'week',
            'target_hours_week' => 40,
            'workdays_mask' => '1,2,3,4,5',
        ], '2027-01-01', '2027-01-01');

        self::assertSame(480, $stats['holiday_reduction_minutes']);
        self::assertSame(0, $stats['company_closure_reduction_minutes']);
        self::assertSame(0, $stats['effective_target_minutes']);
    }
}
