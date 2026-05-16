<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

final class BookingModalRenderer
{
    /**
     * @param array<string, string> $entryTypeOptions
     * @param array<string, mixed> $options
     */
    public function renderTable(array $bookings, array $projects, array $entryTypeOptions, array $options = []): string
    {
        $showSelection = (bool) ($options['show_selection'] ?? false);
        $bulkFormId = (string) ($options['bulk_form_id'] ?? '');
        $emptyMessage = (string) ($options['empty_message'] ?? 'Keine Buchungen vorhanden.');
        $canManage = (bool) ($options['can_manage'] ?? false);
        $canArchive = (bool) ($options['can_archive'] ?? false);
        $canViewAttachments = (bool) ($options['can_view_attachments'] ?? false);
        $openBookingLocation = (string) ($options['open_booking_location'] ?? '/admin/bookings');
        $canOpenModal = $canManage || $canArchive || $canViewAttachments;

        $rows = '';

        foreach ($bookings as $booking) {
            $id = (int) ($booking['id'] ?? 0);
            $projectLabel = $this->projectLabel($booking);
            $projectDisplay = $this->e($projectLabel);

            if ((bool) ($booking['needs_project_assignment'] ?? false)) {
                $projectDisplay .= '<br><span class="badge warn">Projekt offen</span>';
            }

            $typeLabel = (string) ($entryTypeOptions[(string) ($booking['entry_type'] ?? 'work')] ?? ($booking['entry_type'] ?? ''));
            $sourceLabel = (string) ($booking['source_label'] ?? $this->sourceLabel((string) ($booking['source'] ?? 'app')));
            $statusBadge = (int) ($booking['is_deleted'] ?? 0) === 1
                ? '<span class="badge warn">Archiviert</span>'
                : '<span class="badge ok">Aktiv</span>';
            $note = trim((string) ($booking['note'] ?? ''));
            $noteDisplay = $note !== ''
                ? '<span class="table-note">' . $this->e($note) . '</span>'
                : '<span class="muted">-</span>';
            $attachmentCount = (int) ($booking['attachment_count'] ?? 0);
            $attachmentTotalCount = (int) ($booking['attachment_total_count'] ?? $attachmentCount);
            $archivedAttachmentCount = (int) ($booking['archived_attachment_count'] ?? max(0, $attachmentTotalCount - $attachmentCount));
            $imageAttachmentCount = (int) ($booking['image_attachment_count'] ?? 0);
            if ($attachmentCount > 0) {
                $attachmentDisplay = '<span class="badge">Anhänge: ' . $attachmentCount . '</span>'
                    . ($imageAttachmentCount > 0 ? '<br><span class="muted">' . $imageAttachmentCount . ' Bild(er)</span>' : '')
                    . ($archivedAttachmentCount > 0 ? '<br><span class="muted">' . $archivedAttachmentCount . ' archiviert</span>' : '');
            } elseif ($archivedAttachmentCount > 0) {
                $attachmentDisplay = '<span class="badge warn">Anhänge archiviert: ' . $archivedAttachmentCount . '</span>';
            } else {
                $attachmentDisplay = '<span class="muted">-</span>';
            }
            $actionLabel = $canManage ? 'Bearbeiten' : 'Ansehen';
            $actionButton = $canOpenModal
                ? '<a class="button button-secondary booking-edit-trigger" data-booking-open aria-haspopup="dialog" href="' . $this->e($this->openBookingLocation($openBookingLocation, $id)) . '">' . $this->e($actionLabel) . '</a>'
                : '<span class="muted">Nur Ansicht</span>';
            $selectionCell = $showSelection
                ? '<td><input type="checkbox" name="booking_ids[]" value="' . $id . '"' . ($bulkFormId !== '' ? ' form="' . $this->e($bulkFormId) . '"' : '') . '></td>'
                : '';
            $rowData = $this->rowData($booking, $typeLabel, $projectLabel);
            $rowClasses = $canOpenModal ? 'booking-row is-clickable' : 'booking-row';
            $tabIndex = $canOpenModal ? '0' : '-1';

            $rows .= '<tr class="' . $rowClasses . '" data-booking-row data-booking-id="' . $id . '" data-booking-openable="' . ($canOpenModal ? '1' : '0') . '" data-booking="' . $this->dataJson($rowData) . '" tabindex="' . $tabIndex . '">'
                . $selectionCell
                . '<td>' . $this->e((string) ($booking['work_date'] ?? '')) . '</td>'
                . '<td><strong>' . $this->e((string) ($booking['employee_name'] ?? '')) . '</strong><br><span class="muted">' . $this->e((string) ($booking['employee_number'] ?? '')) . '</span></td>'
                . '<td>' . $projectDisplay . '</td>'
                . '<td>' . $this->e($typeLabel) . '</td>'
                . '<td><span class="badge">' . $this->e($sourceLabel) . '</span></td>'
                . '<td>' . $this->displayTime($booking['start_time'] ?? null) . '</td>'
                . '<td>' . $this->displayTime($booking['end_time'] ?? null) . '</td>'
                . '<td>' . $this->e((string) ($booking['break_minutes'] ?? 0)) . ' Min</td>'
                . '<td>' . $this->e((string) ($booking['net_minutes'] ?? 0)) . ' Min</td>'
                . '<td>' . $noteDisplay . '</td>'
                . '<td>' . $attachmentDisplay . '</td>'
                . '<td>' . $statusBadge . '</td>'
                . '<td><span class="muted">' . $this->e((string) ($booking['version_hint'] ?? '')) . '</span></td>'
                . '<td class="table-actions">' . $actionButton . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $colspan = $showSelection ? '15' : '14';
            $rows = '<tr><td colspan="' . $colspan . '" class="table-empty">' . $this->e($emptyMessage) . '</td></tr>';
        }

        $selectionHead = $showSelection ? '<th>Auswahl</th>' : '';

        return <<<HTML
<div class="table-scroll">
    <table class="booking-table">
        <thead>
            <tr>
                {$selectionHead}
                <th>Datum</th>
                <th>Mitarbeiter</th>
                <th>Projekt</th>
                <th>Typ</th>
                <th>Herkunft</th>
                <th>Start</th>
                <th>Ende</th>
                <th>Pause</th>
                <th>Netto</th>
                <th>Notiz</th>
                <th>Anhänge</th>
                <th>Status</th>
                <th>Version</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
    </table>
</div>
HTML;
    }

