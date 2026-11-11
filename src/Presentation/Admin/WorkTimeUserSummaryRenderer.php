<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

final class WorkTimeUserSummaryRenderer
{
    public function render(?array $summary): string
    {
        if ($summary === null) {
            return '';
        }
        $html = '<section class="full-span field-group" data-user-work-time-summary aria-labelledby="user-work-time-heading">'
            . '<h2 id="user-work-time-heading">Kernarbeitszeit / Arbeitszeitfenster</h2>'
            . '<p class="muted">Das Sollstundenmodell legt fest, wie viele Stunden vorgesehen sind. Die Arbeitszeitfenster beschreiben die zeitliche Lage. Die Fensteranrechnung bleibt eine unverbindliche Vorschau; tatsächlich geleistete Arbeitszeit bleibt erhalten.</p>';
        if (!$summary['enabled']) {
            $html .= '<p>Global ausgeschaltet. Gespeicherte Zuordnungen aendern die bisherige Zeiterfassung derzeit nicht.</p>';
        }
        if (!$summary['schema_available']) {
            $html .= '<p>Die Arbeitszeitfenster-Verwaltung ist noch nicht verfügbar.</p>';
        } elseif ($summary['assignments'] === []) {
            $html .= '<p>Keine aktuelle oder künftige Kernarbeitszeit zugeordnet.</p>';
        }
        if ($summary['current_count'] > 1) {
            $html .= '<p class="notice notice-error">Mehrere aktuelle Zuordnungen: Bitte die Gültigkeiten in der Verwaltung prüfen.</p>';
        }
        $days = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        foreach ($summary['assignments'] as $assignment) {
            $state = $assignment['status'] === 'planned' ? 'Geplant' : ($summary['enabled'] ? 'Aktuell gültig' : 'Für diesen Zeitraum hinterlegt');
            $html .= '<div><strong>' . $this->e($state . ': ' . $assignment['template_name']) . '</strong>'
                . '<p>Gültig ab ' . $this->e($assignment['effective_from']) . ' bis ' . $this->e($assignment['effective_to'] ?? 'auf Weiteres') . '.</p>';
            $rules = [];
            foreach ($days as $number => $name) {
                $windows = $assignment['windows'][$number] ?? [];
                $ranges = [];
                foreach ($windows as $window) {
                    $ranges[] = $window['start'] . '–' . $window['end'] . ($window['end'] <= $window['start'] ? ' (Folgetag)' : '');
                }
                $rules[] = $name . ': ' . ($ranges !== [] ? implode(', ', $ranges) : 'kein Fenster');
            }
            $html .= '<p>' . $this->e(implode(' · ', $rules)) . '</p></div>';
        }
        if ($summary['additional_future_count'] > 0) {
            $html .= '<p>Weitere künftige Zuordnungen sind in der Verwaltung sichtbar.</p>';
        }
        if ($summary['schema_available']) {
            $label = $summary['can_manage'] ? 'Kernarbeitszeit für diesen Mitarbeiter verwalten' : 'Kernarbeitszeit für diesen Mitarbeiter ansehen';
            $html .= '<p class="toolbar-actions"><a class="button button-secondary" href="' . $this->e($summary['url']) . '">' . $label . '</a></p>';
        }
        return $html . '</section>';
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}
