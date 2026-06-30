
(() => {
    'use strict';

    const STORAGE_KEY = 'alltune2_theme';

    function normalizeTheme(value) {
        return value === 'light' ? 'light' : 'dark';
    }

    function readStoredTheme() {
        try {
            return normalizeTheme(window.localStorage.getItem(STORAGE_KEY));
        } catch (error) {
            return normalizeTheme(document.documentElement.getAttribute('data-theme'));
        }
    }

    function saveTheme(theme) {
        try {
            window.localStorage.setItem(STORAGE_KEY, theme);
        } catch (error) {
            // Ignore storage errors.
        }
    }

    function syncToggle(theme) {
        const toggle = document.getElementById('theme-toggle');
        if (!toggle) {
            return;
        }

        const isLight = theme === 'light';
        toggle.setAttribute('aria-checked', isLight ? 'true' : 'false');
        toggle.setAttribute('title', isLight ? 'Light theme active' : 'Dark theme active');
    }

    function applyTheme(theme) {
        const normalized = normalizeTheme(theme);
        document.documentElement.setAttribute('data-theme', normalized);
        syncToggle(normalized);
        return normalized;
    }

    document.addEventListener('DOMContentLoaded', () => {
        let currentTheme = applyTheme(readStoredTheme());
        const toggle = document.getElementById('theme-toggle');

        if (!toggle) {
            return;
        }

        const flipTheme = () => {
            currentTheme = currentTheme === 'light' ? 'dark' : 'light';
            applyTheme(currentTheme);
            saveTheme(currentTheme);
        };

        toggle.addEventListener('click', flipTheme);
        toggle.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                flipTheme();
            }
        });
    });
})();