    /**
     * @param array<string, string> $entryTypeOptions
     * @param array<string, mixed> $options
     */
    public function renderModal(array $projects, array $entryTypeOptions, array $options = []): string
    {
        $canManage = (bool) ($options['can_manage'] ?? false);
        $canArchive = (bool) ($options['can_archive'] ?? false);
        $canViewAttachments = (bool) ($options['can_view_attachments'] ?? false);
        $selectedBooking = is_array($options['selected_booking'] ?? null) ? $options['selected_booking'] : null;

        if (!$canManage && !$canArchive && !$canViewAttachments) {
            return '';
        }

        $returnTo = (string) ($options['return_to'] ?? '/admin/bookings');
        $csrfToken = (string) ($options['csrf_token'] ?? '');
        $projectOptions = $this->projectAssignmentOptions($projects);
        $entryTypeHtml = $this->entryTypeOptions($entryTypeOptions);
        $disabled = $canManage ? '' : ' disabled';
        $saveButton = $canManage ? '<button type="submit" class="button">Speichern</button>' : '<span class="muted">Keine Bearbeitungsrechte</span>';
        $isDeleted = (int) ($selectedBooking['is_deleted'] ?? 0) === 1;
        $modalHidden = $selectedBooking === null ? ' hidden' : '';
        $ariaHidden = $selectedBooking === null ? 'true' : 'false';
        $workDate = $this->e((string) ($selectedBooking['work_date'] ?? ''));
        $projectId = ($selectedBooking['project_id'] ?? null) === null ? '__none__' : (string) $selectedBooking['project_id'];
        $entryType = (string) ($selectedBooking['entry_type'] ?? 'work');
        $startTime = $this->e($this->timeValue($selectedBooking['start_time'] ?? null));
        $endTime = $this->e($this->timeValue($selectedBooking['end_time'] ?? null));
        $breakMinutes = $this->e((string) ($selectedBooking['break_minutes'] ?? 0));
        $note = $this->e((string) ($selectedBooking['note'] ?? ''));
        $employeeLabel = $this->e((string) ($selectedBooking['employee_name'] ?? '-'));
        $employeeNumber = trim((string) ($selectedBooking['employee_number'] ?? ''));
        if ($employeeNumber !== '' && $employeeLabel !== '-') {
            $employeeLabel = $employeeLabel . ' (' . $this->e($employeeNumber) . ')';
        }
        $projectLabel = $this->e($this->projectLabel($selectedBooking ?? []));
        $versionHint = $this->e((string) ($selectedBooking['version_hint'] ?? '-'));
        $statusLabel = $this->e($isDeleted ? 'Archiviert' : ((string) ($selectedBooking['status_label'] ?? 'Aktiv')));
        $selectedId = (int) ($selectedBooking['id'] ?? 0);
        $updateAction = $selectedId > 0 ? '/admin/bookings/' . $selectedId : '';
        $archiveAction = $selectedId > 0 ? '/admin/bookings/' . $selectedId . '/archive' : '';
        $restoreAction = $selectedId > 0 ? '/admin/bookings/' . $selectedId . '/restore' : '';
        $attachmentSection = $this->renderAttachmentSection(
            is_array($selectedBooking['attachments'] ?? null) ? $selectedBooking['attachments'] : [],
            $canArchive,
            $returnTo,
            $csrfToken,
            $selectedId
        );
        $archiveControls = $canArchive
            ? <<<HTML
<div class="admin-modal__divider"></div>
<div class="admin-modal__danger-actions">
    <form method="post" action="{$this->e($archiveAction)}" data-booking-action-form="archive" data-booking-reason-form class="stack admin-modal__action-form">
        <input type="hidden" name="_method" value="DELETE">
        <input type="hidden" name="return_to" value="{$this->e($returnTo)}">
        <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
        <label><span>Begruendung fuer Archivierung</span><textarea name="change_reason" rows="2" placeholder="Warum soll diese Buchung archiviert werden?"></textarea></label>
        <button type="submit" class="button button-danger" data-booking-archive-button{$this->hiddenIf($selectedBooking === null || $isDeleted)}>Archivieren</button>
    </form>
    <form method="post" action="{$this->e($restoreAction)}" data-booking-action-form="restore" data-booking-reason-form class="stack admin-modal__action-form">
        <input type="hidden" name="return_to" value="{$this->e($returnTo)}">
        <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
        <label><span>Begruendung fuer Wiederherstellung</span><textarea name="change_reason" rows="2" placeholder="Warum soll diese Buchung wiederhergestellt werden?"></textarea></label>
        <button type="submit" class="button button-secondary" data-booking-restore-button{$this->hiddenIf($selectedBooking === null || !$isDeleted)}>Wiederherstellen</button>
    </form>
</div>
HTML
            : '';

        return <<<HTML
<div class="admin-modal" data-booking-modal{$modalHidden} aria-hidden="{$ariaHidden}">
    <div class="admin-modal__overlay" data-booking-modal-close></div>
    <div class="admin-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle">
        <div class="admin-modal__header">
            <div>
                <p class="eyebrow">Buchung</p>
                <h2 id="bookingModalTitle">Buchung bearbeiten</h2>
                <p class="muted admin-modal__status" data-booking-modal-status>{$statusLabel}</p>
            </div>
            <button type="button" class="button button-secondary admin-modal__close" data-booking-modal-close>Schliessen</button>
        </div>
        <div class="admin-modal__summary">
            <div class="admin-modal__summary-card">
                <span class="muted">Mitarbeiter</span>
                <strong data-booking-modal-employee>{$employeeLabel}</strong>
            </div>
            <div class="admin-modal__summary-card">
                <span class="muted">Projekt</span>
                <strong data-booking-modal-project>{$projectLabel}</strong>
            </div>
            <div class="admin-modal__summary-card">
                <span class="muted">Version</span>
                <strong data-booking-modal-version>{$versionHint}</strong>
            </div>
        </div>
        <form method="post" action="{$this->e($updateAction)}" data-booking-update-form data-booking-reason-form class="stack">
            <input type="hidden" name="_method" value="PUT">
            <input type="hidden" name="return_to" value="{$this->e($returnTo)}">
            <input type="hidden" name="csrf_token" value="{$this->e($csrfToken)}">
            <div class="form-grid">
                <label><span>Datum</span><input type="date" name="work_date" value="{$workDate}"{$disabled} required></label>
                <label><span>Projekt</span><select name="project_id"{$disabled}>{$this->markSelectedOption($projectOptions, $projectId)}</select></label>
                <label><span>Typ</span><select name="entry_type"{$disabled}>{$this->markSelectedOption($entryTypeHtml, $entryType)}</select></label>
                <label><span>Start</span><input type="time" name="start_time" value="{$startTime}"{$disabled}></label>
                <label><span>Ende</span><input type="time" name="end_time" value="{$endTime}"{$disabled}></label>
                <label><span>Pause in Minuten</span><input type="number" min="0" step="1" name="break_minutes" value="{$breakMinutes}"{$disabled}></label>
                <label class="full-span"><span>Notiz</span><textarea name="note" rows="4"{$disabled}>{$note}</textarea></label>
            </div>
            <label class="full-span"><span>Begruendung</span><textarea name="change_reason" rows="3" data-booking-reason required placeholder="Bitte fachliche Begruendung eintragen."></textarea></label>
            <div class="table-actions">
                {$saveButton}
            </div>
        </form>
        {$attachmentSection}
        {$archiveControls}
    </div>
</div>
HTML;
    }

