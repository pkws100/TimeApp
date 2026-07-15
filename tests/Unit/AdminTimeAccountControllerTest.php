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

    public function testZeroJournalEntryDoesNotRenderReversalAction(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'journalAction');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, 'vacation', [
            'id' => 17,
            'effective_date' => '2027-01-01',
            'entry_type' => 'annual_entitlement',
            'days' => 0,
            'is_open' => 0,
        ], 'csrf', true);

        self::assertStringNotContainsString('Ausgleichen', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testCutoverPreviewExplainsTheOpeningVacationCalculation(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'cutoverForm');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, [
            ['id' => 7, 'first_name' => 'Codex', 'last_name' => 'Agent', 'employment_status' => 'active'],
        ], 'csrf', [
            'user_id' => 7,
            'employee_name' => 'MA-0003 Codex Agent',
            'effective_from' => '2026-07-01',
            'locked_until' => '2026-06-30',
            'opening_time_balance_label' => '+00:00',
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => 27,
            'leave_carryover_days' => 1,
            'opening_remaining_leave_days' => 15,
            'opening_adjustment_days' => -13,
            'timesheets_after_cutover' => 1,
            'warnings' => ['Es existieren bereits Buchungen ab dem gewuenschten Stichtag.'],
        ], 7);

        self::assertStringContainsString('Startsaldo nach Finalisierung: 15,00 Tage Resturlaub zum 01.07.2026 (Stand: Ende 30.06.2026).', $html);
        self::assertStringContainsString('27,00 Tage Jahresanspruch + 1,00 Tage Uebertrag - 13,00 Tage technische Anpassung = 15,00 Tage Startsaldo.', $html);
        self::assertStringContainsString('Die technische Anpassung ist keine zusaetzliche Urlaubsnahme.', $html);
        self::assertStringContainsString('Bereits vorhandene Buchungen ab dem Stichtag bleiben erhalten.', $html);
    }

    public function testCutoverPreviewUsesTheCorrectPositiveSignAndHidesTheBookingHintWithoutBookings(): void
    {
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'cutoverForm');
        $method->setAccessible(true);

        $html = (string) $method->invoke($controller, [], 'csrf', [
            'user_id' => 7,
            'employee_name' => 'MA-0003 Codex Agent',
            'effective_from' => '2026-07-01',
            'locked_until' => '2026-06-30',
            'opening_time_balance_label' => '+00:00',
            'leave_year' => 2026,
            'annual_leave_entitlement_days' => 27,
            'leave_carryover_days' => 1,
            'opening_remaining_leave_days' => 30,
            'opening_adjustment_days' => 2,
            'timesheets_after_cutover' => 0,
            'warnings' => [],
        ]);

        self::assertStringContainsString('27,00 Tage Jahresanspruch + 1,00 Tage Uebertrag + 2,00 Tage technische Anpassung = 30,00 Tage Startsaldo.', $html);
        self::assertStringNotContainsString('Bereits vorhandene Buchungen ab dem Stichtag bleiben erhalten.', $html);
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
