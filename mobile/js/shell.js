/**
 * Shared chrome for every signed-in mobile screen: the auth guard, the theme,
 * and the bottom tab bar.
 *
 * The tab bar is at the bottom rather than the top because a phone is held in
 * one hand and the thumb arc is the lower half of the screen — the same reason
 * the till puts its controls there. See docs/mobile-pos-design.md §4.
 */

import { getAuthToken } from './api.js';
import { hideNativeSplash } from './native.js';
import { initTheme } from './theme.js';

const LOGIN = 'login.html';

/**
 * Tabs in the order a shopkeeper needs them, not alphabetically. Selling is the
 * whole job; everything else is looked at between customers.
 *
 * `ready: false` renders the tab but marks it plainly as not built yet — a
 * visible roadmap beats a tab that silently does nothing when tapped.
 */
const TABS = [
  {
    id: 'sell',
    href: 'sell.html',
    label: 'Sell',
    ready: true,
    icon: '<path d="M3 3h2l2.4 12h9.8l2.3-8H6.5"/><circle cx="9.5" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/>',
  },
  {
    id: 'khata',
    href: 'khata.html',
    label: 'Khata',
    ready: false,
    icon: '<path d="M5 4h11a2 2 0 012 2v14H7a2 2 0 01-2-2z"/><path d="M9 4v16M12 9h5M12 13h5"/>',
  },
  {
    id: 'stock',
    href: 'stock.html',
    label: 'Stock',
    ready: false,
    icon: '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4M3 17l9 4 9-4"/>',
  },
  {
    id: 'more',
    href: 'more.html',
    label: 'More',
    ready: true,
    icon: '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
  },
];

function renderTabs(current) {
  const nav = document.createElement('nav');
  nav.className = 'm-tabs';
  nav.setAttribute('aria-label', 'Main');

  for (const tab of TABS) {
    const el = document.createElement(tab.ready ? 'a' : 'button');
    el.className = 'm-tab';
    if (tab.ready) {
      el.href = tab.href;
    } else {
      el.type = 'button';
      el.disabled = true;
      /* Disabled alone reads as "broken" to a screen reader; saying why does not. */
      el.setAttribute('aria-label', `${tab.label} — coming soon`);
    }
    if (tab.id === current) el.setAttribute('aria-current', 'page');

    el.innerHTML =
      `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">${tab.icon}</svg>` +
      `<span>${tab.label}</span>` +
      /* A tab that simply does not respond reads as a broken app. Saying "Soon"
         on its face turns a dead control into a visible roadmap. */
      (tab.ready ? '' : '<span class="m-tab-soon">Soon</span>');
    nav.appendChild(el);
  }

  document.body.appendChild(nav);
}

/**
 * Call once per signed-in screen.
 *
 * Returns false when there is no session and the caller should stop — it has
 * already started the redirect, and letting the page keep booting would fire
 * API calls that are guaranteed to 401.
 *
 * @param {{ current?: string }} [options]
 */
export function initShell(options = {}) {
  if (!getAuthToken()) {
    window.location.replace(LOGIN);
    return false;
  }

  initTheme();
  hideNativeSplash();
  renderTabs(options.current);
  return true;
}
