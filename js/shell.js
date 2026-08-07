/**
 * App shell: sidebar navigation for logged-in pages
 * (pos, products, dashboard, cashflow, reports, billing, profile).
 */
import { apiGet, apiPost, getAuthToken, setAuthToken } from './api.js';

const USER_KEY = 'cashflow_auth_user';
const USER_FETCHED_KEY = 'cashflow_auth_user_at';

/**
 * How long a cached user stays good before the sidebar re-fetches it.
 *
 * The shell mounts on every logged-in page, so an unconditional `/api/user`
 * added a third request to each navigation purely to redraw a name that had
 * not changed — and the dev server handles one request at a time, so it
 * delayed the data the page actually needed.
 */
const USER_REFRESH_MS = 5 * 60 * 1000;

const ICONS = {
  dashboard:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
  cashflow:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3l4 4-4 4"/><path d="M21 7H8"/><path d="M7 21l-4-4 4-4"/><path d="M3 17h13"/></svg>',
  reports:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 14l4-4 3 3 5-6"/></svg>',
  billing:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></svg>',
  profile:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/></svg>',
  admin:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7.5 3v5.5c0 4.4-3 8-7.5 9.5-4.5-1.5-7.5-5.1-7.5-9.5V6z"/><path d="M9 12.2l2.2 2.2 4-4.4"/></svg>',
  home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>',
  pos: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="12" width="18" height="9" rx="2"/><path d="M6.5 12V7.5A1.5 1.5 0 0 1 8 6h8a1.5 1.5 0 0 1 1.5 1.5V12"/><path d="M9.5 9h5"/><path d="M9 16.5h6"/></svg>',
  products:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
  logout:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
  sales:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.5h12v19l-3-2-3 2-3-2-3 2z"/><path d="M9.5 8h5"/><path d="M9.5 12h5"/></svg>',
  activity:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg>',
  shop: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16l1 5a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 12v8h14v-8"/><path d="M10 20v-5h4v5"/></svg>',
  // Two units, not one house: this sits in the same sidebar as the Home link,
  // and a single roofline read as "home" at 20px.
  'admin-shops':
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V9l6-4v16"/><path d="M11 12h8v9"/><path d="M14.5 21v-4H17v4"/></svg>',
  'admin-users':
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-5.5 6.5-5.5s6.5 1.9 6.5 5.5"/><path d="M16.5 5.4a3.5 3.5 0 0 1 0 5.2"/><path d="M18 15c2.1.8 3.5 2.5 3.5 5"/></svg>',
  'admin-subscriptions':
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12A8.5 8.5 0 0 1 5.4 17.3"/><path d="M3.5 12A8.5 8.5 0 0 1 18.6 6.7"/><path d="M18.9 3v3.9H15"/><path d="M5.1 21v-3.9H9"/></svg>',
  'admin-entries':
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M8.5 16V8"/><path d="M6 10.5L8.5 8l2.5 2.5"/><path d="M15.5 8v8"/><path d="M13 13.5l2.5 2.5 2.5-2.5"/></svg>',
  'admin-payments':
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="6.5" rx="7.5" ry="3.5"/><path d="M4.5 6.5v11c0 1.9 3.4 3.5 7.5 3.5s7.5-1.6 7.5-3.5v-11"/><path d="M4.5 12c0 1.9 3.4 3.5 7.5 3.5s7.5-1.6 7.5-3.5"/></svg>',
  'admin-categories':
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 3H5.5A2.5 2.5 0 0 0 3 5.5v7.1a2 2 0 0 0 .6 1.4l7.4 7.4a2 2 0 0 0 2.8 0l7-7a2 2 0 0 0 0-2.8L14 3.6A2 2 0 0 0 12.6 3z"/><path d="M7.5 7.5h.01"/></svg>',
  purchases:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>',
  customers:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h13a3 3 0 0 1 3 3v13a3 3 0 0 0-3-3H4z"/><path d="M4 4v16"/><path d="M8 8h8"/><path d="M8 12h5"/></svg>',
};

