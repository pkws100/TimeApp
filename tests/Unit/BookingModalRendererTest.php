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
                'source' => 'admin',
                'source_label' => 'Admin-Nacherfassung',
                'start_time' => '07:30:00',
                'end_time' => '16:00:00',
                'break_minutes' => 30,
                'net_minutes' => 480,
                'note' => 'Saubere Lesbarkeit',
                'is_deleted' => 0,
                'version_hint' => 'v12',
                'attachment_count' => 1,
                'image_attachment_count' => 1,
                'geo_count' => 1,
                'customer_signature' => [
                    'id' => 8,
                    'customer_name' => 'Max Auftraggeber',
                    'signed_at' => '2026-05-19 18:30:00',
                    'image_url' => '/admin/timesheet-signatures/8/image',
                    'sha256' => str_repeat('a', 64),
                ],
                'customer_signature_present' => true,
                'geo_records' => [[
                    'latitude' => 52.520008,
                    'longitude' => 13.404954,
                    'accuracy_meters' => 24,
                    'recorded_at' => '2026-05-16T10:00:00+02:00',
                    'map_url' => 'https://www.openstreetmap.org/?mlat=52.5200080&mlon=13.4049540#map=18/52.5200080/13.4049540',
                ]],
                'attachments' => [[
                    'id' => 5,
                    'original_name' => 'foto.jpg',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 2048,
                    'uploaded_at' => '2026-05-16 10:00:00',
                    'is_deleted' => 0,
                    'is_image' => true,
                    'download_url' => '/admin/timesheet-files/5/download',
                    'preview_url' => '/admin/timesheet-files/5/download',
                    'archive_url' => '/admin/timesheet-files/5',
                ]],
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
        self::assertStringContainsString('<th>Herkunft</th>', $html);
        self::assertStringContainsString('<th>Anhänge</th>', $html);
        self::assertStringContainsString('<th>Standort</th>', $html);
        self::assertStringContainsString('<th>Bestätigung</th>', $html);
        self::assertStringContainsString('Anhänge: 1', $html);
        self::assertStringContainsString('Standort: 1', $html);
        self::assertStringContainsString('Bestaetigt', $html);
        self::assertStringContainsString('Max Auftraggeber', $html);
        self::assertStringContainsString('timesheet-files', $html);
        self::assertStringContainsString('Admin-Nacherfassung', $html);
        self::assertStringNotContainsString('type="date" name="work_date"', $html);
        self::assertStringNotContainsString('<textarea', $html);
        self::assertStringContainsString('Bearbeiten', $html);
        self::assertStringNotContainsString('has-booking-issue', $html);
        self::assertStringNotContainsString('Zeit unvollstaendig', $html);
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

    public function testRenderModalShowsTimesheetAttachmentsWithDownloadAndArchiveControls(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderModal(
            [['id' => 2, 'project_number' => 'P-2', 'name' => 'Rathaus', 'is_deleted' => 0]],
            ['work' => 'Arbeit'],
            [
                'return_to' => '/admin/bookings',
                'csrf_token' => 'abc123',
                'can_manage' => true,
                'can_archive' => true,
                'document_statuses' => [
                    ['id' => 1, 'label' => 'Unbearbeitet', 'color' => '#f59e0b'],
                    ['id' => 2, 'label' => 'Bearbeitet', 'color' => '#2563eb'],
                ],
                'selected_booking' => [
                    'id' => 15,
                    'work_date' => '2026-04-24',
                    'employee_name' => 'Max Mustermann',
                    'employee_number' => 'M-15',
                    'project_id' => 2,
                    'project_number' => 'P-2',
                    'project_name' => 'Rathaus',
                    'entry_type' => 'work',
                    'start_time' => '07:30:00',
                    'end_time' => '16:00:00',
                    'break_minutes' => 30,
                    'note' => '',
                    'is_deleted' => 0,
                    'version_hint' => 'v12',
                    'geo_records' => [[
                        'latitude' => 52.520008,
                        'longitude' => 13.404954,
                        'accuracy_meters' => 24,
                        'recorded_at' => '2026-05-16T10:00:00+02:00',
                        'map_url' => 'https://www.openstreetmap.org/?mlat=52.5200080&mlon=13.4049540#map=18/52.5200080/13.4049540',
                    ]],
                    'customer_signature' => [
                        'id' => 8,
                        'customer_name' => 'Max Auftraggeber',
                        'signed_at' => '2026-05-19 18:30:00',
                        'image_url' => '/admin/timesheet-signatures/8/image',
                        'sha256' => str_repeat('b', 64),
                    ],
                    'attachments' => [[
                        'id' => 5,
                        'original_name' => 'foto.jpg',
                        'mime_type' => 'image/jpeg',
                        'size_bytes' => 2048,
                        'uploaded_at' => '2026-05-16 10:00:00',
                        'is_deleted' => 0,
                        'is_image' => true,
                        'download_url' => '/admin/timesheet-files/5/download',
                        'preview_url' => '/admin/timesheet-files/5/download',
                        'archive_url' => '/admin/timesheet-files/5',
                        'status_update_url' => '/admin/timesheet-files/5/status',
                        'document_status' => [
                            'id' => 1,
                            'label' => 'Unbearbeitet',
                            'color' => '#f59e0b',
                        ],
                    ]],
                ],
            ]
        );

        self::assertStringContainsString('data-booking-modal-attachments', $html);
        self::assertStringContainsString('data-booking-modal-locations', $html);
        self::assertStringContainsString('data-booking-modal-signature', $html);
        self::assertStringContainsString('/admin/timesheet-signatures/8/image', $html);
        self::assertStringContainsString('Bestätigung archivieren', $html);
        self::assertStringContainsString('52,5200080, 13,4049540', $html);
        self::assertStringContainsString('Karte öffnen', $html);
        self::assertStringContainsString('foto.jpg', $html);
        self::assertStringContainsString('/admin/timesheet-files/5/download', $html);
        self::assertStringContainsString('data-attachment-viewer-open', $html);
        self::assertStringNotContainsString('loading="lazy"', $html);
        self::assertStringContainsString('action="/admin/timesheet-files/5"', $html);
        self::assertStringContainsString('name="_method" value="DELETE"', $html);
        self::assertStringContainsString('name="booking_id" value="15"', $html);
        self::assertStringContainsString('name="csrf_token" value="abc123"', $html);
        self::assertStringContainsString('booking-attachment__status-line', $html);
        self::assertStringContainsString('booking-attachment__status-form', $html);
        self::assertStringContainsString('booking-attachment__status-control', $html);
        self::assertStringContainsString('Dokumentenstatus', $html);
        self::assertStringContainsString('Unbearbeitet', $html);
        self::assertStringContainsString('/admin/timesheet-files/5/status', $html);
        self::assertStringNotContainsString('<span class="badge ok">Aktiv</span>', $html);
    }

    public function testRenderModalAllowsViewOnlyAttachmentAccessWithoutArchiveControls(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderModal(
            [],
            ['work' => 'Arbeit'],
            [
                'return_to' => '/admin/bookings',
                'csrf_token' => 'abc123',
                'can_manage' => false,
                'can_archive' => false,
                'can_view_attachments' => true,
                'selected_booking' => [
                    'id' => 15,
                    'employee_name' => 'Max Mustermann',
                    'employee_number' => 'M-15',
                    'project_id' => null,
                    'entry_type' => 'work',
                    'is_deleted' => 0,
                    'attachments' => [[
                        'id' => 5,
                        'original_name' => 'beleg.pdf',
                        'mime_type' => 'application/pdf',
                        'size_bytes' => 2048,
                        'uploaded_at' => '2026-05-16 10:00:00',
                        'is_deleted' => 0,
                        'is_image' => false,
                        'download_url' => '/admin/timesheet-files/5/download',
                        'preview_url' => null,
                        'archive_url' => '/admin/timesheet-files/5',
                    ]],
                ],
            ]
        );

        self::assertStringContainsString('data-booking-modal', $html);
        self::assertStringContainsString('beleg.pdf', $html);
        self::assertStringContainsString('/admin/timesheet-files/5/download', $html);
        self::assertStringNotContainsString('action="/admin/timesheet-files/5"', $html);
        self::assertStringContainsString('Keine Bearbeitungsrechte', $html);
    }

    public function testRenderModalUsesPdfPreviewTriggerWithoutEmbeddingLargeDocument(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderModal(
            [],
            ['work' => 'Arbeit'],
            [
                'return_to' => '/admin/bookings',
                'csrf_token' => 'abc123',
                'can_manage' => false,
                'can_archive' => false,
                'can_view_attachments' => true,
                'selected_booking' => [
                    'id' => 15,
                    'employee_name' => 'Max Mustermann',
                    'project_id' => null,
                    'entry_type' => 'work',
                    'is_deleted' => 0,
                    'attachments' => [[
                        'id' => 6,
                        'original_name' => 'beleg.pdf',
                        'mime_type' => 'application/pdf',
                        'size_bytes' => 2048,
                        'uploaded_at' => '2026-05-16 10:00:00',
                        'is_deleted' => 0,
                        'is_image' => false,
                        'is_previewable' => true,
                        'download_url' => '/admin/timesheet-files/6/download',
                        'preview_url' => '/admin/timesheet-files/6/download',
                        'archive_url' => '/admin/timesheet-files/6',
                    ]],
                ],
            ]
        );

        self::assertStringContainsString('data-preview-type="pdf"', $html);
        self::assertStringContainsString('>PDF</span>', $html);
        self::assertStringNotContainsString('<iframe', $html);
    }

    public function testRenderModalShowsArchiveBadgeOnlyForArchivedAttachment(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderModal(
            [],
            ['work' => 'Arbeit'],
            [
                'return_to' => '/admin/bookings',
                'csrf_token' => 'abc123',
                'can_manage' => true,
                'can_archive' => true,
                'selected_booking' => [
                    'id' => 15,
                    'employee_name' => 'Max Mustermann',
                    'project_id' => null,
                    'entry_type' => 'work',
                    'is_deleted' => 0,
                    'attachments' => [[
                        'id' => 7,
                        'original_name' => 'alter-beleg.pdf',
                        'mime_type' => 'application/pdf',
                        'size_bytes' => 2048,
                        'uploaded_at' => '2026-05-16 10:00:00',
                        'is_deleted' => 1,
                        'is_image' => false,
                        'download_url' => '/admin/timesheet-files/7/download',
                        'preview_url' => '/admin/timesheet-files/7/download',
                        'archive_url' => '/admin/timesheet-files/7',
                        'status_update_url' => '/admin/timesheet-files/7/status',
                    ]],
                ],
            ]
        );

        self::assertStringContainsString('booking-attachment__archive-badge', $html);
        self::assertStringContainsString('Archiviert', $html);
        self::assertStringNotContainsString('booking-attachment__status-form', $html);
        self::assertStringNotContainsString('Anhang archivieren', $html);
    }

    public function testRenderTableHighlightsOpenProjectAssignments(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderTable(
            [[
                'id' => 16,
                'work_date' => '2026-05-08',
                'employee_name' => 'Erika Beispiel',
                'employee_number' => 'M-16',
                'project_id' => null,
                'entry_type' => 'work',
                'source' => 'app',
                'source_label' => 'App',
                'start_time' => '08:00:00',
                'end_time' => null,
                'break_minutes' => 0,
                'net_minutes' => 0,
                'note' => 'Neubau Musterstrasse',
                'is_deleted' => 0,
                'version_hint' => 'Originalstand',
                'needs_project_assignment' => true,
            ]],
            [],
            ['work' => 'Arbeit']
        );

        self::assertStringContainsString('Nicht zugeordnet', $html);
        self::assertStringContainsString('Projekt offen', $html);
        self::assertStringContainsString('badge warn', $html);
        self::assertStringContainsString('has-booking-issue', $html);
        self::assertStringContainsString('data-booking-issue="1"', $html);
    }

    public function testRenderTableHighlightsIncompleteWorkTime(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderTable(
            [[
                'id' => 17,
                'work_date' => '2026-05-08',
                'employee_name' => 'Max Mustermann',
                'employee_number' => 'M-17',
                'project_id' => 2,
                'project_number' => 'P-2',
                'project_name' => 'Rathaus',
                'entry_type' => 'work',
                'source' => 'app',
                'source_label' => 'App',
                'start_time' => '08:00:00',
                'end_time' => '',
                'break_minutes' => 0,
                'net_minutes' => 0,
                'note' => '',
                'is_deleted' => 0,
                'version_hint' => 'Originalstand',
                'needs_project_assignment' => false,
            ]],
            [],
            ['work' => 'Arbeit']
        );

        self::assertStringContainsString('has-booking-issue', $html);
        self::assertStringContainsString('data-booking-issue="1"', $html);
        self::assertStringContainsString('Zeit unvollstaendig', $html);
        self::assertStringContainsString('badge error', $html);
    }

    public function testRenderTableDoesNotHighlightCleanWorkBooking(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderTable(
            [[
                'id' => 18,
                'work_date' => '2026-05-08',
                'employee_name' => 'Sauber Person',
                'employee_number' => 'M-18',
                'project_id' => 2,
                'project_number' => 'P-2',
                'project_name' => 'Rathaus',
                'entry_type' => 'work',
                'source' => 'app',
                'source_label' => 'App',
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'break_minutes' => 30,
                'net_minutes' => 450,
                'note' => '',
                'is_deleted' => 0,
                'version_hint' => 'Originalstand',
                'needs_project_assignment' => false,
            ]],
            [],
            ['work' => 'Arbeit']
        );

        self::assertStringNotContainsString('has-booking-issue', $html);
        self::assertStringNotContainsString('data-booking-issue="1"', $html);
        self::assertStringNotContainsString('Zeit unvollstaendig', $html);
    }

    public function testRenderTableDoesNotHighlightArchivedIncompleteWorkBooking(): void
    {
        $renderer = new BookingModalRenderer();
        $html = $renderer->renderTable(
            [[
                'id' => 19,
                'work_date' => '2026-05-08',
                'employee_name' => 'Archiv Person',
                'employee_number' => 'M-19',
                'project_id' => null,
                'entry_type' => 'work',
                'source' => 'app',
                'source_label' => 'App',
                'start_time' => '',
                'end_time' => '',
                'break_minutes' => 0,
                'net_minutes' => 0,
                'note' => '',
                'is_deleted' => 1,
                'version_hint' => 'Archivstand',
                'needs_project_assignment' => true,
            ]],
            [],
            ['work' => 'Arbeit']
        );

        self::assertStringContainsString('Archiviert', $html);
        self::assertStringNotContainsString('has-booking-issue', $html);
        self::assertStringNotContainsString('data-booking-issue="1"', $html);
        self::assertStringNotContainsString('Zeit unvollstaendig', $html);
        self::assertStringNotContainsString('Projekt offen', $html);
    }
}
