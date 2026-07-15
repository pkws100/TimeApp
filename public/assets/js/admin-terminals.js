(function () {
    function isInteractive(target) {
        return Boolean(target && target.closest('a, button, input, select, textarea, label, form'));
    }

    function tagData(row) {
        if (!row || !row.dataset.terminalTag) {
            return null;
        }

        try {
            return JSON.parse(row.dataset.terminalTag);
        } catch (error) {
            return null;
        }
    }

    function setText(modal, selector, value) {
        var element = modal.querySelector(selector);
        if (element) {
            element.textContent = value || '-';
        }
    }

    function setSelectValue(form, name, value) {
        var select = form.querySelector('[name="' + name + '"]');
        if (select) {
            select.value = value == null ? '' : String(value);
        }
    }

    function statusText(status) {
        if (status === 'active') {
            return 'Aktiv';
        }
        if (status === 'disabled') {
            return 'Gesperrt';
        }
        return 'Konfiguration erforderlich';
    }

    function setStatus(modal, tag) {
        var element = modal.querySelector('[data-terminal-tag-modal-status]');
        if (!element) {
            return;
        }
        var status = tag.is_deleted ? 'archived' : (tag.status || 'pending');
        var className = status === 'active' ? 'badge ok' : 'badge warn';
        var label = status === 'archived' ? 'Archiviert' : statusText(status);
        element.innerHTML = '<span class="' + className + '"></span>';
        element.querySelector('span').textContent = label;
    }

    function focusModal(modal) {
        var autofocus = modal.querySelector('[name="label"]:not([disabled]), button[data-terminal-tag-modal-close]');
        if (autofocus) {
            window.setTimeout(function () { autofocus.focus(); }, 0);
        }
    }

    function openModal(modal, row, trigger) {
        var tag = tagData(row);
        if (!modal || !tag) {
            return;
        }

        var form = modal.querySelector('[data-terminal-tag-update-form]');
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.__lastTrigger = trigger || null;
        modal.dataset.activeTerminalTagId = String(tag.id || '');

        setText(modal, '[data-terminal-tag-modal-uid]', tag.uid_masked);
        setText(modal, '[data-terminal-tag-modal-terminal]', tag.learned_terminal_name || 'Unbekannt');
        setText(modal, '[data-terminal-tag-modal-learned-at]', tag.learned_at || 'Nicht bekannt');
        setText(modal, '[data-terminal-tag-modal-relearned-at]', tag.relearned_from_archive_at || '-');
        setStatus(modal, tag);

        var relearnWarning = modal.querySelector('[data-terminal-tag-modal-relearn-warning]');
        if (relearnWarning) {
            relearnWarning.hidden = !tag.relearned_from_archive_at;
        }
        var dialog = modal.querySelector('.admin-modal__dialog');
        if (dialog) {
            if (tag.relearned_from_archive_at) {
                dialog.setAttribute('aria-describedby', 'terminalTagModalReuseWarning');
            } else {
                dialog.removeAttribute('aria-describedby');
            }
        }

        if (form) {
            form.action = '/admin/terminals/tags/' + tag.id;
            var label = form.querySelector('[name="label"]');
            var status = form.querySelector('[name="status"]');
            if (label) {
                label.value = tag.label || '';
            }
            if (status) {
                status.value = tag.status || 'pending';
            }
            setSelectValue(form, 'user_id', tag.user_id);
            setSelectValue(form, 'project_id', tag.project_id);
        }

        var archiveForm = modal.querySelector('[data-terminal-tag-archive-form]');
        if (archiveForm) {
            archiveForm.action = '/admin/terminals/tags/' + tag.id + '/archive';
            archiveForm.hidden = Boolean(tag.is_deleted);
        }
        var archiveSection = modal.querySelector('[data-terminal-tag-archive-section]');
        if (archiveSection) {
            archiveSection.hidden = Boolean(tag.is_deleted);
        }

        document.body.classList.add('modal-open');
        focusModal(modal);
    }

    function closeModal(modal) {
        if (!modal || modal.hidden) {
            return;
        }

        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        delete modal.dataset.activeTerminalTagId;
        document.body.classList.remove('modal-open');
        var fallback = modal.__lastTrigger || document.querySelector('[data-terminal-tag-open]') || document.querySelector('.page-header h1');
        if (fallback && typeof fallback.focus === 'function') {
            if (fallback.matches && fallback.matches('h1')) {
                fallback.tabIndex = -1;
            }
            fallback.focus();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.querySelector('[data-terminal-tag-modal]');
        if (!modal) {
            return;
        }

        if (!modal.hidden) {
            document.body.classList.add('modal-open');
            focusModal(modal);
        }

        document.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-terminal-tag-open]');
            if (opener) {
                event.preventDefault();
                openModal(modal, opener.closest('[data-terminal-tag-row]'), opener);
                return;
            }
            if (event.target.closest('[data-terminal-tag-modal-close]')) {
                event.preventDefault();
                closeModal(modal);
                return;
            }
            var row = event.target.closest('[data-terminal-tag-row][data-terminal-tag-openable="1"]');
            if (row && !isInteractive(event.target)) {
                openModal(modal, row, row);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) {
                closeModal(modal);
                return;
            }
            if ((event.key === 'Enter' || event.key === ' ') && document.activeElement && document.activeElement.matches('[data-terminal-tag-row][data-terminal-tag-openable="1"]')) {
                event.preventDefault();
                openModal(modal, document.activeElement, document.activeElement);
                return;
            }
            if (event.key !== 'Tab' || modal.hidden) {
                return;
            }
            var focusable = Array.prototype.slice.call(modal.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (node) {
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
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    });
}());