const APP_LINKS = [
  { key: 'pos', href: 'pos.html', label: 'Sell' },
  { key: 'sales', href: 'sales.html', label: 'Sales' },
  { key: 'products', href: 'products.html', label: 'Products' },
  // Stock in and the khata sit next to the catalogue: both are things the
  // owner does between serving customers, not while serving one.
  { key: 'purchases', href: 'purchases.html', label: 'Stock In' },
  { key: 'customers', href: 'customers.html', label: 'Khata' },
  { key: 'activity', href: 'activity.html', label: 'Activity' },
  { key: 'dashboard', href: 'dashboard.html', label: 'Dashboard' },
  { key: 'cashflow', href: 'cashflow.html', label: 'Cash Flow' },
  { key: 'reports', href: 'reports.html', label: 'Reports' },
  // Billing is gone from the shop's sidebar: accounts are opened and renewed by
  // the platform admin, so the page now bounces a shop straight back. A link
  // that only ever returns you to where you came from reads as a broken app.
  // Admins still reach it from the admin sidebar. `ICONS.billing` stays — the
  // admin nav uses it.
  { key: 'profile', href: 'profile.html', label: 'Profile' },
];

/** Shown only to the account that owns the shop — staff have nothing to manage. */
const SHOP_ADMIN_LINK = { key: 'shop', href: 'shop.html', label: 'Shop & Staff' };

const ADMIN_SIDEBAR_LINKS = [
  { key: 'admin', href: 'admin.html', label: 'Overview' },
  { key: 'admin-users', href: 'admin-users.html', label: 'Users' },
  { key: 'admin-shops', href: 'admin-shops.html', label: 'Shops' },
  { key: 'admin-subscriptions', href: 'admin-subscriptions.html', label: 'Subscriptions' },
  { key: 'admin-entries', href: 'admin-entries.html', label: 'Cash Entries' },
  { key: 'admin-payments', href: 'admin-payments.html', label: 'Payments' },
  { key: 'admin-categories', href: 'admin-categories.html', label: 'Categories' },
];

const SITE_LINKS = [{ key: 'home', href: 'index.html', label: 'Home' }];

function storedUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
  } catch {
    return null;
  }
}

/** No cached user, or one older than USER_REFRESH_MS, means go and ask. */
function userCacheIsStale(user) {
  if (!user) return true;
  const at = Number(localStorage.getItem(USER_FETCHED_KEY));
  return !Number.isFinite(at) || at <= 0 || Date.now() - at > USER_REFRESH_MS;
}

