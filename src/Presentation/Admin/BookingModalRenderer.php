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
        $sortEnabled = (bool) ($options['sort_enabled'] ?? false);
        $currentSort = (string) ($options['sort'] ?? 'date');
        $currentDirection = (string) ($options['direction'] ?? 'desc');
        $sortBaseUrl = (string) ($options['sort_base_url'] ?? '/admin/bookings');
        $sortFilters = is_array($options['sort_filters'] ?? null) ? $options['sort_filters'] : [];
        $columnControlsEnabled = (bool) ($options['column_controls'] ?? false);
        $columns = $this->bookingColumns($showSelection);

        $rows = '';
        $cards = '';

        foreach ($bookings as $booking) {
            $id = (int) ($booking['id'] ?? 0);
            $isActive = (int) ($booking['is_deleted'] ?? 0) !== 1;
            $entryType = (string) ($booking['entry_type'] ?? 'work');
            $hasIncompleteTime = $isActive
                && $entryType === 'work'
                && (trim((string) ($booking['start_time'] ?? '')) === '' || trim((string) ($booking['end_time'] ?? '')) === '');
            $needsProjectAssignment = $isActive && (bool) ($booking['needs_project_assignment'] ?? false);
            $hasBookingIssue = $hasIncompleteTime || $needsProjectAssignment;
            $projectLabel = $this->projectLabel($booking);
            $projectDisplay = $this->e($projectLabel);

            if ($needsProjectAssignment) {
                $projectDisplay .= '<br><span class="badge warn">Projekt offen</span>';
            }

            $typeLabel = (string) ($entryTypeOptions[$entryType] ?? $entryType);
            $typeDisplay = $this->e($typeLabel);

            if ($hasIncompleteTime) {
                $typeDisplay .= '<br><span class="badge error">Zeit unvollstaendig</span>';
            }

            $sourceLabel = (string) ($booking['source_label'] ?? $this->sourceLabel((string) ($booking['source'] ?? 'app')));
            $statusBadge = !$isActive
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
            $geoCount = (int) ($booking['geo_count'] ?? (is_array($booking['geo_records'] ?? null) ? count($booking['geo_records']) : 0));
            if ($attachmentCount > 0) {
                $attachmentDisplay = '<span class="badge">Anhänge: ' . $attachmentCount . '</span>'
                    . ($imageAttachmentCount > 0 ? '<br><span class="muted">' . $imageAttachmentCount . ' Bild(er)</span>' : '')
                    . ($archivedAttachmentCount > 0 ? '<br><span class="muted">' . $archivedAttachmentCount . ' archiviert</span>' : '');
            } elseif ($archivedAttachmentCount > 0) {
                $attachmentDisplay = '<span class="badge warn">Anhänge archiviert: ' . $archivedAttachmentCount . '</span>';
            } else {
                $attachmentDisplay = '<span class="muted">-</span>';
            }
            $geoDisplay = $geoCount > 0
                ? '<span class="badge">Standort: ' . $geoCount . '</span>'
                : '<span class="muted">-</span>';
            $signature = is_array($booking['customer_signature'] ?? null) ? $booking['customer_signature'] : null;
            $signatureDisplay = $signature !== null
                ? '<span class="badge ok">Bestaetigt</span><br><span class="muted">' . $this->e((string) ($signature['customer_name'] ?? '')) . '</span>'
                : '<span class="muted">-</span>';
            $actionLabel = $canManage ? 'Bearbeiten' : 'Ansehen';
            $actionButton = $canOpenModal
                ? '<a class="button button-secondary booking-edit-trigger" data-booking-open aria-haspopup="dialog" href="' . $this->e($this->openBookingLocation($openBookingLocation, $id)) . '">' . $this->e($actionLabel) . '</a>'
                : '<span class="muted">Nur Ansicht</span>';
            $selectionCell = $showSelection
                ? '<td data-booking-column="selection"><input type="checkbox" name="booking_ids[]" value="' . $id . '"' . ($bulkFormId !== '' ? ' form="' . $this->e($bulkFormId) . '"' : '') . '></td>'
                : '';
            $rowData = $this->rowData($booking, $typeLabel, $projectLabel);
            $rowClasses = trim(($canOpenModal ? 'booking-row is-clickable' : 'booking-row') . ($hasBookingIssue ? ' has-booking-issue' : ''));
            $tabIndex = $canOpenModal ? '0' : '-1';

            $rows .= '<tr class="' . $rowClasses . '" data-booking-row data-booking-id="' . $id . '" data-booking-openable="' . ($canOpenModal ? '1' : '0') . '"' . ($hasBookingIssue ? ' data-booking-issue="1"' : '') . ' data-booking="' . $this->dataJson($rowData) . '" tabindex="' . $tabIndex . '">'
                . $selectionCell
                . '<td data-booking-column="date">' . $this->e((string) ($booking['work_date'] ?? '')) . '</td>'
                . '<td data-booking-column="employee"><strong>' . $this->e((string) ($booking['employee_name'] ?? '')) . '</strong><br><span class="muted">' . $this->e((string) ($booking['employee_number'] ?? '')) . '</span></td>'
                . '<td data-booking-column="project">' . $projectDisplay . '</td>'
                . '<td data-booking-column="type">' . $typeDisplay . '</td>'
                . '<td data-booking-column="source"><span class="badge">' . $this->e($sourceLabel) . '</span></td>'
                . '<td data-booking-column="start">' . $this->displayTime($booking['start_time'] ?? null) . '</td>'
                . '<td data-booking-column="end">' . $this->displayTime($booking['end_time'] ?? null) . '</td>'
                . '<td data-booking-column="break">' . $this->e((string) ($booking['break_minutes'] ?? 0)) . ' Min</td>'
                . '<td data-booking-column="net">' . $this->e((string) ($booking['net_minutes'] ?? 0)) . ' Min</td>'
                . '<td data-booking-column="note">' . $noteDisplay . '</td>'
                . '<td data-booking-column="attachments">' . $attachmentDisplay . '</td>'
                . '<td data-booking-column="location">' . $geoDisplay . '</td>'
                . '<td data-booking-column="signature">' . $signatureDisplay . '</td>'
                . '<td data-booking-column="status">' . $statusBadge . '</td>'
                . '<td data-booking-column="version"><span class="muted">' . $this->e((string) ($booking['version_hint'] ?? '')) . '</span></td>'
                . '<td class="table-actions">' . $actionButton . '</td>'
                . '</tr>';

            $selectionControl = $showSelection
                ? '<label class="booking-card__check"><input type="checkbox" name="booking_ids[]" value="' . $id . '"' . ($bulkFormId !== '' ? ' form="' . $this->e($bulkFormId) . '"' : '') . '><span>Auswaehlen</span></label>'
                : '';
            $issueBadges = ($needsProjectAssignment ? '<span class="badge warn">Projekt offen</span>' : '')
                . ($hasIncompleteTime ? '<span class="badge error">Zeit unvollstaendig</span>' : '');
            $timeRange = trim($this->timeValue($booking['start_time'] ?? null) . ' - ' . $this->timeValue($booking['end_time'] ?? null), ' -');
            $timeRange = $timeRange !== '' ? $this->e($timeRange) : '<span class="muted">Keine Zeit</span>';
            $cardClasses = trim('booking-card' . ($hasBookingIssue ? ' has-booking-issue' : ''));

            $cards .= '<article class="' . $cardClasses . '" data-booking-row data-booking-id="' . $id . '" data-booking-openable="' . ($canOpenModal ? '1' : '0') . '"' . ($hasBookingIssue ? ' data-booking-issue="1"' : '') . ' data-booking="' . $this->dataJson($rowData) . '" tabindex="' . $tabIndex . '">'
                . '<div class="booking-card__top">'
                . '<div><span class="muted">' . $this->e((string) ($booking['work_date'] ?? '')) . '</span><strong>' . $this->e((string) ($booking['employee_name'] ?? '')) . '</strong></div>'
                . $selectionControl
                . '</div>'
                . '<div class="booking-card__badges">' . $statusBadge . ($issueBadges !== '' ? $issueBadges : '') . '<span class="badge">' . $this->e($sourceLabel) . '</span></div>'
                . '<dl class="booking-card__meta">'
                . '<div><dt>Projekt</dt><dd>' . $this->e($projectLabel) . '</dd></div>'
                . '<div><dt>Typ</dt><dd>' . $this->e($typeLabel) . '</dd></div>'
                . '<div><dt>Zeit</dt><dd>' . $timeRange . '</dd></div>'
                . '<div><dt>Netto</dt><dd>' . $this->e((string) ($booking['net_minutes'] ?? 0)) . ' Min</dd></div>'
                . '</dl>'
                . '<div class="booking-card__signals">' . $attachmentDisplay . $geoDisplay . $signatureDisplay . '</div>'
                . '<div class="booking-card__actions">' . $actionButton . '</div>'
                . '</article>';
        }

        if ($rows === '') {
            $colspan = $showSelection ? '17' : '16';
            $rows = '<tr><td colspan="' . $colspan . '" class="table-empty">' . $this->e($emptyMessage) . '</td></tr>';
            $cards = '<p class="table-empty">' . $this->e($emptyMessage) . '</p>';
        }

        $selectionHead = $showSelection ? '<th data-booking-column="selection">Auswahl</th>' : '';
        $columnControls = $columnControlsEnabled ? $this->columnControls($columns) : '';

        return <<<HTML
{$columnControls}
<div class="booking-list-desktop table-scroll">
    <table class="booking-table" data-booking-column-table>
        <thead>
            <tr>
                {$selectionHead}
                <th data-booking-column="date">{$this->sortableHeader('date', 'Datum', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="employee">{$this->sortableHeader('employee', 'Mitarbeiter', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="project">{$this->sortableHeader('project', 'Projekt', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="type">{$this->sortableHeader('type', 'Typ', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="source">{$this->sortableHeader('source', 'Herkunft', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="start">{$this->sortableHeader('start', 'Start', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="end">{$this->sortableHeader('end', 'Ende', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="break">Pause</th>
                <th data-booking-column="net">{$this->sortableHeader('net', 'Netto', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="note">Notiz</th>
                <th data-booking-column="attachments">Anhänge</th>
                <th data-booking-column="location">Standort</th>
                <th data-booking-column="signature">Bestätigung</th>
                <th data-booking-column="status">{$this->sortableHeader('status', 'Status', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th data-booking-column="version">{$this->sortableHeader('updated', 'Version', $sortEnabled, $currentSort, $currentDirection, $sortBaseUrl, $sortFilters)}</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
    </table>
</div>
<div class="booking-card-list" aria-label="Buchungen">
    {$cards}
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
        $documentStatuses = is_array($options['document_statuses'] ?? null) ? $options['document_statuses'] : [];

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
            $selectedId,
            $documentStatuses,
            $canManage
        );
        $locationSection = $this->renderLocationSection(
            is_array($selectedBooking['geo_records'] ?? null) ? $selectedBooking['geo_records'] : []
        );
        $signatureSection = $this->renderSignatureSection(
            is_array($selectedBooking['customer_signature'] ?? null) ? $selectedBooking['customer_signature'] : null,
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
        {$locationSection}
        {$signatureSection}
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

    private function bookingColumns(bool $showSelection): array
    {
        $columns = [
            'date' => 'Datum',
            'employee' => 'Mitarbeiter',
            'project' => 'Projekt',
            'type' => 'Typ',
            'source' => 'Herkunft',
            'start' => 'Start',
            'end' => 'Ende',
            'break' => 'Pause',
            'net' => 'Netto',
            'note' => 'Notiz',
            'attachments' => 'Anhänge',
            'location' => 'Standort',
            'signature' => 'Bestätigung',
            'status' => 'Status',
            'version' => 'Version',
        ];

        return $showSelection ? ['selection' => 'Auswahl'] + $columns : $columns;
    }

    private function columnControls(array $columns): string
    {
        $items = '';

        foreach ($columns as $key => $label) {
            $items .= '<label class="booking-column-controls__item">'
                . '<input type="checkbox" value="' . $this->e((string) $key) . '" checked data-booking-column-toggle>'
                . '<span>' . $this->e((string) $label) . '</span>'
                . '</label>';
        }

        return <<<HTML
<details class="booking-column-controls" data-booking-column-controls>
    <summary class="booking-column-controls__summary">
        <span>Tabellenspalten anpassen</span>
    </summary>
    <div class="booking-column-controls__header">
        <p class="booking-column-controls__title">Sichtbare Spalten</p>
        <button type="button" class="button button-secondary booking-column-controls__reset" data-booking-column-reset>Alle anzeigen</button>
    </div>
    <div class="booking-column-controls__grid">
        {$items}
    </div>
</details>
HTML;
    }

    private function sortableHeader(string $sort, string $label, bool $enabled, string $currentSort, string $currentDirection, string $baseUrl, array $filters): string
    {
        if (!$enabled) {
            return $this->e($label);
        }

        return $this->sortLink($sort, $label, $currentSort, $currentDirection, $baseUrl, $filters);
    }

    private function sortLink(string $sort, string $label, string $currentSort, string $currentDirection, string $baseUrl, array $filters): string
    {
        $nextDirection = $currentSort === $sort && $currentDirection === 'asc' ? 'desc' : 'asc';
        $filters['sort'] = $sort;
        $filters['direction'] = $nextDirection;
        $filters['page'] = 1;
        $href = $baseUrl . '?' . http_build_query(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== null
        ));
        $indicator = $currentSort === $sort ? ($currentDirection === 'asc' ? ' ^' : ' v') : '';
        $sortState = $currentSort === $sort
            ? '<span class="sr-only">, aktuell ' . $this->e($currentDirection === 'asc' ? 'aufsteigend' : 'absteigend') . ' sortiert</span>'
            : '';
        $nextState = '<span class="sr-only">, ' . $this->e($nextDirection === 'asc' ? 'aufsteigend' : 'absteigend') . ' sortieren</span>';

        return '<a class="admin-table-sort booking-sort-link" href="' . $this->e($href) . '">' . $this->e($label . $indicator) . $sortState . $nextState . '</a>';
    }

    private function renderAttachmentSection(array $attachments, bool $canArchive, string $returnTo, string $csrfToken, int $bookingId, array $documentStatuses = [], bool $canManageStatus = false): string
    {
        $items = '';
        $statusOptions = $this->documentStatusOptions($documentStatuses);
        $statusOptionsJson = $this->dataJson(['items' => array_values($documentStatuses)]);

        foreach ($attachments as $file) {
            $isDeleted = (int) ($file['is_deleted'] ?? 0) === 1;
            $downloadUrl = (string) ($file['download_url'] ?? '');
            $previewUrl = (string) ($file['preview_url'] ?? '');
            $archiveUrl = (string) ($file['archive_url'] ?? '');
            $statusUpdateUrl = (string) ($file['status_update_url'] ?? '');
            $documentStatus = is_array($file['document_status'] ?? null) ? $file['document_status'] : null;
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
            $archiveBadge = $isDeleted ? '<span class="badge warn booking-attachment__archive-badge">Archiviert</span>' : '';
            $documentStatusBadge = $documentStatus !== null
                ? '<span class="document-status-badge" style="--document-status-color: ' . $this->e((string) ($documentStatus['color'] ?? '#64748b')) . '">' . $this->e((string) ($documentStatus['label'] ?? 'Unbearbeitet')) . '</span>'
                : '<span class="muted">Kein Status</span>';
            $statusForm = ($canManageStatus && !$isDeleted && $statusUpdateUrl !== '')
                ? '<form method="post" action="' . $this->e($statusUpdateUrl) . '" class="booking-attachment__status-form">'
                    . '<input type="hidden" name="return_to" value="' . $this->e($returnTo) . '">'
                    . '<input type="hidden" name="booking_id" value="' . $bookingId . '">'
                    . '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">'
                    . '<label class="booking-attachment__status-control"><span>Dokumentenstatus</span><select name="document_status_id">' . $this->markSelectedOption($statusOptions, (string) ($documentStatus['id'] ?? '')) . '</select></label>'
                    . '<button type="submit" class="button button-secondary">Speichern</button>'
                    . '</form>'
                : '';

            $items .= '<li class="booking-attachment">'
                . $preview
                . '<div class="booking-attachment__body">'
                . '<strong>' . $this->e((string) ($file['original_name'] ?? 'Anhang')) . '</strong>'
                . '<span class="muted">' . $this->e($this->formatFileMeta($file)) . '</span>'
                . '<div class="booking-attachment__status-line"><span class="booking-attachment__label">Dokumentenstatus</span>' . $documentStatusBadge . '</div>'
                . $statusForm
                . '<div class="booking-attachment__actions">' . $archiveBadge . $openLink . $archiveForm . '</div>'
                . '</div>'
                . '</li>';
        }

        if ($items === '') {
            $items = '<li class="booking-attachment is-empty"><p class="muted">Keine Anhänge fuer diese Buchung vorhanden.</p></li>';
        }

        return <<<HTML
<section class="booking-attachments" data-booking-modal-attachments data-can-archive-files="{$this->e($canArchive ? '1' : '0')}" data-can-manage-file-status="{$this->e($canManageStatus ? '1' : '0')}" data-document-status-options="{$statusOptionsJson}">
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
            'geo_records' => is_array($booking['geo_records'] ?? null) ? $booking['geo_records'] : [],
            'geo_count' => (int) ($booking['geo_count'] ?? (is_array($booking['geo_records'] ?? null) ? count($booking['geo_records']) : 0)),
            'latest_geo' => is_array($booking['latest_geo'] ?? null) ? $booking['latest_geo'] : null,
            'customer_signature' => is_array($booking['customer_signature'] ?? null) ? $booking['customer_signature'] : null,
            'customer_signature_present' => (bool) ($booking['customer_signature_present'] ?? is_array($booking['customer_signature'] ?? null)),
        ];
    }

    private function renderSignatureSection(?array $signature, bool $canArchive, string $returnTo, string $csrfToken, int $bookingId): string
    {
        $body = $this->signatureMarkup($signature, $canArchive, $returnTo, $csrfToken, $bookingId);

        return <<<HTML
<section class="booking-attachments" data-booking-modal-signature data-can-archive-signature="{$this->e($canArchive ? '1' : '0')}">
    <div>
        <h3>Kundenbestätigung</h3>
        <p class="muted">Einfache Kundenbestätigung zur angezeigten Zeitbuchung; keine qualifizierte elektronische Signatur.</p>
    </div>
    <ul class="booking-attachment-list">
        {$body}
    </ul>
</section>
HTML;
    }

    private function signatureMarkup(?array $signature, bool $canArchive, string $returnTo, string $csrfToken, int $bookingId): string
    {
        if ($signature === null) {
            return '<li class="booking-attachment is-empty"><p class="muted">Keine Kundenbestätigung fuer diese Buchung vorhanden.</p></li>';
        }

        $imageUrl = (string) ($signature['image_url'] ?? '');
        $name = (string) ($signature['customer_name'] ?? '');
        $signedAt = (string) ($signature['signed_at'] ?? '');
        $sha = (string) ($signature['sha256'] ?? '');
        $signatureId = (int) ($signature['id'] ?? 0);
        $archiveForm = ($canArchive && $signatureId > 0)
            ? '<form method="post" action="/admin/timesheet-signatures/' . $signatureId . '/archive" class="booking-attachment__archive">'
                . '<input type="hidden" name="return_to" value="' . $this->e($returnTo) . '">'
                . '<input type="hidden" name="booking_id" value="' . $bookingId . '">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">'
                . '<button type="submit" class="button button-danger">Bestätigung archivieren</button>'
                . '</form>'
            : '';
        $preview = $imageUrl !== ''
            ? '<a class="booking-attachment__preview" href="' . $this->e($imageUrl) . '" target="_blank" rel="noopener" data-attachment-viewer-open data-preview-url="' . $this->e($imageUrl) . '" data-preview-type="image" data-preview-name="Kundenbestätigung" data-preview-mime="image/png"><img src="' . $this->e($imageUrl) . '" alt=""></a>'
            : '<div class="booking-attachment__icon" aria-hidden="true">PNG</div>';
        $openLink = $imageUrl !== ''
            ? '<a class="button button-secondary" href="' . $this->e($imageUrl) . '" target="_blank" rel="noopener">Öffnen</a>'
            : '';

        return '<li class="booking-attachment">'
            . $preview
            . '<div class="booking-attachment__body">'
            . '<strong>' . $this->e($name) . '</strong>'
            . '<span class="muted">' . $this->e(trim($signedAt . ($sha !== '' ? ' · SHA-256 ' . substr($sha, 0, 12) . '...' : ''))) . '</span>'
            . '<div class="booking-attachment__actions">' . $openLink . $archiveForm . '</div>'
            . '</div>'
            . '</li>';
    }

    private function renderLocationSection(array $locations): string
    {
        $items = '';

        foreach ($locations as $location) {
            $latitude = isset($location['latitude']) ? (float) $location['latitude'] : 0.0;
            $longitude = isset($location['longitude']) ? (float) $location['longitude'] : 0.0;
            $recordedAt = (string) ($location['recorded_at'] ?? '');
            $accuracy = isset($location['accuracy_meters']) ? (int) $location['accuracy_meters'] : null;
            $mapUrl = (string) ($location['map_url'] ?? '');
            $meta = trim($recordedAt) !== '' ? $recordedAt : 'Zeitpunkt unbekannt';

            if ($accuracy !== null) {
                $meta .= ' · Genauigkeit ca. ' . $accuracy . ' m';
            }

            $mapLink = $mapUrl !== ''
                ? '<a class="button button-secondary" href="' . $this->e($mapUrl) . '" target="_blank" rel="noopener">Karte öffnen</a>'
                : '';

            $items .= '<li class="booking-location">'
                . '<div class="booking-location__body">'
                . '<strong>' . $this->e(number_format($latitude, 7, ',', '.') . ', ' . number_format($longitude, 7, ',', '.')) . '</strong>'
                . '<span class="muted">' . $this->e($meta) . '</span>'
                . '</div>'
                . $mapLink
                . '</li>';
        }

        if ($items === '') {
            $items = '<li class="booking-location is-empty"><p class="muted">Kein Standort fuer diese Buchung gespeichert.</p></li>';
        }

        return <<<HTML
<section class="booking-locations" data-booking-modal-locations>
    <div>
        <h3>Standort</h3>
        <p class="muted">Gespeicherte GEO-Daten der App-Buchung, falls beim Erfassen uebermittelt.</p>
    </div>
    <ul class="booking-location-list">
        {$items}
    </ul>
</section>
HTML;
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

        if ((bool) ($file['is_image'] ?? false) && $this->isPreviewableImageMimeType($mimeType)) {
            return 'image';
        }

        return $mimeType === 'application/pdf' ? 'pdf' : '';
    }

    private function previewMarkup(array $file, string $previewUrl, string $previewType): string
    {
        if ($previewType === 'image') {
            return '<img src="' . $this->e($previewUrl) . '" alt="">';
        }

        return '<span>PDF</span>';
    }

    private function isPreviewableImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    private function documentStatusOptions(array $statuses): string
    {
        $html = '<option value="">Kein Status</option>';

        foreach ($statuses as $status) {
            $html .= '<option value="' . $this->e((string) ($status['id'] ?? '')) . '">' . $this->e((string) ($status['label'] ?? '')) . '</option>';
        }

        return $html;
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
            'terminal' => 'Terminal',
            'vacation_request' => 'Urlaubsantrag',
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
