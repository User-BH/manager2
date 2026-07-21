import Chart from 'chart.js/auto';

// Expose Chart for inline dashboard scripts (self-hosted, no CDN).
window.Chart = Chart;

const root = document.documentElement;
const readToken = (name) => getComputedStyle(root).getPropertyValue(name).trim();

// Charts read their text/grid colours from the design tokens, so they follow
// the light/dark theme instead of carrying their own hard-coded palette.
Chart.defaults.font.family = 'Vazirmatn, ui-sans-serif, system-ui, sans-serif';
Chart.defaults.font.size = 12;

function syncChartTheme() {
    Chart.defaults.color = readToken('--text-secondary');
    Chart.defaults.borderColor = readToken('--border-subtle');
    Object.values(Chart.instances ?? {}).forEach((chart) => chart.update('none'));
}

// --- Theme: light/dark with persistence, defaulting to the OS preference. ---
const themeKey = 'theme';

function applyTheme(theme) {
    const dark = theme === 'dark' || (theme === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches);
    root.classList.toggle('dark', dark);
    syncChartTheme();
}

window.setTheme = function (theme) {
    localStorage.setItem(themeKey, theme);
    applyTheme(theme);
};

window.toggleTheme = function () {
    window.setTheme(root.classList.contains('dark') ? 'light' : 'dark');
};

// Keep in sync with OS changes when in "system" mode.
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if ((localStorage.getItem(themeKey) || 'system') === 'system') {
        applyTheme('system');
    }
});

applyTheme(localStorage.getItem(themeKey) || 'system');

// --- Sidebar: collapsed/expanded, persisted. ---
// The state lives on <html data-sidebar> and is also written by an inline script
// in the layout <head>, so the collapsed width applies before the first paint
// instead of flashing open on every page load.
const sidebarKey = 'sidebar';

window.toggleSidebar = function () {
    const collapsed = root.dataset.sidebar !== 'collapsed';
    root.dataset.sidebar = collapsed ? 'collapsed' : 'expanded';
    localStorage.setItem(sidebarKey, collapsed ? 'collapsed' : 'expanded');
};

// --- Landing page: reveal sections as they scroll into view. ---
// The .reveal elements start at opacity 0, so every path out of this function
// must end with them visible. Anything else leaves the page permanently blank.
function initReveal() {
    const targets = document.querySelectorAll('.reveal');
    if (!targets.length) return;

    const revealAll = () => targets.forEach((el) => el.classList.add('is-visible'));

    // No observer support, or the page is not actually being displayed
    // (background tab, prerender): IntersectionObserver never reports an
    // intersection there, so show everything instead of animating.
    if (!('IntersectionObserver' in window) || document.hidden) {
        revealAll();
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        },
        { rootMargin: '0px 0px -10% 0px', threshold: 0.05 },
    );

    targets.forEach((el) => observer.observe(el));

    // Safety net: if nothing above the fold got revealed shortly after load,
    // assume the observer will not fire and reveal everything.
    setTimeout(() => {
        if (!document.querySelector('.reveal.is-visible')) revealAll();
    }, 1500);
}

// --- Landing page: navbar turns solid once the hero is scrolled past. ---
function initStickyNavbar() {
    const navbar = document.querySelector('[data-sticky-navbar]');
    if (!navbar) return;

    const update = () => navbar.classList.toggle('is-scrolled', window.scrollY > 24);
    update();
    window.addEventListener('scroll', update, { passive: true });
}

// --- Landing page: thin bar showing how far the page has been scrolled. ---
function initScrollProgress() {
    const bar = document.querySelector('[data-scroll-progress]');
    if (!bar) return;

    const update = () => {
        const scrollable = document.documentElement.scrollHeight - window.innerHeight;
        bar.style.transform = `scaleX(${scrollable > 0 ? window.scrollY / scrollable : 0})`;
    };

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
}

// --- Landing page: count numbers up when the stats row first becomes visible. ---
function initCounters() {
    const counters = document.querySelectorAll('[data-count-to]');
    if (!counters.length) return;

    // Same reasoning as initReveal: when the observer cannot fire, leave the
    // server-rendered final number in place rather than a half-finished count.
    if (!('IntersectionObserver' in window) || document.hidden) return;

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;

                const el = entry.target;
                observer.unobserve(el);

                const target = Number(el.dataset.countTo);
                const duration = 1200;
                const start = performance.now();

                const tick = (now) => {
                    const progress = Math.min((now - start) / duration, 1);
                    el.textContent = Math.round(progress * target).toLocaleString('fa-IR');
                    if (progress < 1) requestAnimationFrame(tick);
                };

                requestAnimationFrame(tick);
            });
        },
        { threshold: 0.4 },
    );

    counters.forEach((el) => observer.observe(el));
}

document.addEventListener('DOMContentLoaded', () => {
    initReveal();
    initStickyNavbar();
    initScrollProgress();
    initCounters();
});

// Register the PWA service worker (installable, offline-tolerant).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
