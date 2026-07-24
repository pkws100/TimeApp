<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

final class AbsencePeriodModalRenderer
{
    public function render(array $users, string $csrfToken, array $options = []): string
    {
        $vacationOnly = (bool) ($options['vacation_only'] ?? false);
        $selectedDate = $this->e((string) ($options['selected_date'] ?? ''));
        $selectedUserId = (int) ($options['selected_user_id'] ?? 0);
        $canArchive = (bool) ($options['can_archive'] ?? true);
        $allowVacation = (bool) ($options['allow_vacation'] ?? true);
        $createAction = $vacationOnly ? '/admin/vacation-periods' : '/admin/absence-periods';
        $previewAction = $createAction . '/preview';
        $userOptions = $this->userOptions($users, $selectedUserId);
        $typeField = $vacationOnly
            ? '<input type="hidden" name="entry_type" value="vacation"><input type="hidden" name="absence_reason_code" value="vacation_paid">'
            : '<label><span>Art</span><select name="entry_type" required>'
                . ($allowVacation ? '<option value="vacation">Urlaub</option>' : '')
                . '<option value="sick">Krankheit</option>'
                . '<option value="absent">Fehlzeit</option>'
                . '</select></label>'
                . '<label><span>Abwesenheitsgrund</span><select name="absence_reason_code" required>'
                . ($allowVacation ? '<option value="vacation_paid" data-types="vacation">Bezahlter Urlaub</option>' : '')
                . '<option value="sick_paid" data-types="sick">Bezahlte Krankheit</option>'
                . '<option value="sick_unpaid" data-types="sick">Unbezahlte Krankheit</option>'
                . '<option value="paid_leave" data-types="absent">Bezahlte Freistellung</option>'
                . '<option value="employer_release_paid" data-types="absent">Bezahlte Arbeitgeberfreistellung</option>'
                . '<option value="unpaid_leave" data-types="absent">Unbezahlte Abwesenheit</option>'
                . '<option value="unexcused_absence" data-types="absent">Unentschuldigtes Fehlen</option>'
                . '</select></label>';
        $title = $vacationOnly ? 'Urlaub fuer Mitarbeiter buchen' : 'Abwesenheit nacherfassen';

        return <<<HTML
<div class="admin-modal absence-period-modal"
     data-absence-period-modal
     data-create-action="{$this->e($createAction)}"
     data-preview-action="{$this->e($previewAction)}"
     data-vacation-only="{$this->e($vacationOnly ? '1' : '0')}"
     data-can-archive="{$this->e($canArchive ? '1' : '0')}"
     hidden
     aria-hidden="true">
    <div class="admin-modal__overlay" data-absence-period-close></div>
    <div class="admin-modal__dialog absence-period-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="absencePeriodTitle">
        <div class="admin-modal__header">
            <div>
                <p class="eyebrow">Von-bis-Nacherfassung</p>
                <h2 id="absencePeriodTitle" data-absence-period-title>{$this->e($title)}</h2>
                <p class="muted">Es werden nur anrechenbare Arbeitstage als Tagesbuchungen erzeugt.</p>
            </div>
            <button type="button" class="button button-secondary" data-absence-period-close>Schliessen</button>
        </div>
        <form class="stack" data-absence-period-form>
            <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
            <input type="hidden" name="period_id" value="">
            <input type="hidden" name="lock_version" value="">
            <input type="hidden" name="preview_token" value="">
            <div class="form-grid">
                <label><span>Mitarbeiter</span><select name="user_id" required>{$userOptions}</select></label>
                {$typeField}
                <label><span>Von</span><input type="date" name="date_from" value="{$selectedDate}" min="2000-01-01" max="2100-12-31" required></label>
                <label><span>Bis</span><input type="date" name="date_to" value="{$selectedDate}" min="2000-01-01" max="2100-12-31" required></label>
                <label class="full-span"><span>Notiz</span><textarea name="note" rows="3"></textarea></label>
                <label class="full-span"><span>Fachliche Begruendung</span><textarea name="change_reason" rows="3" required></textarea></label>
            </div>
            <div class="absence-period-preview" data-absence-period-preview aria-live="polite">
                <p class="muted">Bitte Zeitraum pruefen, bevor Sie verbindlich speichern.</p>
            </div>
            <label class="absence-period-confirm" data-absence-period-confirm-wrap hidden>
                <input type="checkbox" name="confirm_warnings" value="1">
                <span>Ich habe die Hinweise geprueft und bestaetige diesen Zeitraum.</span>
            </label>
            <p class="notice error" data-absence-period-error role="alert" tabindex="-1" hidden></p>
            <div class="absence-period-modal__actions">
                <button type="button" class="button button-secondary" data-absence-period-preview-button>Zeitraum pruefen</button>
                <button type="submit" class="button" data-absence-period-save disabled>Verbindlich speichern</button>
                <button type="button" class="button button-danger" data-absence-period-archive hidden>Gesamten Zeitraum archivieren</button>
            </div>
        </form>
    </div>
</div>
HTML;
    }

    private function userOptions(array $users, int $selectedUserId): string
    {
        $html = '<option value="">Mitarbeiter auswaehlen</option>';

        foreach ($users as $user) {
            if ((int) ($user['is_deleted'] ?? 0) === 1 || (string) ($user['employment_status'] ?? 'active') !== 'active') {
                continue;
            }

            $id = (int) ($user['id'] ?? 0);
            $label = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
            $number = trim((string) ($user['employee_number'] ?? ''));
            if ($number !== '') {
                $label .= ' (' . $number . ')';
            }
            $html .= '<option value="' . $id . '"' . ($id === $selectedUserId ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
