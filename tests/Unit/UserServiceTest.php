<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Users\UserService;
use App\Infrastructure\Database\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UserServiceTest extends TestCase
{
    public function testNormalizeDefaultsTimeTrackingRequirementToTrue(): void
    {
        $record = $this->normalize([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada@example.test',
        ]);

        self::assertTrue($record['time_tracking_required']);
    }

    public function testNormalizeAcceptsDisabledTimeTrackingRequirement(): void
    {
        $record = $this->normalize([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada@example.test',
            'time_tracking_required' => '0',
        ]);

        self::assertFalse($record['time_tracking_required']);
    }

    public function testNormalizeAcceptsCheckedTimeTrackingRequirement(): void
    {
        $record = $this->normalize([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada@example.test',
            'time_tracking_required' => '1',
        ]);

        self::assertTrue($record['time_tracking_required']);
    }

    public function testNormalizeDefaultsAppUiSettingsToVisibleWidgets(): void
    {
        $record = $this->normalize([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada@example.test',
        ]);

        self::assertTrue($record['app_ui_settings']['show_today_total_minutes']);
        self::assertTrue($record['app_ui_settings']['show_project_today_minutes']);
        self::assertTrue($record['app_ui_settings']['show_history']);
    }

    public function testNormalizeKeepsDisabledAppUiSettingFalse(): void
    {
        $record = $this->normalize([
            'first_name' => 'Ada',
            'last_name' => 'Admin',
            'email' => 'ada@example.test',
            'app_ui_settings' => [
                'show_today_total_minutes' => '0',
                'show_project_today_minutes' => '1',
            ],
        ]);

        self::assertFalse($record['app_ui_settings']['show_today_total_minutes']);
        self::assertTrue($record['app_ui_settings']['show_project_today_minutes']);
        self::assertTrue($record['app_ui_settings']['show_history']);
    }

    public function testEmailExistsReturnsFalseWhenUserTableIsUnavailable(): void
    {
        $service = new UserService(new DatabaseConnection([]));

        self::assertFalse($service->emailExists('ada@example.test'));
    }

    public function testEmployeeNumberExistsReturnsFalseWhenUserTableIsUnavailable(): void
    {
        $service = new UserService(new DatabaseConnection([]));

        self::assertFalse($service->employeeNumberExists('M-42'));
    }

    private function normalize(array $payload): array
    {
        $service = new UserService(new DatabaseConnection([]));
        $method = new ReflectionMethod($service, 'normalize');
        $method->setAccessible(true);

        return $method->invoke($service, $payload);
    }
}
