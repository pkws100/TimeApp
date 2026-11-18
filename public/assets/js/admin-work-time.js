(function () {
    document.querySelectorAll('[data-worktime-form]').forEach(function (form) {
        const save = form.querySelector('[data-worktime-save]');
        const output = form.querySelector('[data-worktime-result]');
        const token = form.querySelector('[name="preview_token"]');
        const overrides = form.querySelector('[data-worktime-overrides]');
        const confirm = form.querySelector('[data-worktime-confirm]');
        const replace = form.querySelector('[data-worktime-replace]');
        const approval = [confirm, replace].filter(Boolean);
        let generation = 0;
        function updateSave() {
            save.disabled = !token.value || approval.some((box) => !box.hidden && !box.querySelector('input').checked);
        }
        function clearApprovals() {
            approval.forEach(function (box) {
                box.hidden = true;
                box.querySelector('input').checked = false;
            });
        }
        function invalidate(event) {
            if (approval.some((box) => box.contains(event.target))) {
                updateSave();
                return;
            }
            generation += 1;
            token.value = '';
            clearApprovals();
            updateSave();
            output.textContent = confirm ? 'Bitte die Auswirkungen erneut prüfen.' : 'Bitte das Beispiel nach Änderungen erneut prüfen.';
        }
        form.addEventListener('input', invalidate);
        form.addEventListener('change', invalidate);
        if (overrides) {
            overrides.addEventListener('change', function () {
                const fields = form.querySelector('[data-worktime-override-fields]');
                fields.hidden = !overrides.checked;
                fields.disabled = !overrides.checked;
            });
        }
        const version = form.querySelector('[data-worktime-version]');
        if (version) {
            version.addEventListener('change', function () {
                const selected = version.selectedOptions[0];
                if (!selected || !selected.dataset.policy) return;
                const policy = JSON.parse(selected.dataset.policy);
                for (let day = 1; day <= 7; day += 1) {
                    form.elements['window_' + day].value = (policy.windows[day] || []).map((window) => window.start + '-' + window.end).join(', ');
                }
                ['early_check_in_mode', 'checkout_delay_minutes', 'outside_window_delay_minutes'].forEach((key) => {
                    if (policy[key] !== undefined) form.elements[key].value = policy[key];
                });
                ['checkout_proposals_enabled', 'outside_window_notifications_enabled', 'notify_employee_push', 'notify_employee_sms', 'notify_supervisor_push', 'notify_supervisor_sms'].forEach((key) => {
                    form.elements[key].checked = !!policy[key];
                });
            });
        }
        if (confirm) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (save.disabled) return;
                const current = ++generation;
                const data = new FormData(form);
                token.value = '';
                updateSave();
                try {
                    const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: data });
                    const result = await response.json().catch(() => null);
                    if (response.ok && result && result.ok) {
                        window.location.assign(result.location);
                        return;
                    }
                    throw new Error(result?.message || 'Die Zuordnung konnte nicht gespeichert werden.');
                } catch (error) {
                    if (current !== generation) return;
                    clearApprovals();
                    updateSave();
                    output.textContent = error.message;
                }
            });
        }
        form.querySelector('[data-worktime-preview]').addEventListener('click', async function () {
            if (confirm && !form.reportValidity()) return;
            const current = ++generation;
            token.value = '';
            clearApprovals();
            updateSave();
            output.textContent = confirm ? 'Auswirkungen werden geprüft …' : 'Beispiel wird geprüft …';
            try {
                const response = await fetch('/admin/work-time/preview', { method: 'POST', body: new FormData(form) });
                const result = await response.json();
                if (current !== generation) return;
                if (!response.ok || !result.ok) throw new Error(result.message || 'Prüfung fehlgeschlagen.');
                const data = result.data;
                if (confirm) {
                    drawImpact(output, data);
                    confirm.hidden = !data.historical;
                    if (replace) replace.hidden = !data.overlaps.length;
                } else {
                    output.textContent = 'Tatsächliche Arbeit: ' + minutes(data.actual_work_minutes)
                        + ' · Im Fenster: ' + minutes(data.inside_window_minutes)
                        + ' · Davor: ' + minutes(data.outside_before_minutes)
                        + ' · Danach: ' + minutes(data.outside_after_minutes)
                        + ' · Offene Außenzeit: ' + minutes(data.outside_pending_minutes)
                        + ' · Vorläufige Anrechnung: ' + minutes(data.accountable_work_minutes)
                        + ' · Status: ' + data.calculation_status;
                }
                token.value = result.preview_token;
                updateSave();
            } catch (error) {
                if (current !== generation) return;
                output.textContent = error.message;
                token.value = '';
            }
        });
    });

    function minutes(value) {
        return value === null || value === undefined ? 'klärungsbedürftig' : value + ' Min.';
    }

    function drawImpact(output, data) {
        output.replaceChildren();
        const summary = document.createElement('p');
        summary.textContent = 'Vorläufige Auswirkungsvorschau. ' + data.booking_count + ' Buchungen an '
            + data.workday_count + ' Arbeitstagen. ' + data.closed_workday_count + ' davon festgeschrieben ('
            + data.closing_count + ' Abschlüsse im Zeitraum). Vergleich zur bisherigen Anrechnung: '
            + minutes(data.accountable_delta_minutes) + '.';
        output.append(summary);
        if (data.overlaps.length) {
            const conflicts = document.createElement('ul');
            data.overlaps.forEach(function (item) {
                const row = document.createElement('li');
                row.textContent = 'Überschneidung mit Regelversion #' + item.policy_version_id + ' vom '
                    + item.effective_from + ' bis ' + (item.effective_to || 'offen') + '.';
                conflicts.append(row);
            });
            output.append(conflicts);
        }
        if (!data.rows.length) return;
        const details = document.createElement('details');
        const heading = document.createElement('summary');
        heading.textContent = data.rows.length < data.booking_count ? 'Erste ' + data.rows.length + ' betroffene Buchungen' : 'Betroffene Buchungen';
        details.append(heading);
        const chronicle = document.createElement('ul');
        data.rows.forEach(function (row) {
            const item = document.createElement('li');
            const next = row.after;
            const title = document.createElement('strong');
            title.textContent = row.work_date + ' · Buchung #' + row.id + (row.closures.length ? ' · festgeschrieben (' + row.closures.join(', ') + ')' : '');
            item.append(title);
            const comparison = document.createElement('p');
            const outside = [next.outside_before_minutes, next.outside_after_minutes, next.outside_gap_minutes];
            const outsideTotal = outside.every(Number.isFinite) ? outside.reduce((sum, value) => sum + value, 0) : null;
            comparison.textContent = 'Dokumentierte Nettozeit ' + minutes(row.recorded_net_minutes)
                + ' · Tatsächliche Arbeit ' + minutes(next.actual_work_minutes)
                + ' · Im Fenster ' + minutes(next.inside_window_minutes)
                + ' · Außerhalb ' + minutes(outsideTotal)
                + ' · Genehmigt ' + minutes(next.outside_approved_minutes)
                + ' · Offen ' + minutes(next.outside_pending_minutes)
                + ' · Vorläufig anrechenbar bisher ' + minutes(row.before.accountable_work_minutes)
                + ', mit Änderung ' + minutes(next.accountable_work_minutes)
                + ' · Differenz ' + minutes(row.delta_minutes) + '.';
            item.append(comparison);
            const breaks = document.createElement('p');
            const intervals = row.documented_breaks.map((entry) => entry.break_started_at + ' – ' + (entry.break_ended_at || 'offen'));
            breaks.textContent = 'Pausenintervalle ' + (intervals.length ? intervals.join(', ') : 'keine')
                + (row.duration_break_minutes ? ' · Dokumentierte Pause ohne Uhrzeit ' + minutes(row.duration_break_minutes) : '')
                + ' · Gespeicherte Pausendauer ' + minutes(row.recorded_break_minutes)
                + ' · Ungeklärte Pausenlage ' + minutes(next.unplaced_break_minutes) + '.';
            item.append(breaks);
            chronicle.append(item);
        });
        details.append(chronicle);
        output.append(details);
    }

    document.querySelectorAll('[data-pause-evidence-form]').forEach(function (form) {
        const list = form.querySelector('[data-pause-intervals]');
        const first = list.querySelector('[data-pause-interval]');
        const refresh = () => {
            const rows = list.querySelectorAll('[data-pause-interval]');
            rows.forEach((row) => { row.querySelector('[data-pause-remove]').hidden = rows.length === 1; });
            form.querySelector('[data-pause-add]').disabled = rows.length >= 12;
        };
        form.querySelector('[data-pause-add]').addEventListener('click', function () {
            if (list.querySelectorAll('[data-pause-interval]').length >= 12) return;
            const row = first.cloneNode(true);
            row.querySelectorAll('input').forEach((input) => { input.value = ''; });
            list.appendChild(row);
            refresh();
        });
        list.addEventListener('click', function (event) {
            const remove = event.target.closest('[data-pause-remove]');
            if (remove && list.querySelectorAll('[data-pause-interval]').length > 1) {
                remove.closest('[data-pause-interval]').remove();
                refresh();
            }
        });
        form.addEventListener('submit', function () {
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            button.textContent = 'Nachweis wird gespeichert …';
        });
    });
}());
