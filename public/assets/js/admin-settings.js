function classifySettingsField(field) {
    const control = field.querySelector('input, select, textarea');

    if (!control) {
        return 'optional';
    }

    const isRequired = field.dataset.required === 'true' || control.required;
    const kind = field.dataset.fieldKind ?? control.type ?? control.tagName.toLowerCase();

    if (kind === 'file') {
        const hasExistingFile = field.dataset.hasExistingFile === '1';
        const hasSelectedFile = control.files && control.files.length > 0;

        if (hasExistingFile || hasSelectedFile) {
            return 'complete';
        }

        return isRequired ? 'missing' : 'optional';
    }

    if (control.type === 'checkbox') {
        return control.checked ? 'complete' : 'optional';
    }

    const value = (control.value ?? '').trim();

    if (value === '') {
        return isRequired ? 'missing' : 'optional';
    }

    if (control.type === 'email' && typeof control.checkValidity === 'function' && !control.checkValidity()) {
        return 'invalid';
    }

    if (control.type === 'number' && typeof control.checkValidity === 'function' && !control.checkValidity()) {
        return 'invalid';
    }

    return 'complete';
}

function stateLabel(state) {
    const labels = {
        complete: 'OK',
        optional: 'Optional offen',
        missing: 'Fehlt',
        invalid: 'Ungueltig'
    };

    return labels[state] ?? 'Offen';
}

function stateClass(state) {
    const classes = {
        complete: 'is-complete',
        optional: 'is-optional',
        missing: 'is-missing',
        invalid: 'is-invalid'
    };

    return classes[state] ?? 'is-optional';
}

function renderFieldState(field, state) {
    field.classList.remove('is-complete', 'is-optional', 'is-missing', 'is-invalid');
    field.classList.add(stateClass(state));
    field.dataset.state = state;

    let pill = field.querySelector('[data-settings-state-pill]');

    if (!pill) {
        pill = document.createElement('small');
        pill.className = 'settings-state-pill';
        pill.setAttribute('data-settings-state-pill', 'true');
        field.insertBefore(pill, field.firstChild);
    }

    pill.textContent = stateLabel(state);
}

function updateSectionState(section) {
    const fields = Array.from(section.querySelectorAll('[data-settings-field]'));
    const badge = section.querySelector('[data-settings-section-badge]');

    if (!badge || fields.length === 0) {
        return;
    }

    const states = fields.map((field) => field.dataset.state);
    let label = 'Vollstaendig';
    let badgeClass = 'ok';

    if (states.includes('missing') || states.includes('invalid')) {
        label = 'Handlungsbedarf';
        badgeClass = 'warn';
    } else if (states.includes('optional')) {
        label = 'Teilweise offen';
        badgeClass = 'warn';
    }

    badge.className = 'badge ' + badgeClass;
    badge.textContent = label;
}

function updateSettingsSummary() {
    const fields = Array.from(document.querySelectorAll('[data-settings-field]'));
    const counts = {
        complete: 0,
        optional: 0,
        missing: 0,
        invalid: 0
    };

    fields.forEach((field) => {
        const state = classifySettingsField(field);
        renderFieldState(field, state);
        counts[state] += 1;
    });

    document.querySelectorAll('[data-settings-count]').forEach((node) => {
        const key = node.dataset.settingsCount;
        node.textContent = String(counts[key] ?? 0);
    });

    const overall = document.querySelector('[data-settings-overall]');
    const overallText = document.querySelector('[data-settings-overall-text]');

    if (overall && overallText) {
        let label = 'Vollstaendig';
        let text = 'Alle markierten Felder sind aktuell sauber ausgefuellt.';
        let badgeClass = 'ok';

        if (counts.invalid > 0 || counts.missing > 0) {
            label = 'Bitte pruefen';
            text = 'Es gibt fehlende Pflichtangaben oder ungueltige Werte.';
            badgeClass = 'warn';
        } else if (counts.optional > 0) {
            label = 'Fast komplett';
            text = 'Pflichtfelder sind gesetzt, optionale Angaben koennen noch ergaenzt werden.';
            badgeClass = 'warn';
        }

        overall.className = 'badge ' + badgeClass;
        overall.textContent = label;
        overallText.textContent = text;
    }

    document.querySelectorAll('.card').forEach((section) => updateSectionState(section));
}

function bootSettingsInspector() {
    const summary = document.querySelector('[data-settings-summary]');

    if (!summary) {
        return;
    }

    const refresh = () => updateSettingsSummary();

    document.addEventListener('input', refresh);
    document.addEventListener('change', refresh);
    refresh();
}

function bootLogoUploadForm() {
    document.querySelectorAll('[data-logo-upload-form], [data-file-upload-form]').forEach((form) => {
        const input = form.querySelector('[data-logo-upload-input], [data-file-upload-input]');

        if (!input) {
            return;
        }

        input.addEventListener('change', function () {
            if (input.files && input.files.length > 0) {
                form.submit();
            }
        });
    });
}

bootSettingsInspector();
bootLogoUploadForm();
