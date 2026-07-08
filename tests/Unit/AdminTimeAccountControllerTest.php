<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\Exports\TimeAccountExportService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Users\UserService;
use App\Http\Controllers\AdminTimeAccountController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminTimeAccountControllerTest extends TestCase
{
    public function testPageRendersExportLinksWithoutPagination(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderPage');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, [
            'year' => 2026,
            'month' => 1,
            'rows' => [],
            'pagination' => [
                'total' => 0,
                'page' => 3,
                'per_page' => 25,
                'total_pages' => 3,
            ],
            'filters' => [
                'user_id' => 7,
                'q' => 'Ada',
                'saldo_filter' => 'negative',
                'vacation_filter' => 'pending',
                'sort' => 'saldo',
                'direction' => 'desc',
                'page' => 3,
                'per_page' => 25,
            ],
        ], [
            ['id' => 7, 'first_name' => 'Ada', 'last_name' => 'Admin', 'employment_status' => 'active'],
        ]);

        self::assertStringContainsString('/admin/time-accounts/export?', $html);
        self::assertStringContainsString('year=2026', $html);
        self::assertStringContainsString('month=1', $html);
        self::assertStringContainsString('user_id=7', $html);
        self::assertStringContainsString('q=Ada', $html);
        self::assertStringContainsString('saldo_filter=negative', $html);
        self::assertStringContainsString('vacation_filter=pending', $html);
        self::assertStringContainsString('sort=saldo', $html);
        self::assertStringContainsString('direction=desc', $html);
        self::assertStringContainsString('format=csv', $html);
        self::assertStringContainsString('format=xlsx', $html);
        self::assertStringContainsString('format=pdf', $html);
        self::assertDoesNotMatchRegularExpression('#/admin/time-accounts/export[^"]*(page|per_page)=#', $html);
    }

    public function testExportErrorNoticeIsVisible(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'notice');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, new Request('GET', '/admin/time-accounts', ['error' => 'export'], [], [], [], []));

        self::assertStringContainsString('notice error', $html);
        self::assertStringContainsString('Der Export konnte nicht erstellt werden.', $html);
    }

    private function controller(): AdminTimeAccountController
    {
        $connection = new DatabaseConnection([]);
        $timeAccountService = new TimeAccountService($connection, new CalendarPolicyService($connection));

        return new AdminTimeAccountController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            $timeAccountService,
            new TimeAccountExportService($timeAccountService),
            new UserService($connection)
        );
    }
}
