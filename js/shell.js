/**
 * App shell: sidebar navigation for logged-in pages
 * (pos, products, dashboard, cashflow, reports, billing, profile).
 */
import { apiGet, apiPost, getAuthToken, setAuthToken } from './api.js';

const USER_KEY = 'cashflow_auth_user';

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
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>',
  home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>',
  pos: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18l-1.5 9h-15z"/><path d="M3 6L2 3"/><circle cx="9" cy="20" r="1.5"/><circle cx="17" cy="20" r="1.5"/></svg>',
  products:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>',
  logout:
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
};

const APP_LINKS = [
  { key: 'pos', href: 'pos.html', label: 'Sell' },
  { key: 'products', href: 'products.html', label: 'Products' },
  { key: 'dashboard', href: 'dashboard.html', label: 'Dashboard' },
  { key: 'cashflow', href: 'cashflow.html', label: 'Cash Flow' },
  { key: 'reports', href: 'reports.html', label: 'Reports' },
  { key: 'billing', href: 'billing.html', label: 'Billing' },
  { key: 'profile', href: 'profile.html', label: 'Profile' },
];

const ADMIN_SIDEBAR_LINKS = [
  { key: 'admin', href: 'admin.html', label: 'Overview' },
  { key: 'admin-users', href: 'admin-users.html', label: 'Users' },
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

    navContent = `
      <p class="sidebar-section-label">Overview</p>
      ${APP_LINKS.map((item) => linkHTML(item, current)).join('')}
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

  // User card: show cached user immediately, refresh in background.
  renderUserCard(user);
  if (getAuthToken()) {
    apiGet('/api/user')
      .then((data) => {
        if (data?.user) {
          localStorage.setItem(USER_KEY, JSON.stringify(data.user));
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
