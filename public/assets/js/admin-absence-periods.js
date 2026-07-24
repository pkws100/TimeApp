(function () {
    var modal = document.querySelector('[data-absence-period-modal]');

    if (!modal) {
        return;
    }

    var form = modal.querySelector('[data-absence-period-form]');
    var previewBox = modal.querySelector('[data-absence-period-preview]');
    var previewButton = modal.querySelector('[data-absence-period-preview-button]');
    var saveButton = modal.querySelector('[data-absence-period-save]');
    var archiveButton = modal.querySelector('[data-absence-period-archive]');
    var errorBox = modal.querySelector('[data-absence-period-error]');
    var confirmWrap = modal.querySelector('[data-absence-period-confirm-wrap]');
    var confirmField = form.querySelector('[name="confirm_warnings"]');
    var title = modal.querySelector('[data-absence-period-title]');
    var lastTrigger = null;
    var previewValid = false;
    var modalHistoryEntry = false;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setError(message) {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
        if (message) {
            errorBox.focus();
        }
    }

    function invalidatePreview() {
        previewValid = false;
        saveButton.disabled = true;
        form.querySelector('[name="preview_token"]').value = '';
        confirmWrap.hidden = true;
        confirmField.checked = false;
    }

    function syncReasons() {
        var type = form.querySelector('[name="entry_type"]');
        var reason = form.querySelector('select[name="absence_reason_code"]');

        if (!type || !reason) {
            return;
        }

        var first = null;
        Array.prototype.forEach.call(reason.options, function (option) {
            var visible = String(option.dataset.types || '').split(',').indexOf(type.value) !== -1;
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible && !first) {
                first = option;
            }
        });

        if (!reason.selectedOptions.length || reason.selectedOptions[0].disabled) {
            reason.value = first ? first.value : '';
        }
    }

    function payload() {
        var data = {};
        new FormData(form).forEach(function (value, key) {
            data[key] = value;
        });
        data.confirm_warnings = confirmField.checked ? '1' : '0';
        return data;
    }

    async function requestJson(url, method, body) {
        var response = await fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body)
        });
        var contentType = response.headers.get('content-type') || '';
        if (response.redirected || contentType.indexOf('application/json') === -1) {
            if (response.url && response.url.indexOf('/admin/login') !== -1) {
                window.location.assign(response.url);
                throw new Error('Die Sitzung ist abgelaufen. Sie werden zur Anmeldung weitergeleitet.');
            }
            throw new Error('Die Serverantwort konnte nicht verarbeitet werden. Bitte die Seite neu laden.');
        }
        var result = await response.json();

        if (!response.ok || !result.ok) {
            throw new Error(result.message || 'Der Zeitraum konnte nicht verarbeitet werden.');
        }

        return result.data || {};
    }

    function dateList(items, formatter) {
        if (!items || !items.length) {
            return '<p class="muted">Keine</p>';
        }

        return '<ul>' + items.map(function (item) {
            return '<li>' + formatter(item) + '</li>';
        }).join('') + '</ul>';
    }

    function renderPreview(data) {
        var blockers = data.blockers || [];
        var warnings = data.warnings || [];
        var impact = data.vacation_impact || [];
        var diff = data.diff || { added: [], removed: [], unchanged: [] };
        form.querySelector('[name="preview_token"]').value = data.preview_token || '';
        var impactHtml = impact.length
            ? '<h4>Urlaubskonto</h4>' + dateList(impact, function (item) {
                return escapeHtml(item.year + ': ' + Number(item.delta_days).toFixed(2)
                    + ' Tage, verfuegbar danach ' + Number(item.available_after).toFixed(2));
            })
            : '';

        previewBox.innerHTML = ''
            + '<div class="absence-period-preview__summary">'
            + '<strong>' + escapeHtml(data.booked_day_count) + ' Buchungstage</strong>'
            + '<span>' + escapeHtml(data.credited_minutes_total) + ' Gutschrift-Minuten</span>'
            + '<span>' + escapeHtml(data.calendar_day_count) + ' Kalendertage</span>'
            + '</div>'
            + '<details><summary>Gebuchte Tage (' + escapeHtml((data.book_dates || []).length) + ')</summary>'
            + dateList(data.book_dates || [], function (item) { return escapeHtml(item); }) + '</details>'
            + '<details><summary>Uebersprungen (' + escapeHtml((data.skipped_dates || []).length) + ')</summary>'
            + dateList(data.skipped_dates || [], function (item) {
                return escapeHtml(item.date + ' – ' + item.reason);
            }) + '</details>'
            + (data.period_id ? '<p><strong>Aenderung:</strong> '
                + escapeHtml(diff.added.length) + ' neu, '
                + escapeHtml(diff.removed.length) + ' entfaellt, '
                + escapeHtml(diff.unchanged.length) + ' bleibt.</p>' : '')
            + (blockers.length ? '<div class="notice error"><strong>Blockiert</strong>'
                + dateList(blockers, function (item) { return escapeHtml(item.message); }) + '</div>' : '')
            + (warnings.length ? '<div class="notice warn"><strong>Hinweise</strong>'
                + dateList(warnings, function (item) { return escapeHtml(item.message); }) + '</div>' : '')
            + impactHtml;

        previewValid = blockers.length === 0;
        confirmWrap.hidden = warnings.length === 0;
        confirmField.checked = warnings.length === 0;
        saveButton.disabled = saveButton.dataset.canManage === '0'
            || !previewValid
            || (warnings.length > 0 && !confirmField.checked);
    }

    async function preview() {
        setError('');
        previewButton.disabled = true;
        previewBox.setAttribute('aria-busy', 'true');

        try {
            var periodId = form.querySelector('[name="period_id"]').value;
            var url = periodId
                ? '/admin/absence-periods/' + encodeURIComponent(periodId) + '/preview'
                : modal.dataset.previewAction;
            renderPreview(await requestJson(url, 'POST', payload()));
        } catch (error) {
            invalidatePreview();
            setError(error.message);
        } finally {
            previewButton.disabled = false;
            previewBox.setAttribute('aria-busy', 'false');
        }
    }

    function openModal(trigger) {
        lastTrigger = trigger || null;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        setError('');
        invalidatePreview();
        setTimeout(function () {
            var target = form.querySelector('[name="user_id"]:not([disabled]), [name="date_from"]');
            if (target) {
                target.focus();
            }
        }, 0);
    }

    function resetCreate(trigger) {
        form.reset();
        form.querySelector('[name="period_id"]').value = '';
        form.querySelector('[name="lock_version"]').value = '';
        form.querySelector('[name="preview_token"]').value = '';
        form.querySelector('[name="user_id"]').disabled = false;
        var createType = form.querySelector('select[name="entry_type"]');
        var createReason = form.querySelector('select[name="absence_reason_code"]');
        if (createType) {
            createType.disabled = false;
        }
        if (createReason) {
            createReason.disabled = false;
        }
        archiveButton.hidden = true;
        saveButton.dataset.canManage = '1';
        previewButton.hidden = false;
        saveButton.hidden = false;
        Array.prototype.forEach.call(form.elements, function (field) {
            if (field.name !== 'period_id' && field.name !== 'lock_version') {
                field.disabled = false;
            }
        });
        title.textContent = modal.dataset.vacationOnly === '1'
            ? 'Urlaub fuer Mitarbeiter buchen'
            : 'Abwesenheit nacherfassen';
        var date = trigger ? trigger.dataset.selectedDate : '';
        if (date) {
            form.querySelector('[name="date_from"]').value = date;
            form.querySelector('[name="date_to"]').value = date;
        }
        var userId = trigger ? trigger.dataset.selectedUserId : '';
        if (userId) {
            form.querySelector('[name="user_id"]').value = userId;
        }
        syncReasons();
        previewBox.innerHTML = '<p class="muted">Bitte Zeitraum pruefen, bevor Sie verbindlich speichern.</p>';
        openModal(trigger);
    }

    async function openExisting(trigger, historyMode) {
        setError('');
        try {
            var periodId = trigger.dataset.absencePeriodEdit;
            var response = await fetch('/admin/absence-periods/' + encodeURIComponent(periodId), {
                headers: { 'Accept': 'application/json' }
            });
            var contentType = response.headers.get('content-type') || '';
            if (response.redirected || contentType.indexOf('application/json') === -1) {
                if (response.url && response.url.indexOf('/admin/login') !== -1) {
                    window.location.assign(response.url);
                    return;
                }
                throw new Error('Die Serverantwort konnte nicht verarbeitet werden.');
            }
            var result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.message || 'Zeitraum konnte nicht geladen werden.');
            }
            var data = result.data;
            form.querySelector('[name="period_id"]').value = data.id;
            form.querySelector('[name="lock_version"]').value = data.lock_version;
            form.querySelector('[name="user_id"]').value = data.user_id;
            form.querySelector('[name="user_id"]').disabled = true;
            form.querySelector('[name="date_from"]').value = data.date_from;
            form.querySelector('[name="date_to"]').value = data.date_to;
            form.querySelector('[name="entry_type"]').value = data.entry_type;
            var reason = form.querySelector('[name="absence_reason_code"]');
            if (reason) {
                syncReasons();
                reason.value = data.absence_reason_code;
            }
            var fixedVacation = data.source === 'vacation_request' || data.source === 'admin_vacation';
            var typeSelect = form.querySelector('select[name="entry_type"]');
            var reasonSelect = form.querySelector('select[name="absence_reason_code"]');
            if (typeSelect) {
                typeSelect.disabled = fixedVacation;
            }
            if (reasonSelect) {
                reasonSelect.disabled = fixedVacation;
            }
            form.querySelector('[name="note"]').value = data.note || '';
            form.querySelector('[name="change_reason"]').value = '';
            archiveButton.hidden = data.can_archive !== true;
            saveButton.dataset.canManage = data.can_manage === true ? '1' : '0';
            previewButton.hidden = data.can_manage !== true;
            saveButton.hidden = data.can_manage !== true;
            ['date_from', 'date_to', 'note'].forEach(function (name) {
                form.querySelector('[name="' + name + '"]').disabled = data.can_manage !== true;
            });
            if (typeSelect) {
                typeSelect.disabled = fixedVacation || data.can_manage !== true;
            }
            if (reasonSelect) {
                reasonSelect.disabled = fixedVacation || data.can_manage !== true;
            }
            title.textContent = 'Gesamten Zeitraum bearbeiten';
            previewBox.innerHTML = '<p class="muted">Bitte die Aenderung erneut pruefen.</p>';
            openModal(trigger);
            var deepLink = new URL(window.location.href);
            deepLink.searchParams.set('absence_period_id', String(data.id));
            if (historyMode === 'push') {
                window.history.pushState({ absencePeriodId: String(data.id) }, '', deepLink.toString());
                modalHistoryEntry = true;
            } else {
                modalHistoryEntry = historyMode === 'history';
            }
        } catch (error) {
            window.alert(error.message);
        }
    }

    function closeModal(fromHistory) {
        if (!fromHistory && modalHistoryEntry) {
            window.history.back();
            return;
        }
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        if (!fromHistory) {
            clearDeepLink();
        }
        modalHistoryEntry = false;
        if (lastTrigger && typeof lastTrigger.focus === 'function') {
            lastTrigger.focus();
        }
    }

    function clearDeepLink() {
        var deepLink = new URL(window.location.href);
        if (deepLink.searchParams.has('absence_period_id')) {
            deepLink.searchParams.delete('absence_period_id');
            window.history.replaceState({}, '', deepLink.toString());
        }
        modalHistoryEntry = false;
    }

    document.addEventListener('click', function (event) {
        var createTrigger = event.target.closest('[data-absence-period-open]');
        var editTrigger = event.target.closest('[data-absence-period-edit]');

        if (createTrigger) {
            resetCreate(createTrigger);
        } else if (editTrigger) {
            openExisting(editTrigger, 'push');
        } else if (event.target.closest('[data-absence-period-close]')) {
            closeModal();
        }
    });

    form.addEventListener('input', function (event) {
        if (event.target === confirmField) {
            saveButton.disabled = saveButton.dataset.canManage === '0' || !previewValid || !confirmField.checked;
            return;
        }
        invalidatePreview();
    });
    form.addEventListener('change', function (event) {
        if (event.target.name === 'entry_type') {
            syncReasons();
        }
        if (event.target !== confirmField) {
            invalidatePreview();
        }
    });
    previewButton.addEventListener('click', preview);

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!previewValid || saveButton.disabled) {
            return;
        }
        saveButton.disabled = true;
        setError('');
        try {
            var periodId = form.querySelector('[name="period_id"]').value;
            var url = periodId ? '/admin/absence-periods/' + encodeURIComponent(periodId) : modal.dataset.createAction;
            await requestJson(url, periodId ? 'PUT' : 'POST', payload());
            clearDeepLink();
            window.location.reload();
        } catch (error) {
            setError(error.message);
            saveButton.disabled = false;
        }
    });

    archiveButton.addEventListener('click', async function () {
        var periodId = form.querySelector('[name="period_id"]').value;
        var reason = form.querySelector('[name="change_reason"]').value.trim();
        if (!periodId || !reason) {
            setError('Bitte eine fachliche Begruendung fuer die Archivierung angeben.');
            return;
        }
        if (!window.confirm('Soll der gesamte Abwesenheitszeitraum mit allen Tagesbuchungen wirklich archiviert werden?')) {
            return;
        }
        archiveButton.disabled = true;
        try {
            await requestJson('/admin/absence-periods/' + encodeURIComponent(periodId) + '/archive', 'DELETE', payload());
            clearDeepLink();
            window.location.reload();
        } catch (error) {
            setError(error.message);
            archiveButton.disabled = false;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
            return;
        }
        if (event.key === 'Tab' && !modal.hidden) {
            var focusable = Array.prototype.filter.call(
                modal.querySelectorAll('button:not([hidden]):not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]'),
                function (element) {
                    return element.offsetParent !== null;
                }
            );
            if (!focusable.length) {
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
        }
    });

    syncReasons();
    var initialPeriodId = new URL(window.location.href).searchParams.get('absence_period_id');
    if (initialPeriodId && /^\d+$/.test(initialPeriodId)) {
        openExisting({ dataset: { absencePeriodEdit: initialPeriodId } }, 'initial');
    }
    window.addEventListener('popstate', function () {
        var periodId = new URL(window.location.href).searchParams.get('absence_period_id');
        if (periodId && /^\d+$/.test(periodId)) {
            openExisting({ dataset: { absencePeriodEdit: periodId } }, 'history');
        } else if (!modal.hidden) {
            closeModal(true);
        }
    });
}());
