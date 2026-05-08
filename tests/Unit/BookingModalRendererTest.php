<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\Admin\BookingModalRenderer;
use PHPUnit\Framework\TestCase;

final class BookingModalRendererTest extends TestCase
{
    public function testRenderTableUsesReadOnlyRowsWithModalContext(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderTable(
            [[
                'id' => 15,
                'work_date' => '2026-04-24',
                'employee_name' => 'Max Mustermann',
                'employee_number' => 'M-15',
                'project_id' => 2,
                'project_number' => 'P-2',
                'project_name' => 'Rathaus',
                'project_is_deleted' => 0,
                'entry_type' => 'work',
                'start_time' => '07:30:00',
                'end_time' => '16:00:00',
                'break_minutes' => 30,
                'net_minutes' => 480,
                'note' => 'Saubere Lesbarkeit',
                'is_deleted' => 0,
                'version_hint' => 'v12',
            ]],
            [['id' => 2, 'project_number' => 'P-2', 'name' => 'Rathaus', 'is_deleted' => 0]],
            ['work' => 'Arbeit'],
            [
                'show_selection' => true,
                'bulk_form_id' => 'bulk-assignment-form',
                'can_manage' => true,
                'can_archive' => true,
            ]
        );

        self::assertStringContainsString('data-booking-row', $html);
        self::assertStringContainsString('data-booking-open', $html);
        self::assertStringNotContainsString('type="date" name="work_date"', $html);
        self::assertStringNotContainsString('<textarea', $html);
        self::assertStringContainsString('Bearbeiten', $html);
    }

    public function testRenderModalIncludesProjectAssignmentAndSharedReason(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderModal(
            [['id' => 2, 'project_number' => 'P-2', 'name' => 'Rathaus', 'is_deleted' => 0]],
            ['work' => 'Arbeit', 'vacation' => 'Urlaub'],
            [
                'return_to' => '/admin/bookings',
                'csrf_token' => 'abc123',
                'can_manage' => true,
                'can_archive' => true,
            ]
        );

        self::assertStringContainsString('data-booking-modal', $html);
        self::assertStringContainsString('name="project_id"', $html);
        self::assertStringContainsString('Nicht zugeordnet', $html);
        self::assertStringContainsString('data-booking-reason', $html);
        self::assertStringContainsString('data-booking-action-form="archive"', $html);
    }
}
