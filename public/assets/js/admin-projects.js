(function () {
    function formSnapshot(form) {
        return Array.from(new FormData(form).entries())
            .filter(function (entry) {
                return entry[0] !== 'csrf_token';
            })
            .map(function (entry) {
                return entry[0] + '=' + String(entry[1]);
            })
            .sort()
            .join('&');
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

        if (!projectForm || !dispatchForm || !dispatchButton) {
            return;
        }

        const initialSnapshot = formSnapshot(projectForm);
        const initiallyDisabled = dispatchButton.disabled;
        let dirty = false;

        const updateDirtyState = function () {
            dirty = formSnapshot(projectForm) !== initialSnapshot;
            dispatchButton.disabled = initiallyDisabled || dirty;

            if (unsavedNotice) {
                unsavedNotice.hidden = !dirty;
            }
        };

        projectForm.addEventListener('input', updateDirtyState);
        projectForm.addEventListener('change', updateDirtyState);
        dispatchForm.addEventListener('submit', function (event) {
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
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initProjectPage);
    } else {
        initProjectPage();
    }
}());
