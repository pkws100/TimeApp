<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Domain\Assets\AssetService;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Auth\AuthService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Users\RoleService;
use App\Domain\Users\UserService;

final class AdminContextService
{
    public function __construct(
        private CompanySettingsService $companySettingsService,
        private AttendanceService $attendanceService,
        private ProjectService $projectService,
        private UserService $userService,
        private RoleService $roleService,
        private AssetService $assetService,
        private AdminBookingService $bookingService,
        private AuthService $authService,
        private string $defaultAppName
    ) {
    }

    public function context(): array
    {
        return [
            'app_name' => $this->appName(),
            'navigation' => $this->navigationItems(),
        ];
    }

    private function appName(): string
    {
        $settings = $this->companySettingsService->current();
        $appName = trim((string) ($settings['app_display_name'] ?? ''));

        return $appName !== '' ? $appName : $this->defaultAppName;
    }

    private function navigationItems(): array
    {
        $items = [
            ['href' => '/admin', 'label' => 'Dashboard', 'active_prefix' => '/admin', 'permission' => 'dashboard.view'],
            [
                'href' => '/admin/calendar',
                'label' => 'Kalender',
                'active_prefix' => '/admin/calendar',
                'permission' => 'timesheets.view',
            ],
            [
                'href' => '/admin/attendance',
                'label' => 'Anwesenheit',
                'active_prefix' => '/admin/attendance',
                'badge' => $this->attendanceService->presentCount(),
                'permission' => 'attendance.view',
            ],
            [
                'href' => '/admin/projects',
                'label' => 'Projekte',
                'active_prefix' => '/admin/projects',
                'badge' => count($this->projectService->list('active')),
                'permission' => 'projects.view',
            ],
            [
                'href' => '/admin/bookings',
                'label' => 'Buchungen',
                'active_prefix' => '/admin/bookings',
                'badge' => $this->bookingService->activeCount(),
                'permission' => 'timesheets.view',
            ],
            [
                'href' => '/admin/users',
                'label' => 'User',
                'active_prefix' => '/admin/users',
                'badge' => count($this->userService->list('active')),
                'permission' => 'users.manage',
            ],
            [
                'href' => '/admin/roles',
                'label' => 'Rollen',
                'active_prefix' => '/admin/roles',
                'badge' => count($this->roleService->list('active')),
                'permission' => 'roles.manage',
            ],
            [
                'href' => '/admin/assets',
                'label' => 'Geraete',
                'active_prefix' => '/admin/assets',
                'badge' => count($this->assetService->list('active')),
                'permission' => 'assets.manage',
            ],
            ['href' => '/admin/settings/company', 'label' => 'Settings', 'active_prefix' => '/admin/settings', 'permission' => 'settings.manage'],
        ];

        return array_values(array_filter(
            $items,
            fn (array $item): bool => $this->authService->hasPermission((string) ($item['permission'] ?? ''))
        ));
    }
}
