<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use DateTimeImmutable;
use DateTimeZone;

final class AdminPausePolicyRenderer
{
    private const PATH = '/admin/work-time/pause-models';

    public function render(array $templates, array $assignments, array $defaults, array $audit, array $users, int $userId, bool $manage, string $csrf, bool $saved, string $error): string
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
        $tomorrow = $today->modify('+1 day')->format('Y-m-d');
        $todayString = $today->format('Y-m-d');
        $tokens = '<input type="hidden" name="csrf_token" value="' . $this->e($csrf) . '">';
        $notice = $saved ? '<p class="notice notice-success" role="status">Pausenregel gespeichert.</p>' : '';
        $notice .= $error !== '' ? '<p class="notice notice-error" role="alert">' . $this->e($error) . '</p>' : '';
        $userOptions = $this->userOptions($users, $userId);
        $html = '<div class="stack" id="pausenmodelle"><section class="card stack"><h2>Pausenmodelle</h2>' . $notice
            . '<p>Die Pause wird unabhängig von der Kernarbeitszeit geregelt. Individuelle Zuordnungen haben Vorrang vor dem Betriebsstandard. Bis zur Aktivierung gilt die bisherige Berechnung.</p>'
            . '<p>Bei mehr als sechs Stunden Arbeit sind mindestens 30 Minuten Ruhepause erforderlich, bei mehr als neun Stunden mindestens 45 Minuten. Ein Ruhepausenabschnitt dauert mindestens 15 Minuten. Die Prüfung berücksichtigt auch sechs Stunden ununterbrochene Arbeit. Planfenster dienen der Orientierung.</p>'
            . '<form class="form-grid" method="get" action="/admin/work-time#pausenmodelle"><label><span>Zuordnungen nach Mitarbeiter filtern</span><select name="user_id"><option value="">Alle Mitarbeiter</option>' . $userOptions . '</select></label><button class="button button-secondary">Zuordnungen anzeigen</button></form></section>';

        $editOptions = '<option value="">Neue Vorlage</option>';
        $activeOptions = '<option value="">Bitte wählen</option>';
        $allOptions = '<option value="">Bitte wählen</option>';
        $versionRows = '';
        foreach ($templates as $template) {
            $name = $this->e((string) $template['name']);
            $active = (bool) $template['is_active'];
            foreach ($template['versions'] as $index => $version) {
                $id = (int) $version['id'];
                $revision = (int) $version['revision'];
                $option = '<option value="' . $id . '">' . $name . ' · Version ' . $revision . ($active ? '' : ' (archiviert)') . '</option>';
                $allOptions .= $option;
                if ($active) {
                    $activeOptions .= $option;
                }
                $archive = '';
                if ($index === 0 && $active) {
                    $data = $this->e(json_encode($version['policy'], JSON_THROW_ON_ERROR));
                    $editOptions .= '<option value="' . (int) $template['id'] . '" data-pause-template-policy="' . $data . '">' . $name . '</option>';
                    if ($manage) {
                        $archive = '<form method="post" action="' . self::PATH . '/templates/' . (int) $template['id'] . '/archive" data-pause-model-mutation>' . $tokens . $this->output() . '<button class="button button-secondary" type="submit">Vorlage archivieren</button></form>';
                    }
                }
                $versionRows .= '<tr><td>' . $name . ($active ? '' : ' (archiviert)') . '</td><td>' . $revision . '</td><td>' . $this->ruleDetail($version['policy']) . '</td><td>' . $this->e((string) $version['created_at']) . '</td><td>' . $archive . '</td></tr>';
            }
        }

        if ($manage) {
            $html .= '<section class="card stack"><h3>Pausenvorlage anlegen oder versionieren</h3><form class="form-grid" method="post" action="' . self::PATH . '/templates" data-pause-model-mutation>' . $tokens
                . '<label><span>Zu versionierende Vorlage</span><select name="template_id" data-pause-edit-template>' . $editOptions . '</select></label><label><span>Name für neue Vorlage</span><input name="name" maxlength="150" required></label>'
                . $this->ruleFields() . $this->output() . '<button class="button" type="submit">Regelversion speichern</button></form></section>';
            if ($activeOptions !== '<option value="">Bitte wählen</option>') {
                $html .= $this->scheduleForm(true, $activeOptions, $userOptions, $tomorrow, $tokens);
                if ($users !== []) {
                    $html .= $this->scheduleForm(false, $activeOptions, $userOptions, $tomorrow, $tokens);
                }
            }
        }

