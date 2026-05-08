<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Attendance\AttendanceService;
use App\Http\Request;
use App\Http\Response;
use App\Presentation\Admin\AdminView;

final class AttendanceController
{
    public function __construct(
        private AttendanceService $attendanceService,
        private AdminView $view
    ) {
    }

    public function index(Request $request): Response
    {
        $summary = $this->attendanceService->todaySummary();
        $presentRows = '';

        foreach ($summary['present'] as $person) {
            $presentRows .= '<tr>'
                . '<td>' . $this->escape((string) ($person['employee_number'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($person['user_name'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($person['location'] ?? 'Nicht zugeordnet')) . '</td>'
                . '<td>' . $this->escape((string) ($person['start_time'] ?? '-')) . '</td>'
                . '<td>' . $this->escape((string) ($person['note'] ?? '')) . '</td>'
                . '</tr>';
        }

        if ($presentRows === '') {
            $presentRows = '<tr><td colspan="5" class="table-empty">Heute ist noch niemand als anwesend erfasst.</td></tr>';
        }

        $statusRows = '';

        foreach ($summary['statuses'] as $status) {
            $statusRows .= '<tr>'
                . '<td>' . $this->escape((string) ($status['employee_number'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($status['user_name'] ?? '')) . '</td>'
                . '<td>' . $this->escape($this->statusLabel((string) ($status['entry_type'] ?? ''))) . '</td>'
                . '<td>' . $this->escape((string) ($status['note'] ?? '')) . '</td>'
                . '</tr>';
        }

        if ($statusRows === '') {
            $statusRows = '<tr><td colspan="4" class="table-empty">Es liegen keine weiteren Tagesstatus vor.</td></tr>';
        }

        $content = <<<HTML
<header class="page-header">
    <div>
        <p class="eyebrow">Tagesstatus</p>
        <h1>Anwesenheit</h1>
        <p>Stand {$this->escape((string) $summary['today'])} - <span class="badge ok">{$this->escape((string) $summary['present_count'])} anwesend</span></p>
    </div>
</header>

<section class="grid cards">
    <article class="card metric">
        <h2>Anwesend</h2>
        <p>{$this->escape((string) $summary['present_count'])}</p>
    </article>
    <article class="card status-card">
        <h2>Krank</h2>
        <p class="status-value">{$this->escape((string) ($summary['status_counts']['sick'] ?? 0))}</p>
    </article>
    <article class="card status-card">
        <h2>Urlaub</h2>
        <p class="status-value">{$this->escape((string) ($summary['status_counts']['vacation'] ?? 0))}</p>
    </article>
    <article class="card status-card">
        <h2>Feiertag / Fehlt</h2>
        <p class="status-value">{$this->escape((string) ((int) ($summary['status_counts']['holiday'] ?? 0) + (int) ($summary['status_counts']['absent'] ?? 0)))}</p>
    </article>
</section>

<section class="grid split">
    <article class="card">
        <h2>Anwesend heute</h2>
        <table>
            <thead><tr><th>Nr.</th><th>Name</th><th>Standort</th><th>Start</th><th>Hinweis</th></tr></thead>
            <tbody>{$presentRows}</tbody>
        </table>
    </article>
    <article class="card">
        <h2>Weitere Tagesstatus</h2>
        <table>
            <thead><tr><th>Nr.</th><th>Name</th><th>Status</th><th>Hinweis</th></tr></thead>
            <tbody>{$statusRows}</tbody>
        </table>
    </article>
</section>
HTML;

        return Response::html($this->view->render('Anwesenheit', $content));
    }

    public function today(Request $request): Response
    {
        return Response::json($this->attendanceService->todaySummary());
    }

    private function statusLabel(string $entryType): string
    {
        return match ($entryType) {
            'sick' => 'Krank',
            'vacation' => 'Urlaub',
            'holiday' => 'Feiertag',
            'absent' => 'Fehlt',
            default => $entryType,
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
