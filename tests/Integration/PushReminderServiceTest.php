<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Push\PushNotificationService;
use App\Domain\Push\PushReminderService;
use App\Domain\Push\PushSettingsService;
use App\Domain\Push\PushSubscriptionService;
use App\Domain\Users\UserWorkdayPolicy;
use DateTimeImmutable;
use Tests\Support\MariaDbTestCase;

final class PushReminderServiceTest extends MariaDbTestCase
{
    public function testUserIsNotCandidateOutsideIndividualWorkdayMask(): void
    {
        $userId = $this->createReminderUser('2,3,4');

        $summary = $this->service()->sendDueReminders($this->now('2026-05-11'), true);

        self::assertSame(0, $summary['candidates']);
        self::assertSame(0, $summary['sent']);
        self::assertGreaterThan(0, $userId);
    }

    public function testUserIsCandidateOnIndividualWorkdayWithoutBooking(): void
    {
        $this->createReminderUser('2,3,4');

        $summary = $this->service()->sendDueReminders($this->now('2026-05-12'), true);

        self::assertSame(1, $summary['candidates']);
        self::assertSame(1, $summary['sent']);
    }

    public function testExistingBookingSuppressesReminder(): void
    {
        $userId = $this->createReminderUser('2,3,4');
        $this->connection()->execute(
            'INSERT INTO timesheets (user_id, work_date, entry_type, created_at, updated_at)
             VALUES (:user_id, :work_date, "work", NOW(), NOW())',
            ['user_id' => $userId, 'work_date' => '2026-05-12']
        );

        $summary = $this->service()->sendDueReminders($this->now('2026-05-12'), true);

        self::assertSame(0, $summary['candidates']);
    }

    public function testStoredAbsenceSuppressesReminder(): void
    {
        $userId = $this->createReminderUser('2,3,4');
        $this->connection()->execute(
            'INSERT INTO timesheets (user_id, work_date, entry_type, created_at, updated_at)
             VALUES (:user_id, :work_date, "sick", NOW(), NOW())',
            ['user_id' => $userId, 'work_date' => '2026-05-12']
        );

        $summary = $this->service()->sendDueReminders($this->now('2026-05-12'), true);

        self::assertSame(0, $summary['candidates']);
    }

    public function testDisabledTimeTrackingSuppressesReminder(): void
    {
        $userId = $this->createReminderUser('2,3,4');
        $this->connection()->execute(
            'UPDATE users SET time_tracking_required = 0 WHERE id = :id',
            ['id' => $userId]
        );

        $summary = $this->service()->sendDueReminders($this->now('2026-05-12'), true);

        self::assertSame(0, $summary['candidates']);
    }

    public function testGlobalReminderWeekdaysRemainAnAdditionalGate(): void
    {
        $this->createReminderUser('2');
        $this->enableReminders('1,3,4,5');

        $summary = $this->service()->sendDueReminders($this->now('2026-05-12'), true);

        self::assertSame(0, $summary['candidates']);
        self::assertStringContainsString('Wochentag ist deaktiviert', implode(' ', $summary['messages']));
    }

    public function testCompanyClosureSuppressesReminder(): void
    {
        $this->createReminderUser('2');
        $this->connection()->execute(
            'INSERT INTO company_closures (
                title, date_from, date_to, year, created_at, updated_at, is_deleted
             ) VALUES (
                "Test-Betriebsurlaub", "2026-05-12", "2026-05-12", 2026, NOW(), NOW(), 0
             )'
        );

        $summary = $this->service()->sendDueReminders($this->now('2026-05-12'), true);

        self::assertSame(0, $summary['candidates']);
        self::assertStringContainsString('keine Pflichtbuchung', implode(' ', $summary['messages']));
    }

    public function testSaturdayWorkerRequiresSaturdayInGlobalReminderConfiguration(): void
    {
        $this->createReminderUser('6');

        $globallyDisabled = $this->service()->sendDueReminders($this->now('2026-05-16'), true);
        $this->enableReminders('1,2,3,4,5,6');
        $globallyEnabled = $this->service()->sendDueReminders($this->now('2026-05-16'), true);

        self::assertSame(0, $globallyDisabled['candidates']);
        self::assertSame(1, $globallyEnabled['candidates']);
        self::assertSame(1, $globallyEnabled['sent']);
    }

    public function testArchivedRoleNoLongerGrantsPushEligibility(): void
    {
        $this->createReminderUser('2');
        $this->connection()->execute(
            'UPDATE roles SET is_deleted = 1 WHERE slug = "reminder-test"'
        );

        $summary = $this->service()->sendDueReminders($this->now('2026-05-12'), true);

        self::assertSame(0, $summary['candidates']);
    }

    private function createReminderUser(string $workdaysMask): int
    {
        $this->enableReminders();
        $userId = $this->createUser(['workdays_mask' => $workdaysMask]);
        $this->connection()->execute(
            'INSERT INTO roles (slug, name, description, is_system_role, created_at, updated_at)
             VALUES ("reminder-test", "Reminder-Test", NULL, 0, NOW(), NOW())'
        );
        $roleId = $this->connection()->lastInsertId();
        $this->connection()->execute(
            'INSERT INTO permissions (code, label, scope, created_at)
             VALUES ("push.receive", "Push empfangen", "app", NOW())'
        );
        $permissionId = (int) $this->connection()->fetchColumn(
            'SELECT id FROM permissions WHERE code = "push.receive" LIMIT 1'
        );
        $this->connection()->execute(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
            ['role_id' => $roleId, 'permission_id' => $permissionId]
        );
        $this->connection()->execute(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $userId, 'role_id' => $roleId]
        );
        $this->connection()->execute(
            'INSERT INTO push_subscriptions (
                user_id, endpoint_hash, endpoint, public_key, auth_token, content_encoding,
                device_label, is_enabled, permission_status, last_seen_at, created_at, updated_at
             ) VALUES (
                :user_id, :endpoint_hash, :endpoint, "test-public-key", "test-auth-token", "aes128gcm",
                "PHPUnit", 1, "granted", NOW(), NOW(), NOW()
             )',
            [
                'user_id' => $userId,
                'endpoint_hash' => hash('sha256', 'https://push.example.test/' . $userId),
                'endpoint' => 'https://push.example.test/' . $userId,
            ]
        );

        return $userId;
    }

    private function enableReminders(string $weekdays = '1,2,3,4,5'): void
    {
        $this->connection()->execute(
            'INSERT INTO push_settings (
                id, enabled, reminder_enabled, reminder_time, reminder_weekdays, notice_text,
                created_at, updated_at
             ) VALUES (
                1, 1, 1, "09:00", :weekdays, "Test-Erinnerung", NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                enabled = 1,
                reminder_enabled = 1,
                reminder_time = "09:00",
                reminder_weekdays = VALUES(reminder_weekdays)',
            ['weekdays' => $weekdays]
        );
    }

    private function service(): PushReminderService
    {
        $settings = new PushSettingsService($this->connection(), []);
        $subscriptions = new PushSubscriptionService($this->connection());
        $notifications = new PushNotificationService($this->connection(), $settings, $subscriptions);

        return new PushReminderService(
            $this->connection(),
            $settings,
            $subscriptions,
            $notifications,
            'Europe/Berlin',
            new CalendarPolicyService($this->connection()),
            new UserWorkdayPolicy()
        );
    }

    private function now(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 10:00:00 Europe/Berlin');
    }
}
