(function () {
    const editor = document.querySelector('[data-pause-edit-template]');
    editor?.addEventListener('change', function () {
        const fields = editor.form.elements;
        const raw = editor.selectedOptions[0]?.dataset.pauseTemplatePolicy;
        const policy = raw ? JSON.parse(raw) : null;
        fields.name.required = !policy;
        fields.capture_mode.value = policy?.capture_mode ?? 'stamped';
        fields.minimum_over_six_minutes.value = policy?.minimum_over_six_minutes ?? 30;
        fields.minimum_over_nine_minutes.value = policy?.minimum_over_nine_minutes ?? 45;
        fields.additional_minutes.value = policy?.additional_minutes ?? 0;
        fields.planned_windows.value = (policy?.planned_windows ?? []).map((window) => window.start + '-' + window.end).join(', ');
    });

    document.querySelectorAll('[data-pause-model-mutation], [data-pause-model-preview]').forEach(function (form) {
        const preview = form.hasAttribute('data-pause-model-preview');
        const output = form.querySelector('[data-pause-model-output]');
        const button = form.querySelector('button[type="submit"]');
        let active = false;

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (active) return;
            active = true;
            button.setAttribute('aria-disabled', 'true');
            output.textContent = preview ? 'Beispiel wird geprüft …' : 'Pausenregel wird gespeichert …';
            const data = new FormData(form);
            const submitted = new URLSearchParams(data).toString();
            try {
                const response = await fetch(form.action, {
                    method: 'POST', headers: { Accept: 'application/json' }, body: data,
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'Die Anfrage konnte nicht abgeschlossen werden.');
                if (submitted !== new URLSearchParams(new FormData(form)).toString()) {
                    output.textContent = preview ? 'Bitte die geänderten Beispielwerte erneut prüfen.' : 'Die gesendeten Werte wurden gespeichert. Die neuen Eingaben stehen noch im Formular.';
                } else if (preview) {
                    output.textContent = description(result.data);
                } else {
                    const location = new URL(result.location, window.location.href);
                    if (location.pathname === window.location.pathname && location.search === window.location.search) {
                        window.history.replaceState(null, '', location.href);
                        window.location.reload();
                    } else {
                        window.location.assign(location.href);
                    }
                }
            } catch (error) {
                output.textContent = error.message;
            } finally {
                active = false;
                button.removeAttribute('aria-disabled');
            }
        });
    });

    function description(data) {
        const minutes = (value) => value == null ? 'klärungsbedürftig' : value + ' Min.';
        const status = { resolved: 'geprüft', needs_clarification: 'klärungsbedürftig', pending: 'vorläufig' };
        return 'Tatsächliche Arbeit ' + minutes(data.actual_work_minutes)
            + ' · Dokumentierte Pause ' + minutes(data.documented_break_minutes)
            + ' · Anerkannte Ruhepausenabschnitte ' + minutes(data.qualifying_break_minutes)
            + ' · Pflichtpause ' + minutes(data.required_break_minutes)
            + ' · Fehlende Pause ' + minutes(data.missing_break_minutes)
            + ' · Längster Arbeitsabschnitt ' + minutes(data.longest_uninterrupted_work_minutes)
            + ' · ' + (status[data.calculation_status] ?? 'vorläufig');
    }
}());
