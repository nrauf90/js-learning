/**
 * The "More" menu — everything the signed-in user can reach that is not one of
 * the four bottom tabs, filtered by their role.
 *
 * The role rules mirror js/shell.js (the desktop sidebar) deliberately, rather
 * than inventing a second set: two places deciding who may see "Shop & Staff"
 * would eventually disagree, and the one that is wrong would be a privacy bug
 * rather than a cosmetic one.
 */

import { apiGet, apiPost, getAuthToken, setAuthToken } from './api.js';
import { initShell } from './shell.js';

const USER_KEY = 'cashflow_auth_user';
const USER_FETCHED_KEY = 'cashflow_auth_user_at';
const LOGIN = 'login.html';

/**
 * `ready` marks what exists as a mobile screen today.
 *
 * Unbuilt entries are still listed — a shopkeeper should be able to see what
 * the app will do, and a menu that hides everything unfinished makes the
 * product look emptier than it is. They are visibly disabled rather than
 * linking somewhere that 404s.
 */
const SECTIONS = [
  {
    title: 'Counter',
    items: [
      { href: 'sell.html', label: 'Sell', note: 'Ring up a sale', ready: true },
      { href: 'khata.html', label: 'Khata', note: 'Who owes the shop', ready: false },
      { href: 'sales.html', label: 'Sales', note: 'Every ticket, and refunds', ready: false },
    ],
  },
  {
    title: 'Stock',
    items: [
      { href: 'products.html', label: 'Products', note: 'Prices, stock, wastage', ready: false },
      { href: 'purchases.html', label: 'Stock In', note: 'Deliveries and suppliers', ready: false },
    ],
  },
  {
    title: 'Money',
    items: [
      { href: 'dashboard.html', label: 'Dashboard', note: 'The week at a glance', ready: false },
      { href: 'cashflow.html', label: 'Cash Flow', note: 'Income and expenses', ready: false },
      { href: 'reports.html', label: 'Reports', note: 'Profit and cash position', ready: false },
    ],
  },
];

/** Shown only to the account that owns the shop — staff have nothing to manage. */
const SHOP_ADMIN_SECTION = {
  title: 'Shop',
  items: [
    { href: 'shop.html', label: 'Shop & Staff', note: 'Details and staff logins', ready: false },
    { href: 'activity.html', label: 'Activity', note: 'Who changed what', ready: false },
  ],
};

/** Platform operators only. */
const ADMIN_SECTION = {
  title: 'Admin',
  items: [{ href: 'admin.html', label: 'Admin Panel', note: 'Shops and subscriptions', ready: false }],
};

const ACCOUNT_SECTION = {
  title: 'Account',
  items: [{ href: 'profile.html', label: 'Profile', note: 'Name and password', ready: false }],
};

const ROLE_LABELS = {
  admin: 'Platform admin',
  shop_admin: 'Shop owner',
  staff: 'Staff',
};

const $ = (id) => document.getElementById(id);

function cachedUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
  } catch {
    return null;
  }
}

function escapeHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
  );
}

function initials(name) {
  return (
    (name || '?')
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((w) => w[0].toUpperCase())
      .join('') || '?'
  );
}

/**
 * Which sections this user may see.
 *
 * Same two rules the desktop sidebar applies: the admin panel for platform
 * admins, and Shop & Staff for a shop owner who is not also a platform admin
 * (an admin manages shops from the admin panel instead, so the duplicate link
 * would only be confusing).
 */
function sectionsFor(user) {
  const isAdmin = Boolean(user?.is_admin);
  const isShopAdmin = user?.role === 'shop_admin';

  const out = [...SECTIONS];
  if (isShopAdmin && !isAdmin) out.push(SHOP_ADMIN_SECTION);
  if (isAdmin) out.push(ADMIN_SECTION);
  out.push(ACCOUNT_SECTION);
  return out;
}

function renderMenu(user) {
  const host = $('m-menu');
  host.innerHTML = '';

  for (const section of sectionsFor(user)) {
    const wrap = document.createElement('section');
    wrap.className = 'm-menu-section';

    const h = document.createElement('h2');
    h.className = 'm-menu-title';
    h.textContent = section.title;
    wrap.appendChild(h);

    const list = document.createElement('ul');
    list.className = 'm-menu-list';

    for (const item of section.items) {
      const li = document.createElement('li');
      const node = document.createElement(item.ready ? 'a' : 'div');
      node.className = `m-menu-item${item.ready ? '' : ' is-soon'}`;
      if (item.ready) node.href = item.href;

      node.innerHTML = `
        <span class="m-menu-body">
          <strong>${escapeHtml(item.label)}</strong>
          <span>${escapeHtml(item.note)}</span>
        </span>
        ${
          item.ready
            ? '<svg class="m-menu-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>'
            : '<span class="m-menu-soon">Soon</span>'
        }`;
      li.appendChild(node);
      list.appendChild(li);
    }

    wrap.appendChild(list);
    host.appendChild(wrap);
  }
}

function renderUser(user) {
  $('m-avatar').textContent = initials(user?.name);
  $('m-username').textContent = user?.name || 'Account';
  $('m-useremail').textContent = user?.email || '';

  const role = $('m-role');
  const label = ROLE_LABELS[user?.is_admin ? 'admin' : user?.role];
  if (label) {
    role.hidden = false;
    role.textContent = label;
  } else {
    role.hidden = true;
  }
}

async function logout() {
  const btn = $('logout');
  btn.disabled = true;
  try {
    if (getAuthToken()) await apiPost('/api/logout', {});
  } catch {
    /* A logout that cannot reach the server still has to clear the device:
       leaving the token behind keeps the shop signed in on a handset that was
       just handed to someone else. */
  } finally {
    setAuthToken(null);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(USER_FETCHED_KEY);
    window.location.replace(LOGIN);
  }
}

if (initShell({ current: 'more' })) {
  /* Render from cache first so the menu is never blank, then confirm the role
     against the API — a role change must not leave someone looking at a menu
     they are no longer entitled to. */
  const cached = cachedUser();
  renderUser(cached);
  renderMenu(cached);

  $('logout').addEventListener('click', logout);

  apiGet('/api/user')
    .then((res) => {
      /* The endpoint has returned a bare user object and a {user} wrapper at
         different points; accept either rather than silently rendering blank. */
      const user = res?.user ?? res;
      if (!user?.email) return;
      localStorage.setItem(USER_KEY, JSON.stringify(user));
      localStorage.setItem(USER_FETCHED_KEY, String(Date.now()));
      renderUser(user);
      renderMenu(user);
    })
    .catch((err) => {
      if (err?.status === 401) return; /* api.js is already redirecting */
      const alert = $('more-alert');
      alert.hidden = false;
      alert.textContent = 'Could not refresh your account — showing saved details.';
    });
}
