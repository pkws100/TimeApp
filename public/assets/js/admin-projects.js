(function () {
    function formSnapshot(form) {
        return JSON.stringify(Array.from(new FormData(form).entries())
            .filter(function (entry) {
                return entry[0] !== 'csrf_token';
            })
            .map(function (entry) { return [entry[0], String(entry[1])]; })
            .sort(function (left, right) { return left[0].localeCompare(right[0]); }));
    }

    function initProjectPage() {
        const projectForm = document.querySelector('[data-project-master-form]');
        const dispatchForm = document.querySelector('[data-project-dispatch-form]');
        const dispatchButton = document.querySelector('[data-project-dispatch-button]');
        const unsavedNotice = document.querySelector('[data-project-unsaved-notice]');
        const instructions = document.getElementById('work_instructions');
        const counter = document.getElementById('work-instructions-count');

        if (instructions && counter) {
            const updateCount = function () {
                counter.textContent = String(instructions.value.length);
            };

            instructions.addEventListener('input', updateCount);
            updateCount();
        }

        if (!projectForm || projectForm.dataset.projectGuardReady === '1') {
            return;
        }

        projectForm.dataset.projectGuardReady = '1';
        let initialSnapshot = formSnapshot(projectForm);
        let invalidSubmission = projectForm.dataset.projectMasterInvalid === '1';
        const initiallyDisabled = dispatchButton ? dispatchButton.disabled : false;
        const updatesExistingProject = /^\/admin\/projects\/\d+$/.test(new URL(projectForm.action, window.location.href).pathname);
        const masterButtons = Array.from(projectForm.querySelectorAll('button[type="submit"], input[type="submit"]'));
        const masterDisabledStates = masterButtons.map(function (button) { return button.disabled; });
        let dirty = false;
        let saving = false;
        let navigationAllowed = false;
        let bypassSubmit = null;
        let discardAbort = null;
        let pendingAction = null;
        let lastField = null;
        let lastView = null;
        let guardView = null;
        let errorBox = document.querySelector('[data-project-master-errors]');

        if (!errorBox) {
            errorBox = document.createElement('div');
            errorBox.setAttribute('data-project-master-errors', '');
            projectForm.before(errorBox);
        }

        const guard = document.createElement('div');
        guard.className = 'admin-modal';
        guard.hidden = true;
        guard.setAttribute('data-project-unsaved-dialog', '');
        guard.setAttribute('role', 'dialog');
        guard.setAttribute('aria-modal', 'true');
        guard.setAttribute('aria-labelledby', 'project-unsaved-title');
        guard.innerHTML = '<div class="admin-modal__overlay" data-project-guard-cancel></div>'
            + '<section class="admin-modal__dialog stack"><h2 id="project-unsaved-title">Ungespeicherte Projektstammdaten</h2>'
            + '<p>Ihre Eingaben sind noch nicht gespeichert. Speichern Sie zuerst und fuehren Sie die andere Aktion danach erneut aus, oder verwerfen Sie die Aenderungen ausdruecklich.</p>'
            + '<p class="notice error" role="alert" data-project-guard-error hidden></p>'
            + '<div class="toolbar-actions"><button type="button" class="button" data-project-guard-save>Speichern und hier bleiben</button>'
            + '<button type="button" class="button button-danger" data-project-guard-discard>Aenderungen verwerfen und fortfahren</button>'
            + '<button type="button" class="button button-secondary" data-project-guard-cancel>Weiter bearbeiten</button></div></section>';
        document.body.appendChild(guard);

        function fieldView(field) {
            return field ? {
                name: field.name, start: field.selectionStart, end: field.selectionEnd,
                direction: field.selectionDirection, top: field.scrollTop, left: field.scrollLeft
            } : null;
        }

        function captureView() {
            return { x: window.scrollX, y: window.scrollY, field: fieldView(lastField) };
        }

        function restoreView(view) {
            if (!view) { return; }
            const field = view.field && projectForm.elements.namedItem(view.field.name);
            if (field instanceof HTMLElement) {
                field.focus({ preventScroll: true });
                if (typeof field.setSelectionRange === 'function' && Number.isInteger(view.field.start)) {
                    try { field.setSelectionRange(view.field.start, view.field.end, view.field.direction || 'none'); } catch (error) { /* Non-text controls have no selection. */ }
                }
                field.scrollTop = view.field.top || 0;
                field.scrollLeft = view.field.left || 0;
                lastField = field;
            }
            window.scrollTo(view.x || 0, view.y || 0);
            lastView = view;
        }

        function viewKey(action) {
            const path = new URL(action, window.location.href).pathname.replace(/\/edit$/, '');
            return 'timeapp:project-master-view:' + (projectForm.dataset.projectActorId || '0') + ':' + path;
        }

        function rememberView(action) {
            try {
                sessionStorage.setItem(viewKey(action), JSON.stringify({ savedAt: Date.now(), view: lastView || captureView() }));
            } catch (error) { /* Position restoration is optional; form values are never stored here. */ }
        }

        function restoreSavedView() {
            try {
                const key = viewKey(projectForm.action);
                const stored = sessionStorage.getItem(key);
                if (!stored) { return; }
                sessionStorage.removeItem(key);
                const data = JSON.parse(stored);
                if (Date.now() - data.savedAt > 300000 || !['updated', 'created'].includes(new URLSearchParams(window.location.search).get('notice'))) { return; }
                window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { restoreView(data.view); }); });
            } catch (error) { /* No project content is persisted in the view metadata. */ }
        }

        function showNotice(message, success) {
            errorBox.replaceChildren();
            const notice = document.createElement('div');
            notice.className = success ? 'notice success' : 'notice error';
            notice.setAttribute('role', success ? 'status' : 'alert');
            notice.tabIndex = -1;
            notice.textContent = message;
            errorBox.appendChild(notice);
            return notice;
        }

        function closeGuard(restore) {
            if (discardAbort) { discardAbort.abort(); discardAbort = null; }
            guard.hidden = true;
            pendingAction = null;
            document.body.classList.toggle('modal-open', !!document.querySelector('.admin-modal:not([hidden])'));
            if (restore) { restoreView(guardView); }
        }

        function askBeforeLeaving(action) {
            if (saving) {
                showNotice('Die Stammdaten werden gerade verarbeitet. Bitte den Abschluss abwarten.', false).focus();
                return;
            }
            pendingAction = action;
            guard.querySelector('[data-project-guard-error]').hidden = true;
            guardView = lastView || captureView();
            guard.hidden = false;
            document.body.classList.add('modal-open');
            guard.querySelector('[data-project-guard-cancel].button').focus();
        }

        const updateDirtyState = function () {
            dirty = invalidSubmission || formSnapshot(projectForm) !== initialSnapshot;
            if (dispatchButton) { dispatchButton.disabled = initiallyDisabled || dirty || saving; }

            if (unsavedNotice) {
                unsavedNotice.hidden = !dirty;
            }
        };

        function captureEditing(event) {
            const field = event.target;
            if (field.form === projectForm && !field.matches('button, input[type="hidden"], input[type="submit"]')) {
                lastField = field;
                lastView = captureView();
            }
        }

        projectForm.addEventListener('focusin', captureEditing);
        projectForm.addEventListener('input', function (event) { captureEditing(event); updateDirtyState(); });
        // A text field emits change on blur, after the browser may already have
        // scrolled to a foreign action. It updates dirty state, not edit position.
        projectForm.addEventListener('change', updateDirtyState);
        projectForm.addEventListener('keyup', captureEditing);
        projectForm.addEventListener('mouseup', captureEditing);
        function captureUserScroll() {
            window.requestAnimationFrame(function () {
                if (document.activeElement === lastField) { lastView = captureView(); }
            });
        }
        // A browser can scroll to a clicked foreign button before moving focus
        // out of the textarea. Do not mistake that navigation for an edit view.
        window.addEventListener('wheel', captureUserScroll, { passive: true });
        window.addEventListener('touchend', captureUserScroll, { passive: true });

        function applyValidation(incoming) {
            const incomingForm = incoming.querySelector('[data-project-master-form]');
            const incomingErrors = incoming.querySelector('[data-project-master-errors]');
            if (!incomingForm) { return false; }
            projectForm.querySelectorAll('.field-error').forEach(function (node) { node.remove(); });
            projectForm.querySelectorAll('.is-invalid').forEach(function (node) { node.classList.remove('is-invalid'); });
            projectForm.querySelectorAll('[aria-invalid]').forEach(function (node) {
                node.removeAttribute('aria-invalid'); node.removeAttribute('aria-describedby');
            });
            if (incomingErrors) { errorBox.innerHTML = incomingErrors.innerHTML; }
            else { showNotice('Die Stammdaten konnten nicht gespeichert werden. Bitte die markierten Eingaben pruefen.', false); }
            incomingForm.querySelectorAll('[name][aria-invalid="true"]').forEach(function (source) {
                const field = projectForm.elements.namedItem(source.name);
                if (!(field instanceof HTMLElement)) { return; }
                field.setAttribute('aria-invalid', 'true');
                const description = source.getAttribute('aria-describedby');
                const error = description && incoming.getElementById(description);
                if (error) {
                    field.setAttribute('aria-describedby', description);
                    const wrapper = field.closest('.form-field') || field.parentElement;
                    wrapper.classList.add('is-invalid');
                    wrapper.appendChild(error.cloneNode(true));
                }
            });
            const freshCsrf = incomingForm.elements.namedItem('csrf_token');
            const currentCsrf = projectForm.elements.namedItem('csrf_token');
            if (freshCsrf && currentCsrf) { currentCsrf.value = freshCsrf.value; }
            invalidSubmission = true;
            updateDirtyState();
            const firstError = projectForm.querySelector('[aria-invalid="true"]') || errorBox.querySelector('[role="alert"]');
            if (firstError) { firstError.focus(); }
            return true;
        }

        async function saveMaster() {
            if (!updatesExistingProject) {
                if (!saving) { projectForm.requestSubmit(); }
                return;
            }
            if (saving || !projectForm.reportValidity()) { return; }
            saving = true;
            const submittedSnapshot = formSnapshot(projectForm);
            const payload = new FormData(projectForm);
            const submitButtons = Array.from(projectForm.querySelectorAll('button[type="submit"], input[type="submit"]'));
            const disabledStates = submitButtons.map(function (button) { return button.disabled; });
            submitButtons.forEach(function (button) { button.disabled = true; });
            projectForm.setAttribute('aria-busy', 'true');
            updateDirtyState();
            try {
                const response = await fetch(projectForm.action, { method: 'POST', body: payload, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
                const destination = new URL(response.url, window.location.href);
                const success = response.ok && response.redirected && destination.origin === window.location.origin
                    && /^\/admin\/projects\/\d+\/edit$/.test(destination.pathname)
                    && ['updated', 'created'].includes(destination.searchParams.get('notice'));
                if (success) {
                    initialSnapshot = submittedSnapshot;
                    invalidSubmission = false;
                    if (formSnapshot(projectForm) !== submittedSnapshot) {
                        showNotice('Gespeichert. Ihre danach eingegebenen Aenderungen sind noch nicht gespeichert.', true);
                    } else {
                        rememberView(destination.href);
                        navigationAllowed = true;
                        window.location.assign(destination.href);
                    }
                } else if (response.status === 422) {
                    const incoming = new DOMParser().parseFromString(await response.text(), 'text/html');
                    if (!applyValidation(incoming)) { showNotice('Die Stammdaten konnten nicht gespeichert werden. Ihre Eingaben bleiben erhalten.', false).focus(); }
                } else if (destination.pathname === '/admin/login' || response.status === 401 || response.status === 403) {
                    const notice = showNotice('Die Sitzung oder Sicherheitspruefung ist abgelaufen. Ihre Eingaben bleiben hier erhalten. Bitte melden Sie sich in einem neuen Tab an und speichern Sie danach erneut.', false);
                    const link = document.createElement('a');
                    link.href = '/admin/login'; link.target = '_blank'; link.rel = 'noopener'; link.textContent = ' Anmeldung in neuem Tab oeffnen';
                    notice.appendChild(link); notice.focus();
                } else {
                    showNotice('Speichern konnte nicht bestaetigt werden. Ihre Eingaben bleiben erhalten. Bitte pruefen Sie den gespeicherten Stand, bevor Sie erneut speichern.', false).focus();
                }
            } catch (error) {
                showNotice('Speichern konnte nicht bestaetigt werden. Ihre Eingaben bleiben erhalten. Bitte pruefen Sie die Verbindung und den gespeicherten Stand.', false).focus();
            } finally {
                saving = false;
                submitButtons.forEach(function (button, index) { button.disabled = disabledStates[index]; });
                projectForm.removeAttribute('aria-busy');
                updateDirtyState();
            }
        }

        async function discardAndContinue() {
            if (saving || !pendingAction) { return; }
            const action = pendingAction;
            const buttons = Array.from(guard.querySelectorAll('[data-project-guard-save], [data-project-guard-discard]'));
            saving = true;
            discardAbort = new AbortController();
            buttons.forEach(function (button) { button.disabled = true; });
            try {
                const editUrl = new URL(projectForm.action, window.location.href);
                if (/^\/admin\/projects\/\d+$/.test(editUrl.pathname)) {
                    editUrl.pathname += '/edit';
                    const response = await fetch(editUrl.href, { credentials: 'same-origin', cache: 'no-store', signal: discardAbort.signal, headers: { Accept: 'text/html' } });
                    const incoming = new DOMParser().parseFromString(await response.text(), 'text/html');
                    const savedForm = incoming.querySelector('[data-project-master-form]');
                    if (!response.ok || !savedForm || !/^\/admin\/projects\/\d+\/edit$/.test(new URL(response.url).pathname)) {
                        throw new Error('Saved form unavailable.');
                    }
                    if (pendingAction !== action || guard.hidden) { return; }
                    Array.from(projectForm.elements).forEach(function (field) {
                        if (!field.name) { return; }
                        const saved = savedForm.elements.namedItem(field.name);
                        if (!saved) { return; }
                        if (field.matches('input[type="checkbox"], input[type="radio"]')) { field.checked = saved.checked; }
                        else { field.value = saved.value; }
                    });
                } else {
                    projectForm.reset();
                }
                invalidSubmission = false;
                initialSnapshot = formSnapshot(projectForm);
                errorBox.replaceChildren();
                projectForm.querySelectorAll('.field-error').forEach(function (node) { node.remove(); });
                projectForm.querySelectorAll('.is-invalid').forEach(function (node) { node.classList.remove('is-invalid'); });
                projectForm.querySelectorAll('[aria-invalid]').forEach(function (node) { node.removeAttribute('aria-invalid'); node.removeAttribute('aria-describedby'); });
                if (instructions) { instructions.dispatchEvent(new Event('input', { bubbles: true })); }
                discardAbort = null;
                closeGuard(false);
                saving = false;
                updateDirtyState();
                if (action.type === 'link') { navigationAllowed = true; window.location.assign(action.href); }
                else if (action.form.isConnected) {
                    bypassSubmit = action.form;
                    action.form.requestSubmit(action.submitter && action.submitter.isConnected ? action.submitter : undefined);
                    bypassSubmit = null;
                }
            } catch (error) {
                if (pendingAction !== action || guard.hidden) { return; }
                const notice = guard.querySelector('[data-project-guard-error]');
                notice.textContent = 'Der gespeicherte Stand konnte nicht geladen werden. Ihre Eingaben wurden nicht verworfen. Bitte pruefen Sie die Sitzung oder bearbeiten Sie weiter.';
                notice.hidden = false;
            } finally {
                discardAbort = null;
                saving = false;
                buttons.forEach(function (button) { button.disabled = false; });
                updateDirtyState();
            }
        }

        document.addEventListener('submit', function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) { return; }
            if (form === projectForm) {
                if (!updatesExistingProject) {
                    if (saving) { event.preventDefault(); event.stopImmediatePropagation(); return; }
                    // Preserve the existing create/redirect flow. A later save
                    // then edits that actual project, rather than creating again.
                    saving = true;
                    navigationAllowed = true;
                    projectForm.setAttribute('aria-busy', 'true');
                    queueMicrotask(function () {
                        if (event.defaultPrevented) { saving = false; navigationAllowed = false; projectForm.removeAttribute('aria-busy'); }
                    });
                    return;
                }
                event.preventDefault(); event.stopImmediatePropagation();
                saveMaster();
                return;
            }
            if (bypassSubmit === form) {
                bypassSubmit = null;
                navigationAllowed = true;
                queueMicrotask(function () { if (event.defaultPrevented) { navigationAllowed = false; } });
                return;
            }
            updateDirtyState();
            if (dirty || saving) {
                event.preventDefault(); event.stopImmediatePropagation();
                askBeforeLeaving({ type: 'submit', form: form, submitter: event.submitter });
            }
        }, true);

        document.addEventListener('click', function (event) {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
                || link.hasAttribute('download') || (link.target && link.target !== '_self')) { return; }
            const destination = new URL(link.href, window.location.href);
            if (!['http:', 'https:'].includes(destination.protocol)
                || (destination.origin === window.location.origin && destination.pathname === window.location.pathname
                    && destination.search === window.location.search && destination.hash)) { return; }
            updateDirtyState();
            if (dirty || saving) {
                event.preventDefault(); event.stopImmediatePropagation();
                askBeforeLeaving({ type: 'link', href: destination.href });
            }
        }, true);

        guard.addEventListener('click', function (event) {
            if (event.target.closest('[data-project-guard-cancel]')) { closeGuard(true); }
            else if (event.target.closest('[data-project-guard-save]')) {
                closeGuard(true); saveMaster();
            } else if (event.target.closest('[data-project-guard-discard]')) {
                discardAndContinue();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (guard.hidden) { return; }
            if (event.key === 'Escape') {
                event.preventDefault(); event.stopImmediatePropagation(); closeGuard(true);
            } else if (event.key === 'Tab') {
                const buttons = Array.from(guard.querySelectorAll('button:not([disabled])'));
                const first = buttons[0]; const last = buttons[buttons.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        }, true);
        window.addEventListener('beforeunload', function (event) {
            updateDirtyState();
            if (!navigationAllowed && (dirty || saving)) { event.preventDefault(); event.returnValue = ''; }
        });
        window.addEventListener('pageshow', function (event) {
            navigationAllowed = false;
            if (event.persisted) {
                saving = false;
                projectForm.removeAttribute('aria-busy');
                masterButtons.forEach(function (button, index) { button.disabled = masterDisabledStates[index]; });
                updateDirtyState();
            }
        });

        if (dispatchForm) { dispatchForm.addEventListener('submit', function (event) {
            updateDirtyState();

            if (dirty) {
                event.preventDefault();
                return;
            }

            const projectLabel = dispatchForm.getAttribute('data-project-label') || 'dieses Projekt';
            const recipientCount = dispatchForm.getAttribute('data-recipient-count') || '0';
            const message = 'Auftrag fuer ' + projectLabel + ' an ' + recipientCount + ' Mitarbeiter senden?\n\n'
                + 'Versendet wird ausschliesslich ein Hinweis auf den bereits gespeicherten Projektstand und die gespeicherten Dateien. '
                + 'Der Versand kann spaeter bewusst erneut ausgeloest werden.';

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        }); }
        updateDirtyState();
        if (document.readyState === 'complete') { restoreSavedView(); }
        else { window.addEventListener('load', restoreSavedView, { once: true }); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProjectPage);
    } else {
        initProjectPage();
    }
}());
