import Chart from 'chart.js/auto';

// Expose Chart for inline dashboard scripts (self-hosted, no CDN).
window.Chart = Chart;

// Theme handling: light/dark with persistence, defaulting to the OS preference.
const storageKey = 'theme';

function applyTheme(theme) {
    const root = document.documentElement;
    const dark = theme === 'dark' || (theme === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches);
    root.classList.toggle('dark', dark);
}

window.setTheme = function (theme) {
    localStorage.setItem(storageKey, theme);
    applyTheme(theme);
};

window.toggleTheme = function () {
    const current = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
    window.setTheme(current);
};

// Keep in sync with OS changes when in "system" mode.
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if ((localStorage.getItem(storageKey) || 'system') === 'system') {
        applyTheme('system');
    }
});

applyTheme(localStorage.getItem(storageKey) || 'system');

// Register the PWA service worker (installable, offline-tolerant).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
