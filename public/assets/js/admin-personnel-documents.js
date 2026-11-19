(() => {
    'use strict';

    const records = document.querySelector('[data-personnel-records]');
    if (!records) {
        return;
    }
    const canonicalUrl = records.dataset.canonicalUrl;
    const revalidationSearch = '?revalidate=1';
    if (window.location.pathname === canonicalUrl && window.location.search === revalidationSearch) {
        window.history.replaceState(null, '', canonicalUrl);
    }

    // Avoid redisplaying an old, authorized representation after a later scope change.
    window.addEventListener('pagehide', (event) => {
        if (event.persisted) {
            records.hidden = true;
        }
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            records.hidden = true;
            window.location.replace(canonicalUrl + revalidationSearch);
        }
    });
    records.querySelector('[data-personnel-error]')?.focus({ preventScroll: true });

    records.querySelectorAll('[data-personnel-assignment]').forEach((form) => {
        const types = form.elements.namedItem('document_type_id');
        const rules = form.elements.namedItem('document_requirement_id');
        const updateRuleChoices = () => {
            for (const option of rules.querySelectorAll('[data-personnel-rule-type]')) {
                option.disabled = option.dataset.personnelRuleType !== types.value;
                option.hidden = option.disabled;
            }
            if (rules.selectedOptions[0]?.disabled) {
                rules.value = '';
            }
        };
        types.addEventListener('change', updateRuleChoices);
        updateRuleChoices();
    });

    records.querySelectorAll('[data-personnel-evidence]').forEach((form) => {
        const outcome = form.elements.namedItem('outcome');
        const reason = form.elements.namedItem('reason');
        const checklist = form.querySelector('[data-personnel-checklist]');
        const updateEvidenceChoices = () => {
            for (const group of reason.querySelectorAll('[data-personnel-evidence-outcome]')) {
                group.disabled = group.dataset.personnelEvidenceOutcome !== outcome.value;
                group.hidden = group.disabled;
            }
            if (reason.selectedOptions[0]?.closest('optgroup')?.disabled) {
                reason.value = '';
            }
            const confirmed = outcome.value === 'confirmed_internal';
            checklist.hidden = !confirmed;
            for (const input of checklist.querySelectorAll('input')) {
                input.disabled = !confirmed;
                input.required = confirmed;
            }
        };
        outcome.addEventListener('change', updateEvidenceChoices);
        updateEvidenceChoices();
    });

    const form = records.querySelector('[data-personnel-upload]');
    if (!form) {
        return;
    }

    const fileInput = form.elements.namedItem('file');
    const submit = form.querySelector('button[type="submit"]');
    const description = form.querySelector('[data-personnel-file-description]');
    const status = form.querySelector('[data-personnel-upload-status]');
    const login = form.querySelector('[data-personnel-upload-login]');
    const progress = form.querySelector('[data-personnel-upload-progress]');
    const reloadButton = form.querySelector('[data-personnel-reload-versions]');
    const maxBytes = 16 * 1024 * 1024;
    const formValues = (otherForm) => JSON.stringify(Array.from(new FormData(otherForm)));
    const otherForms = Array.from(records.querySelectorAll('form'))
        .filter((otherForm) => otherForm !== form && otherForm.elements.namedItem('request_id'))
        .map((otherForm) => ({ form: otherForm, initial: formValues(otherForm) }));

    let inProgress = false;
    let pending = null;

    function announce(message, tone = '', focus = false) {
        status.hidden = false;
        status.className = tone === '' ? '' : `notice ${tone}`;
        status.textContent = message;
        if (focus) {
            status.focus({ preventScroll: true });
        }
    }

    async function inspectFile(file) {
        if (!file) {
            return;
        }
        const size = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 }).format(file.size / 1024);
        let label = 'Unbekannter Dateityp';
        try {
            const signature = new Uint8Array(await file.slice(0, 8).arrayBuffer());
            if ([0x25, 0x50, 0x44, 0x46, 0x2d].every((byte, index) => signature[index] === byte)) {
                label = 'PDF';
            } else if ([0xff, 0xd8, 0xff].every((byte, index) => signature[index] === byte)) {
                label = 'JPEG';
            } else if ([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a].every((byte, index) => signature[index] === byte)) {
                label = 'PNG';
            }
        } catch {
            label = 'Dateityp wird beim Upload geprüft';
        }
        if (fileInput.files[0] === file) {
            description.textContent = `${label} · ${size} KiB`;
        }
    }

    fileInput.addEventListener('change', () => {
        fileInput.setCustomValidity('');
        description.textContent = '';
        status.hidden = true;
        const file = fileInput.files[0];
        if (file && (file.size === 0 || file.size > maxBytes)) {
            fileInput.setCustomValidity('Wählen Sie eine nicht leere Datei mit höchstens 16 MiB.');
            announce('Die Datei muss mindestens 1 Byte und höchstens 16 MiB groß sein.', 'error');
        }
        void inspectFile(file);
    });

    function allowRetry(message) {
        inProgress = false;
        progress.hidden = true;
        submit.disabled = false;
        submit.textContent = 'Denselben Upload wiederholen';
        announce(message, 'warn', true);
    }

    function clearAttempt() {
        inProgress = false;
        pending = null;
        progress.hidden = true;
        fileInput.disabled = false;
        submit.disabled = false;
        submit.textContent = 'Datei hochladen';
    }

    function refreshVersions() {
        if (window.location.pathname === canonicalUrl && window.location.search === '') {
            window.history.replaceState(null, '', `${canonicalUrl}#versionen`);
            window.location.reload();
            return;
        }
        window.location.replace(`${canonicalUrl}#versionen`);
    }

    function hasUnsavedChanges() {
        const active = records.dataset.activeForm;
        return (active !== '' && active !== 'upload') || otherForms.some((entry) => formValues(entry.form) !== entry.initial);
    }

    reloadButton.addEventListener('click', () => {
        if (!hasUnsavedChanges() || window.confirm('Offene Eingaben werden beim Aktualisieren verworfen. Möchten Sie fortfahren?')) {
            refreshVersions();
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (inProgress || submit.disabled || (pending === null && !fileInput.files[0])) {
            return;
        }

        // Keep the exact file, predecessor and request identifier until an ambiguous result is resolved.
        pending ??= new FormData(form);
        inProgress = true;
        fileInput.disabled = true;
        submit.disabled = true;
        login.hidden = true;
        progress.hidden = false;
        progress.value = 0;
        announce('Datei wird übertragen …');

        const request = new XMLHttpRequest();
        request.open('POST', form.action);
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-CSRF-Token', String(pending.get('csrf_token')));
        request.timeout = 90000;
        request.upload.addEventListener('progress', (upload) => {
            if (upload.lengthComputable && upload.total > 0) {
                progress.value = 100 * upload.loaded / upload.total;
            }
        });
        request.upload.addEventListener('load', () => {
            progress.removeAttribute('value');
            announce('Format, Schadsoftware und Ablage werden geprüft …');
        });
        const uncertain = () => allowRetry('Die Antwort ist ausgeblieben. Sie können denselben Upload mit derselben Datei wiederholen.');
        request.addEventListener('error', uncertain);
        request.addEventListener('timeout', uncertain);
        request.addEventListener('abort', uncertain);
        request.addEventListener('load', () => {
            let answer = null;
            if (request.getResponseHeader('Content-Type')?.startsWith('application/json')) {
                try {
                    answer = JSON.parse(request.responseText);
                } catch {
                    // The response did not confirm the state of the request.
                }
            }
            if (request.status >= 200 && request.status < 300 && answer?.ok === true) {
                if (hasUnsavedChanges()) {
                    inProgress = false;
                    pending = null;
                    progress.hidden = true;
                    reloadButton.hidden = false;
                    announce('Dateiversion gespeichert. Offene Eingaben auf dieser Seite bleiben erhalten.', 'success', true);
                } else {
                    announce('Dateiversion gespeichert.', 'success');
                    refreshVersions();
                }
                return;
            }
            const responsePath = request.responseURL ? new URL(request.responseURL).pathname : '';
            if (request.status === 401 || responsePath === '/admin/login') {
                inProgress = false;
                pending = null;
                progress.hidden = true;
                submit.textContent = 'Datei hochladen';
                announce('Die Sitzung ist abgelaufen. Melden Sie sich erneut an, öffnen Sie die Akte und prüfen Sie die vorhandenen Versionen vor einem neuen Upload.', 'warn', true);
                login.hidden = false;
                return;
            }
            const message = typeof answer?.message === 'string' ? answer.message : 'Die Datei konnte nicht sicher gespeichert werden.';
            if (request.status === 0 || request.status >= 500 || (request.status < 300 && answer === null)) {
                allowRetry(`${message} Sie können denselben Upload wiederholen.`);
                return;
            }
            clearAttempt();
            announce(message, 'error', true);
        });
        try {
            request.send(pending);
        } catch {
            uncertain();
        }
    });
})();
