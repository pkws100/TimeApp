<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\CsrfService;
use App\Domain\Exports\AccountingClosureService;
use App\Domain\Exports\AccountingDocumentExportService;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\CompanySettingsService;
use App\Domain\Timesheets\AdminBookingService;
use App\Domain\Timesheets\TimesheetCalculator;
use App\Domain\Users\PermissionMatrix;
use App\Domain\Users\UserService;
use App\Http\Controllers\AdminAccountingController;
use App\Infrastructure\Database\DatabaseConnection;
use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdminAccountingControllerTest extends TestCase
{
    public function testAccountingPageRendersFiltersExportsAndValidationState(): void
    {
        $_SESSION = [];
        $controller = $this->controller();
        $method = new ReflectionMethod($controller, 'renderPage');
        $method->setAccessible(true);

        $html = (string) $method->invoke(
            $controller,
            [
                'type' => 'month',
                'period' => '2026-05',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
                'project_id' => null,
                'user_id' => null,
            ],
            [
                'closure' => [
                    'status_label' => 'VORLAEUFIG - nicht festgeschrieben',
                    'period_label' => '2026-05',
                    'total_net_minutes' => 480,
                ],
                'items' => [['id' => 1]],
            ],
            ['ok' => false, 'errors' => ['Mindestens eine Arbeitsbuchung ist offen oder unvollstaendig.']],
            [],
            [['id' => 2, 'project_number' => 'P-2', 'name' => 'Kita Nord']],
            [['id' => 7, 'employee_number' => 'M-7', 'first_name' => 'Nina', 'last_name' => 'Feld']],
            '',
            '',
            'csrf-token'
        );

        self::assertStringContainsString('/admin/accounting/export', $html);
        self::assertStringContainsString('format=pdf', $html);
        self::assertStringContainsString('format=xlsx', $html);
        self::assertStringContainsString('format=zip', $html);
        self::assertStringContainsString('Abschlussstatus', $html);
        self::assertStringContainsString('VORLAEUFIG - nicht festgeschrieben', $html);
        self::assertStringContainsString('Festschreibung blockiert', $html);
        self::assertStringContainsString('Keine Berechtigung zum Festschreiben', $html);
        self::assertStringContainsString('Mindestens eine Arbeitsbuchung ist offen oder unvollstaendig.', $html);
    }

    private function controller(): AdminAccountingController
    {
        $connection = new DatabaseConnection([]);
        $bookingService = new AdminBookingService($connection, new TimesheetCalculator());
        $companySettingsService = new CompanySettingsService($connection, []);

        return new AdminAccountingController(
            new AdminView('Baustellen Zeiterfassung', 'http://localhost'),
            new AccountingClosureService($connection, $bookingService),
            new AccountingDocumentExportService($companySettingsService, []),
            new ProjectService($connection),
            new UserService($connection),
            new AuthService($connection, new PermissionMatrix([], [])),
            new CsrfService()
        );
    }
}
