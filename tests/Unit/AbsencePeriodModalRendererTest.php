<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\Admin\AbsencePeriodModalRenderer;
use PHPUnit\Framework\TestCase;

final class AbsencePeriodModalRendererTest extends TestCase
{
    public function testCalendarVariantContainsSharedPreviewAndAllSupportedTypes(): void
    {
        $html = (new AbsencePeriodModalRenderer())->render(
            [[
                'id' => 7,
                'first_name' => 'Erika',
                'last_name' => 'Beispiel',
                'employee_number' => 'MA-007',
                'employment_status' => 'active',
                'is_deleted' => 0,
            ]],
            'csrf-token',
            ['selected_date' => '2026-07-24', 'can_archive' => true]
        );

        self::assertStringContainsString('data-absence-period-modal', $html);
        self::assertStringContainsString('data-preview-action="/admin/absence-periods/preview"', $html);
        self::assertStringContainsString('name="date_from" value="2026-07-24"', $html);
        self::assertStringContainsString('name="date_to" value="2026-07-24"', $html);
        self::assertStringContainsString('<option value="vacation">Urlaub</option>', $html);
        self::assertStringContainsString('<option value="sick">Krankheit</option>', $html);
        self::assertStringContainsString('<option value="absent">Fehlzeit</option>', $html);
        self::assertStringContainsString('Erika Beispiel (MA-007)', $html);
        self::assertStringContainsString('name="change_reason"', $html);
        self::assertStringContainsString('data-absence-period-confirm-wrap', $html);
    }

    public function testVacationVariantFixesTypeAndReasonServerRoute(): void
    {
        $html = (new AbsencePeriodModalRenderer())->render([], 'csrf-token', [
            'vacation_only' => true,
            'can_archive' => false,
        ]);

        self::assertStringContainsString('data-create-action="/admin/vacation-periods"', $html);
        self::assertStringContainsString('data-preview-action="/admin/vacation-periods/preview"', $html);
        self::assertStringContainsString('name="entry_type" value="vacation"', $html);
        self::assertStringContainsString('name="absence_reason_code" value="vacation_paid"', $html);
        self::assertStringNotContainsString('<option value="sick">', $html);
        self::assertStringContainsString('data-can-archive="0"', $html);
    }

    public function testCalendarVariantCanHideVacationWithoutVacationPermission(): void
    {
        $html = (new AbsencePeriodModalRenderer())->render([], 'csrf-token', [
            'allow_vacation' => false,
        ]);

        self::assertStringNotContainsString('<option value="vacation">', $html);
        self::assertStringNotContainsString('value="vacation_paid"', $html);
        self::assertStringContainsString('<option value="sick">Krankheit</option>', $html);
        self::assertStringContainsString('<option value="absent">Fehlzeit</option>', $html);
    }
}
