(function () {
    var lastAttachmentTrigger = null;

    function isInteractive(target) {
        return Boolean(target.closest('button, a, input, select, textarea, label, form'));
    }

    function parseBooking(row) {
        if (!row || !row.dataset.booking) {
            return null;
        }

        try {
            return JSON.parse(row.dataset.booking);
        } catch (error) {
            return null;
        }
    }

    function setFormReason(modal, form) {
        if (!modal || !form) {
            return true;
        }

        var visibleReason = modal.querySelector('[data-booking-reason]');
        var formReason = form.querySelector('textarea[name="change_reason"], input[name="change_reason"]');
        var sharedReason = visibleReason ? visibleReason.value.trim() : '';
        var currentReason = formReason && typeof formReason.value === 'string' ? formReason.value.trim() : '';

        if (!formReason) {
            return true;
        }

        if (currentReason === '' && sharedReason !== '' && formReason !== visibleReason) {
            formReason.value = sharedReason;
            currentReason = sharedReason;
        }

        if (currentReason !== '') {
            return true;
        }

        if (formReason && typeof formReason.focus === 'function') {
            formReason.focus();
        }

        return false;
    }

    var absenceReasonsByType = {
        vacation: ['vacation_paid'],
        sick: ['sick_paid', 'sick_unpaid'],
        absent: ['paid_leave', 'employer_release_paid', 'unpaid_leave', 'unexcused_absence'],
        holiday: ['employer_release_paid']
    };

    function syncAbsenceReasonControl(entryTypeSelect, reasonSelect) {
        if (!entryTypeSelect || !reasonSelect) {
            return;
        }

        var entryType = entryTypeSelect.value || 'work';
        var allowed = (absenceReasonsByType[entryType] || []).slice();
        var form = reasonSelect.closest('form');
        var isLegacyVacation = entryType === 'vacation'
            && reasonSelect.value === 'unpaid_leave'
            && form
            && form.querySelector('input[name="_method"][value="PUT"]');
        if (isLegacyVacation) {
            allowed.push('unpaid_leave');
        }
        var requiresReason = allowed.length > 0;

        reasonSelect.required = requiresReason;
        reasonSelect.disabled = !requiresReason;

        Array.prototype.forEach.call(reasonSelect.options, function (option) {
            if (option.value === '') {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            var isAllowed = allowed.indexOf(option.value) !== -1;
            option.hidden = !isAllowed;
            option.disabled = !isAllowed;
        });

        if (!requiresReason || allowed.indexOf(reasonSelect.value) === -1) {
            reasonSelect.value = '';
        }
    }

    function initAbsenceReasonControls(root) {
        var scope = root || document;

        scope.querySelectorAll('select[name="absence_reason_code"]').forEach(function (reasonSelect) {
            var container = reasonSelect.closest('form') || scope;
            var entryTypeSelect = container.querySelector('select[name="entry_type"]');

            syncAbsenceReasonControl(entryTypeSelect, reasonSelect);

            if (entryTypeSelect && !entryTypeSelect.dataset.absenceReasonBound) {
                entryTypeSelect.dataset.absenceReasonBound = '1';
                entryTypeSelect.addEventListener('change', function () {
                    syncAbsenceReasonControl(entryTypeSelect, reasonSelect);
                });
            }
        });
    }

    function currentReturnTo() {
        var url = new URL(window.location.href);

        url.searchParams.delete('notice');
        url.searchParams.delete('error');
        url.searchParams.delete('booking_id');
        url.searchParams.delete('modal');

        return url.pathname + url.search;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatBytes(bytes) {
        var value = Number(bytes || 0);

        if (value < 1024) {
            return value + ' B';
        }

        if (value < 1024 * 1024) {
            return (value / 1024).toFixed(1).replace('.', ',') + ' KB';
        }

        return (value / (1024 * 1024)).toFixed(1).replace('.', ',') + ' MB';
    }

    function fileMeta(file) {
        var parts = [];

        if (file.mime_type) {
            parts.push(file.mime_type);
        }

        parts.push(formatBytes(file.size_bytes));

        if (file.uploaded_at) {
            parts.push(file.uploaded_at);
        }

        return parts.join(' · ');
    }

    function csrfToken(modal) {
        var field = modal.querySelector('input[name="csrf_token"]');

        return field ? field.value : '';
    }

    function previewType(file) {
        if (file.is_image) {
            return 'image';
        }

        return file.mime_type === 'application/pdf' ? 'pdf' : '';
    }

    function previewMarkup(file, url, type) {
        if (type === 'image') {
            return '<img src="' + escapeHtml(url) + '" alt="">';
        }

        return '<span>PDF</span>';
    }

    function ensureAttachmentViewer() {
        var existing = document.querySelector('[data-admin-attachment-viewer]');

        if (existing) {
            return existing;
        }

        var viewer = document.createElement('div');
        viewer.className = 'admin-attachment-viewer';
        viewer.hidden = true;
        viewer.setAttribute('aria-hidden', 'true');
        viewer.setAttribute('data-admin-attachment-viewer', '');
        viewer.innerHTML = ''
            + '<div class="admin-attachment-viewer__overlay" data-attachment-viewer-close></div>'
            + '<div class="admin-attachment-viewer__dialog" role="dialog" aria-modal="true" aria-labelledby="attachmentViewerTitle">'
            + '<div class="admin-attachment-viewer__header">'
            + '<div><p class="eyebrow">Anhang</p><h2 id="attachmentViewerTitle" data-attachment-viewer-title>Anhang anzeigen</h2><p class="muted" data-attachment-viewer-meta></p></div>'
            + '<button type="button" class="button button-secondary" data-attachment-viewer-close>Schliessen</button>'
            + '</div>'
            + '<div class="admin-attachment-viewer__content" data-attachment-viewer-content></div>'
            + '<div class="admin-attachment-viewer__actions"><a class="button button-secondary" href="#" target="_blank" rel="noopener" data-attachment-viewer-open-external>In neuem Tab öffnen</a></div>'
            + '</div>';
        document.body.appendChild(viewer);

        return viewer;
    }

    function openAttachmentViewer(link) {
        var viewer = ensureAttachmentViewer();
        var title = viewer.querySelector('[data-attachment-viewer-title]');
        var meta = viewer.querySelector('[data-attachment-viewer-meta]');
        var content = viewer.querySelector('[data-attachment-viewer-content]');
        var external = viewer.querySelector('[data-attachment-viewer-open-external]');
        var url = link.dataset.previewUrl || link.href;
        var type = link.dataset.previewType || '';
        var name = link.dataset.previewName || 'Anhang';
        var mime = link.dataset.previewMime || '';

        lastAttachmentTrigger = link;

        if (title) {
            title.textContent = name;
        }

        if (meta) {
            meta.textContent = mime;
        }

        if (external) {
            external.href = url;
        }

        if (content) {
            if (type === 'image') {
                content.innerHTML = '<img class="admin-attachment-viewer__image" src="' + escapeHtml(url) + '" alt="">';
            } else if (type === 'pdf') {
                content.innerHTML = '<iframe class="admin-attachment-viewer__frame" src="' + escapeHtml(url) + '" title="' + escapeHtml(name) + '"></iframe>';
            } else {
                content.innerHTML = '<p class="muted">Für diese Datei ist keine Vorschau verfuegbar.</p>';
            }
        }

        viewer.hidden = false;
        viewer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        var closeButton = viewer.querySelector('[data-attachment-viewer-close]');

        if (closeButton && typeof closeButton.focus === 'function') {
            closeButton.focus();
        }
    }

    function closeAttachmentViewer() {
        var viewer = document.querySelector('[data-admin-attachment-viewer]');

        if (!viewer || viewer.hidden) {
            return;
        }

        var content = viewer.querySelector('[data-attachment-viewer-content]');

        if (content) {
            content.innerHTML = '';
        }

        viewer.hidden = true;
        viewer.setAttribute('aria-hidden', 'true');

        if (!document.querySelector('[data-booking-modal]:not([hidden])')) {
            document.body.classList.remove('modal-open');
        }

        if (lastAttachmentTrigger && typeof lastAttachmentTrigger.focus === 'function') {
            lastAttachmentTrigger.focus();
        }

        lastAttachmentTrigger = null;
    }

    function renderAttachments(modal, attachments) {
        var section = modal.querySelector('[data-booking-modal-attachments]');

        if (!section) {
            return;
        }

        var list = section.querySelector('.booking-attachment-list');

        if (!list) {
            return;
        }

        var files = Array.isArray(attachments) ? attachments : [];
        var canArchive = section.dataset.canArchiveFiles === '1';
        var canManageStatus = section.dataset.canManageFileStatus === '1';
        var statusOptions = [];

        try {
            var statusPayload = JSON.parse(section.dataset.documentStatusOptions || '{"items":[]}');
            statusOptions = Array.isArray(statusPayload.items) ? statusPayload.items : [];
        } catch (error) {
            statusOptions = [];
        }

        if (files.length === 0) {
            list.innerHTML = '<li class="booking-attachment is-empty"><p class="muted">Keine Anhänge fuer diese Buchung vorhanden.</p></li>';
            return;
        }

        list.innerHTML = files.map(function (file) {
            var isDeleted = Number(file.is_deleted || 0) === 1;
            var type = previewType(file);
            var preview = file.preview_url && type
                ? '<a class="booking-attachment__preview" href="' + escapeHtml(file.preview_url) + '" target="_blank" rel="noopener" data-attachment-viewer-open data-preview-url="' + escapeHtml(file.preview_url) + '" data-preview-type="' + escapeHtml(type) + '" data-preview-name="' + escapeHtml(file.original_name || 'Anhang') + '" data-preview-mime="' + escapeHtml(file.mime_type || '') + '" aria-label="' + escapeHtml((file.original_name || 'Anhang') + ' gross anzeigen') + '">' + previewMarkup(file, file.preview_url, type) + '</a>'
                : '<div class="booking-attachment__icon" aria-hidden="true">Datei</div>';
            var openLink = (!isDeleted && file.download_url)
                ? '<a class="button button-secondary" href="' + escapeHtml(file.download_url) + '" target="_blank" rel="noopener">Öffnen</a>'
                : '<span class="muted">Nicht abrufbar</span>';
            var archiveForm = (canArchive && !isDeleted && file.archive_url)
                ? '<form method="post" action="' + escapeHtml(file.archive_url) + '" class="booking-attachment__archive">'
                    + '<input type="hidden" name="_method" value="DELETE">'
                    + '<input type="hidden" name="return_to" value="' + escapeHtml(currentReturnTo()) + '">'
                    + '<input type="hidden" name="booking_id" value="' + escapeHtml(modal.dataset.activeBookingId || '') + '">'
                    + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken(modal)) + '">'
                    + '<button type="submit" class="button button-danger">Anhang archivieren</button>'
                    + '</form>'
                : '';
            var archiveBadge = isDeleted ? '<span class="badge warn booking-attachment__archive-badge">Archiviert</span>' : '';
            var documentStatus = file.document_status || null;
            var documentStatusBadge = documentStatus
                ? '<span class="document-status-badge" style="--document-status-color: ' + escapeHtml(documentStatus.color || '#64748b') + '">' + escapeHtml(documentStatus.label || 'Unbearbeitet') + '</span>'
                : '<span class="muted">Kein Status</span>';
            var statusForm = (canManageStatus && !isDeleted && file.status_update_url)
                ? '<form method="post" action="' + escapeHtml(file.status_update_url) + '" class="booking-attachment__status-form">'
                    + '<input type="hidden" name="return_to" value="' + escapeHtml(currentReturnTo()) + '">'
                    + '<input type="hidden" name="booking_id" value="' + escapeHtml(modal.dataset.activeBookingId || '') + '">'
                    + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken(modal)) + '">'
                    + '<label class="booking-attachment__status-control"><span>Dokumentenstatus</span><select name="document_status_id">' + documentStatusOptionsMarkup(statusOptions, documentStatus ? String(documentStatus.id || '') : '') + '</select></label>'
                    + '<button type="submit" class="button button-secondary">Speichern</button>'
                    + '</form>'
                : '';

            return '<li class="booking-attachment">'
                + preview
                + '<div class="booking-attachment__body">'
                + '<strong>' + escapeHtml(file.original_name || 'Anhang') + '</strong>'
                + '<span class="muted">' + escapeHtml(fileMeta(file)) + '</span>'
                + '<div class="booking-attachment__status-line"><span class="booking-attachment__label">Dokumentenstatus</span>' + documentStatusBadge + '</div>'
                + statusForm
                + '<div class="booking-attachment__actions">' + archiveBadge + openLink + archiveForm + '</div>'
                + '</div>'
                + '</li>';
        }).join('');
    }

    function documentStatusOptionsMarkup(statuses, selected) {
        var options = '<option value=""' + (selected === '' ? ' selected' : '') + '>Kein Status</option>';

        statuses.forEach(function (status) {
            var id = String(status.id || '');
            options += '<option value="' + escapeHtml(id) + '"' + (id === selected ? ' selected' : '') + '>' + escapeHtml(status.label || '') + '</option>';
        });

        return options;
    }

    function renderLocations(modal, locations) {
        var section = modal.querySelector('[data-booking-modal-locations]');

        if (!section) {
            return;
        }

        var list = section.querySelector('.booking-location-list');

        if (!list) {
            return;
        }

        var records = Array.isArray(locations) ? locations : [];

        if (records.length === 0) {
            list.innerHTML = '<li class="booking-location is-empty"><p class="muted">Kein Standort fuer diese Buchung gespeichert.</p></li>';
            return;
        }

        list.innerHTML = records.map(function (record) {
            var latitude = Number(record.latitude || 0);
            var longitude = Number(record.longitude || 0);
            var meta = record.recorded_at || 'Zeitpunkt unbekannt';

            if (record.accuracy_meters !== null && typeof record.accuracy_meters !== 'undefined') {
                meta += ' · Genauigkeit ca. ' + Number(record.accuracy_meters || 0) + ' m';
            }

            var mapLink = record.map_url
                ? '<a class="button button-secondary" href="' + escapeHtml(record.map_url) + '" target="_blank" rel="noopener">Karte öffnen</a>'
                : '';

            return '<li class="booking-location">'
                + '<div class="booking-location__body">'
                + '<strong>' + escapeHtml(latitude.toFixed(7).replace('.', ',') + ', ' + longitude.toFixed(7).replace('.', ',')) + '</strong>'
                + '<span class="muted">' + escapeHtml(meta) + '</span>'
                + '</div>'
                + mapLink
                + '</li>';
        }).join('');
    }

    function renderSignature(modal, signature) {
        var section = modal.querySelector('[data-booking-modal-signature]');

        if (!section) {
            return;
        }

        var list = section.querySelector('.booking-attachment-list');

        if (!list) {
            return;
        }

        var canArchive = section.dataset.canArchiveSignature === '1';

        if (!signature || !signature.id) {
            list.innerHTML = '<li class="booking-attachment is-empty"><p class="muted">Keine Kundenbestätigung fuer diese Buchung vorhanden.</p></li>';
            return;
        }

        var imageUrl = signature.image_url || '';
        var preview = imageUrl
            ? '<a class="booking-attachment__preview" href="' + escapeHtml(imageUrl) + '" target="_blank" rel="noopener" data-attachment-viewer-open data-preview-url="' + escapeHtml(imageUrl) + '" data-preview-type="image" data-preview-name="Kundenbestätigung" data-preview-mime="image/png"><img src="' + escapeHtml(imageUrl) + '" alt=""></a>'
            : '<div class="booking-attachment__icon" aria-hidden="true">PNG</div>';
        var openLink = imageUrl
            ? '<a class="button button-secondary" href="' + escapeHtml(imageUrl) + '" target="_blank" rel="noopener">Öffnen</a>'
            : '';
        var sha = signature.sha256 ? ' · SHA-256 ' + String(signature.sha256).slice(0, 12) + '...' : '';
        var archiveForm = canArchive
            ? '<form method="post" action="/admin/timesheet-signatures/' + escapeHtml(signature.id) + '/archive" class="booking-attachment__archive">'
                + '<input type="hidden" name="return_to" value="' + escapeHtml(currentReturnTo()) + '">'
                + '<input type="hidden" name="booking_id" value="' + escapeHtml(modal.dataset.activeBookingId || '') + '">'
                + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken(modal)) + '">'
                + '<button type="submit" class="button button-danger">Bestätigung archivieren</button>'
                + '</form>'
            : '';

        list.innerHTML = '<li class="booking-attachment">'
            + preview
            + '<div class="booking-attachment__body">'
            + '<strong>' + escapeHtml(signature.customer_name || '') + '</strong>'
            + '<span class="muted">' + escapeHtml((signature.signed_at || '') + sha) + '</span>'
            + '<div class="booking-attachment__actions">' + openLink + archiveForm + '</div>'
            + '</div>'
            + '</li>';
    }

    function openModal(modal, row, trigger) {
        var booking = parseBooking(row);

        if (!modal || !booking) {
            return;
        }

        var updateForm = modal.querySelector('[data-booking-update-form]');
        var archiveForm = modal.querySelector('[data-booking-action-form="archive"]');
        var restoreForm = modal.querySelector('[data-booking-action-form="restore"]');
        var archiveButton = modal.querySelector('[data-booking-archive-button]');
        var restoreButton = modal.querySelector('[data-booking-restore-button]');

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.dataset.activeBookingId = String(booking.id || '');
        modal.__lastTrigger = trigger || null;

        var employee = modal.querySelector('[data-booking-modal-employee]');
        var project = modal.querySelector('[data-booking-modal-project]');
        var version = modal.querySelector('[data-booking-modal-version]');
        var status = modal.querySelector('[data-booking-modal-status]');

        if (employee) {
            employee.textContent = booking.employee_name || '-';
            if (booking.employee_number) {
                employee.textContent += ' (' + booking.employee_number + ')';
            }
        }

        if (project) {
            project.textContent = booking.project_label || 'Nicht zugeordnet';
        }

        if (version) {
            version.textContent = booking.version_hint || '-';
        }

        if (status) {
            status.textContent = booking.status_label || '';
        }

        if (updateForm) {
            updateForm.action = '/admin/bookings/' + booking.id;

            var workDate = updateForm.querySelector('[name="work_date"]');
            var projectId = updateForm.querySelector('[name="project_id"]');
            var entryType = updateForm.querySelector('[name="entry_type"]');
            var absenceReason = updateForm.querySelector('[name="absence_reason_code"]');
            var creditedDisplay = updateForm.querySelector('[data-booking-credited-display]');
            var startTime = updateForm.querySelector('[name="start_time"]');
            var endTime = updateForm.querySelector('[name="end_time"]');
            var breakMinutes = updateForm.querySelector('[name="break_minutes"]');
            var note = updateForm.querySelector('[name="note"]');
            var visibleReason = modal.querySelector('[data-booking-reason]');

            if (workDate) {
                workDate.value = booking.work_date || '';
            }

            if (projectId) {
                projectId.value = booking.project_id || '__none__';
            }

            if (entryType) {
                entryType.value = booking.entry_type || 'work';
            }

            if (absenceReason) {
                absenceReason.value = booking.absence_reason_code || '';
                syncAbsenceReasonControl(entryType, absenceReason);
            }

            if (creditedDisplay) {
                creditedDisplay.value = booking.credited_minutes == null ? 'Server berechnet' : String(booking.credited_minutes) + ' Min';
            }

            if (startTime) {
                startTime.value = booking.start_time || '';
            }

            if (endTime) {
                endTime.value = booking.end_time || '';
            }

            if (breakMinutes) {
                breakMinutes.value = booking.break_minutes != null ? String(booking.break_minutes) : '0';
            }

            if (note) {
                note.value = booking.note || '';
            }

            if (visibleReason) {
                visibleReason.value = '';
            }
        }

        modal.querySelectorAll('input[name="return_to"]').forEach(function (field) {
            field.value = currentReturnTo();
        });

        modal.querySelectorAll('[data-booking-action-form] textarea[name="change_reason"]').forEach(function (field) {
            field.value = '';
        });

        if (archiveForm) {
            archiveForm.action = '/admin/bookings/' + booking.id + '/archive';
        }

        if (restoreForm) {
            restoreForm.action = '/admin/bookings/' + booking.id + '/restore';
        }

        if (archiveButton) {
            archiveButton.hidden = Boolean(booking.is_deleted);
        }

        if (restoreButton) {
            restoreButton.hidden = !Boolean(booking.is_deleted);
        }

        renderLocations(modal, booking.geo_records);
        renderSignature(modal, booking.customer_signature);
        renderAttachments(modal, booking.attachments);

        document.body.classList.add('modal-open');

        var autofocusTarget = modal.querySelector('[name="work_date"]:not([disabled]), [data-booking-reason]');

        if (autofocusTarget) {
            window.setTimeout(function () {
                autofocusTarget.focus();
            }, 0);
        }
    }

    function closeModal(modal) {
        if (!modal || modal.hidden) {
            return;
        }

        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        delete modal.dataset.activeBookingId;
        document.body.classList.remove('modal-open');

        if (modal.__lastTrigger && typeof modal.__lastTrigger.focus === 'function') {
            modal.__lastTrigger.focus();
        }
    }

    function hiddenBookingColumnsKey() {
        return 'admin.bookings.hiddenColumns';
    }

    function readHiddenBookingColumns() {
        try {
            var stored = window.localStorage.getItem(hiddenBookingColumnsKey());
            var parsed = stored ? JSON.parse(stored) : [];

            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function writeHiddenBookingColumns(columns) {
        try {
            if (columns.length === 0) {
                window.localStorage.removeItem(hiddenBookingColumnsKey());
                return;
            }

            window.localStorage.setItem(hiddenBookingColumnsKey(), JSON.stringify(columns));
        } catch (error) {
            // Browser storage can be unavailable in strict privacy modes.
        }
    }

    function initBookingColumnControls() {
        var table = document.querySelector('[data-booking-column-table]');
        var controls = document.querySelector('[data-booking-column-controls]');

        if (!table || !controls) {
            return;
        }

        var toggles = Array.prototype.slice.call(controls.querySelectorAll('[data-booking-column-toggle]'));
        var reset = controls.querySelector('[data-booking-column-reset]');

        if (toggles.length === 0) {
            return;
        }

        function knownColumns() {
            return toggles.map(function (toggle) {
                return toggle.value;
            }).filter(Boolean);
        }

        function normalizeHiddenColumns(columns) {
            var known = knownColumns();
            var hidden = columns.filter(function (column, index, list) {
                return known.indexOf(column) !== -1 && list.indexOf(column) === index;
            });

            return hidden.length >= known.length ? [] : hidden;
        }

        function applyHiddenColumns(columns) {
            var hidden = normalizeHiddenColumns(columns);

            toggles.forEach(function (toggle) {
                toggle.checked = hidden.indexOf(toggle.value) === -1;
            });

            table.querySelectorAll('[data-booking-column]').forEach(function (cell) {
                cell.hidden = hidden.indexOf(cell.getAttribute('data-booking-column')) !== -1;
            });

            writeHiddenBookingColumns(hidden);
        }

        applyHiddenColumns(readHiddenBookingColumns());

        toggles.forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                var hidden = toggles.filter(function (item) {
                    return !item.checked;
                }).map(function (item) {
                    return item.value;
                });

                applyHiddenColumns(hidden);
            });
        });

        if (reset) {
            reset.addEventListener('click', function () {
                applyHiddenColumns([]);
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAbsenceReasonControls(document);
        initBookingColumnControls();

        var modal = document.querySelector('[data-booking-modal]');

        if (!modal) {
            return;
        }

        if (!modal.hidden) {
            document.body.classList.add('modal-open');
        }

        document.addEventListener('click', function (event) {
            var attachmentPreview = event.target.closest('[data-attachment-viewer-open]');

            if (attachmentPreview) {
                event.preventDefault();
                openAttachmentViewer(attachmentPreview);
                return;
            }

            if (event.target.closest('[data-attachment-viewer-close]')) {
                closeAttachmentViewer();
                return;
            }

            var openButton = event.target.closest('[data-booking-open]');

            if (openButton) {
                event.preventDefault();
                var row = openButton.closest('[data-booking-row]');

                if (row) {
                    openModal(modal, row, openButton);
                }

                return;
            }

            if (event.target.closest('[data-booking-modal-close]')) {
                closeModal(modal);
                return;
            }

            var row = event.target.closest('[data-booking-row][data-booking-openable="1"]');

            if (row && !isInteractive(event.target)) {
                openModal(modal, row, row);
            }
        });

        document.addEventListener('keydown', function (event) {
            var attachmentViewer = document.querySelector('[data-admin-attachment-viewer]');

            if (event.key === 'Escape' && attachmentViewer && !attachmentViewer.hidden) {
                closeAttachmentViewer();
                return;
            }

            if (event.key === 'Tab' && attachmentViewer && !attachmentViewer.hidden) {
                var viewerFocusable = Array.prototype.slice.call(
                    attachmentViewer.querySelectorAll('a[href], button:not([disabled]), iframe, [tabindex]:not([tabindex="-1"])')
                ).filter(function (node) {
                    return !node.hidden && node.offsetParent !== null;
                });

                if (viewerFocusable.length === 0) {
                    return;
                }

                var viewerFirst = viewerFocusable[0];
                var viewerLast = viewerFocusable[viewerFocusable.length - 1];

                if (event.shiftKey && document.activeElement === viewerFirst) {
                    event.preventDefault();
                    viewerLast.focus();
                    return;
                }

                if (!event.shiftKey && document.activeElement === viewerLast) {
                    event.preventDefault();
                    viewerFirst.focus();
                    return;
                }

                return;
            }

            if (event.key === 'Escape' && !modal.hidden) {
                closeModal(modal);
                return;
            }

            if (event.key === 'Tab' && !modal.hidden) {
                var focusable = Array.prototype.slice.call(
                    modal.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')
                ).filter(function (node) {
                    return !node.hidden && node.offsetParent !== null;
                });

                if (focusable.length === 0) {
                    return;
                }

                var first = focusable[0];
                var last = focusable[focusable.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                    return;
                }

                if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                    return;
                }
            }

            if ((event.key === 'Enter' || event.key === ' ') && document.activeElement && document.activeElement.matches('[data-booking-row][data-booking-openable="1"]')) {
                event.preventDefault();
                openModal(modal, document.activeElement, document.activeElement);
            }
        });

        modal.querySelectorAll('[data-booking-reason-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!setFormReason(modal, form)) {
                    event.preventDefault();
                }
            });
        });

        var params = new URLSearchParams(window.location.search);
        var reopenId = params.get('booking_id');
        var reopenModal = params.get('modal');

        if (reopenId && reopenModal === 'edit') {
            var row = document.querySelector('[data-booking-row][data-booking-id="' + reopenId + '"]');

            if (row) {
                openModal(modal, row, row);
            }
        }
    });
}());
