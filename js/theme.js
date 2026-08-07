/**
 * Shared light/dark theme handling (M13).
 *
 * Light is the true default: an explicit stored choice always wins,
 * otherwise dark is only used if the OS explicitly prefers it
 * (`prefers-color-scheme: dark`) — everyone else gets light, matching the
 * light-first default already painted by css/styles.css before this module
 * even runs, so there's no dark-then-light flash on load.
 */

const THEME_KEY = 'tax-calculator-theme';

/**
 * @param {'light'|'dark'} theme
 */
export function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  const toggle = document.getElementById('theme-toggle');
  if (!toggle) return;
  toggle.setAttribute('aria-pressed', String(theme === 'light'));
  toggle.setAttribute(
    'aria-label',
    theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme'
  );
}

function preferredTheme() {
  const stored = localStorage.getItem(THEME_KEY);
  if (stored === 'light' || stored === 'dark') return stored;
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/**
 * Call once per page load. Wires up #theme-toggle if present.
 * @param {(theme: 'light'|'dark') => void} [onToggle] optional callback fired
 *   after a user-triggered toggle (not on initial load) — e.g. to re-render
 *   theme-colored charts.
 */
export function initTheme(onToggle) {
  applyTheme(preferredTheme());

  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    applyTheme(next);
    localStorage.setItem(THEME_KEY, next);
    onToggle?.(next);
  });
}
