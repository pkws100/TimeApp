(function () {
    document.querySelectorAll('[data-worktime-form]').forEach(function (form) {
        const save = form.querySelector('[data-worktime-save]');
        const output = form.querySelector('[data-worktime-result]');
        const token = form.querySelector('[name="preview_token"]');
        const overrides = form.querySelector('[data-worktime-overrides]');
        let generation = 0;
        function invalidate() {
            generation += 1;
            token.value = '';
            save.disabled = true;
            output.textContent = 'Bitte das Beispiel nach Änderungen erneut prüfen.';
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
                ['early_check_in_mode', 'checkout_delay_minutes'].forEach((key) => {
                    if (policy[key] !== undefined) form.elements[key].value = policy[key];
                });
                ['checkout_proposals_enabled', 'notify_employee_push', 'notify_employee_sms', 'notify_supervisor_push', 'notify_supervisor_sms'].forEach((key) => {
                    form.elements[key].checked = !!policy[key];
                });
            });
        }
        form.querySelector('[data-worktime-preview]').addEventListener('click', async function () {
            const current = ++generation;
            save.disabled = true;
            output.textContent = 'Beispiel wird geprüft …';
            try {
                const response = await fetch('/admin/work-time/preview', { method: 'POST', body: new FormData(form) });
                const result = await response.json();
                if (current !== generation) return;
                if (!response.ok || !result.ok) throw new Error(result.message || 'Prüfung fehlgeschlagen.');
                const data = result.data;
                const minutes = (value) => value === null || value === undefined ? 'klärungsbedürftig' : value + ' Min.';
                output.textContent = 'Tatsächliche Arbeit: ' + minutes(data.actual_work_minutes)
                    + ' · Im Fenster: ' + minutes(data.inside_window_minutes)
                    + ' · Davor: ' + minutes(data.outside_before_minutes)
                    + ' · Danach: ' + minutes(data.outside_after_minutes)
                    + ' · Offene Außenzeit: ' + minutes(data.outside_pending_minutes)
                    + ' · Vorläufige Anrechnung: ' + minutes(data.accountable_work_minutes)
                    + ' · Status: ' + data.calculation_status;
                token.value = result.preview_token;
                save.disabled = false;
            } catch (error) {
                if (current !== generation) return;
                output.textContent = error.message;
                token.value = '';
            }
        });
    });
}());