function initials(name) {
  return (name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('');
}

function renderUserCard(user) {
  const el = document.getElementById('sidebar-user');
  if (!el || !user) return;
  el.querySelector('.sidebar-avatar').textContent = initials(user.name);
  el.querySelector('.sidebar-user-name').textContent = user.name || 'Account';
  el.querySelector('.sidebar-user-email').textContent = user.email || '';
}

function linkHTML(item, current) {
  const active = item.key === current;
  return `
    <a class="sidebar-link${active ? ' active' : ''}" href="${item.href}"
       ${active ? 'aria-current="page"' : ''}>
      <span class="sidebar-link-icon">${ICONS[item.key] || ''}</span>
      <span>${item.label}</span>
    </a>`;
}

/**
 * Repeats the sidebar glyph beside the page heading.
 *
 * Injected from here rather than written into each page's markup: fifteen
 * hand-kept copies is exactly how the pages drifted apart before. Re-entrant —
 * initShell runs a second time when the refreshed user turns out to be an
 * admin, and that must not stack a second glyph on the heading.
 */
function renderHeadingIcon(current) {
  const heading = document.querySelector('.topbar-heading h1');
  if (!heading) return;
  heading.querySelector('.topbar-icon')?.remove();
  const icon = ICONS[current];
  if (!icon) return;
  heading.insertAdjacentHTML('afterbegin', `<span class="topbar-icon" aria-hidden="true">${icon}</span>`);
}

function closeSidebar() {
  document.body.classList.remove('sidebar-open');
  const toggle = document.getElementById('sidebar-toggle');
  if (toggle) toggle.setAttribute('aria-expanded', 'false');
}

/**
 * @param {{ current?: 'pos' | 'products' | 'dashboard' | 'cashflow' | 'reports' | 'billing' | 'profile' | 'admin' | 'admin-users' }} [options]
 */
export function initShell(options = {}) {
  const sidebar = document.getElementById('app-sidebar');
  if (!sidebar) return;

  const current = options.current;
  renderHeadingIcon(current);

  const user = storedUser();
  const isAdmin = user?.is_admin || false;

  // Admin links
  const isAdminPage = current?.startsWith('admin');

  let navContent = '';

  if (isAdminPage && isAdmin) {
    navContent = `
      <p class="sidebar-section-label">Administration</p>
      ${ADMIN_SIDEBAR_LINKS.map((item) => linkHTML(item, current)).join('')}
      <p class="sidebar-section-label">App</p>
      <a class="sidebar-link" href="dashboard.html">
        <span class="sidebar-link-icon">${ICONS.dashboard}</span>
        <span>Back to App</span>
      </a>
    `;
  } else {
    const ADMIN_LINK = { key: 'admin', href: 'admin.html', label: 'Admin Panel' };
    const adminSection = isAdmin ? `
      <p class="sidebar-section-label">Administration</p>
      ${linkHTML(ADMIN_LINK, current)}
    ` : '';

    // Shop and staff management belongs to whoever owns the shop. A platform
    // admin manages shops from the admin panel instead, so they don't get it
    // here either.
    const shopSection = user?.role === 'shop_admin' && !isAdmin
      ? linkHTML(SHOP_ADMIN_LINK, current)
      : '';

    navContent = `
      <p class="sidebar-section-label">Overview</p>
      ${APP_LINKS.map((item) => linkHTML(item, current)).join('')}
      ${shopSection}
      ${adminSection}
    `;
  }

  sidebar.innerHTML = `
    <a class="sidebar-brand" href="dashboard.html">
      <span class="sidebar-brand-mark">🇵🇰</span>
      <span class="sidebar-brand-text">Cashflow<span class="sidebar-brand-dot">.</span></span>
    </a>

    <nav class="sidebar-nav" aria-label="App">
      ${navContent}
      <p class="sidebar-section-label">Site</p>
      ${SITE_LINKS.map((item) => linkHTML(item, current)).join('')}
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-user" id="sidebar-user">
        <span class="sidebar-avatar" aria-hidden="true">?</span>
        <span class="sidebar-user-meta">
          <span class="sidebar-user-name">Account</span>
          <span class="sidebar-user-email"></span>
        </span>
      </div>
      <button type="button" class="sidebar-logout" id="sidebar-logout">
        <span class="sidebar-link-icon">${ICONS.logout}</span>
        Log out
      </button>
    </div>`;

  sidebar.querySelectorAll('a').forEach((a) => a.addEventListener('click', closeSidebar));

  document.getElementById('sidebar-logout')?.addEventListener('click', async () => {
    try {
      if (getAuthToken()) await apiPost('/api/logout', {});
    } catch {
      // ignore network errors on logout
    }
    setAuthToken(null);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(USER_FETCHED_KEY);
    window.location.href = 'login.html';
  });

  // Mobile drawer toggle + overlay.
  const toggle = document.getElementById('sidebar-toggle');
  const overlay = document.getElementById('app-overlay');
  toggle?.addEventListener('click', () => {
    const open = !document.body.classList.contains('sidebar-open');
    document.body.classList.toggle('sidebar-open', open);
    toggle.setAttribute('aria-expanded', String(open));
  });
  overlay?.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidebar();
  });

  // User card: show cached user immediately, refresh only once it goes stale.
  renderUserCard(user);
  if (getAuthToken() && userCacheIsStale(user)) {
    apiGet('/api/user')
      .then((data) => {
        if (data?.user) {
          localStorage.setItem(USER_KEY, JSON.stringify(data.user));
          localStorage.setItem(USER_FETCHED_KEY, String(Date.now()));
          renderUserCard(data.user);
          // Re-render sidebar if admin status changed
          if (data.user.is_admin !== isAdmin) {
            initShell(options);
          }
        }
      })
      .catch(() => {});
  }
}
