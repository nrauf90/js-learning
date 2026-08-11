/**
 * Theme handling for the mobile app.
 *
 * Separate from the web app's js/theme.js on purpose. That module stores under
 * `tax-calculator-theme` — a key left over from a feature removed in M23 — and
 * renaming it there would silently reset every existing user's choice. The
 * mobile app is a fresh install with no such history, so it uses the honest
 * key, and theme-boot.js reads the same one before first paint.
 *
 * THEME_KEY must stay identical to the one in theme-boot.js. If the two ever
 * diverge the pre-paint script applies one theme and this module immediately
 * replaces it with another, which reads as a flash on every load.
 */

import { syncStatusBar } from './native.js';

const THEME_KEY = 'theme';

function currentTheme() {
  return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}

function apply(theme) {
  document.documentElement.setAttribute('data-theme', theme);

  const toggle = document.getElementById('theme-toggle');
  if (toggle) {
    toggle.setAttribute('aria-pressed', String(theme === 'dark'));
    /* Labels say what the button will DO, not what is currently showing —
       a button labelled with its current state reads as a status, not a
       control, to anyone using a screen reader. */
    toggle.setAttribute('aria-label', theme === 'dark' ? 'Roshni wala theme' : 'Andhera theme');
  }

  /* The status bar is painted by the OS, not the webview, so it has to be told
     separately or it keeps the old theme's colour above the app. */
  syncStatusBar(theme);
}

/**
 * Wires #theme-toggle if the page has one. theme-boot.js has already applied
 * the stored or OS preference before first paint, so this only re-applies it
 * to pick up the button state and the status bar.
 */
export function initTheme() {
  apply(currentTheme());

  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    apply(next);
    try {
      localStorage.setItem(THEME_KEY, next);
    } catch {
      /* A locked-down webview still gets the theme for this session; it just
         will not remember it next launch. Not worth failing the tap over. */
    }
  });
}