        $html .= '<section class="card stack"><h3>Pausenregelversionen</h3><div class="table-scroll"><table><thead><tr><th>Vorlage</th><th>Version</th><th>Regel</th><th>Erstellt</th><th>Aktion</th></tr></thead><tbody>'
            . ($versionRows ?: '<tr><td colspan="5">Keine Pausenmodelle vorhanden.</td></tr>') . '</tbody></table></div></section>';
        $html .= $this->scheduleList('Globale Standardmodelle', $defaults, true, $todayString, $manage, $tokens);
        $html .= $this->scheduleList('Mitarbeiterzuordnungen für Pausenmodelle', $assignments, false, $todayString, $manage, $tokens);
        $html .= $this->history($audit, $userId);
        if ($templates !== []) {
            $html .= '<section class="card stack"><h3>Pausenregel anhand einer Beispielbuchung prüfen</h3><form class="form-grid" method="post" action="' . self::PATH . '/preview" data-pause-model-preview>' . $tokens
                . '<label><span>Beispielregelversion</span><select name="policy_version_id" required>' . $allOptions . '</select></label>'
                . '<label><span>Beispieldatum (Europe/Berlin)</span><input type="date" name="example_date" required value="' . $todayString . '"></label>'
                . '<label><span>Arbeitsbeginn</span><input type="time" name="example_start" value="08:00" required></label><label><span>Arbeitsende</span><input type="time" name="example_end" value="14:30" required></label>'
                . '<label><span>Dokumentierte Pause 1 ab</span><input type="time" name="example_break_start_1"></label><label><span>Dokumentierte Pause 1 bis</span><input type="time" name="example_break_end_1"></label>'
                . '<label><span>Dokumentierte Pause 2 ab</span><input type="time" name="example_break_start_2"></label><label><span>Dokumentierte Pause 2 bis</span><input type="time" name="example_break_end_2"></label>'
                . $this->output() . '<button class="button button-secondary" type="submit">Beispiel prüfen</button></form></section>';
        }
        return $html . '</div>';
    }

    private function scheduleForm(bool $global, string $versionOptions, string $userOptions, string $tomorrow, string $tokens): string
    {
        $scope = $global ? 'defaults' : 'assignments';
        $title = $global ? 'Globales Standardmodell planen' : 'Pausenmodell einem Mitarbeiter zuordnen';
        $extra = $global
            ? '<label class="full-span"><span><input type="checkbox" name="confirm_global" value="1" required> Ich aktiviere den Standard für Mitarbeiter ohne eigene Zuordnung ab dem gewählten Datum.</span></label>'
            : '<details class="full-span"><summary>Individuelle Regeln</summary><div class="form-grid">' . $this->ruleFields('override_') . '</div></details>';
        $employee = $global ? '' : '<label><span>Mitarbeiter</span><select name="user_id" required><option value="">Bitte wählen</option>' . $userOptions . '</select></label>';
        return '<section class="card stack"><h3>' . $title . '</h3><form class="form-grid" method="post" action="' . self::PATH . '/' . $scope . '" data-pause-model-mutation>' . $tokens . $employee
            . '<label><span>Regelversion</span><select name="policy_version_id" required>' . $versionOptions . '</select></label>'
            . '<label><span>Gültig ab</span><input type="date" name="effective_from" min="' . $tomorrow . '" value="' . $tomorrow . '" required></label>'
            . '<label><span>Gültig bis (optional)</span><input type="date" name="effective_to" min="' . $tomorrow . '"></label>'
            . $extra . '<label class="full-span"><span><input type="checkbox" name="replace_previous" value="1"> Bisherige Zuordnung zum neuen Beginn ablösen</span></label>'
            . '<label class="full-span"><span>Begründung</span><textarea name="reason" required maxlength="2000"></textarea></label>' . $this->output()
            . '<button class="button" type="submit">' . ($global ? 'Standardmodell planen' : 'Zuordnung speichern') . '</button></form></section>';
    }

    private function scheduleList(string $heading, array $rows, bool $global, string $today, bool $manage, string $tokens): string
    {
        $body = '';
        $employeeColumn = $global ? '' : '<th>Mitarbeiter</th>';
        $columns = $global ? 5 : 6;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $from = $this->e($row['effective_from']);
            $to = $this->e($row['effective_to'] ?? 'Offen');
            $status = match (true) {
                $row['superseded_at'] !== null => 'Ersetzt',
                $row['effective_from'] > $today => 'Geplant',
                $row['effective_to'] !== null && $row['effective_to'] < $today => 'Historisch',
                default => 'Gültig',
            };
            $endForm = '';
            if ($manage && in_array($status, ['Geplant', 'Gültig'], true)) {
                $scope = $global ? 'defaults' : 'assignments';
                $endForm = '<details><summary>Gültigkeit beenden</summary><form method="post" action="' . self::PATH . '/' . $scope . '/' . $id . '/end" class="stack" data-pause-model-mutation>' . $tokens
                    . '<label><span>Letzter Gültigkeitstag</span><input type="date" name="effective_to" min="' . $today . '" required></label>'
                    . '<label><span>Begründung</span><input name="reason" maxlength="2000" required></label>' . $this->output()
                    . '<button class="button button-secondary" type="submit">Ende speichern</button></form></details>';
            }
            $employee = $global ? '' : '<td><a href="/admin/work-time?user_id=' . (int) $row['user_id'] . '#pausenmodelle">' . $this->e((string) $row['employee_name']) . '</a></td>';
            $overrides = $global ? [] : json_decode((string) $row['overrides_json'], true, 32, JSON_THROW_ON_ERROR);
            $detail = '';
            if ($overrides !== []) {
                $policy = json_decode((string) $row['policy_json'], true, 32, JSON_THROW_ON_ERROR);
                $detail = '<details><summary>Individuelle Regel</summary>' . $this->ruleDetail(array_replace($policy, $overrides)) . '</details>';
            }
            $body .= '<tr>' . $employee . '<td>' . $this->e((string) $row['template_name']) . ' · Version ' . (int) $row['revision'] . $detail . '</td><td>' . $from . '</td><td>' . $to . '</td><td>' . $status . '</td><td>' . $endForm . '</td></tr>';
        }
        return '<section class="card stack"><h3>' . $heading . '</h3><div class="table-scroll"><table><thead><tr>' . $employeeColumn . '<th>Regelversion</th><th>Ab</th><th>Bis</th><th>Status</th><th>Aktion</th></tr></thead><tbody>'
            . ($body ?: '<tr><td colspan="' . $columns . '">Keine Zuordnungen vorhanden.</td></tr>') . '</tbody></table></div></section>';
    }

    private function history(array $entries, int $userId): string
    {
        $labels = [
            'template_created' => 'Vorlage angelegt', 'template_archived' => 'Vorlage archiviert', 'version_created' => 'Regelversion angelegt',
            'default_scheduled' => 'Standard geplant', 'default_ended' => 'Standard beendet', 'default_superseded' => 'Geplanter Standard ersetzt',
            'assignment_created' => 'Mitarbeiterzuordnung angelegt', 'assignment_ended' => 'Mitarbeiterzuordnung beendet', 'assignment_superseded' => 'Geplante Zuordnung ersetzt',
        ];
        $more = count($entries) > 50;
        $rows = '';
        foreach (array_slice($entries, 0, 50) as $entry) {
            $after = json_decode((string) $entry['after_json'], true, 32, JSON_THROW_ON_ERROR);
            $before = $entry['before_json'] === null ? [] : json_decode((string) $entry['before_json'], true, 32, JSON_THROW_ON_ERROR);
            $period = isset($after['effective_from']) ? $after['effective_from'] . ' bis ' . ($after['effective_to'] ?? 'offen') : '';
            if ($period !== '' && isset($before['effective_from']) && ($before['effective_to'] ?? null) !== ($after['effective_to'] ?? null)) {
                $period .= ' (zuvor bis ' . ($before['effective_to'] ?? 'offen') . ')';
            }
            $rule = $after['policy_version_id'] ?? $after['version']['id'] ?? ($entry['action'] === 'version_created' ? $entry['entity_id'] : null);
            $detail = isset($after['name']) ? $after['name'] : '';
            if ($rule !== null) {
                $detail .= ($detail === '' ? '' : ' · ') . 'Regel-ID ' . (int) $rule;
            }
            $rows .= '<tr><td>' . $this->e((string) $entry['created_at']) . '</td><td>' . $this->e($labels[$entry['action']] ?? (string) $entry['action']) . '</td><td>' . $this->e((string) $entry['actor_name']) . '</td><td>' . $this->e((string) ($entry['employee_name'] ?? '')) . '</td><td>' . $this->e($detail) . '</td><td>' . $this->e($period) . '</td><td>' . $this->e((string) $entry['reason']) . '</td></tr>';
        }
        $pagination = $more ? '<a class="button button-secondary" href="/admin/work-time?pause_history_before=' . (int) $entries[49]['id'] . ($userId ? '&amp;user_id=' . $userId : '') . '#pausenmodell-historie">Ältere Änderungen</a>' : '';
        return '<section class="card stack" id="pausenmodell-historie"><h3>Änderungshistorie der Pausenmodelle</h3><div class="table-scroll"><table><thead><tr><th>Zeitpunkt</th><th>Aktion</th><th>Geändert von</th><th>Mitarbeiter</th><th>Regel</th><th>Gültigkeit</th><th>Begründung</th></tr></thead><tbody>'
            . ($rows ?: '<tr><td colspan="7">Keine Änderungen vorhanden.</td></tr>') . '</tbody></table></div>' . $pagination . '</section>';
    }

    private function ruleFields(string $prefix = ''): string
    {
        $optional = $prefix !== '';
        $defaultNumber = static fn (int $value): string => $optional ? '' : ' value="' . $value . '" required';
        $inherit = $optional ? '<option value="">Wie in der Vorlage</option>' : '';
        return '<label><span>Erfassungsmodus</span><select name="' . $prefix . 'capture_mode">' . $inherit . '<option value="stamped">Start und Ende stempeln</option><option value="manual">Bestätigte manuelle Erfassung</option></select></label>'
            . '<label><span>Mindestpause bei mehr als 6 Stunden (Min.)</span><input type="number" name="' . $prefix . 'minimum_over_six_minutes" min="30" max="1440"' . $defaultNumber(30) . '></label>'
            . '<label><span>Mindestpause bei mehr als 9 Stunden (Min.)</span><input type="number" name="' . $prefix . 'minimum_over_nine_minutes" min="45" max="1440"' . $defaultNumber(45) . '></label>'
            . '<label><span>Zusätzliche Pausenminuten je Arbeitstag</span><input type="number" name="' . $prefix . 'additional_minutes" min="0" max="1440"' . $defaultNumber(0) . '></label>'
            . '<label class="full-span"><span>Geplante Pausenfenster (optional)</span><input name="' . $prefix . 'planned_windows" placeholder="12:00-12:30, 15:00-15:15" maxlength="128"></label>'
            . ($optional ? '<label class="full-span"><span><input type="checkbox" name="' . $prefix . 'apply_windows" value="1"> Auch eine leere Liste als individuelle Planfenster übernehmen</span></label>' : '');
    }

    private function ruleDetail(array $policy): string
    {
        $mode = $policy['capture_mode'] === 'manual' ? 'Bestätigte manuelle Erfassung' : 'Start und Ende stempeln';
        $windows = array_map(static fn (array $item): string => $item['start'] . '–' . $item['end'], $policy['planned_windows']);
        return '<div>' . $mode . '</div><div>Mehr als 6 Std. ' . (int) $policy['minimum_over_six_minutes'] . ' Min. · Mehr als 9 Std. ' . (int) $policy['minimum_over_nine_minutes'] . ' Min.</div>'
            . ((int) $policy['additional_minutes'] > 0 ? '<div>Zusätzlich ' . (int) $policy['additional_minutes'] . ' Min. je Arbeitstag</div>' : '')
            . ($windows !== [] ? '<div>Planfenster ' . $this->e(implode(', ', $windows)) . '</div>' : '');
    }

    private function userOptions(array $users, int $selected): string
    {
        $options = '';
        foreach ($users as $user) {
            $id = (int) $user['id'];
            $name = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
            $options .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>' . $this->e($name) . '</option>';
        }
        return $options;
    }

    private function output(): string
    {
        return '<p class="full-span muted" data-pause-model-output role="status" aria-live="polite"></p>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