    private function projectLabel(array $booking): string
    {
        if (($booking['project_id'] ?? null) === null) {
            return 'Nicht zugeordnet';
        }

        $label = trim((string) ($booking['project_number'] ?? '') . ' ' . (string) ($booking['project_name'] ?? ''));

        if ((int) ($booking['project_is_deleted'] ?? 0) === 1) {
            $label .= ' (archiviert)';
        }

        return $label !== '' ? $label : 'Nicht zugeordnet';
    }

    private function renderAttachmentSection(array $attachments, bool $canArchive, string $returnTo, string $csrfToken, int $bookingId): string
    {
        $items = '';

        foreach ($attachments as $file) {
            $isDeleted = (int) ($file['is_deleted'] ?? 0) === 1;
            $downloadUrl = (string) ($file['download_url'] ?? '');
            $previewUrl = (string) ($file['preview_url'] ?? '');
            $archiveUrl = (string) ($file['archive_url'] ?? '');
            $previewType = $this->previewType($file);
            $previewAttributes = $previewUrl !== '' && $previewType !== ''
                ? ' data-attachment-viewer-open data-preview-url="' . $this->e($previewUrl) . '" data-preview-type="' . $this->e($previewType) . '" data-preview-name="' . $this->e((string) ($file['original_name'] ?? 'Anhang')) . '" data-preview-mime="' . $this->e((string) ($file['mime_type'] ?? '')) . '"'
                : '';
            $preview = $previewUrl !== '' && $previewType !== ''
                ? '<a class="booking-attachment__preview" href="' . $this->e($previewUrl) . '" target="_blank" rel="noopener"' . $previewAttributes . ' aria-label="' . $this->e((string) ($file['original_name'] ?? 'Anhang')) . ' gross anzeigen">' . $this->previewMarkup($file, $previewUrl, $previewType) . '</a>'
                : '<div class="booking-attachment__icon" aria-hidden="true">Datei</div>';
            $openLink = (!$isDeleted && $downloadUrl !== '')
                ? '<a class="button button-secondary" href="' . $this->e($downloadUrl) . '" target="_blank" rel="noopener">Öffnen</a>'
                : '<span class="muted">Nicht abrufbar</span>';
            $archiveForm = ($canArchive && !$isDeleted && $archiveUrl !== '')
                ? '<form method="post" action="' . $this->e($archiveUrl) . '" class="booking-attachment__archive">'
                    . '<input type="hidden" name="_method" value="DELETE">'
                    . '<input type="hidden" name="return_to" value="' . $this->e($returnTo) . '">'
                    . '<input type="hidden" name="booking_id" value="' . $bookingId . '">'
                    . '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">'
                    . '<button type="submit" class="button button-danger">Anhang archivieren</button>'
                    . '</form>'
                : '';
            $status = $isDeleted ? '<span class="badge warn">Archiviert</span>' : '<span class="badge ok">Aktiv</span>';

            $items .= '<li class="booking-attachment">'
                . $preview
                . '<div class="booking-attachment__body">'
                . '<strong>' . $this->e((string) ($file['original_name'] ?? 'Anhang')) . '</strong>'
                . '<span class="muted">' . $this->e($this->formatFileMeta($file)) . '</span>'
                . '<div class="table-actions">' . $status . $openLink . $archiveForm . '</div>'
                . '</div>'
                . '</li>';
        }

        if ($items === '') {
            $items = '<li class="booking-attachment is-empty"><p class="muted">Keine Anhänge fuer diese Buchung vorhanden.</p></li>';
        }

        return <<<HTML
<section class="booking-attachments" data-booking-modal-attachments data-can-archive-files="{$this->e($canArchive ? '1' : '0')}">
    <div>
        <h3>Anhänge</h3>
        <p class="muted">Bilder werden direkt aus dem geschuetzten Storage geladen; Dateien koennen geoeffnet oder archiviert werden.</p>
    </div>
    <ul class="booking-attachment-list">
        {$items}
    </ul>
</section>
HTML;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowData(array $booking, string $typeLabel, string $projectLabel): array
    {
        return [
            'id' => (int) ($booking['id'] ?? 0),
            'employee_name' => (string) ($booking['employee_name'] ?? ''),
            'employee_number' => (string) ($booking['employee_number'] ?? ''),
            'project_id' => ($booking['project_id'] ?? null) === null ? '__none__' : (string) $booking['project_id'],
            'project_label' => $projectLabel,
            'entry_type' => (string) ($booking['entry_type'] ?? 'work'),
            'entry_type_label' => $typeLabel,
            'source' => (string) ($booking['source'] ?? 'app'),
            'source_label' => (string) ($booking['source_label'] ?? $this->sourceLabel((string) ($booking['source'] ?? 'app'))),
            'work_date' => (string) ($booking['work_date'] ?? ''),
            'start_time' => $this->timeValue($booking['start_time'] ?? null),
            'end_time' => $this->timeValue($booking['end_time'] ?? null),
            'break_minutes' => (int) ($booking['break_minutes'] ?? 0),
            'note' => (string) ($booking['note'] ?? ''),
            'is_deleted' => (int) ($booking['is_deleted'] ?? 0) === 1,
            'version_hint' => (string) ($booking['version_hint'] ?? ''),
            'status_label' => (int) ($booking['is_deleted'] ?? 0) === 1 ? 'Archiviert' : 'Aktiv',
            'attachments' => is_array($booking['attachments'] ?? null) ? $booking['attachments'] : [],
            'attachment_count' => (int) ($booking['attachment_count'] ?? 0),
            'attachment_total_count' => (int) ($booking['attachment_total_count'] ?? (int) ($booking['attachment_count'] ?? 0)),
            'archived_attachment_count' => (int) ($booking['archived_attachment_count'] ?? 0),
            'image_attachment_count' => (int) ($booking['image_attachment_count'] ?? 0),
        ];
    }

    private function projectAssignmentOptions(array $projects): string
    {
        $html = '<option value="__none__">Nicht zugeordnet</option>';

        foreach ($projects as $project) {
            $id = (string) ($project['id'] ?? '');
            $label = trim((string) ($project['project_number'] ?? '') . ' ' . (string) ($project['name'] ?? ''));

            if ((int) ($project['is_deleted'] ?? 0) === 1) {
                $label .= ' (archiviert)';
            }

            $html .= '<option value="' . $this->e($id) . '">' . $this->e(trim($label)) . '</option>';
        }

        return $html;
    }

    private function formatFileMeta(array $file): string
    {
        $parts = [];
        $mimeType = trim((string) ($file['mime_type'] ?? ''));
        $uploadedAt = trim((string) ($file['uploaded_at'] ?? ''));

        if ($mimeType !== '') {
            $parts[] = $mimeType;
        }

        $parts[] = $this->formatBytes((int) ($file['size_bytes'] ?? 0));

        if ($uploadedAt !== '') {
            $parts[] = $uploadedAt;
        }

        return implode(' · ', $parts);
    }

    private function previewType(array $file): string
    {
        $mimeType = (string) ($file['mime_type'] ?? '');

        if ((bool) ($file['is_image'] ?? false)) {
            return 'image';
        }

        return $mimeType === 'application/pdf' ? 'pdf' : '';
    }

    private function previewMarkup(array $file, string $previewUrl, string $previewType): string
    {
        if ($previewType === 'image') {
            return '<img src="' . $this->e($previewUrl) . '" alt="" loading="lazy">';
        }

        return '<span>PDF</span>';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'admin' => 'Admin-Nacherfassung',
            default => 'App',
        };
    }

