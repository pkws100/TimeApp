(function () {
    const THEME_KEY = 'app.theme';
    const PREFERRED_PROJECT_KEY = 'app.preferredProjectId';
    const PROJECT_SELECTION_KEY = 'app.projectSelectionId';
    const TIMESHEET_FILTER_SCOPE_KEY = 'app.timesheetFilterScope';
    const TIMESHEET_FILTER_PROJECT_KEY = 'app.timesheetFilterProjectId';
    const ALLOWED_THEME_MODES = ['light', 'dark', 'system'];
    const THEME_LABELS = {
        light: 'Hell',
        dark: 'Dunkel',
        system: 'System'
    };
    const GEO_ACK_KEY = 'app.geoAck';
    const DB_NAME = 'zeiterfassung-app';
    const DB_VERSION = 1;
    const currentRoute = window.location.pathname || '/app';
    const root = document.getElementById('appRoot');
    const bootstrap = window.__APP_BOOTSTRAP__ || {};
    let state = {
        session: bootstrap.session || { authenticated: false, user: null },
        today: null,
        company: null,
        projects: [],
        pendingCount: 0,
        online: navigator.onLine,
        route: currentRoute,
        menuOpen: false,
        now: Date.now(),
        feedback: null,
        dialog: null,
        pendingAction: null,
        uploadingTimesheetId: null,
        uploadingProjectId: null,
        preferredProjectId: null,
        projectSelectionId: undefined,
        projectFiles: {},
        projectFilesLoading: false,
        timesheetList: [],
        timesheetFilterScope: 'all',
        timesheetFilterProjectId: null,
        timesheetListUpdatedAt: null,
        timesheetListLoading: false,
        timesheetListOffline: false,
        push: null,
        pushLoading: false,
        pushBusy: false,
        installPromptAvailable: false,
        fileSelectionGuardUntil: 0,
    };
    let feedbackTimer = null;
    let clientIdCounter = 0;
    let revalidateTimer = null;
    let deferredInstallPrompt = null;

    function getStoredThemeMode() {
        try {
            const stored = window.localStorage.getItem(THEME_KEY);

            if (ALLOWED_THEME_MODES.includes(stored)) {
                return stored;
            }
        } catch (error) {
            return 'system';
        }

        return 'system';
    }

    function readPreferredProjectId() {
        try {
            const stored = window.localStorage.getItem(PREFERRED_PROJECT_KEY);

            if (stored === null || stored === '') {
                return null;
            }

            const parsed = Number(stored);

            return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function storePreferredProjectId(projectId) {
        state.preferredProjectId = typeof projectId === 'number' && projectId > 0 ? projectId : null;

        try {
            if (state.preferredProjectId === null) {
                window.localStorage.removeItem(PREFERRED_PROJECT_KEY);
            } else {
                window.localStorage.setItem(PREFERRED_PROJECT_KEY, String(state.preferredProjectId));
            }
        } catch (error) {
            console.warn('Projektvormerkung konnte nicht gespeichert werden.', error);
        }
    }

    function readProjectSelectionState() {
        try {
            const stored = window.localStorage.getItem(PROJECT_SELECTION_KEY);

            if (stored === null) {
                return undefined;
            }

            if (stored === 'none') {
                return null;
            }

            const parsed = Number(stored);

            return Number.isInteger(parsed) && parsed > 0 ? parsed : undefined;
        } catch (error) {
            return undefined;
        }
    }

    function readTimesheetFilterScope() {
        try {
            const stored = window.localStorage.getItem(TIMESHEET_FILTER_SCOPE_KEY);

            return stored === 'project' ? 'project' : 'all';
        } catch (error) {
            return 'all';
        }
    }

    function storeTimesheetFilterScope(scope) {
        state.timesheetFilterScope = scope === 'all' ? 'all' : 'project';

        try {
            window.localStorage.setItem(TIMESHEET_FILTER_SCOPE_KEY, state.timesheetFilterScope);
        } catch (error) {
            console.warn('Zeiten-Filter konnte nicht gespeichert werden.', error);
        }
    }

    function readTimesheetFilterProjectId() {
        try {
            const stored = window.localStorage.getItem(TIMESHEET_FILTER_PROJECT_KEY);

            if (stored === null || stored === '' || stored === 'none') {
                return null;
            }

            const parsed = Number(stored);

            return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function storeTimesheetFilterProjectId(projectId) {
        state.timesheetFilterProjectId = typeof projectId === 'number' && projectId > 0 ? projectId : null;

        try {
            window.localStorage.setItem(
                TIMESHEET_FILTER_PROJECT_KEY,
                state.timesheetFilterProjectId === null ? 'none' : String(state.timesheetFilterProjectId)
            );
        } catch (error) {
            console.warn('Historien-Projektfilter konnte nicht gespeichert werden.', error);
        }
    }

    function preferredProjectId() {
        return typeof state.preferredProjectId === 'number' && state.preferredProjectId > 0
            ? state.preferredProjectId
            : readPreferredProjectId();
    }

    function preferredProject() {
        const projectId = preferredProjectId();

        if (projectId === null || !state.today || !Array.isArray(state.today.projects)) {
            return null;
        }

        return state.today.projects.find((project) => project.id === projectId) || null;
    }

    function activeProjects() {
        return state.today && Array.isArray(state.today.projects) ? state.today.projects : [];
    }

    function selectedProjectId() {
        const projectSelect = document.getElementById('projectSelect');

        if (!projectSelect || !projectSelect.value) {
            const selection = projectSelectionStateId();

            return typeof selection === 'undefined' ? null : selection;
        }

        const projectId = Number(projectSelect.value);

        return Number.isInteger(projectId) && projectId > 0 ? projectId : null;
    }

    function projectById(projectId) {
        if (projectId === null || !state.today || !Array.isArray(state.today.projects)) {
            return null;
        }

        return state.today.projects.find((project) => project.id === projectId) || null;
    }

    function hasPermission(permission) {
        const permissions = state.session && state.session.user && Array.isArray(state.session.user.permissions)
            ? state.session.user.permissions
            : [];

        return permissions.includes('*') || permissions.includes(permission);
    }

    function projectFileContextId() {
        const explicitSelection = projectSelectionStateId();

        if (typeof explicitSelection === 'number' && explicitSelection > 0) {
            return explicitSelection;
        }

        if (explicitSelection === null) {
            return null;
        }

        const entry = workEntry();

        if (entry && typeof entry.project_id === 'number' && entry.project_id > 0) {
            return entry.project_id;
        }

        const preferred = preferredProjectId();

        if (preferred !== null) {
            return preferred;
        }

        const projects = activeProjects();

        return projects.length > 0 ? projects[0].id : null;
    }

    function projectFilesCacheKey(projectId) {
        return 'project_files_' + String(projectId);
    }

    function currentTimesheetProjectId() {
        if (state.timesheetFilterScope === 'all') {
            return null;
        }

        return state.timesheetFilterProjectId;
    }

    function currentTimesheetCacheKey() {
        if (state.timesheetFilterScope === 'all') {
            return 'timesheets_all';
        }

        const projectId = currentTimesheetProjectId();

        return projectId === null ? 'timesheets_project_none' : 'timesheets_project_' + projectId;
    }

    function currentTimesheetFilterLabel() {
        if (state.timesheetFilterScope === 'all') {
            return 'Gesamtuebersicht ueber alle Projekte';
        }

        const projectId = currentTimesheetProjectId();
        const project = projectById(projectId);

        return 'Projektfilter: ' + (project ? project.name : 'Nicht zugeordnet');
    }

    function projectSelectionStateId() {
        return typeof state.projectSelectionId === 'undefined'
            ? undefined
            : state.projectSelectionId;
    }

    function setProjectSelectionState(projectId) {
        state.projectSelectionId = typeof projectId === 'number' && projectId > 0 ? projectId : null;

        try {
            window.localStorage.setItem(
                PROJECT_SELECTION_KEY,
                state.projectSelectionId === null ? 'none' : String(state.projectSelectionId)
            );
        } catch (error) {
            console.warn('Projekt-Auswahl konnte nicht gespeichert werden.', error);
        }
    }

    function resetProjectSelectionState() {
        state.projectSelectionId = undefined;

        try {
            window.localStorage.removeItem(PROJECT_SELECTION_KEY);
        } catch (error) {
            console.warn('Projekt-Auswahl konnte nicht zurueckgesetzt werden.', error);
        }
    }

    function currentThemeMode() {
        const mode = document.documentElement.dataset.themeMode || '';

        return ALLOWED_THEME_MODES.includes(mode) ? mode : getStoredThemeMode();
    }

    function resolveTheme(mode) {
        const nextMode = ALLOWED_THEME_MODES.includes(mode) ? mode : getStoredThemeMode();
        const resolved = nextMode === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : nextMode;

        document.documentElement.dataset.themeMode = nextMode;
        document.documentElement.dataset.theme = resolved;
        document.documentElement.style.colorScheme = resolved;
        syncThemeControls();
    }

    function setTheme(mode) {
        if (!ALLOWED_THEME_MODES.includes(mode)) {
            return;
        }

        state.menuOpen = false;

        try {
            window.localStorage.setItem(THEME_KEY, mode);
        } catch (error) {
            console.warn('Theme konnte nicht gespeichert werden.', error);
        }

        resolveTheme(mode);
        render();
    }

    function syncThemeControls() {
        const mode = currentThemeMode();

        document.querySelectorAll('[data-theme-mode-button]').forEach((button) => {
            const buttonMode = button.getAttribute('data-theme-mode-button') || '';
            const active = buttonMode === mode;

            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        document.querySelectorAll('[data-theme-select]').forEach((select) => {
            if (select.value !== mode) {
                select.value = mode;
            }
        });
    }

    function showFeedback(kind, message, persist) {
        if (!message) {
            return;
        }

        if (feedbackTimer) {
            window.clearTimeout(feedbackTimer);
            feedbackTimer = null;
        }

        state.feedback = {
            kind: kind || 'info',
            message: message
        };
        render();

        if (!persist) {
            feedbackTimer = window.setTimeout(function () {
                state.feedback = null;
                feedbackTimer = null;
                render();
            }, 3600);
        }
    }

    function clearFeedback() {
        if (feedbackTimer) {
            window.clearTimeout(feedbackTimer);
            feedbackTimer = null;
        }

        state.feedback = null;
        render();
    }

    function projectlessNoteValue(fieldId) {
        const field = document.getElementById(fieldId);

        return field && typeof field.value === 'string' ? field.value.trim() : '';
    }

    function requireProjectlessNote(fieldId) {
        const note = projectlessNoteValue(fieldId);

        if (note !== '') {
            clearFeedback();

            return note;
        }

        showFeedback('error', 'Bitte beschreiben Sie die Baustelle oder den Ort, bevor Sie ohne Projekt starten.', true);

        const field = document.getElementById(fieldId);

        if (field && typeof field.focus === 'function') {
            field.focus();
        }

        return null;
    }

    function openDialog(type, payload) {
        state.dialog = {
            type: type,
            payload: payload || {}
        };
        render();
    }

    function closeDialog() {
        state.dialog = null;
        render();
    }

    function friendlyMessage(message, fallback) {
        const value = String(message || '').trim();
        const map = {
            'Nicht authentifiziert.': 'Bitte erneut anmelden.',
            'Ungueltige Zugangsdaten.': 'Die Zugangsdaten stimmen nicht. Bitte E-Mail und Passwort pruefen.',
            'Eine client_request_id ist erforderlich.': 'Die Aktion konnte nicht vorbereitet werden. Bitte erneut versuchen.',
            'Die Aktion ist ungueltig.': 'Diese Aktion konnte nicht ausgefuehrt werden. Bitte erneut versuchen.',
            'Es gibt keine laufende Pause zum Beenden.': 'Es laeuft aktuell keine Pause. Sie koennen direkt weiterarbeiten oder eine neue Pause buchen.',
            'Bitte zuerst einen Check-in buchen, bevor Sie eine Pause erfassen.': 'Bitte zuerst einen Check-in buchen, bevor Sie eine Pause speichern.',
            'Bitte zuerst einen Check-in buchen, bevor Sie den Check-out erfassen.': 'Bitte zuerst einen Check-in buchen, bevor Sie den Check-out speichern.',
            'Bitte zuerst einen Check-in buchen. Danach koennen Sie das Projekt zuordnen.': 'Bitte zuerst einen Check-in buchen. Danach koennen Sie die Baustelle oder das Projekt zuordnen.',
            'Bitte zuerst einen Check-in buchen oder eine Startzeit eingeben.': 'Bitte zuerst einen Check-in buchen oder eine Startzeit eintragen.',
            'Check-out ist erst moeglich, wenn die laufende Pause beendet wurde.': 'Bitte erst die laufende Pause beenden, bevor Sie den Check-out buchen.',
            'Datei-Upload fehlgeschlagen.': 'Das Bild konnte nicht hochgeladen werden. Bitte erneut versuchen.',
            'Dateityp ist nicht freigegeben.': 'Bitte ein Bild im erlaubten Format waehlen.',
            'MIME-Typ ist nicht freigegeben.': 'Bitte ein Bild im erlaubten Format waehlen.',
            'Datei ist zu gross.': 'Bitte ein kleineres Bild waehlen.',
            'Datei konnte nicht gespeichert werden.': 'Das Bild konnte nicht gespeichert werden. Bitte erneut versuchen.',
            'Keine Datei uebergeben.': 'Bitte zuerst ein Bild auswaehlen.',
            'Keine Berechtigung.': 'Dafuer fehlt die Berechtigung.',
        };

        return map[value] || value || fallback || 'Bitte erneut versuchen.';
    }

    function db() {
        return new Promise((resolve, reject) => {
            const request = window.indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = function () {
                const database = request.result;

                if (!database.objectStoreNames.contains('cache')) {
                    database.createObjectStore('cache', { keyPath: 'key' });
                }

                if (!database.objectStoreNames.contains('queue')) {
                    const store = database.createObjectStore('queue', { keyPath: 'id' });
                    store.createIndex('status', 'status', { unique: false });
                }
            };

            request.onsuccess = function () {
                resolve(request.result);
            };

            request.onerror = function () {
                reject(request.error);
            };
        });
    }

    async function cacheSet(key, value) {
        const database = await db();

        await new Promise((resolve, reject) => {
            const tx = database.transaction('cache', 'readwrite');
            tx.objectStore('cache').put({ key, value, updatedAt: Date.now() });
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
        });
    }

    async function cacheGet(key) {
        const database = await db();

        return new Promise((resolve, reject) => {
            const tx = database.transaction('cache', 'readonly');
            const request = tx.objectStore('cache').get(key);
            request.onsuccess = () => resolve(request.result ? request.result.value : null);
            request.onerror = () => reject(request.error);
        });
    }

    function createClientId() {
        const cryptography = typeof globalThis !== 'undefined' ? globalThis.crypto : null;

        if (cryptography && typeof cryptography.randomUUID === 'function') {
            const value = cryptography.randomUUID();

            if (typeof value === 'string' && value.trim() !== '') {
                return value;
            }
        }

        if (cryptography && typeof cryptography.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);

            cryptography.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;

            const hex = Array.from(bytes, function (byte) {
                return byte.toString(16).padStart(2, '0');
            });

            return [
                hex.slice(0, 4).join(''),
                hex.slice(4, 6).join(''),
                hex.slice(6, 8).join(''),
                hex.slice(8, 10).join(''),
                hex.slice(10, 16).join('')
            ].join('-');
        }

        clientIdCounter += 1;

        return [
            'fallback',
            Date.now().toString(16),
            Math.floor(Math.random() * 0xffffffff).toString(16).padStart(8, '0'),
            clientIdCounter.toString(16).padStart(4, '0')
        ].join('-');
    }

    async function queueAdd(item) {
        const database = await db();
        const record = {
            ...item,
            id: item.id || createClientId(),
            status: 'pending',
            createdAt: Date.now()
        };

        await new Promise((resolve, reject) => {
            const tx = database.transaction('queue', 'readwrite');
            tx.objectStore('queue').put(record);
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
        });

        return record;
    }

    async function queueAll() {
        const database = await db();

        return new Promise((resolve, reject) => {
            const tx = database.transaction('queue', 'readonly');
            const request = tx.objectStore('queue').getAll();
            request.onsuccess = () => resolve(request.result || []);
            request.onerror = () => reject(request.error);
        });
    }

    async function queueUpdate(item) {
        const database = await db();

        await new Promise((resolve, reject) => {
            const tx = database.transaction('queue', 'readwrite');
            tx.objectStore('queue').put(item);
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
        });
    }

    async function queueRemove(id) {
        const database = await db();

        await new Promise((resolve, reject) => {
            const tx = database.transaction('queue', 'readwrite');
            tx.objectStore('queue').delete(id);
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
        });
    }

    function routeName() {
        return state.route.replace('/app', '') || '/';
    }

    function linkActive(path) {
        return state.route === path ? 'is-active' : '';
    }

    function badgeStatus() {
        if (!state.online) {
            return '<span class="app-badge warn">Offline</span>';
        }

        if (state.pendingCount > 0) {
            return '<span class="app-badge warn">' + state.pendingCount + ' warten</span>';
        }

        return '<span class="app-badge ok">Synchron</span>';
    }

    function feedbackMarkup() {
        if (!state.feedback) {
            return '';
        }

        const title = state.feedback.kind === 'success'
            ? 'Erfolg'
            : (state.feedback.kind === 'error' ? 'Fehler' : 'Hinweis');

        return '<section class="app-feedback app-feedback-' + escapeHtml(state.feedback.kind) + '">'
            + '<div><strong>' + escapeHtml(title) + '</strong><p>' + escapeHtml(state.feedback.message) + '</p></div>'
            + '<button type="button" class="app-feedback-close" id="feedbackClose" aria-label="Hinweis schliessen">Schliessen</button>'
            + '</section>';
    }

    function dialogMarkup() {
        if (!state.dialog) {
            return '';
        }

        if (state.dialog.type === 'confirm-check-in-without-project') {
            const payload = state.dialog.payload || {};
            const note = typeof payload.note === 'string' ? payload.note : '';

            return '<div class="app-dialog-layer">'
                + '<button type="button" class="app-dialog-overlay" id="dialogCancel" aria-label="Dialog schliessen"></button>'
                + '<section class="app-dialog">'
                + '<p class="muted">Check-in ohne Baustelle</p>'
                + '<h2>Ohne Projekt starten?</h2>'
                + '<p>Bitte beschreiben Sie kurz die Baustelle oder den Ort. Die Zuordnung erfolgt spaeter im Buero.</p>'
                + '<label class="app-field"><span>Baustelle / Ort</span><textarea id="projectlessDialogNote" rows="3" required placeholder="Baustelle oder Ort kurz beschreiben">' + escapeHtml(note) + '</textarea></label>'
                + '<div class="app-inline-actions">'
                + '<button type="button" id="dialogConfirmPrimary">Ohne Projekt starten</button>'
                + '<button type="button" id="dialogConfirmSecondary" class="app-button app-button-secondary">Projekt jetzt waehlen</button>'
                + '</div>'
                + '</section>'
                + '</div>';
        }

        if (state.dialog.type === 'pause-minutes') {
            return '<div class="app-dialog-layer">'
                + '<button type="button" class="app-dialog-overlay" id="dialogCancel" aria-label="Dialog schliessen"></button>'
                + '<section class="app-dialog">'
                + '<p class="muted">Pause buchen</p>'
                + '<h2>Wie lang war die Pause?</h2>'
                + '<div class="app-choice-grid">'
                + [15, 30, 45, 60].map((minutes) => '<button type="button" data-pause-minutes="' + minutes + '">' + minutes + ' Minuten</button>').join('')
                + '</div>'
                + '</section>'
                + '</div>';
        }

        return '';
    }

    function buttonLabel(action, defaultLabel) {
        if (state.pendingAction !== action) {
            return defaultLabel;
        }

        const labels = {
            check_in: 'Wird gebucht ...',
            check_out: 'Wird gebucht ...',
            upsert: 'Wird gespeichert ...',
            select_project: 'Wird zugeordnet ...',
            pause: 'Wird gespeichert ...',
            upload_attachment: 'Wird hochgeladen ...',
            upload_project_attachment: 'Wird hochgeladen ...',
        };

        return labels[action] || 'Wird verarbeitet ...';
    }

    function isBusy(action) {
        return state.pendingAction === action;
    }

    function appNavItems() {
        return [
            { href: '/app/heute', label: 'Heute' },
            { href: '/app/zeiten', label: 'Zeiten' },
            { href: '/app/historie', label: 'Historie' },
            { href: '/app/projektwahl', label: 'Projekt' },
            { href: '/app/profil', label: 'Profil' }
        ];
    }

    function appNav(className) {
        return appNavItems().map((item) => {
            const classes = [linkActive(item.href)];

            if (className) {
                classes.push(className);
            }

            const classAttribute = classes.filter(Boolean).join(' ');

            return '<a href="' + item.href + '"' + (classAttribute !== '' ? ' class="' + classAttribute + '"' : '') + ' data-app-link="1">' + item.label + '</a>';
        }).join('');
    }

    function statusLabel(status) {
        const labels = {
            not_started: 'Nicht gestartet',
            planned: 'Geplant',
            working: 'Arbeitet',
            paused: 'In Pause',
            completed: 'Einsatz abgeschlossen',
            sick: 'Krank',
            vacation: 'Urlaub',
            holiday: 'Feiertag',
            absent: 'Abwesend',
            unknown: 'Unbekannt'
        };

        return labels[status] || 'Unbekannt';
    }

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function formatClock(timestamp) {
        const date = new Date(timestamp);

        return pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
    }

    function formatDurationMinutes(totalMinutes) {
        const safeMinutes = Math.max(0, Math.floor(totalMinutes));
        const hours = Math.floor(safeMinutes / 60);
        const minutes = safeMinutes % 60;

        return pad(hours) + ':' + pad(minutes);
    }

    function formatTime(value) {
        if (!value) {
            return '--:--';
        }

        return String(value).slice(0, 5);
    }

    function formatDate(value) {
        if (!value) {
            return '-';
        }

        const parts = String(value).split('-');

        if (parts.length !== 3) {
            return String(value);
        }

        return parts[2] + '.' + parts[1] + '.' + parts[0];
    }

    function formatDateTimeStamp(value) {
        const parsed = parseDateTime(value);

        if (!parsed) {
            return '-';
        }

        return pad(parsed.getDate()) + '.' + pad(parsed.getMonth() + 1) + '.' + parsed.getFullYear()
            + ' ' + pad(parsed.getHours()) + ':' + pad(parsed.getMinutes());
    }

    function entryTypeLabel(entryType) {
        const labels = {
            work: 'Arbeit',
            sick: 'Krank',
            vacation: 'Urlaub',
            holiday: 'Feiertag',
            absent: 'Abwesend'
        };

        return labels[entryType] || String(entryType || '-');
    }

    function parseDateTime(value) {
        if (!value) {
            return null;
        }

        const parsed = new Date(value);

        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function projectDaySummaries() {
        return state.today && Array.isArray(state.today.project_day_summaries)
            ? state.today.project_day_summaries
            : [];
    }

    function normalizeProjectSelectionAgainstToday() {
        if (!state.today) {
            return;
        }

        const todayState = state.today.today_state || {};
        const entry = todayState.work_entry || null;
        const explicitSelection = projectSelectionStateId();
        const summaryProjectIds = projectDaySummaries().map((item) => Object.prototype.hasOwnProperty.call(item, 'project_id') ? item.project_id : null);
        const availableProjectIds = Array.isArray(state.today.projects)
            ? state.today.projects.map((project) => project.id)
            : [];

        if (entry && entry.start_time && !entry.end_time) {
            setProjectSelectionState(Object.prototype.hasOwnProperty.call(entry, 'project_id') ? entry.project_id : null);
            return;
        }

        if (typeof explicitSelection === 'undefined') {
            return;
        }

        if (explicitSelection === null) {
            return;
        }

        const selectionExists = availableProjectIds.includes(explicitSelection) || summaryProjectIds.includes(explicitSelection);

        if (selectionExists) {
            return;
        }

        if (entry && Object.prototype.hasOwnProperty.call(entry, 'project_id')) {
            setProjectSelectionState(entry.project_id);
        } else {
            resetProjectSelectionState();
        }
    }

    function normalizeTimesheetFilterAgainstToday() {
        if (!state.today || state.timesheetFilterProjectId === null) {
            return;
        }

        const availableProjectIds = Array.isArray(state.today.projects)
            ? state.today.projects.map((project) => project.id)
            : [];

        if (availableProjectIds.includes(state.timesheetFilterProjectId)) {
            return;
        }

        storeTimesheetFilterProjectId(null);
    }

    function activeProjectId() {
        const explicitSelection = projectSelectionStateId();

        if (typeof explicitSelection !== 'undefined') {
            return explicitSelection;
        }

        const projectSelectValue = selectedProjectId();

        if (projectSelectValue !== null) {
            return projectSelectValue;
        }

        const entry = state.today && state.today.today_state ? state.today.today_state.work_entry || null : null;

        if (entry && Object.prototype.hasOwnProperty.call(entry, 'project_id')) {
            return entry.project_id === null ? null : Number(entry.project_id);
        }

        return preferredProjectId();
    }

    function projectNameForId(projectId) {
        const project = projectById(projectId);

        return project && project.name ? project.name : 'Nicht zugeordnet';
    }

    function emptyProjectDayView() {
        return {
            project_id: activeProjectId(),
            project_name: projectNameForId(activeProjectId()),
            status: 'not_started',
            start_time: null,
            end_time: null,
            total_break_minutes: 0,
            total_net_minutes: 0,
            current_break: null,
            tracked_minutes_live_basis: null,
            work_entry: null,
            breaks_today: [],
            attachments: []
        };
    }

    function currentProjectDayView() {
        const projectId = activeProjectId();
        const summaries = projectDaySummaries();
        const summary = summaries.find((item) => {
            const itemProjectId = Object.prototype.hasOwnProperty.call(item, 'project_id') ? item.project_id : null;

            return itemProjectId === projectId;
        });

        if (summary) {
            return summary;
        }

        if (summaries.length === 0 && state.today && state.today.today_state && state.today.today_state.status_entry) {
            return {
                ...emptyProjectDayView(),
                project_id: projectId,
                project_name: projectNameForId(projectId),
                status: state.today.today_state.status_entry.entry_type || 'not_started'
            };
        }

        return emptyProjectDayView();
    }

    function currentBreak() {
        const view = currentProjectDayView();

        return view.current_break || null;
    }

    function workEntry() {
        const view = currentProjectDayView();

        return view.work_entry || null;
    }

    function breaksToday() {
        const view = currentProjectDayView();

        return Array.isArray(view.breaks_today) ? view.breaks_today : [];
    }

    function trackedBasis() {
        const view = currentProjectDayView();

        return view.tracked_minutes_live_basis || null;
    }

    function currentStatus() {
        return currentProjectDayView().status || 'not_started';
    }

    function currentProjectName() {
        const view = currentProjectDayView();

        return view.project_name || 'Nicht zugeordnet';
    }

    function activeProjectlessWorkEntry() {
        const entry = globalWorkEntry();

        if (!entry || !entry.start_time || entry.end_time) {
            return null;
        }

        const projectId = Object.prototype.hasOwnProperty.call(entry, 'project_id') ? entry.project_id : null;

        return projectId === null ? entry : null;
    }

    function globalWorkEntry() {
        return state.today && state.today.today_state ? state.today.today_state.work_entry || null : null;
    }

    function globalActiveProjectId() {
        const entry = globalWorkEntry();

        if (!entry || !entry.start_time || entry.end_time) {
            return null;
        }

        return Object.prototype.hasOwnProperty.call(entry, 'project_id') ? entry.project_id : null;
    }

    function otherActiveProjectName() {
        const globalEntry = globalWorkEntry();

        if (!globalEntry || !globalEntry.start_time || globalEntry.end_time) {
            return null;
        }

        return globalEntry.project_name || projectNameForId(globalEntry.project_id ?? null);
    }

    function isBlockedByOtherActiveProject() {
        const entry = globalWorkEntry();

        if (!entry || !entry.start_time || entry.end_time) {
            return false;
        }

        const globalProjectId = Object.prototype.hasOwnProperty.call(entry, 'project_id') ? entry.project_id : null;

        return globalProjectId !== activeProjectId();
    }

    function currentStartTimeValue() {
        return formatTime(currentProjectDayView().start_time || null);
    }

    function currentEndTimeValue() {
        return formatTime(currentProjectDayView().end_time || null);
    }

    function currentTotalBreakMinutes() {
        return Number(currentProjectDayView().total_break_minutes || 0);
    }

    function breakMinutesFromCollection(items) {
        return items.reduce((minutes, item) => {
            const startedAt = parseDateTime(item.break_started_at);
            const endedAt = parseDateTime(item.break_ended_at);

            if (!startedAt || !endedAt || endedAt <= startedAt) {
                return minutes;
            }

            return minutes + Math.max(0, Math.floor((endedAt.getTime() - startedAt.getTime()) / 60000));
        }, 0);
    }

    function combineWorkDateTime(workDate, time, startTime) {
        if (!workDate || !time) {
            return null;
        }

        const value = new Date(workDate + 'T' + String(time).slice(0, 5) + ':00');

        if (Number.isNaN(value.getTime())) {
            return null;
        }

        if (startTime) {
            const start = new Date(workDate + 'T' + String(startTime).slice(0, 5) + ':00');

            if (!Number.isNaN(start.getTime()) && value.getTime() <= start.getTime()) {
                value.setDate(value.getDate() + 1);
            }
        }

        return value.toISOString();
    }

    function rebuildTodayLiveBasis() {
        if (!state.today) {
            return;
        }

        const entry = workEntry();
        const activeBreak = currentBreak();
        const status = currentStatus();

        state.today.tracked_minutes_live_basis = {
            work_started_at: combineWorkDateTime(state.today.today, entry ? entry.start_time : null),
            work_ended_at: combineWorkDateTime(state.today.today, entry ? entry.end_time : null, entry ? entry.start_time : null),
            completed_break_minutes: breakMinutesFromCollection(breaksToday()),
            current_break_started_at: activeBreak ? activeBreak.break_started_at : null,
            is_running: status === 'working',
            is_paused: status === 'paused'
        };
    }

    function syncTodayStateStatus() {
        if (!state.today) {
            return;
        }

        const todayState = state.today.today_state || {};
        const entry = todayState.work_entry || null;
        const activeBreak = currentBreak();
        let status = 'not_started';

        if (entry && entry.start_time) {
            status = entry.end_time ? 'completed' : 'working';
        }

        if (activeBreak) {
            status = 'paused';
        }

        todayState.current_break = activeBreak;
        todayState.status = status;
        state.today.current_break = activeBreak;
        state.today.today_state = todayState;
        rebuildTodayLiveBasis();
    }

    function syncSelectedProjectSummaryFromTodayState() {
        if (!state.today) {
            return;
        }

        const todayState = state.today.today_state || {};
        const entry = todayState.work_entry || null;
        const summaries = projectDaySummaries().slice();
        const projectId = entry && Object.prototype.hasOwnProperty.call(entry, 'project_id')
            ? entry.project_id
            : activeProjectId();
        const previousSummary = summaries.find((item) => item.project_id === projectId) || null;
        const filtered = summaries.filter((item) => item.project_id !== projectId);

        if (!entry || !entry.start_time) {
            state.today.project_day_summaries = filtered;
            return;
        }

        const currentBreakValue = state.today.current_break || null;
        const breaks = Array.isArray(state.today.breaks_today) ? state.today.breaks_today : [];
        const tracked = state.today.tracked_minutes_live_basis || null;
        const attachments = Array.isArray(state.today.attachments)
            ? state.today.attachments
            : (Array.isArray(entry.attachments) ? entry.attachments : []);
        const isFreshOptimisticEntry = !Number(entry.id || 0);
        const previousNetMinutes = isFreshOptimisticEntry && previousSummary
            ? Number(previousSummary.total_net_minutes || 0)
            : 0;
        const previousBreakMinutes = isFreshOptimisticEntry && previousSummary
            ? Number(previousSummary.total_break_minutes || 0)
            : 0;

        filtered.unshift({
            project_id: projectId,
            project_name: entry.project_name || projectNameForId(projectId),
            status: todayState.status || 'not_started',
            start_time: entry.start_time || null,
            end_time: entry.end_time || null,
            total_break_minutes: previousBreakMinutes + Number(entry.break_minutes || 0),
            total_net_minutes: previousNetMinutes + Number(entry.net_minutes || 0),
            current_break: currentBreakValue,
            tracked_minutes_live_basis: tracked,
            work_entry: {
                ...entry,
                attachments
            },
            breaks_today: breaks,
            attachments
        });

        state.today.project_day_summaries = filtered;
    }

    function liveWorkMinutes() {
        const view = currentProjectDayView();
        const basis = trackedBasis();
        const accumulatedNetMinutes = Number(view.total_net_minutes || 0);

        if (!basis || !basis.work_started_at || (!basis.is_running && !basis.is_paused)) {
            return accumulatedNetMinutes;
        }

        const startedAt = parseDateTime(basis.work_started_at);

        if (!startedAt) {
            return 0;
        }

        const endedAt = basis.work_ended_at ? parseDateTime(basis.work_ended_at) : new Date(state.now);
        const completedBreakMinutes = Number(basis.completed_break_minutes || 0);

        if (!endedAt || endedAt <= startedAt) {
            return accumulatedNetMinutes;
        }

        let grossMinutes = Math.floor((endedAt.getTime() - startedAt.getTime()) / 60000);

        if (basis.current_break_started_at) {
            const breakStartedAt = parseDateTime(basis.current_break_started_at);

            if (breakStartedAt) {
                grossMinutes -= Math.floor((endedAt.getTime() - breakStartedAt.getTime()) / 60000);
            }
        }

        return Math.max(0, accumulatedNetMinutes + grossMinutes - completedBreakMinutes);
    }

    function liveCurrentBreakMinutes() {
        const activeBreak = currentBreak();

        if (!activeBreak || !activeBreak.break_started_at) {
            return 0;
        }

        const startedAt = parseDateTime(activeBreak.break_started_at);

        if (!startedAt) {
            return 0;
        }

        const endedAt = activeBreak.break_ended_at ? parseDateTime(activeBreak.break_ended_at) : new Date(state.now);

        if (!endedAt || endedAt <= startedAt) {
            return 0;
        }

        return Math.max(0, Math.floor((endedAt.getTime() - startedAt.getTime()) / 60000));
    }

    function compactClockMarkup() {
        return '<div class="app-live-strip">'
            + '<span class="app-live-chip app-live-chip-compact"><strong data-live-clock>' + formatClock(state.now) + '</strong></span>'
            + '</div>';
    }

    function drawerMenuMarkup() {
        const mode = currentThemeMode();
        const companyName = state.company && state.company.company_name ? state.company.company_name : '';
        const userName = state.session.authenticated && state.session.user
            ? (state.session.user.display_name || 'Angemeldet')
            : 'Nicht angemeldet';

        if (!state.menuOpen) {
            return '';
        }

        return '<div class="app-drawer-layer">'
            + '<button type="button" class="app-drawer-overlay" id="appMenuClose" aria-label="Menue schliessen"></button>'
            + '<aside class="app-drawer" aria-label="App-Menue">'
            + '<div class="app-drawer-head">'
            + '<div class="app-drawer-brand"><span class="muted">Menue</span><strong>' + escapeHtml(bootstrap.app_name || 'Baustellen Zeiterfassung') + '</strong></div>'
            + '<button type="button" class="app-menu-button app-menu-button-close" id="appMenuCloseButton" aria-label="Menue schliessen">Schliessen</button>'
            + '</div>'
            + '<div class="app-settings-block">'
            + '<div class="app-empty"><strong>Status:</strong> <span data-live-status>' + escapeHtml(statusLabel(currentStatus())) + '</span><br><strong>Sync:</strong> ' + badgeStatus() + '<br><strong>Nutzer:</strong> ' + escapeHtml(userName) + '<br><strong>Firma:</strong> ' + escapeHtml(companyName || '-') + '</div>'
            + '</div>'
            + '<nav class="app-drawer-nav">' + appNav('app-drawer-link') + '</nav>'
            + '<div class="app-settings-block"><span class="muted">Theme</span>'
            + '<div class="app-theme-group app-theme-group-stacked" role="group" aria-label="Theme waehlen">'
            + ALLOWED_THEME_MODES.map((themeMode) => {
                const active = themeMode === mode ? ' is-active' : '';

                return '<button type="button" class="app-theme-button' + active + '" data-theme-mode-button="' + themeMode + '" aria-pressed="' + (themeMode === mode ? 'true' : 'false') + '">' + THEME_LABELS[themeMode] + '</button>';
            }).join('')
            + '</div></div>'
            + (state.session.authenticated
                ? '<button type="button" id="logoutButton" class="app-menu-logout">Abmelden</button>'
                : '')
            + '</aside>'
            + '</div>';
    }

    function shell(inner) {
        return '<div class="app-shell">'
            + '<header class="app-topbar"><div class="app-topbar-main">'
            + '<div class="app-brand app-brand-compact"><span class="muted">Mitarbeiter-App</span><strong>'
            + escapeHtml(bootstrap.app_name || 'Baustellen Zeiterfassung')
            + '</strong></div>'
            + '<div class="app-topbar-tools">'
            + compactClockMarkup()
            + '<button type="button" class="app-menu-button app-burger-button" id="appMenuToggle" aria-expanded="' + (state.menuOpen ? 'true' : 'false') + '" aria-label="Menue oeffnen"><span></span><span></span><span></span></button>'
            + '</div></div></header>'
            + drawerMenuMarkup()
            + feedbackMarkup()
            + dialogMarkup()
            + '<main class="app-layout">' + inner + '</main>'
            + '<nav class="app-nav">' + appNav() + '</nav>'
            + '</div>';
    }

    function themeSettingsHint() {
        const mode = currentThemeMode();

        return '<div class="app-empty"><strong>Theme:</strong> ' + escapeHtml(THEME_LABELS[mode] || 'System') + '<br><span class="muted">Umschaltung ueber das Einstellungsmenue im Header.</span></div>';
    }

    function compactLine(parts) {
        return parts
            .map((part) => String(part || '').trim())
            .filter((part) => part !== '')
            .join(' ');
    }

    function compactBlock(parts, separator) {
        return parts
            .map((part) => String(part || '').trim())
            .filter((part) => part !== '')
            .join(separator || ', ');
    }

    function appInfoRows(rows) {
        const markup = rows
            .filter((row) => String(row.value || '').trim() !== '')
            .map((row) => '<div class="app-info-row"><span class="muted">' + escapeHtml(row.label) + '</span><strong>' + escapeHtml(row.value) + '</strong></div>')
            .join('');

        return markup !== '' ? '<div class="app-info-list">' + markup + '</div>' : '<div class="app-empty">Noch keine Angaben hinterlegt.</div>';
    }

    function legalTextSection(title, text) {
        const value = String(text || '').trim();

        return '<section class="app-card app-grid">'
            + '<div><p class="muted">Rechtstext</p><h2>' + escapeHtml(title) + '</h2></div>'
            + (value !== ''
                ? '<details class="app-legal-details"><summary>' + escapeHtml(title) + ' anzeigen</summary><div class="app-legal-text">' + escapeHtml(value) + '</div></details>'
                : '<div class="app-empty">Noch kein Text hinterlegt.</div>')
            + '</section>';
    }

    function geoPolicy() {
        if (state.today && state.today.geo_policy) {
            return state.today.geo_policy;
        }

        const company = state.company || {};

        return {
            enabled: !!company.geo_capture_enabled,
            notice_text: company.geo_notice_text || '',
            requires_acknowledgement: !!company.geo_requires_acknowledgement
        };
    }

    function currentAttachments() {
        const entry = workEntry();

        if (entry && Array.isArray(entry.attachments)) {
            return entry.attachments;
        }

        return state.today && Array.isArray(state.today.attachments) ? state.today.attachments : [];
    }

    function fileSizeLabel(bytes) {
        const value = Number(bytes || 0);

        if (!Number.isFinite(value) || value <= 0) {
            return '';
        }

        if (value >= 1024 * 1024) {
            return (value / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB';
        }

        return Math.max(1, Math.round(value / 1024)) + ' KB';
    }

    function attachmentListMarkup(files, options) {
        const items = Array.isArray(files) ? files : [];
        const settings = options || {};

        if (items.length === 0) {
            return '<div class="app-empty">' + escapeHtml(settings.emptyText || 'Noch keine Dateien vorhanden.') + '</div>';
        }

        return items.map((attachment) => {
            const downloadUrl = attachment.download_url || attachment.preview_url || '#';
            const canOpen = state.online && downloadUrl && downloadUrl !== '#';
            const meta = compactBlock([attachment.mime_type || '', fileSizeLabel(attachment.size_bytes)], ' · ');
            const preview = attachment.is_image && attachment.preview_url
                ? (canOpen
                    ? '<a class="app-attachment-preview" href="' + escapeHtml(downloadUrl) + '" target="_blank" rel="noopener" aria-label="' + escapeHtml((attachment.original_name || 'Datei') + ' oeffnen') + '"><img src="' + escapeHtml(attachment.preview_url) + '" alt=""></a>'
                    : '<span class="app-attachment-icon" aria-label="Datei nur online abrufbar">Datei</span>')
                : (canOpen
                    ? '<a class="app-attachment-icon" href="' + escapeHtml(downloadUrl) + '" target="_blank" rel="noopener" aria-label="' + escapeHtml((attachment.original_name || 'Datei') + ' oeffnen') + '">Datei</a>'
                    : '<span class="app-attachment-icon" aria-label="Datei nur online abrufbar">Datei</span>');
            const removeButton = settings.deleteAttribute
                ? '<button type="button" data-' + settings.deleteAttribute + '="' + attachment.id + '">Entfernen</button>'
                : '';

            return '<article class="app-attachment-row">'
                + preview
                + '<div class="app-attachment-info"><strong>' + escapeHtml(attachment.original_name || 'Datei') + '</strong><p class="muted">' + escapeHtml(meta) + '</p>'
                + (canOpen ? '<a href="' + escapeHtml(downloadUrl) + '" target="_blank" rel="noopener">Oeffnen</a>' : '<span class="muted">Nur online abrufbar</span>')
                + '</div>'
                + removeButton
                + '</article>';
        }).join('');
    }

    function attachmentSectionMarkup() {
        const entry = workEntry();
        const attachments = currentAttachments();

        if (!entry || !entry.id) {
            return '<section class="app-card app-grid"><div><p class="muted">Bilder</p><h2>Anhaenge</h2><p>Bitte zuerst einen Zeiteintrag buchen. Danach koennen Sie Bilder hochladen.</p></div></section>';
        }

        return '<section class="app-card app-grid">'
            + '<div><p class="muted">Dateien</p><h2>Anhaenge zum Zeiteintrag</h2><p>Fotos und Dateien koennen direkt nach dem Buchen hochgeladen werden. Bei fehlender Verbindung ist der Upload nicht verfuegbar.</p></div>'
            + '<div class="app-grid">'
            + '<label class="app-field"><span>Foto aufnehmen</span><input id="timesheetCameraInput" type="file" accept="image/*" capture="environment" ' + (state.online ? '' : 'disabled') + '></label>'
            + '<label class="app-field"><span>Datei auswaehlen</span><input id="timesheetAttachmentInput" type="file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.heic,.heif" ' + (state.online ? '' : 'disabled') + '></label>'
            + '<button type="button" id="uploadAttachmentButton" ' + (state.online && !isBusy('upload_attachment') ? '' : 'disabled') + '>' + escapeHtml(buttonLabel('upload_attachment', 'Datei hochladen')) + '</button>'
            + (state.online ? '' : '<div class="app-empty">Datei-Uploads sind nur mit aktiver Verbindung moeglich.</div>')
            + '</div>'
            + '<div class="app-grid">'
            + attachmentListMarkup(attachments, {
                deleteAttribute: 'delete-attachment',
                emptyText: 'Noch keine Dateien zu diesem Zeiteintrag.'
            })
            + '</div>'
            + '</section>';
    }

    function projectAttachmentSectionMarkup() {
        const projectId = projectFileContextId();

        if (projectId === null) {
            return '<section class="app-card app-grid"><div><p class="muted">Projektdateien</p><h2>Dateien</h2><p>Bitte zuerst ein Projekt auswaehlen.</p></div></section>';
        }

        const project = projectById(projectId);
        const files = state.projectFiles[String(projectId)] || [];
        const loading = state.projectFilesLoading ? '<div class="app-empty">Projektdateien werden geladen ...</div>' : '';
        const canUpload = hasPermission('files.upload');
        const uploadDisabled = !state.online || !canUpload || isBusy('upload_project_attachment');

        return '<section class="app-card app-grid">'
            + '<div><p class="muted">Projektdateien</p><h2>' + escapeHtml(project ? project.name : 'Dateien') + '</h2><p>Projektbezogene Fotos und Dateien werden geschuetzt gespeichert. Uploads sind nur online moeglich.</p></div>'
            + '<div class="app-grid">'
            + '<label class="app-field"><span>Foto aufnehmen</span><input id="projectCameraInput" type="file" accept="image/*" capture="environment" ' + (uploadDisabled ? 'disabled' : '') + '></label>'
            + '<label class="app-field"><span>Datei auswaehlen</span><input id="projectAttachmentInput" type="file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.heic,.heif" ' + (uploadDisabled ? 'disabled' : '') + '></label>'
            + '<button type="button" id="uploadProjectAttachmentButton" ' + (uploadDisabled ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('upload_project_attachment', 'Datei hochladen')) + '</button>'
            + (state.online ? '' : '<div class="app-empty">Projektdateien koennen nur mit aktiver Verbindung hochgeladen werden.</div>')
            + (canUpload ? '' : '<div class="app-empty">Projektdatei-Uploads sind fuer Ihre Rolle nicht freigegeben.</div>')
            + '</div>'
            + '<div class="app-grid">'
            + loading
            + attachmentListMarkup(files, {
                emptyText: 'Noch keine Projektdateien vorhanden.'
            })
            + '</div>'
            + '</section>';
    }

    function timesheetRowsMarkup() {
        if (state.timesheetListLoading) {
            return '<div class="app-empty">Zeiten werden geladen ...</div>';
        }

        if (!Array.isArray(state.timesheetList) || state.timesheetList.length === 0) {
            return '<div class="app-empty">Keine Zeiten fuer diese Auswahl gefunden.</div>';
        }

        const cards = '<div class="app-timesheet-cards">'
            + state.timesheetList.map((item) => {
                const projectName = item.project_name || 'Nicht zugeordnet';
                const note = item.note ? '<p class="muted">' + escapeHtml(item.note) + '</p>' : '';
                const attachments = Array.isArray(item.attachments) ? item.attachments : [];
                const attachmentMarkup = attachments.length > 0
                    ? '<div class="app-timesheet-attachments">' + attachmentListMarkup(attachments, {
                        emptyText: ''
                    }) + '</div>'
                    : '';

                return '<article class="app-timesheet-card">'
                    + '<div><p class="muted">' + escapeHtml(formatDate(item.work_date)) + '</p><h3>' + escapeHtml(projectName) + '</h3>' + note + '</div>'
                    + '<div class="app-timesheet-card-grid">'
                    + '<div><span class="muted">Start</span><strong>' + escapeHtml(formatTime(item.start_time)) + '</strong></div>'
                    + '<div><span class="muted">Ende</span><strong>' + escapeHtml(formatTime(item.end_time)) + '</strong></div>'
                    + '<div><span class="muted">Pause</span><strong>' + escapeHtml(formatDurationMinutes(Number(item.break_minutes || 0))) + '</strong></div>'
                    + '<div><span class="muted">Netto</span><strong>' + escapeHtml(formatDurationMinutes(Number(item.net_minutes || 0))) + '</strong></div>'
                    + '</div>'
                    + '<div class="app-timesheet-type">' + escapeHtml(entryTypeLabel(item.entry_type)) + '</div>'
                    + attachmentMarkup
                    + '</article>';
            }).join('')
            + '</div>';

        const table = '<div class="app-timesheet-table-wrap"><table class="app-timesheet-table">'
            + '<thead><tr>'
            + '<th>Datum</th><th>Projekt</th><th>Start</th><th>Ende</th><th>Pause</th><th>Netto</th><th>Typ</th><th>Dateien</th>'
            + '</tr></thead><tbody>'
            + state.timesheetList.map((item) => {
                const projectName = item.project_name || 'Nicht zugeordnet';
                const note = item.note ? '<div class="muted">' + escapeHtml(item.note) + '</div>' : '';
                const attachmentCount = Array.isArray(item.attachments) ? item.attachments.length : 0;

                return '<tr>'
                    + '<td>' + escapeHtml(formatDate(item.work_date)) + '</td>'
                    + '<td><strong>' + escapeHtml(projectName) + '</strong>' + note + '</td>'
                    + '<td>' + escapeHtml(formatTime(item.start_time)) + '</td>'
                    + '<td>' + escapeHtml(formatTime(item.end_time)) + '</td>'
                    + '<td>' + escapeHtml(formatDurationMinutes(Number(item.break_minutes || 0))) + '</td>'
                    + '<td>' + escapeHtml(formatDurationMinutes(Number(item.net_minutes || 0))) + '</td>'
                    + '<td>' + escapeHtml(entryTypeLabel(item.entry_type)) + '</td>'
                    + '<td>' + escapeHtml(String(attachmentCount)) + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table></div>';

        return cards + table;
    }

    function timesheetHistoryMarkup() {
        const projectScopeActive = state.timesheetFilterScope !== 'all';
        const allScopeActive = state.timesheetFilterScope === 'all';
        const statusNote = state.timesheetListUpdatedAt
            ? 'Stand: ' + formatDateTimeStamp(state.timesheetListUpdatedAt)
            : 'Noch kein Stand geladen';
        const offlineNote = state.timesheetListOffline
            ? '<div class="app-empty">Letzter bekannter Stand (offline).</div>'
            : '';
        const projectFilter = projectScopeActive
            ? '<label class="app-field"><span>Projektfilter</span><select id="timesheetProjectFilter">' + timesheetProjectFilterOptions() + '</select></label>'
            : '';

        return '<section class="app-card app-grid">'
            + '<div>'
            + '<p class="muted">Eigene Zeiten</p>'
            + '<h2>Zeituebersicht</h2>'
            + '<p>' + escapeHtml(currentTimesheetFilterLabel()) + '</p>'
            + '<p class="muted">' + escapeHtml(statusNote) + '</p>'
            + '</div>'
            + '<div class="app-filter-toggle" role="group" aria-label="Zeiten filtern">'
            + '<button type="button" data-timesheet-scope="project" class="' + (projectScopeActive ? 'is-active' : '') + '" aria-pressed="' + (projectScopeActive ? 'true' : 'false') + '">Projekt</button>'
            + '<button type="button" data-timesheet-scope="all" class="' + (allScopeActive ? 'is-active' : '') + '" aria-pressed="' + (allScopeActive ? 'true' : 'false') + '">Gesamtuebersicht</button>'
            + '</div>'
            + projectFilter
            + offlineNote
            + timesheetRowsMarkup()
            + '</section>';
    }

    function manualTimeMarkup(entry) {
        return '<div class="app-grid app-time-edit-grid">'
            + '<label class="app-field"><span>Start manuell</span><input id="manualStartTime" type="time" value="' + escapeHtml(entry && entry.start_time ? formatTime(entry.start_time) : '') + '"></label>'
            + '<label class="app-field"><span>Ende manuell</span><input id="manualEndTime" type="time" value="' + escapeHtml(entry && entry.end_time ? formatTime(entry.end_time) : '') + '"></label>'
            + '</div>';
    }

    function loginView() {
        const bootstrapNotice = state.session.bootstrap_required
            ? '<div id="loginNotice" class="app-empty">Das System ist noch nicht initialisiert. Ein Administrator muss zuerst per CLI angelegt werden.</div>'
            : '<div id="loginNotice" class="app-empty">Bei fehlender Verbindung ist Login nur mit bereits vorhandener Session moeglich.</div>';
        const logoMarkup = bootstrap.company_logo_url
            ? '<div class="app-login-logo-frame"><img class="app-login-logo" src="' + escapeHtml(bootstrap.company_logo_url) + '" alt="Firmenlogo" onerror="this.parentElement.hidden = true"></div>'
            : '';

        return shell(
            '<section class="app-card app-grid">'
            + '<div class="app-login-head">' + logoMarkup + '<p class="muted">App-Login</p><h1>Anmelden</h1><p>Mit Ihrem Mitarbeiterkonto anmelden und anschliessend offline weiterarbeiten.</p></div>'
            + '<form id="loginForm" class="app-grid">'
            + '<label class="app-field"><span>E-Mail</span><input name="email" type="email" required></label>'
            + '<label class="app-field"><span>Passwort</span><input name="password" type="password" required></label>'
            + '<button type="submit">Anmelden</button>'
            + '</form>'
            + bootstrapNotice
            + '</section>'
        );
    }

    function metric(title, value, note) {
        return '<article class="app-card"><p class="muted">' + title + '</p><h2>' + value + '</h2><p class="muted">' + note + '</p></article>';
    }

    function statusTone(status) {
        if (status === 'working') {
            return 'working';
        }

        if (status === 'paused') {
            return 'paused';
        }

        if (status === 'completed') {
            return 'completed';
        }

        if (status === 'not_started' || status === 'planned') {
            return 'missing';
        }

        return 'neutral';
    }

    function statusHeadline(status) {
        const headlines = {
            working: 'Eingecheckt',
            paused: 'Pause laeuft',
            completed: 'Einsatz abgeschlossen',
            not_started: 'Noch nicht eingecheckt',
            planned: 'Noch nicht eingecheckt'
        };

        return headlines[status] || statusLabel(status);
    }

    function statusHint(status) {
        const hints = {
            working: 'Sie sind aktuell in Arbeit.',
            paused: 'Bitte Pause spaeter beenden.',
            completed: 'Dieser Einsatz ist abgeschlossen. Sie koennen einen weiteren Einsatz starten.',
            not_started: 'Bitte Check-in nicht vergessen.',
            planned: 'Bitte Check-in nicht vergessen.',
            sick: 'Heute ist ein Kranktag hinterlegt.',
            vacation: 'Heute ist Urlaub hinterlegt.',
            holiday: 'Heute ist ein Feiertag hinterlegt.',
            absent: 'Heute ist eine Abwesenheit hinterlegt.'
        };

        return hints[status] || 'Aktueller Tagesstatus.';
    }

    function statusMetric(today, status) {
        const tone = statusTone(status);

        return '<article class="app-card app-status-card is-' + tone + '">'
            + '<p class="muted">Heute</p>'
            + '<h2>' + escapeHtml(statusHeadline(status)) + '</h2>'
            + '<p class="app-status-date">' + escapeHtml(today.today || '-') + '</p>'
            + '<p class="app-status-current"><span data-live-status>' + escapeHtml(statusLabel(status)) + '</span></p>'
            + '<p class="app-status-hint">' + escapeHtml(statusHint(status)) + '</p>'
            + '</article>';
    }

    function projectStatusValueMarkup() {
        return '<span data-live-project-name>' + escapeHtml(currentProjectName()) + '</span>';
    }

    function statusValueMarkup() {
        return '<span data-live-status>' + escapeHtml(statusLabel(currentStatus())) + '</span>';
    }

    function startValueMarkup() {
        return '<span data-live-start-time>' + escapeHtml(currentStartTimeValue()) + '</span>';
    }

    function endValueMarkup() {
        return '<span data-live-end-time>' + escapeHtml(currentEndTimeValue()) + '</span>';
    }

    function totalBreakValueMarkup() {
        return '<span data-live-break-total>' + escapeHtml(formatDurationMinutes(currentTotalBreakMinutes())) + '</span>';
    }

    function pendingCountValueMarkup() {
        return '<span data-live-pending-count>' + escapeHtml(String(state.pendingCount)) + '</span>';
    }

    function todayView() {
        const today = state.today || {};
        const entry = workEntry();
        const status = currentStatus();
        const blockedByOtherProject = isBlockedByOtherActiveProject();
        const canCheckIn = !blockedByOtherProject && status !== 'working' && status !== 'paused';
        const canPause = !blockedByOtherProject && status === 'working';
        const canCheckOut = !blockedByOtherProject && (status === 'working' || status === 'paused');
        const totalBreakMinutes = currentTotalBreakMinutes();
        const otherProjectHint = blockedByOtherProject
            ? '<div class="app-empty">Auf <strong>' + escapeHtml(otherActiveProjectName() || 'einem anderen Projekt') + '</strong> laeuft noch ein Einsatz. Bitte zuerst dorthin wechseln.</div>'
            : '';

        return shell(
            '<section class="app-grid app-metrics">'
            + statusMetric(today, status)
            + metric('Aktuelle Aufgabe', projectStatusValueMarkup(), entry && entry.start_time ? 'Aktueller Arbeitseinsatz' : 'Noch kein Arbeitseinsatz gestartet')
            + metric('Offline Queue', pendingCountValueMarkup(), state.online ? 'Wird automatisch synchronisiert' : 'Wird spaeter gesendet')
            + '</section>'
            + '<section class="app-card app-grid">'
            + '<div><p class="muted">Heute</p><h1>Tagesstatus</h1><p>Der zuletzt bekannte Stand bleibt auch offline sichtbar.</p></div>'
            + '<div class="app-stat-list">'
            + '<div class="app-stat-row"><span class="muted">Start</span><strong data-live-start-time>' + escapeHtml(currentStartTimeValue()) + '</strong></div>'
            + '<div class="app-stat-row"><span class="muted">Ende</span><strong data-live-end-time>' + escapeHtml(currentEndTimeValue()) + '</strong></div>'
            + '<div class="app-stat-row"><span class="muted">Einsatzzeit</span><strong data-live-work-duration>' + escapeHtml(formatDurationMinutes(liveWorkMinutes())) + '</strong></div>'
            + '<div class="app-stat-row"><span class="muted">Pausen gesamt</span><strong data-live-break-total>' + escapeHtml(formatDurationMinutes(totalBreakMinutes)) + '</strong></div>'
            + '<div class="app-stat-row"><span class="muted">Projekt</span><strong data-live-project-name>' + escapeHtml(currentProjectName()) + '</strong></div>'
            + '</div>'
            + '<div class="app-inline-actions">'
            + (canCheckIn ? '<button type="button" data-action="check_in" ' + (isBusy('check_in') ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('check_in', 'Check-in')) + '</button>' : '')
            + (canPause ? '<button type="button" data-action="pause" ' + (isBusy('pause') ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('pause', 'Pause buchen')) + '</button>' : '')
            + (canCheckOut ? '<button type="button" data-action="check_out" ' + (isBusy('check_out') ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('check_out', 'Check-out')) + '</button>' : '')
            + '</div>'
            + otherProjectHint
            + '<div class="app-grid">'
            + '<label class="app-field"><span>Projekt</span><select id="projectSelect">' + projectOptions() + '</select></label>'
            + '<label class="app-field"><span>Notiz</span><textarea id="timesheetNote" rows="4">' + escapeHtml(entry && entry.note ? entry.note : '') + '</textarea></label>'
            + '<button type="button" id="saveUpsert" ' + (isBusy('upsert') || blockedByOtherProject ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('upsert', 'Stand speichern')) + '</button>'
            + '</div>'
            + '</section>'
            + '<section class="app-card">'
            + '<h2>GEO</h2>'
            + geoPolicyMarkup()
            + '</section>'
        );
    }

    function timesView() {
        const entry = workEntry();
        const status = currentStatus();
        const blockedByOtherProject = isBlockedByOtherActiveProject();
        const canCheckIn = !blockedByOtherProject && status !== 'working' && status !== 'paused';
        const canPause = !blockedByOtherProject && status === 'working';
        const canCheckOut = !blockedByOtherProject && (status === 'working' || status === 'paused');
        const otherProjectHint = blockedByOtherProject
            ? '<div class="app-empty">Auf <strong>' + escapeHtml(otherActiveProjectName() || 'einem anderen Projekt') + '</strong> laeuft noch ein Einsatz. Bitte zuerst dorthin wechseln.</div>'
            : '';

        return shell(
            '<section class="app-card app-grid">'
            + '<div><p class="muted">Zeiterfassung</p><h1>Arbeitszeiten</h1></div>'
            + '<div class="app-grid app-metrics">'
            + metric('Status', statusValueMarkup(), 'Aktueller Tagesstatus')
            + metric('Einsatzzeit', '<span data-live-work-duration>' + escapeHtml(formatDurationMinutes(liveWorkMinutes())) + '</span>', 'Nettozeit fuer den aktuellen Einsatz')
            + metric('Pause gesamt', totalBreakValueMarkup(), 'Abgeschlossene Pausen')
            + metric('Laufende Pause', '<span data-live-break-duration>' + escapeHtml(currentBreak() ? formatDurationMinutes(liveCurrentBreakMinutes()) : '--:--') + '</span>', 'Wird waehrend der Pause live aktualisiert')
            + '</div>'
            + '<div class="app-stat-list">'
            + '<div class="app-stat-row"><span class="muted">Projekt</span><strong data-live-project-name>' + escapeHtml(currentProjectName()) + '</strong></div>'
            + '<div class="app-stat-row"><span class="muted">Start</span><strong data-live-start-time>' + escapeHtml(currentStartTimeValue()) + '</strong></div>'
            + '<div class="app-stat-row"><span class="muted">Ende</span><strong data-live-end-time>' + escapeHtml(currentEndTimeValue()) + '</strong></div>'
            + '</div>'
            + '<div class="app-grid">'
            + '<label class="app-field"><span>Projekt</span><select id="projectSelect">' + projectOptions() + '</select></label>'
            + '</div>'
            + manualTimeMarkup(entry)
            + '<div class="app-inline-actions">'
            + (canCheckIn ? '<button type="button" data-action="check_in" ' + (isBusy('check_in') ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('check_in', 'Start jetzt')) + '</button>' : '')
            + (canPause ? '<button type="button" data-action="pause" ' + (isBusy('pause') ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('pause', 'Pause buchen')) + '</button>' : '')
            + (canCheckOut ? '<button type="button" data-action="check_out" ' + (isBusy('check_out') ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('check_out', 'Ende jetzt')) + '</button>' : '')
            + '<button type="button" id="saveUpsert" ' + (isBusy('upsert') || blockedByOtherProject ? 'disabled' : '') + '>' + escapeHtml(buttonLabel('upsert', 'Zeiten speichern')) + '</button>'
            + '</div>'
            + otherProjectHint
            + '</section>'
            + attachmentSectionMarkup()
        );
    }

    function historyView() {
        return shell(
            '<section class="app-card app-grid">'
            + '<div><p class="muted">Historie</p><h1>Meine Zeiten</h1><p>Ihre erfassten Zeiten bleiben mit dem letzten bekannten Stand auch offline sichtbar.</p></div>'
            + '</section>'
            + timesheetHistoryMarkup()
        );
    }

    function projectView() {
        const entry = workEntry();
        const hasStartedEntry = !!(entry && entry.start_time);
        const preferred = preferredProject();
        const hasProjects = activeProjects().length > 0;
        const projectlessEntry = activeProjectlessWorkEntry();
        const projectSelectionButtonLabel = projectlessEntry
            ? 'Laufenden Einsatz zuordnen'
            : (hasStartedEntry ? 'Projekt fuer heute speichern' : 'Projekt fuer Check-in vormerken');
        const canStartWithoutProject = !hasStartedEntry
            && currentStatus() !== 'working'
            && currentStatus() !== 'paused'
            && !isBlockedByOtherActiveProject();
        const projectlessReturnCard = projectlessEntry
            ? '<div class="app-current-project-card">'
                + '<div><p class="muted">Laufender Einsatz</p><strong>Nicht zugeordnet</strong>'
                + '<p>' + escapeHtml(projectlessEntry.note || 'Keine Beschreibung hinterlegt.') + '</p></div>'
                + '<button type="button" id="returnToProjectlessEntry">Zum laufenden Einsatz</button>'
                + '</div>'
            : '';

        return shell(
            '<section class="app-card app-grid">'
            + '<div><p class="muted">Projektwahl</p><h1>Baustelle waehlen</h1><p>'
            + (hasStartedEntry
                ? 'Die gewaehlte Baustelle wird direkt dem heutigen Zeiteintrag zugeordnet.'
                : (hasProjects
                    ? 'Sie koennen hier vorab eine Baustelle vormerken. Beim naechsten Check-in wird sie automatisch verwendet.'
                    : 'Kein Projekt angelegt? Starten Sie ohne Projekt und beschreiben Sie kurz die Baustelle. Die Zuordnung erfolgt spaeter im Buero.'))
            + '</p></div>'
            + (hasProjects
                ? '<label class="app-field"><span>Aktives Projekt</span><select id="projectSelect">' + projectOptions() + '</select></label>'
                : '')
            + (hasProjects && preferred && !hasStartedEntry
                ? '<div class="app-empty"><strong>Vorgemerkt:</strong> ' + escapeHtml(preferred.project_number + ' - ' + preferred.name) + '</div>'
                : '')
            + projectlessReturnCard
            + (hasProjects
                ? '<button type="button" id="saveProjectSelection" ' + (isBusy('select_project') ? 'disabled' : '') + '>'
                    + escapeHtml(buttonLabel('select_project', projectSelectionButtonLabel))
                    + '</button>'
                : '')
            + (!hasStartedEntry && !projectlessEntry
                ? '<div class="app-projectless-card">'
                    + '<div><strong>' + escapeHtml(hasProjects ? 'Projekt nicht in der Liste?' : 'Ohne Projekt weiterarbeiten') + '</strong>'
                    + '<p>Starten Sie ohne Projekt und beschreiben Sie kurz die Baustelle. Die Zuordnung erfolgt spaeter im Buero.</p></div>'
                    + '<label class="app-field"><span>Baustelle / Ort</span><textarea id="projectlessProjectNote" rows="3" required placeholder="z. B. Musterstrasse 12, Neubau Garage"></textarea></label>'
                    + '<button type="button" id="startWithoutProject" ' + (isBusy('check_in') || !canStartWithoutProject ? 'disabled' : '') + '>'
                    + escapeHtml(buttonLabel('check_in', 'Ohne Projekt starten'))
                    + '</button>'
                    + '</div>'
                : (!entry || entry.project_id
                    ? ''
                    : '<div class="app-empty"><strong>Nicht zugeordnet:</strong> Diese Buchung kann spaeter im Buero einem Projekt zugeordnet werden.</div>'))
            + '</section>'
            + projectAttachmentSectionMarkup()
        );
    }

    function profileView() {
        const company = state.company || {};
        const companyName = compactLine([company.company_name, company.legal_form]) || '-';
        const address = compactBlock([
            compactLine([company.street, company.house_number]),
            compactLine([company.postal_code, company.city]),
            company.country
        ]);
        const register = compactBlock([company.register_court, company.commercial_register]);
        const tax = compactBlock([company.vat_id, company.tax_number], ' / ');

        return shell(
            '<section class="app-card app-grid">'
            + '<div><p class="muted">Profil</p><h1>Einstellungen und Firma</h1><p>Firmenangaben, Rechtstexte und GEO-Hinweise bleiben mit dem letzten bekannten Stand offline lesbar.</p></div>'
            + themeSettingsHint()
            + '</section>'
            + '<section class="app-card app-grid">'
            + '<div><p class="muted">Firmenprofil</p><h2>' + escapeHtml(companyName) + '</h2></div>'
            + appInfoRows([
                { label: 'Adresse', value: address },
                { label: 'E-Mail', value: company.email },
                { label: 'Telefon', value: company.phone },
                { label: 'Website', value: company.website },
                { label: 'Vertretung', value: company.managing_director },
                { label: 'Register', value: register },
                { label: 'Steuer', value: tax }
            ])
            + '</section>'
            + legalTextSection('AGB', company.agb_text)
            + legalTextSection('Datenschutz', company.datenschutz_text)
            + '<section class="app-card app-grid">'
            + '<div><p class="muted">GEO</p><h2>Standortfreigabe</h2></div>'
            + geoPolicyMarkup()
            + '</section>'
            + pushProfileSection()
            + installProfileSection()
        );
    }

    function pushProfileSection() {
        const support = pushSupport();
        const push = state.push || {};
        const devices = Array.isArray(push.devices) ? push.devices : [];
        const permission = support.notification ? Notification.permission : 'unsupported';
        const permissionLabel = permission === 'granted'
            ? 'erlaubt'
            : (permission === 'denied' ? 'blockiert' : 'offen');
        const statusRows = appInfoRows([
            { label: 'Status', value: push.enabled ? 'Global aktiv' : 'Global deaktiviert' },
            { label: 'Freigabe', value: push.can_subscribe ? 'Fuer Ihre Rolle freigegeben' : (push.permission_required ? 'Nicht fuer Ihre Rolle freigegeben' : 'Aktuell nicht verfuegbar') },
            { label: 'Browser', value: support.supported ? 'unterstuetzt' : 'nicht unterstuetzt' },
            { label: 'Berechtigung', value: permissionLabel },
            { label: 'Erinnerung', value: push.reminder_time ? 'taeglich ab ' + push.reminder_time + ' Uhr' : null }
        ]);
        const enableButton = support.supported && push.can_subscribe && permission !== 'denied'
            ? '<button type="button" id="enablePushButton" ' + (state.pushBusy ? 'disabled' : '') + '>' + escapeHtml(state.pushBusy ? 'Wird aktiviert ...' : 'Push aktivieren') + '</button>'
            : '';
        const reloadButton = state.online
            ? '<button type="button" id="reloadPushButton">Status aktualisieren</button>'
            : '';
        const deviceMarkup = devices.length > 0
            ? '<div class="app-info-list">' + devices.map((device) => {
                const status = device.is_enabled ? 'aktiv' : 'inaktiv';
                const disableButton = device.is_enabled
                    ? '<button type="button" data-disable-push-device="' + String(device.id) + '" ' + (state.pushBusy ? 'disabled' : '') + '>Deaktivieren</button>'
                    : '';

                return '<div class="app-info-row">'
                    + '<span class="muted">' + escapeHtml(status) + '</span>'
                    + '<strong>' + escapeHtml(device.device_label || 'Browser-Geraet') + '</strong>'
                    + '<span class="muted">Zuletzt gesehen: ' + escapeHtml(device.last_seen_at || '-') + '</span>'
                    + disableButton
                    + '</div>';
            }).join('') + '</div>'
            : '<div class="app-empty">Noch kein Geraet fuer Push aktiviert.</div>';
        const deniedHint = permission === 'denied'
            ? '<div class="app-empty">Benachrichtigungen sind im Browser blockiert. Bitte die Browser-Einstellung pruefen.</div>'
            : '';
        const unsupportedHint = !support.supported
            ? '<div class="app-empty">Dieser Browser unterstuetzt Push-Benachrichtigungen hier nicht.</div>'
            : '';

        return '<section class="app-card app-grid">'
            + '<div><p class="muted">Push</p><h2>Benachrichtigungen</h2><p>' + escapeHtml(push.notice_text || 'Erinnerungen werden nur fuer fehlende Tagesbuchungen gesendet.') + '</p></div>'
            + statusRows
            + deniedHint
            + unsupportedHint
            + '<div class="app-inline-actions">' + enableButton + reloadButton + '</div>'
            + deviceMarkup
            + '</section>';
    }

    function installProfileSection() {
        const installed = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
        const canInstall = state.installPromptAvailable && !installed;

        return '<section class="app-card app-grid">'
            + '<div><p class="muted">App</p><h2>Installation</h2><p>' + escapeHtml(installed ? 'Die App laeuft bereits im installierten Modus.' : 'Die mobile App kann auf geeigneten Geraeten installiert werden.') + '</p></div>'
            + '<div class="app-inline-actions">'
            + (canInstall ? '<button type="button" id="installAppButton">App installieren</button>' : '<button type="button" disabled>Installation aktuell nicht verfuegbar</button>')
            + '</div>'
            + '</section>';
    }

    function geoPolicyMarkup() {
        const policy = geoPolicy();

        if (!policy.enabled) {
            return '<div class="app-empty">GEO ist aktuell global deaktiviert.</div>';
        }

        const acknowledged = readGeoAck();

        return '<div class="app-grid">'
            + '<p>' + escapeHtml(policy.notice_text || 'Bei Zustimmung kann die Position zusammen mit Zeiteintraegen uebermittelt werden.') + '</p>'
            + '<label class="app-field"><span>GEO-Zustimmung</span><select id="geoAckSelect">'
            + '<option value="0"' + (!acknowledged ? ' selected' : '') + '>Noch nicht bestaetigt</option>'
            + '<option value="1"' + (acknowledged ? ' selected' : '') + '>Zugestimmt</option>'
            + '</select></label>'
            + '</div>';
    }

    function pushSupport() {
        const notification = 'Notification' in window;
        const serviceWorker = 'serviceWorker' in navigator;
        const pushManager = 'PushManager' in window;

        return {
            notification,
            serviceWorker,
            pushManager,
            supported: notification && serviceWorker && pushManager
        };
    }

    function urlBase64ToUint8Array(value) {
        const padding = '='.repeat((4 - value.length % 4) % 4);
        const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const output = new Uint8Array(rawData.length);

        for (let index = 0; index < rawData.length; index++) {
            output[index] = rawData.charCodeAt(index);
        }

        return output;
    }

    function projectOptions() {
        const items = state.today && state.today.projects ? state.today.projects : [];
        const explicitSelection = projectSelectionStateId();
        const activeId = typeof explicitSelection !== 'undefined'
            ? explicitSelection
            : (state.today && state.today.today_state && state.today.today_state.work_entry
                ? state.today.today_state.work_entry.project_id
                : preferredProjectId());

        const options = ['<option value="">Nicht zugeordnet</option>'];

        items.forEach((project) => {
            const selected = activeId === project.id ? ' selected' : '';
            options.push('<option value="' + project.id + '"' + selected + '>' + escapeHtml(project.project_number + ' - ' + project.name) + '</option>');
        });

        return options.join('');
    }

    function timesheetProjectFilterOptions() {
        const items = state.today && Array.isArray(state.today.projects) ? state.today.projects : [];
        const activeId = currentTimesheetProjectId();
        const options = ['<option value=""' + (activeId === null ? ' selected' : '') + '>Nicht zugeordnet</option>'];

        items.forEach((project) => {
            const selected = activeId === project.id ? ' selected' : '';
            options.push('<option value="' + project.id + '"' + selected + '>' + escapeHtml(project.project_number + ' - ' + project.name) + '</option>');
        });

        return options.join('');
    }

    function render() {
        if (!root) {
            return;
        }

        let html = '';

        if (!state.session.authenticated) {
            html = loginView();
        } else if (routeName() === '/zeiten') {
            html = timesView();
        } else if (routeName() === '/historie') {
            html = historyView();
        } else if (routeName() === '/projektwahl') {
            html = projectView();
        } else if (routeName() === '/profil') {
            html = profileView();
        } else {
            html = todayView();
        }

        root.innerHTML = html;
        syncThemeControls();
        bindInteractions();
    }

    function bindInteractions() {
        const menuToggle = document.getElementById('appMenuToggle');

        if (menuToggle) {
            menuToggle.addEventListener('click', function () {
                state.menuOpen = !state.menuOpen;
                render();
            });
        }

        const menuClose = document.getElementById('appMenuClose');

        if (menuClose) {
            menuClose.addEventListener('click', function () {
                state.menuOpen = false;
                render();
            });
        }

        const menuCloseButton = document.getElementById('appMenuCloseButton');

        if (menuCloseButton) {
            menuCloseButton.addEventListener('click', function () {
                state.menuOpen = false;
                render();
            });
        }

        const feedbackClose = document.getElementById('feedbackClose');

        if (feedbackClose) {
            feedbackClose.addEventListener('click', function () {
                clearFeedback();
            });
        }

        const dialogCancel = document.getElementById('dialogCancel');

        if (dialogCancel) {
            dialogCancel.addEventListener('click', function () {
                closeDialog();
            });
        }

        const dialogConfirmPrimary = document.getElementById('dialogConfirmPrimary');

        if (dialogConfirmPrimary) {
            dialogConfirmPrimary.addEventListener('click', async function () {
                if (state.dialog && state.dialog.type === 'confirm-check-in-without-project') {
                    const note = requireProjectlessNote('projectlessDialogNote');

                    if (note === null) {
                        return;
                    }

                    const payload = state.dialog.payload || {};

                    state.dialog = null;
                    await submitAction('check_in', { ...payload, project_id: null, note });
                }
            });
        }

        const dialogConfirmSecondary = document.getElementById('dialogConfirmSecondary');

        if (dialogConfirmSecondary) {
            dialogConfirmSecondary.addEventListener('click', function () {
                state.dialog = null;
                navigate('/app/projektwahl');
            });
        }

        document.querySelectorAll('[data-pause-minutes]').forEach((button) => {
            button.addEventListener('click', async function () {
                const minutes = Number(button.getAttribute('data-pause-minutes') || '0');

                state.dialog = null;
                await submitAction('pause', { manual_break_minutes: minutes });
            });
        });

        const loginForm = document.getElementById('loginForm');

        if (loginForm) {
            loginForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                const formData = new FormData(loginForm);
                const response = await fetch('/api/v1/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email: formData.get('email'),
                        password: formData.get('password')
                    })
                });
                const payload = await response.json();
                const notice = document.getElementById('loginNotice');

                if (!response.ok) {
                    if (notice) {
                        notice.textContent = friendlyMessage(payload.message || payload.error, 'Login fehlgeschlagen.');
                    }

                    showFeedback('error', friendlyMessage(payload.message || payload.error, 'Login fehlgeschlagen.'));

                    return;
                }

                state.session = payload.session || { authenticated: true, user: payload.user || null };
                await cacheSet('session', state.session);
                await loadOnlineData(true);
                showFeedback('success', friendlyMessage(payload.message, 'Login erfolgreich.'));
                navigate('/app/heute');
            });
        }

        document.querySelectorAll('[data-action]').forEach((button) => {
            button.addEventListener('click', async function () {
                await enqueueAction(button.getAttribute('data-action'));
            });
        });

        const upsertButton = document.getElementById('saveUpsert');

        if (upsertButton) {
            upsertButton.addEventListener('click', async function () {
                await enqueueAction('upsert');
            });
        }

        const projectSelection = document.getElementById('saveProjectSelection');

        if (projectSelection) {
            projectSelection.addEventListener('click', async function () {
                await handleProjectSelection();
            });
        }

        const returnToProjectlessEntry = document.getElementById('returnToProjectlessEntry');

        if (returnToProjectlessEntry) {
            returnToProjectlessEntry.addEventListener('click', function () {
                setProjectSelectionState(null);
                navigate('/app/heute');
            });
        }

        const startWithoutProject = document.getElementById('startWithoutProject');

        if (startWithoutProject) {
            startWithoutProject.addEventListener('click', async function () {
                const note = requireProjectlessNote('projectlessProjectNote');

                if (note === null) {
                    return;
                }

                storePreferredProjectId(null);

                const saved = await submitAction('check_in', { project_id: null, note });

                if (saved) {
                    navigate('/app/heute');
                }
            });
        }

        const projectSelect = document.getElementById('projectSelect');

        if (projectSelect) {
            projectSelect.addEventListener('change', function () {
                setProjectSelectionState(selectedProjectId());
                render();
                if (routeName() === '/projektwahl') {
                    loadProjectFiles(projectFileContextId(), false);
                }
            });
        }

        document.querySelectorAll('[data-timesheet-scope]').forEach((button) => {
            button.addEventListener('click', function () {
                storeTimesheetFilterScope(button.getAttribute('data-timesheet-scope') || 'project');
                render();
                loadTimesheetList(false);
            });
        });

        const timesheetProjectFilter = document.getElementById('timesheetProjectFilter');

        if (timesheetProjectFilter) {
            timesheetProjectFilter.addEventListener('change', function () {
                const projectId = timesheetProjectFilter.value ? Number(timesheetProjectFilter.value) : null;

                storeTimesheetFilterProjectId(projectId);
                render();
                loadTimesheetList(false);
            });
        }

        document.querySelectorAll('[data-theme-mode-button]').forEach((button) => {
            button.addEventListener('click', function () {
                setTheme(button.getAttribute('data-theme-mode-button') || 'system');
            });
        });

        const geoAckSelect = document.getElementById('geoAckSelect');

        if (geoAckSelect) {
            geoAckSelect.addEventListener('change', function () {
                try {
                    window.localStorage.setItem(GEO_ACK_KEY, geoAckSelect.value === '1' ? '1' : '0');
                    showFeedback('success', geoAckSelect.value === '1' ? 'GEO-Zustimmung wurde lokal gespeichert.' : 'GEO-Zustimmung wurde lokal zurueckgenommen.');
                } catch (error) {
                    console.warn('GEO-Zustimmung konnte nicht gespeichert werden.', error);
                    showFeedback('error', 'GEO-Zustimmung konnte nicht gespeichert werden.');
                }
            });
        }

        const enablePushButton = document.getElementById('enablePushButton');

        if (enablePushButton) {
            enablePushButton.addEventListener('click', async function () {
                await enablePushNotifications();
            });
        }

        const reloadPushButton = document.getElementById('reloadPushButton');

        if (reloadPushButton) {
            reloadPushButton.addEventListener('click', async function () {
                await loadPushStatus(false);
            });
        }

        document.querySelectorAll('[data-disable-push-device]').forEach((button) => {
            button.addEventListener('click', async function () {
                await disablePushDevice(Number(button.getAttribute('data-disable-push-device') || '0'));
            });
        });

        const installAppButton = document.getElementById('installAppButton');

        if (installAppButton) {
            installAppButton.addEventListener('click', async function () {
                await promptAppInstall();
            });
        }

        const logoutButton = document.getElementById('logoutButton');

        if (logoutButton) {
            logoutButton.addEventListener('click', async function () {
                await fetch('/api/v1/auth/logout', { method: 'POST' });
                state.session = { authenticated: false, user: null };
                state.menuOpen = false;
                resetProjectSelectionState();
                await cacheSet('session', state.session);
                showFeedback('success', 'Sie wurden erfolgreich abgemeldet.');
                navigate('/app/login');
            });
        }

        const uploadAttachmentButton = document.getElementById('uploadAttachmentButton');

        if (uploadAttachmentButton) {
            uploadAttachmentButton.addEventListener('click', async function () {
                await uploadTimesheetAttachment();
            });
        }

        const uploadProjectAttachmentButton = document.getElementById('uploadProjectAttachmentButton');

        if (uploadProjectAttachmentButton) {
            uploadProjectAttachmentButton.addEventListener('click', async function () {
                await uploadProjectAttachment();
            });
        }

        bindExclusiveFileInputs(['timesheetCameraInput', 'timesheetAttachmentInput']);
        bindExclusiveFileInputs(['projectCameraInput', 'projectAttachmentInput']);

        document.querySelectorAll('[data-delete-attachment]').forEach((button) => {
            button.addEventListener('click', async function () {
                await archiveTimesheetAttachment(Number(button.getAttribute('data-delete-attachment') || '0'));
            });
        });

        document.querySelectorAll('[data-app-link]').forEach((link) => {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                navigate(link.getAttribute('href'));
            });
        });
    }

    function bindExclusiveFileInputs(inputIds) {
        inputIds.forEach((inputId) => {
            const input = document.getElementById(inputId);

            if (!input) {
                return;
            }

            input.addEventListener('pointerdown', function () {
                markFileSelectionGuard(30000);
            });

            input.addEventListener('click', function () {
                markFileSelectionGuard(30000);
            });

            input.addEventListener('change', function () {
                if (!input.files || input.files.length === 0) {
                    clearFileSelectionGuardIfIdle();
                    return;
                }

                markFileSelectionGuard(10 * 60 * 1000);

                inputIds
                    .filter((otherId) => otherId !== inputId)
                    .forEach((otherId) => {
                        const other = document.getElementById(otherId);

                        if (other) {
                            other.value = '';
                        }
                    });
            });
        });
    }

    function navigate(path) {
        state.menuOpen = false;
        state.route = path;
        window.history.pushState({}, '', path);
        render();

        if (state.session.authenticated) {
            loadOnlineData(false);
        }
    }

    async function applyCachedData() {
        const cachedToday = await cacheGet('today');
        const cachedCompany = await cacheGet('company');

        if (cachedToday) {
            state.today = cachedToday;
            syncTodayStateStatus();
            normalizeTimesheetFilterAgainstToday();
        }

        if (cachedCompany) {
            state.company = cachedCompany;
        }

        if (routeName() !== '/historie') {
            return;
        }

        const cachedTimesheets = await cacheGet(currentTimesheetCacheKey());

        if (cachedTimesheets && Array.isArray(cachedTimesheets.items)) {
            state.timesheetList = cachedTimesheets.items;
            state.timesheetListUpdatedAt = cachedTimesheets.cached_at || null;
            state.timesheetListOffline = true;
        } else {
            state.timesheetList = [];
            state.timesheetListUpdatedAt = null;
            state.timesheetListOffline = true;
        }
    }

    async function loadOnlineData(force) {
        if (!state.session.authenticated) {
            return;
        }

        if (!navigator.onLine) {
            await applyCachedData();
            if (routeName() === '/projektwahl') {
                await loadProjectFiles(projectFileContextId(), force);
            }
            render();
            return;
        }

        try {
            const [dayResponse, companyResponse, pushResponse] = await Promise.all([
                fetch('/api/v1/app/me/day'),
                fetch('/api/v1/settings/company'),
                fetch('/api/v1/app/push/status')
            ]);

            if (dayResponse.ok) {
                const dayPayload = await dayResponse.json();
                state.today = dayPayload.data;
                syncTodayStateStatus();
                normalizeProjectSelectionAgainstToday();
                normalizeTimesheetFilterAgainstToday();
                await cacheSet('today', state.today);
            }

            if (companyResponse.ok) {
                const companyPayload = await companyResponse.json();
                state.company = companyPayload.data;
                await cacheSet('company', state.company);
            }

            if (pushResponse.ok) {
                const pushPayload = await pushResponse.json();
                state.push = pushPayload.data || null;
            }

            if (routeName() === '/historie') {
                await loadTimesheetList(force);
            }

            if (routeName() === '/projektwahl') {
                await loadProjectFiles(projectFileContextId(), force);
            }
        } catch (error) {
            console.warn('App-Daten konnten nicht geladen werden.', error);
            await applyCachedData();

            if (!force) {
                showFeedback('error', 'Die aktuellen App-Daten konnten nicht geladen werden. Bitte erneut versuchen.');
            }
        }

        state.pendingCount = (await queueAll()).length;
        render();
    }

    async function loadTimesheetList(force) {
        if (!state.session.authenticated) {
            return;
        }

        const cacheKey = currentTimesheetCacheKey();

        if (!navigator.onLine) {
            const cached = await cacheGet(cacheKey);

            if (cached && Array.isArray(cached.items)) {
                state.timesheetList = cached.items;
                state.timesheetListUpdatedAt = cached.cached_at || null;
                state.timesheetListOffline = true;
            } else {
                state.timesheetList = [];
                state.timesheetListUpdatedAt = null;
                state.timesheetListOffline = true;
            }

            state.timesheetListLoading = false;
            render();
            return;
        }

        state.timesheetListLoading = true;
        render();

        try {
            const params = new URLSearchParams();

            params.set('scope', state.timesheetFilterScope);

            if (state.timesheetFilterScope !== 'all') {
                const projectId = currentTimesheetProjectId();

                if (projectId !== null) {
                    params.set('project_id', String(projectId));
                }
            }

            const response = await fetch('/api/v1/app/me/timesheets?' + params.toString());
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || payload.error || 'Die Zeiten konnten nicht geladen werden.');
            }

            const data = payload.data || {};
            const cachedAt = data.cached_at || data.server_time || null;

            state.timesheetList = Array.isArray(data.items) ? data.items : [];
            state.timesheetListUpdatedAt = cachedAt;
            state.timesheetListOffline = false;

            await cacheSet(cacheKey, {
                items: state.timesheetList,
                cached_at: cachedAt,
                scope: data.scope || state.timesheetFilterScope,
                project_id: Object.prototype.hasOwnProperty.call(data, 'project_id') ? data.project_id : currentTimesheetProjectId()
            });
        } catch (error) {
            const cached = await cacheGet(cacheKey);

            if (cached && Array.isArray(cached.items)) {
                state.timesheetList = cached.items;
                state.timesheetListUpdatedAt = cached.cached_at || null;
                state.timesheetListOffline = true;
            } else {
                state.timesheetList = [];
                state.timesheetListUpdatedAt = null;
                state.timesheetListOffline = false;
            }

            if (!force) {
                showFeedback('error', friendlyMessage(error.message, 'Die Zeiten konnten nicht geladen werden.'));
            }
        } finally {
            state.timesheetListLoading = false;
            render();
        }
    }

    async function loadProjectFiles(projectId, force) {
        if (!state.session.authenticated || projectId === null) {
            return;
        }

        const cacheKey = projectFilesCacheKey(projectId);

        if (!navigator.onLine) {
            const cached = await cacheGet(cacheKey);
            state.projectFiles[String(projectId)] = cached && Array.isArray(cached.items) ? cached.items : [];
            state.projectFilesLoading = false;
            render();
            return;
        }

        state.projectFilesLoading = true;
        render();

        try {
            const response = await fetch('/api/v1/app/projects/' + projectId + '/files');
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || payload.error || 'Projektdateien konnten nicht geladen werden.');
            }

            const files = Array.isArray(payload.data) ? payload.data : [];
            state.projectFiles[String(projectId)] = files;

            await cacheSet(cacheKey, {
                items: files,
                cached_at: new Date().toISOString()
            });
        } catch (error) {
            const cached = await cacheGet(cacheKey);
            state.projectFiles[String(projectId)] = cached && Array.isArray(cached.items) ? cached.items : [];

            if (!force) {
                showFeedback('error', friendlyMessage(error.message, 'Projektdateien konnten nicht geladen werden.'));
            }
        } finally {
            state.projectFilesLoading = false;
            render();
        }
    }

    async function loadPushStatus(showErrors) {
        if (!state.session.authenticated || !navigator.onLine) {
            return;
        }

        state.pushLoading = true;
        render();

        try {
            const response = await fetch('/api/v1/app/push/status');
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || payload.error || 'Push-Status konnte nicht geladen werden.');
            }

            state.push = payload.data || null;
        } catch (error) {
            if (showErrors) {
                showFeedback('error', friendlyMessage(error.message, 'Push-Status konnte nicht geladen werden.'));
            }
        } finally {
            state.pushLoading = false;
            render();
        }
    }

    async function enablePushNotifications() {
        const support = pushSupport();

        if (!support.supported) {
            showFeedback('error', 'Dieser Browser unterstuetzt Push-Benachrichtigungen hier nicht.');
            return;
        }

        if (!state.push || !state.push.can_subscribe || !state.push.vapid_public_key) {
            showFeedback('error', 'Push ist aktuell nicht freigegeben oder nicht konfiguriert.');
            return;
        }

        state.pushBusy = true;
        render();

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                showFeedback('error', 'Push-Berechtigung wurde nicht erteilt.');
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(state.push.vapid_public_key)
            });
            const body = subscription.toJSON();

            body.permission_status = permission;
            body.device_label = navigator.userAgent && navigator.userAgent.includes('Mobile') ? 'Mobiles Geraet' : 'Browser-Geraet';

            const response = await fetch('/api/v1/app/push/subscriptions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || payload.error || 'Push konnte nicht aktiviert werden.');
            }

            state.push = {
                ...(state.push || {}),
                devices: payload.data && Array.isArray(payload.data.devices) ? payload.data.devices : []
            };
            showFeedback('success', friendlyMessage(payload.message, 'Push wurde aktiviert.'));
        } catch (error) {
            showFeedback('error', friendlyMessage(error.message, 'Push konnte nicht aktiviert werden.'));
        } finally {
            state.pushBusy = false;
            render();
        }
    }

    async function disablePushDevice(deviceId) {
        if (!deviceId || !navigator.onLine) {
            showFeedback('error', 'Push-Geraete koennen nur online geaendert werden.');
            return;
        }

        state.pushBusy = true;
        render();

        try {
            const response = await fetch('/api/v1/app/push/subscriptions/' + deviceId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: '_method=DELETE'
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || payload.error || 'Push konnte nicht deaktiviert werden.');
            }

            state.push = {
                ...(state.push || {}),
                devices: payload.data && Array.isArray(payload.data.devices) ? payload.data.devices : []
            };
            showFeedback('success', friendlyMessage(payload.message, 'Push wurde deaktiviert.'));
        } catch (error) {
            showFeedback('error', friendlyMessage(error.message, 'Push konnte nicht deaktiviert werden.'));
        } finally {
            state.pushBusy = false;
            render();
        }
    }

    async function promptAppInstall() {
        if (!deferredInstallPrompt) {
            showFeedback('error', 'Die Installation ist aktuell nicht verfuegbar.');
            return;
        }

        const promptEvent = deferredInstallPrompt;
        deferredInstallPrompt = null;
        state.installPromptAvailable = false;
        promptEvent.prompt();

        try {
            await promptEvent.userChoice;
        } catch (error) {
            console.warn('Installationsdialog wurde ohne Ergebnis geschlossen.', error);
        }

        render();
    }

    async function enqueueAction(action) {
        const projectSelect = document.getElementById('projectSelect');
        const projectId = projectSelect
            ? projectSelect.value || null
            : (workEntry() ? workEntry().project_id : preferredProjectId());

        if (action === 'check_in' && !projectId) {
            const noteField = document.getElementById('timesheetNote');

            openDialog('confirm-check-in-without-project', {
                note: noteField && typeof noteField.value === 'string' ? noteField.value : ''
            });
            return;
        }

        if (action === 'pause') {
            openDialog('pause-minutes');
            return;
        }

        await submitAction(action, {});
    }

    async function handleProjectSelection() {
        const projectSelect = document.getElementById('projectSelect');
        const selectedProjectId = projectSelect && projectSelect.value ? Number(projectSelect.value) : null;
        const projectlessEntry = activeProjectlessWorkEntry();
        const entry = workEntry();
        const hasStartedEntry = !!(entry && entry.start_time);

        if (projectlessEntry) {
            if (selectedProjectId === null) {
                setProjectSelectionState(null);
                navigate('/app/heute');

                return true;
            }

            const saved = await submitAction('select_project', { project_id: selectedProjectId });

            if (saved) {
                navigate('/app/heute');
            }

            return saved;
        }

        if (!hasStartedEntry) {
            storePreferredProjectId(selectedProjectId);
            showFeedback(
                'success',
                selectedProjectId
                    ? 'Baustelle erfolgreich vorgemerkt. Beim naechsten Check-in wird sie verwendet.'
                    : 'Keine Baustelle vorgemerkt. Sie koennen den Check-in auch ohne Projekt buchen.'
            );
            navigate('/app/heute');

            return true;
        }

        const saved = await submitAction('select_project', {});

        if (saved) {
            navigate('/app/heute');
        }

        return saved;
    }

    function manualTimesOverride() {
        const manualStartTime = document.getElementById('manualStartTime');
        const manualEndTime = document.getElementById('manualEndTime');

        return {
            start_time: manualStartTime && manualStartTime.value ? manualStartTime.value : null,
            end_time: manualEndTime && manualEndTime.value ? manualEndTime.value : null,
        };
    }

    async function submitAction(action, overrides) {
        if (!state.session.authenticated) {
            showFeedback('error', 'Bitte zuerst anmelden.');
            return false;
        }

        const allowProjectlessReassignment = action === 'select_project' && activeProjectlessWorkEntry() !== null;

        if (isBlockedByOtherActiveProject() && !allowProjectlessReassignment) {
            showFeedback(
                'error',
                'Auf ' + (otherActiveProjectName() || 'einem anderen Projekt') + ' laeuft noch ein Einsatz. Bitte zuerst dorthin wechseln.'
            );
            return false;
        }

        const projectSelect = document.getElementById('projectSelect');
        const noteField = document.getElementById('timesheetNote');
        const entry = workEntry();
        const manualTimes = manualTimesOverride();
        const payload = {
            action: action,
            work_date: state.today ? state.today.today : new Date().toISOString().slice(0, 10),
            project_id: projectSelect
                ? projectSelect.value || null
                : (entry ? entry.project_id : preferredProjectId()),
            note: noteField ? noteField.value : (entry ? entry.note : ''),
            manual_break_minutes: entry ? entry.break_minutes : 0
        };

        if (action === 'check_in') {
            payload.start_time = manualTimes.start_time || nowTime();
        }

        if (action === 'check_out' || action === 'day_close') {
            payload.end_time = manualTimes.end_time || nowTime();
        }

        if (action === 'upsert') {
            payload.start_time = manualTimes.start_time || (entry && entry.start_time ? entry.start_time : null);
            payload.end_time = manualTimes.end_time || (entry && entry.end_time ? entry.end_time : null);
        }

        Object.assign(payload, overrides || {});

        const geo = await currentGeoPayload();

        if (geo) {
            payload.geo = geo;
            payload.geo_acknowledged = readGeoAck();
        }

        state.pendingAction = action;
        render();
        let succeeded = false;

        try {
            const clientRequestId = createClientId();

            if (typeof clientRequestId !== 'string' || clientRequestId.trim() === '') {
                throw new Error('Die Aktion konnte nicht vorbereitet werden. Bitte erneut versuchen.');
            }

            await queueAdd({
                client_request_id: clientRequestId,
                endpoint: '/api/v1/app/timesheets/sync',
                payload
            });

            state.pendingCount = (await queueAll()).length;
            applyOptimisticPayload(payload);
            await cacheSet('today', state.today);
            render();
            await syncQueue();
            await registerBackgroundSync();
            succeeded = true;
        } catch (error) {
            showFeedback('error', friendlyMessage(error.message, 'Die Aktion konnte nicht vorbereitet werden. Bitte erneut versuchen.'), true);
        } finally {
            state.pendingAction = null;
            render();
        }

        return succeeded;
    }

    function applyOptimisticPayload(payload) {
        if (!state.today) {
            return;
        }

        const todayState = state.today.today_state || {};
        const previousEntry = todayState.work_entry || null;
        const startsFreshEntry = payload.action === 'check_in'
            && previousEntry
            && previousEntry.start_time
            && previousEntry.end_time;
        const workEntry = startsFreshEntry ? {
            id: null,
            project_id: null,
            project_name: null,
            work_date: state.today.today,
            start_time: null,
            end_time: null,
            break_minutes: 0,
            net_minutes: 0,
            note: null,
            attachments: []
        } : (previousEntry || {
            project_id: null,
            project_name: null,
            work_date: state.today.today,
            start_time: null,
            end_time: null,
            break_minutes: 0,
            net_minutes: 0,
            note: null
        });
        const todayBreaks = breaksToday().slice();

        if (payload.project_id !== undefined) {
            workEntry.project_id = payload.project_id ? Number(payload.project_id) : null;
            const match = (state.today.projects || []).find((project) => project.id === workEntry.project_id);
            workEntry.project_name = match ? match.name : null;
        }

        if (payload.note !== undefined) {
            workEntry.note = payload.note;
        }

        if (payload.start_time) {
            workEntry.start_time = payload.start_time;
        }

        if (payload.end_time) {
            workEntry.end_time = payload.end_time;
        }

        if (payload.manual_break_minutes !== undefined) {
            workEntry.break_minutes = Number(payload.manual_break_minutes) || 0;
        }

        if (payload.action === 'check_in') {
            workEntry.end_time = null;
            workEntry.break_minutes = 0;
            workEntry.attachments = [];
        }

        if (payload.action === 'pause') {
            todayBreaks.length = 0;
        }

        if (payload.action === 'pause_start') {
            todayBreaks.push({
                id: Date.now() * -1,
                break_started_at: payload.break_started_at || new Date().toISOString(),
                break_ended_at: null,
                source: 'app',
                note: null
            });
        }

        if (payload.action === 'pause_end') {
            const openBreak = todayBreaks.find((item) => !item.break_ended_at);

            if (openBreak) {
                openBreak.break_ended_at = payload.break_ended_at || new Date().toISOString();
            }
        }

        todayState.work_entry = workEntry;
        state.today.breaks_today = todayBreaks;
        state.today.attachments = workEntry.attachments || [];
        state.today.today_state = todayState;
        workEntry.break_minutes = payload.action === 'pause'
            ? Number(payload.manual_break_minutes || 0)
            : breakMinutesFromCollection(todayBreaks);
        syncTodayStateStatus();
        syncSelectedProjectSummaryFromTodayState();
    }

    async function syncQueue() {
        if (!navigator.onLine || !state.session.authenticated) {
            return;
        }

        const items = await queueAll();

        for (const item of items) {
            try {
                await queueUpdate({ ...item, status: 'syncing' });
                const response = await fetch(item.endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        client_request_id: item.client_request_id,
                        ...item.payload
                    })
                });
                const payload = await response.json();

                if (!response.ok) {
                    const message = friendlyMessage(payload.message || payload.error, 'Synchronisierung fehlgeschlagen.');

                    await queueUpdate({ ...item, status: 'failed', error: message });
                    showFeedback('error', message, true);
                    continue;
                }

                await queueRemove(item.id);

                if (state.today && payload.data) {
                    if (payload.data.today_state) {
                        state.today.today_state = payload.data.today_state;
                    }

                    if (Array.isArray(payload.data.breaks_today)) {
                        state.today.breaks_today = payload.data.breaks_today;
                    }

                    if (Object.prototype.hasOwnProperty.call(payload.data, 'current_break')) {
                        state.today.current_break = payload.data.current_break;
                    }

                    if (payload.data.tracked_minutes_live_basis) {
                        state.today.tracked_minutes_live_basis = payload.data.tracked_minutes_live_basis;
                    } else if (payload.data.today_state && payload.data.today_state.tracked_minutes_live_basis) {
                        state.today.tracked_minutes_live_basis = payload.data.today_state.tracked_minutes_live_basis;
                    }

                    if (payload.data.timesheet) {
                        state.today.today_state = state.today.today_state || {};
                        state.today.today_state.work_entry = payload.data.timesheet;
                    }

                    if (payload.data.timesheet && Array.isArray(payload.data.timesheet.attachments)) {
                        state.today.attachments = payload.data.timesheet.attachments;
                    }

                    if (payload.data.server_time) {
                        state.today.server_time = payload.data.server_time;
                    }

                    syncTodayStateStatus();
                }

                showFeedback('success', friendlyMessage(payload.message || (payload.data ? payload.data.message : ''), 'Aenderung erfolgreich gespeichert.'));
            } catch (error) {
                const message = friendlyMessage(error.message, 'Synchronisierung fehlgeschlagen.');

                await queueUpdate({ ...item, status: 'failed', error: message });
                showFeedback('error', message, true);
            }
        }

        state.pendingCount = (await queueAll()).length;
        await cacheSet('today', state.today);
        await loadOnlineData(true);
    }

    async function currentGeoPayload() {
        const policy = geoPolicy();

        if (!policy || !policy.enabled || !readGeoAck() || !navigator.geolocation) {
            return null;
        }

        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(function (position) {
                resolve({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy_meters: position.coords.accuracy,
                    recorded_at: new Date(position.timestamp).toISOString()
                });
            }, function () {
                resolve(null);
            }, {
                enableHighAccuracy: false,
                timeout: 4000,
                maximumAge: 60000
            });
        });
    }

    function updateCurrentAttachments(files) {
        const entry = workEntry();

        if (!entry) {
            return;
        }

        entry.attachments = Array.isArray(files) ? files : [];
        state.today.attachments = entry.attachments;
        state.today.today_state = state.today.today_state || {};
        state.today.today_state.work_entry = entry;
    }

    function selectedUploadFile(inputIds) {
        for (const inputId of inputIds) {
            const input = document.getElementById(inputId);

            if (input && input.files && input.files.length > 0) {
                return input.files[0];
            }
        }

        return null;
    }

    function uploadInputIds() {
        return [
            'timesheetCameraInput',
            'timesheetAttachmentInput',
            'projectCameraInput',
            'projectAttachmentInput'
        ];
    }

    function hasPendingUploadSelection() {
        return uploadInputIds().some((inputId) => {
            const input = document.getElementById(inputId);

            return !!(input && input.files && input.files.length > 0);
        });
    }

    function markFileSelectionGuard(durationMs) {
        state.fileSelectionGuardUntil = Date.now() + durationMs;
    }

    function clearFileSelectionGuardIfIdle() {
        if (!hasPendingUploadSelection()) {
            state.fileSelectionGuardUntil = 0;
        }
    }

    function shouldSkipRevalidateForFileSelection() {
        return Date.now() < Number(state.fileSelectionGuardUntil || 0) || hasPendingUploadSelection();
    }

    function updateProjectAttachments(projectId, files) {
        if (projectId === null) {
            return;
        }

        state.projectFiles[String(projectId)] = Array.isArray(files) ? files : [];
    }

    async function uploadTimesheetAttachment() {
        const entry = workEntry();
        const selectedFile = selectedUploadFile(['timesheetCameraInput', 'timesheetAttachmentInput']);

        if (!entry || !entry.id) {
            showFeedback('error', 'Bitte zuerst einen Zeiteintrag buchen, bevor Sie ein Bild hochladen.');
            return;
        }

        if (!state.online) {
            showFeedback('error', 'Bild-Uploads sind nur mit aktiver Verbindung moeglich.');
            return;
        }

        if (!selectedFile) {
            showFeedback('error', 'Bitte zuerst eine Datei auswaehlen.');
            return;
        }

        const formData = new FormData();
        formData.append('file', selectedFile);

        state.pendingAction = 'upload_attachment';
        state.uploadingTimesheetId = entry.id;
        render();

        try {
            const response = await fetch('/api/v1/app/timesheets/' + entry.id + '/files', {
                method: 'POST',
                body: formData
            });
            const payload = await response.json();

            if (!response.ok) {
                showFeedback('error', friendlyMessage(payload.message || payload.error, 'Das Bild konnte nicht hochgeladen werden.'), true);
                return;
            }

            updateCurrentAttachments(payload.data && payload.data.files ? payload.data.files : []);
            await cacheSet('today', state.today);
            showFeedback('success', friendlyMessage(payload.message, 'Bild erfolgreich hochgeladen.'));
        } catch (error) {
            showFeedback('error', friendlyMessage(error.message, 'Das Bild konnte nicht hochgeladen werden.'), true);
        } finally {
            state.pendingAction = null;
            state.uploadingTimesheetId = null;
            state.fileSelectionGuardUntil = 0;
            render();
        }
    }

    async function uploadProjectAttachment() {
        const projectId = projectFileContextId();
        const selectedFile = selectedUploadFile(['projectCameraInput', 'projectAttachmentInput']);

        if (projectId === null) {
            showFeedback('error', 'Bitte zuerst ein Projekt auswaehlen.');
            return;
        }

        if (!state.online) {
            showFeedback('error', 'Projektdateien koennen nur mit aktiver Verbindung hochgeladen werden.');
            return;
        }

        if (!hasPermission('files.upload')) {
            showFeedback('error', 'Projektdatei-Uploads sind fuer Ihre Rolle nicht freigegeben.');
            return;
        }

        if (!selectedFile) {
            showFeedback('error', 'Bitte zuerst eine Datei auswaehlen.');
            return;
        }

        const formData = new FormData();
        formData.append('file', selectedFile);

        state.pendingAction = 'upload_project_attachment';
        state.uploadingProjectId = projectId;
        render();

        try {
            const response = await fetch('/api/v1/app/projects/' + projectId + '/files', {
                method: 'POST',
                body: formData
            });
            const payload = await response.json();

            if (!response.ok) {
                showFeedback('error', friendlyMessage(payload.message || payload.error, 'Die Datei konnte nicht hochgeladen werden.'), true);
                return;
            }

            updateProjectAttachments(projectId, payload.data && payload.data.files ? payload.data.files : []);
            await cacheSet(projectFilesCacheKey(projectId), {
                items: state.projectFiles[String(projectId)] || [],
                cached_at: new Date().toISOString()
            });
            showFeedback('success', friendlyMessage(payload.message, 'Datei erfolgreich hochgeladen.'));
        } catch (error) {
            showFeedback('error', friendlyMessage(error.message, 'Die Datei konnte nicht hochgeladen werden.'), true);
        } finally {
            state.pendingAction = null;
            state.uploadingProjectId = null;
            state.fileSelectionGuardUntil = 0;
            render();
        }
    }

    async function archiveTimesheetAttachment(fileId) {
        const entry = workEntry();

        if (!entry || !entry.id || fileId <= 0) {
            showFeedback('error', 'Dieses Bild konnte nicht entfernt werden.');
            return;
        }

        state.pendingAction = 'upload_attachment';
        render();

        try {
            const response = await fetch('/api/v1/app/timesheet-files/' + fileId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: '_method=DELETE'
            });
            const payload = await response.json();

            if (!response.ok) {
                showFeedback('error', friendlyMessage(payload.message || payload.error, 'Das Bild konnte nicht entfernt werden.'), true);
                return;
            }

            updateCurrentAttachments(payload.data && payload.data.files ? payload.data.files : []);
            await cacheSet('today', state.today);
            showFeedback('success', friendlyMessage(payload.message, 'Bild erfolgreich entfernt.'));
        } catch (error) {
            showFeedback('error', friendlyMessage(error.message, 'Das Bild konnte nicht entfernt werden.'), true);
        } finally {
            state.pendingAction = null;
            render();
        }
    }

    function readGeoAck() {
        try {
            return window.localStorage.getItem(GEO_ACK_KEY) === '1';
        } catch (error) {
            return false;
        }
    }

    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        try {
            await navigator.serviceWorker.register('/app/sw.js');
        } catch (error) {
            console.warn('Service Worker konnte nicht registriert werden.', error);
        }
    }

    async function registerBackgroundSync() {
        if (!('serviceWorker' in navigator) || !('SyncManager' in window)) {
            return;
        }

        try {
            const registration = await navigator.serviceWorker.ready;

            if (registration.sync) {
                await registration.sync.register('timesheet-sync');
            }
        } catch (error) {
            console.warn('Background Sync konnte nicht registriert werden.', error);
        }
    }

    function watchSystemTheme() {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

        const handleChange = function () {
            if (currentThemeMode() === 'system') {
                resolveTheme('system');
            }
        };

        if (typeof mediaQuery.addEventListener === 'function') {
            mediaQuery.addEventListener('change', handleChange);
            return;
        }

        if (typeof mediaQuery.addListener === 'function') {
            mediaQuery.addListener(handleChange);
        }
    }

    async function bootstrapSession() {
        resolveTheme();
        state.preferredProjectId = readPreferredProjectId();
        state.projectSelectionId = readProjectSelectionState();
        state.timesheetFilterScope = readTimesheetFilterScope();
        state.timesheetFilterProjectId = readTimesheetFilterProjectId();
        const cachedSession = await cacheGet('session');

        if (cachedSession) {
            state.session = cachedSession;
        }

        if (navigator.onLine) {
            try {
                const response = await fetch('/api/v1/auth/session');
                const payload = await response.json();
                state.session = payload;
                await cacheSet('session', state.session);
            } catch (error) {
                console.warn('Sessionstatus konnte nicht geladen werden.', error);
            }
        }

        state.pendingCount = (await queueAll()).length;
        await loadOnlineData(true);
        render();
    }

    function nowTime() {
        const date = new Date();

        return String(date.getHours()).padStart(2, '0') + ':' + String(date.getMinutes()).padStart(2, '0');
    }

    function refreshLiveDisplays() {
        document.querySelectorAll('[data-live-clock]').forEach((element) => {
            element.textContent = formatClock(state.now);
        });

        document.querySelectorAll('[data-live-status]').forEach((element) => {
            element.textContent = statusLabel(currentStatus());
        });

        document.querySelectorAll('[data-live-work-duration]').forEach((element) => {
            element.textContent = formatDurationMinutes(liveWorkMinutes());
        });

        document.querySelectorAll('[data-live-break-duration]').forEach((element) => {
            element.textContent = currentBreak() ? formatDurationMinutes(liveCurrentBreakMinutes()) : '--:--';
        });

        refreshProjectDisplays();
    }

    function refreshProjectDisplays() {
        document.querySelectorAll('[data-live-project-name]').forEach((element) => {
            element.textContent = currentProjectName();
        });

        document.querySelectorAll('[data-live-start-time]').forEach((element) => {
            element.textContent = currentStartTimeValue();
        });

        document.querySelectorAll('[data-live-end-time]').forEach((element) => {
            element.textContent = currentEndTimeValue();
        });

        document.querySelectorAll('[data-live-break-total]').forEach((element) => {
            element.textContent = formatDurationMinutes(currentTotalBreakMinutes());
        });

        document.querySelectorAll('[data-live-pending-count]').forEach((element) => {
            element.textContent = String(state.pendingCount);
        });
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    window.addEventListener('online', async function () {
        state.online = true;
        await syncQueue();
        await loadOnlineData(false);
    });

    window.addEventListener('offline', function () {
        state.online = false;
        render();
    });

    window.addEventListener('popstate', function () {
        state.route = window.location.pathname || '/app';
        render();
    });

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredInstallPrompt = event;
        state.installPromptAvailable = true;
        render();
    });

    window.addEventListener('appinstalled', function () {
        deferredInstallPrompt = null;
        state.installPromptAvailable = false;
        showFeedback('success', 'App wurde installiert.');
        render();
    });

    window.addEventListener('focus', function () {
        scheduleRevalidate();
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            scheduleRevalidate();
        }
    });

    resolveTheme();
    watchSystemTheme();
    window.setInterval(function () {
        state.now = Date.now();
        refreshLiveDisplays();
    }, 1000);
    window.setInterval(function () {
        if (state.session && state.session.authenticated && state.online) {
            scheduleRevalidate();
        }
    }, 60000);
    registerServiceWorker();
    bootstrapSession();

    function scheduleRevalidate() {
        if (!state.session || !state.session.authenticated || !navigator.onLine) {
            return;
        }

        if (shouldSkipRevalidateForFileSelection()) {
            return;
        }

        window.clearTimeout(revalidateTimer);
        revalidateTimer = window.setTimeout(async function () {
            if (shouldSkipRevalidateForFileSelection()) {
                return;
            }

            await loadOnlineData(true);
        }, 160);
    }
}());
