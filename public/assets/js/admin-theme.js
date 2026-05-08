const THEME_STORAGE_KEY = 'app.theme';
const ALLOWED_THEME_MODES = ['light', 'dark', 'system'];

function getStoredThemeMode() {
    try {
        const storedMode = window.localStorage.getItem(THEME_STORAGE_KEY);

        return ALLOWED_THEME_MODES.includes(storedMode) ? storedMode : 'system';
    } catch (error) {
        return 'system';
    }
}

function resolveTheme(mode) {
    if (mode === 'system') {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    return mode;
}

function applyTheme(mode) {
    const root = document.documentElement;
    const resolvedTheme = resolveTheme(mode);

    root.dataset.themeMode = mode;
    root.dataset.theme = resolvedTheme;
    root.style.colorScheme = resolvedTheme;

    document.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.themeOption === mode);
        button.setAttribute(
            'aria-pressed',
            button.dataset.themeOption === mode ? 'true' : 'false'
        );
    });

    const themeStatus = document.getElementById('themeStatus');

    if (themeStatus) {
        const labels = {
            light: 'Hell',
            dark: 'Dunkel',
            system: 'System'
        };

        themeStatus.textContent = labels[mode] ?? 'System';
    }

    window.dispatchEvent(new CustomEvent('app-theme-change', {
        detail: {
            mode,
            theme: resolvedTheme
        }
    }));
}

function persistThemeMode(mode) {
    try {
        window.localStorage.setItem(THEME_STORAGE_KEY, mode);
    } catch (error) {
        console.warn('Theme-Einstellung konnte nicht gespeichert werden.', error);
    }
}

function setupThemeSwitcher() {
    const buttons = document.querySelectorAll('[data-theme-option]');

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const mode = button.dataset.themeOption;

            if (!ALLOWED_THEME_MODES.includes(mode)) {
                return;
            }

            persistThemeMode(mode);
            applyTheme(mode);
        });
    });
}

function watchSystemTheme() {
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

    const handleChange = () => {
        if (getStoredThemeMode() === 'system') {
            applyTheme('system');
        }
    };

    if (typeof mediaQuery.addEventListener === 'function') {
        mediaQuery.addEventListener('change', handleChange);
        return;
    }

    mediaQuery.addListener(handleChange);
}

function bootTheme() {
    applyTheme(getStoredThemeMode());
    setupThemeSwitcher();
    watchSystemTheme();
}

bootTheme();