    /**
     * @param array<string, string> $entryTypeOptions
     */
    private function entryTypeOptions(array $entryTypeOptions): string
    {
        $html = '';

        foreach ($entryTypeOptions as $value => $label) {
            $html .= '<option value="' . $this->e($value) . '">' . $this->e($label) . '</option>';
        }

        return $html;
    }

    private function displayTime(?string $time): string
    {
        $value = $this->timeValue($time);

        return $value !== '' ? $this->e($value) : '<span class="muted">-</span>';
    }

    private function timeValue(?string $time): string
    {
        if ($time === null || trim($time) === '') {
            return '';
        }

        return substr($time, 0, 5);
    }

    /**
     * @param array<string, bool|int|string> $data
     */
    private function dataJson(array $data): string
    {
        return $this->e((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
    }

    private function openBookingLocation(string $location, int $bookingId): string
    {
        $separator = str_contains($location, '?') ? '&' : '?';

        return $location . $separator . 'booking_id=' . $bookingId . '&modal=edit';
    }

    private function hiddenIf(bool $hidden): string
    {
        return $hidden ? ' hidden' : '';
    }

    private function markSelectedOption(string $optionsHtml, string $selectedValue): string
    {
        $quoted = 'value="' . $this->e($selectedValue) . '"';

        return str_replace($quoted, $quoted . ' selected', $optionsHtml);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
