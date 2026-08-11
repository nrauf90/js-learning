import { apiPost, getAuthToken, setAuthToken } from './api.js';

const USER_KEY = 'cashflow_auth_user';

const PAGES = {
  home: { href: 'index.html', label: 'Home' },
  pos: { href: 'pos.html', label: 'Sell' },
  products: { href: 'products.html', label: 'Products' },
  dashboard: { href: 'dashboard.html', label: 'Dashboard' },
  reports: { href: 'reports.html', label: 'Reports' },
  cashflow: { href: 'cashflow.html', label: 'Cash Flow' },
  // No billing entry: shops are opened and renewed by the platform admin, and
  // the page turns a shop away. Removed here as well as from the app shell so
  // the legacy top nav on the public pages cannot offer a door the sidebar has
  // already closed.
  profile: { href: 'profile.html', label: 'Profile' },
};

/** Pages that only make sense once you have an account. */
const AUTH_ONLY = new Set(['pos', 'products', 'dashboard', 'reports', 'cashflow', 'profile']);

function wireLogout(link) {
  link.textContent = 'Log out';
  link.href = '#';
  link.onclick = async (e) => {
    e.preventDefault();
    try {
      if (getAuthToken()) {
        await apiPost('/api/logout', {});
      }
    } catch {
      // ignore network errors on logout
    }
    setAuthToken(null);
    localStorage.removeItem(USER_KEY);
    window.location.href = 'login.html';
  };
}

function closeMobileNav(headerActions, toggle) {
  if (!headerActions) return;
  headerActions.classList.remove('nav-open');
  if (toggle) {
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Menu');
  }
}

function ensureNavToggle(headerActions) {
  let toggle = headerActions.querySelector('.nav-toggle');
  if (toggle) return toggle;

  toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'nav-toggle';
  toggle.setAttribute('aria-label', 'Menu');
  toggle.setAttribute('aria-expanded', 'false');
  toggle.innerHTML = `
    <svg class="icon-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path d="M4 7h16M4 12h16M4 17h16"/>
    </svg>
    <svg class="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path d="M6 6l12 12M18 6L6 18"/>
    </svg>`;

  const nav = headerActions.querySelector('.site-nav');
  headerActions.insertBefore(toggle, nav);

  toggle.addEventListener('click', () => {
    const open = !headerActions.classList.contains('nav-open');
    headerActions.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Menu');
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeMobileNav(headerActions, toggle);
    }
  });

  return toggle;
}

/**
 * @param {{
 *   current?: 'home' | 'pos' | 'products' | 'dashboard' | 'reports' | 'cashflow' | 'billing' | 'profile',
 *   sections?: Array<{ href: string, label: string }>,
 * }} [options]
 *
 * `sections` prepends in-page anchors, for the marketing pages. They are shown
 * only to logged-out visitors: once you have an account the nav's job is to get
 * you to the till, not back to the sales copy. Lenis handles the smooth scroll
 * and the existing toggle handler closes the mobile drawer on click, so nothing
 * extra is wired here.
 */
export function initNav(options = {}) {
  const nav = document.querySelector('.site-nav');
  if (!nav) return;

  const headerActions = nav.closest('.header-actions');
  const toggle = headerActions ? ensureNavToggle(headerActions) : null;

  const loggedIn = Boolean(getAuthToken());
  const current = options.current;

  nav.innerHTML = '';

  if (!loggedIn) {
    for (const section of options.sections || []) {
      const a = document.createElement('a');
      a.href = section.href;
      a.textContent = section.label;
      a.addEventListener('click', () => closeMobileNav(headerActions, toggle));
      nav.appendChild(a);
    }
  }

  for (const [key, page] of Object.entries(PAGES)) {
    if (AUTH_ONLY.has(key) && !loggedIn) continue;
    // The marketing anchors already point back to the top of this page.
    if (key === 'home' && !loggedIn && (options.sections || []).length) continue;

    const a = document.createElement('a');
    a.href = page.href;
    a.textContent = page.label;
    if (current === key) {
      a.setAttribute('aria-current', 'page');
    }
    a.addEventListener('click', () => closeMobileNav(headerActions, toggle));
    nav.appendChild(a);
  }

  const authLink = document.createElement('a');
  authLink.id = 'nav-auth';
  if (loggedIn) {
    wireLogout(authLink);
  } else {
    const next = encodeURIComponent(window.location.pathname.split('/').pop() || 'index.html');
    authLink.textContent = 'Log in';
    authLink.href = `login.html?next=${next}`;
  }
  authLink.addEventListener('click', () => {
    if (!loggedIn) closeMobileNav(headerActions, toggle);
  });
  nav.appendChild(authLink);
}
