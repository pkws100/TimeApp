<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;

final class AdminViewTest extends TestCase
{
    public function testNavigationContainsManagementEntries(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/attendance';
        $view = new AdminView(
            'Baustellen Zeiterfassung',
            'http://localhost',
            static fn (): array => [
                'app_name' => 'Team Cockpit',
                'navigation' => [
                    ['href' => '/admin', 'label' => 'Dashboard', 'active_prefix' => '/admin'],
                    ['href' => '/admin/attendance', 'label' => 'Anwesenheit', 'active_prefix' => '/admin/attendance', 'badge' => 4],
                    ['href' => '/admin/projects', 'label' => 'Projekte', 'active_prefix' => '/admin/projects', 'badge' => 12],
                    ['href' => '/admin/bookings', 'label' => 'Buchungen', 'active_prefix' => '/admin/bookings', 'badge' => 28],
                    ['href' => '/admin/users', 'label' => 'User', 'active_prefix' => '/admin/users', 'badge' => 6],
                    ['href' => '/admin/roles', 'label' => 'Rollen', 'active_prefix' => '/admin/roles', 'badge' => 5],
                    ['href' => '/admin/assets', 'label' => 'Geraete', 'active_prefix' => '/admin/assets', 'badge' => 9],
                    ['href' => '/admin/settings/company', 'label' => 'Settings', 'active_prefix' => '/admin/settings'],
                ],
            ]
        );
        $html = $view->render('Titel', '<p>Inhalt</p>');

        self::assertStringContainsString('Team Cockpit', $html);
        self::assertStringContainsString('Titel | Team Cockpit', $html);
        self::assertStringContainsString('<link rel="icon" href="/assets/app-icon.svg" type="image/svg+xml">', $html);
        self::assertStringContainsString('/admin/attendance', $html);
        self::assertStringContainsString('/admin/projects', $html);
        self::assertStringContainsString('/admin/bookings', $html);
        self::assertStringContainsString('/admin/users', $html);
        self::assertStringContainsString('/admin/roles', $html);
        self::assertStringContainsString('/admin/assets', $html);
        self::assertStringContainsString('/admin/settings/company', $html);
        self::assertStringContainsString('class="badge nav-badge">4</span>', $html);
    }
}
