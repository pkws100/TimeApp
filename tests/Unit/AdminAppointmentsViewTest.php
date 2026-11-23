<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\Admin\AdminAppointmentsView;
use App\Presentation\Admin\AdminView;
use PHPUnit\Framework\TestCase;

final class AdminAppointmentsViewTest extends TestCase
{
    public function testListEscapesRecordAndKeepsServerFiltersOnNextPage(): void
    {
        $record = $this->appointment();
        $html = $this->view()->index(['items' => [$record], 'total' => 26, 'page' => 1, 'per_page' => 25],
            ['period' => 'week', 'q' => '<Filter>'], ['projects' => [], 'participants' => []], false, true, []);

        self::assertStringContainsString('href="/admin/appointments/12">&lt;Bau&lt;Termin&gt;', $html);
        self::assertStringContainsString('P-1 Projekt &amp; Co', $html);
        self::assertStringContainsString('Adele &lt;Test&gt;', $html);
        self::assertStringContainsString('25.11.2026 08:00', $html);
        self::assertStringContainsString('/admin/appointments?period=week&amp;q=%3CFilter%3E&amp;page=2', $html);
        self::assertStringNotContainsString('/admin/appointments/12/edit', $html);
        self::assertStringNotContainsString('href="/admin/appointments/new"', $html);
    }

    public function testConflictRendersSubmittedValuesWithExplicitReloadAndNoMutationActions(): void
    {
        $record = $this->appointment();
        $values = $record;
        $values['description'] = '</textarea><script>unsafe()</script>';
        $values['version'] = 2;
        $values['participant_ids'] = [6];
        $html = $this->view()->form($record, $values, [
            'projects' => [['id' => 5, 'name' => 'Projekt & Co', 'project_number' => 'P-1', 'archived' => true]],
            'participants' => [['id' => 6, 'name' => 'Adele <Test>', 'archived' => true]],
        ], 'appointment-token', ['error' => 'Zwischenzeitlich geändert.', 'conflict' => true]);

        self::assertStringContainsString('name="version" value="2"', $html);
        self::assertStringContainsString('name="csrf_token" value="appointment-token"', $html);
        self::assertStringContainsString('name="participants_submitted" value="1"', $html);
        self::assertStringContainsString('name="participant_ids[]" value="6" checked', $html);
        self::assertStringContainsString('P-1 Projekt &amp; Co (archiviert)', $html);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;unsafe()&lt;/script&gt;', $html);
        self::assertStringContainsString('role="alert">Zwischenzeitlich geändert.', $html);
        self::assertStringContainsString('href="/admin/appointments/12/edit">Aktuellen Stand öffnen', $html);
        self::assertStringNotContainsString('Änderungen speichern', $html);
        self::assertStringNotContainsString('/admin/appointments/12/archive', $html);
    }

    public function testEditTimeHintUsesTheCurrentApplicationTimezone(): void
    {
        $record = $this->appointment();
        $timezone = date_default_timezone_get();
        $record['timezone'] = $timezone === 'Europe/Berlin' ? 'UTC' : 'Europe/Berlin';
        $html = $this->view()->form($record, $record, ['projects' => [], 'participants' => []], 'appointment-token', []);

        self::assertStringContainsString('id="appointment-timezone">Zeitzone ' . $timezone . '</p>', $html);
        self::assertStringNotContainsString('id="appointment-timezone">Zeitzone ' . $record['timezone'] . '</p>', $html);
    }

    private function view(): AdminAppointmentsView
    {
        return new AdminAppointmentsView(new AdminView('TimeApp', 'http://127.0.0.1'));
    }

    private function appointment(): array
    {
        return [
            'id' => 12, 'title' => '<Bau<Termin>', 'description' => '',
            'starts_at' => '2026-11-25T08:00', 'ends_at' => '2026-11-25T09:30', 'timezone' => 'Europe/Berlin',
            'project_id' => 5, 'project_name' => 'Projekt & Co', 'project_number' => 'P-1',
            'participants' => [['id' => 6, 'name' => 'Adele <Test>']], 'location' => 'Einsatzort',
            'status' => 'planned', 'version' => 3, 'archived_at' => null,
        ];
    }
}
