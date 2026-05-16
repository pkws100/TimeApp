(function () {
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
            var status = isDeleted ? '<span class="badge warn">Archiviert</span>' : '<span class="badge ok">Aktiv</span>';

            return '<li class="booking-attachment">'
                + preview
                + '<div class="booking-attachment__body">'
                + '<strong>' + escapeHtml(file.original_name || 'Anhang') + '</strong>'
                + '<span class="muted">' + escapeHtml(fileMeta(file)) + '</span>'
                + '<div class="table-actions">' + status + openLink + archiveForm + '</div>'
                + '</div>'
                + '</li>';
        }).join('');
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

    document.addEventListener('DOMContentLoaded', function () {
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
