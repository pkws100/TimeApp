<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Calendar\CalendarPolicyService;
use App\Domain\TimeAccounts\TimeAccountService;
use App\Domain\Timesheets\TimesheetWriteGuard;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Domain\Vacation\VacationRequestService;
use App\Http\Controllers\AdminVacationRequestController;
use App\Http\Request;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminVacationRequestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testPageRendersVacationAccountsForSelectedYear(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderPage');
        $method->setAccessible(true);
        $rows = [
            $this->accountRow(),
            $this->accountRow([
                'user_id' => 8,
                'user' => 'Ohne Stichtag',
                'employee_number' => 'MA-008',
                'account_status' => 'missing',
                'cutover_id' => null,
                'cutover_date' => null,
                'leave_year' => null,
                'source' => null,
                'vacation' => null,
            ]),
        ];

        $html = (string) $method->invoke(
            $controller,
            [],
            ['year' => 2027, 'rows' => $rows],
            [
                ['id' => 7, 'first_name' => 'Ada', 'last_name' => 'Admin'],
                ['id' => 8, 'first_name' => 'Ohne', 'last_name' => 'Stichtag'],
            ],
            ['year' => 2027, 'status' => 'pending', 'user_id' => 7],
            new Request('GET', '/admin/vacation-requests', ['year' => '2027', 'status' => 'pending', 'user_id' => '7'], [], [], [], [])
        );

        self::assertStringContainsString('Urlaubskonten und Urlaubsantraege', $html);
        self::assertStringContainsString('name="year"', $html);
        self::assertStringContainsString('value="2027"', $html);
        self::assertStringContainsString('Zukuenftig', $html);
        self::assertStringContainsString('Resturlaub', $html);
        self::assertStringContainsString('Verfuegbar', $html);
        self::assertStringContainsString('32,00 Tage', $html);
        self::assertStringContainsString('Vorschlag', $html);
        self::assertStringContainsString('Nicht eingerichtet', $html);
        self::assertStringContainsString('Keine Urlaubsantraege fuer diese Auswahl gefunden.', $html);
    }

    public function testTimeAccountLinksDependOnPermissionAndStatus(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'accountRows');
        $method->setAccessible(true);
        $missing = $this->accountRow([
            'user_id' => 8,
            'account_status' => 'missing',
            'source' => null,
            'vacation' => null,
        ]);

        $withPermission = (string) $method->invoke($controller, [$this->accountRow(), $missing], true, true);
        $viewOnly = (string) $method->invoke($controller, [$missing], true, false);
        $withoutPermission = (string) $method->invoke($controller, [$this->accountRow()], false, false);

        self::assertStringContainsString('/admin/time-accounts?user_id=7&amp;year=2027', $withPermission);
        self::assertStringContainsString('Zeitkonto oeffnen', $withPermission);
        self::assertStringContainsString('Stichtag einrichten', $withPermission);
        self::assertStringContainsString('Zeitkonto oeffnen', $viewOnly);
        self::assertStringNotContainsString('Stichtag einrichten', $viewOnly);
        self::assertStringNotContainsString('/admin/time-accounts', $withoutPermission);
    }

    public function testDecisionRedirectKeepsVacationFilters(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'withQuery');
        $method->setAccessible(true);

        $url = (string) $method->invoke(
            $controller,
            '/admin/vacation-requests?year=2027&status=pending&user_id=7',
            'notice',
            'approved'
        );

        self::assertSame('/admin/vacation-requests?year=2027&status=pending&user_id=7&notice=approved', $url);
    }

    private function controller(): AdminVacationRequestController
    {
        $connection = new DatabaseConnection([]);
        $calendar = new CalendarPolicyService($connection);
        $auth = new AuthService($connection, new PermissionMatrix([], []));

        return new AdminVacationRequestController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            new VacationRequestService($connection, $calendar, new TimesheetWriteGuard($connection)),
            new TimeAccountService($connection, $calendar),
            new UserService($connection),
            $auth,
            new CsrfService()
        );
    }

    private function accountRow(array $overrides = []): array
    {
        return $overrides + [
            'user_id' => 7,
            'user' => 'Ada Admin',
            'employee_number' => 'MA-007',
            'year' => 2027,
            'account_status' => 'active',
            'cutover_id' => 12,
            'cutover_date' => '2026-01-01',
            'leave_year' => 2026,
            'source' => 'user_defaults',
            'vacation' => [
                'total_days' => 32.0,
                'approved_taken_past_days' => 4.0,
                'future_approved_days' => 3.0,
                'pending_days' => 2.0,
                'remaining_days' => 25.0,
                'available_days' => 23.0,
            ],
        ];
    }
}
