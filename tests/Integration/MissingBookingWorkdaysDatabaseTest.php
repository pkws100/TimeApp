<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Auth\AuthService;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\AdminCalendarService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserWorkdayPolicy;
use Tests\Support\MariaDbTestCase;

final class MissingBookingWorkdaysDatabaseTest extends MariaDbTestCase
{
    public function testCalendarAndAttendanceUseStoredIndividualWorkdays(): void
    {
        $weekdayUserId = $this->createHistoricalUser('2,3,4');
        $saturdayUserId = $this->createHistoricalUser('6');
        $policy = new CalendarPolicyService($this->connection());
        $workdayPolicy = new UserWorkdayPolicy();
        $calendar = new AdminCalendarService(
            $this->connection(),
            new AdminBookingService($this->connection(), new TimesheetCalculator()),
            $policy,
            null,
            $workdayPolicy
        );
        $attendance = new AttendanceService($this->connection(), $policy, $workdayPolicy);

        $monday = $calendar->summarizeDay('2026-07-13', []);
        $tuesday = $calendar->summarizeDay('2026-07-14', []);
        $friday = $calendar->summarizeDay('2026-07-17', []);
        $saturday = $calendar->summarizeDay('2026-07-18', []);

        self::assertSame([], $monday['missing_users']);
        self::assertSame([$weekdayUserId], array_column($tuesday['missing_users'], 'user_id'));
        self::assertSame([], $friday['missing_users']);
        self::assertSame([$saturdayUserId], array_column($saturday['missing_users'], 'user_id'));
        self::assertSame(0, $attendance->todaySummary('2026-07-13')['derived_missing_count']);
        self::assertSame([$weekdayUserId], $this->derivedMissingUserIds($attendance->todaySummary('2026-07-14')));
        self::assertSame(0, $attendance->todaySummary('2026-07-17')['derived_missing_count']);
        self::assertSame([$saturdayUserId], $this->derivedMissingUserIds($attendance->todaySummary('2026-07-18')));

        $this->connection()->execute(
            'INSERT INTO timesheets (user_id, work_date, entry_type, start_time, end_time, created_at, updated_at)
             VALUES (:user_id, "2026-07-18", "work", "08:00:00", "12:00:00", NOW(), NOW())',
            ['user_id' => $saturdayUserId]
        );

        self::assertSame(
            0,
            $attendance->todaySummary('2026-07-18')['derived_missing_count']
        );
        self::assertSame(
            [],
            $calendar->summarizeDay('2026-07-18', [['user_id' => $saturdayUserId, 'is_deleted' => 0]])['missing_users']
        );
    }

    public function testAuthenticatedUserCarriesStoredWorkdayMaskIntoAppContextInput(): void
    {
        $userId = $this->createHistoricalUser('6');
        $email = (string) $this->connection()->fetchColumn(
            'SELECT email FROM users WHERE id = :id',
            ['id' => $userId]
        );
        $auth = new AuthService($this->connection(), new PermissionMatrix([], []));

        $login = $auth->login($email, 'test-password');
        $currentUser = $auth->currentUser();

        self::assertTrue($login['ok']);
        self::assertIsArray($currentUser);
        self::assertSame('6', $currentUser['workdays_mask']);
        self::assertTrue((new UserWorkdayPolicy())->isScheduledWorkday($currentUser, '2026-07-18'));
        self::assertFalse((new UserWorkdayPolicy())->isScheduledWorkday($currentUser, '2026-07-16'));
    }

    private function createHistoricalUser(string $workdaysMask): int
    {
        $userId = $this->createUser(['workdays_mask' => $workdaysMask]);
        $this->connection()->execute(
            'UPDATE users SET created_at = "2026-01-01 00:00:00" WHERE id = :id',
            ['id' => $userId]
        );

        return $userId;
    }

    /**
     * @return list<int>
     */
    private function derivedMissingUserIds(array $summary): array
    {
        return array_values(array_map(
            static fn (array $status): int => (int) $status['user_id'],
            array_filter(
                $summary['statuses'] ?? [],
                static fn (array $status): bool => ($status['status_source'] ?? null) === 'derived_missing'
            )
        ));
    }
}
