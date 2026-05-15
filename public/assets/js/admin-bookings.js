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
