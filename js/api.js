/**
 * JSON API client for the Laravel backend.
 * Set window.API_BASE_URL before loading this script to override the default.
 */

/**
 * 127.0.0.1 rather than localhost: on Windows `localhost` resolves to ::1
 * first and the dev server only listens on IPv4, so every request paid ~200ms
 * for the refused IPv6 connect before falling back — and the dev server sends
 * `Connection: close`, so that cost repeats on each call instead of once.
 * The e2e helpers and QA scripts already point at 127.0.0.1.
 */
export const API_BASE_URL =
  typeof window !== 'undefined' && window.API_BASE_URL
    ? window.API_BASE_URL
    : 'http://127.0.0.1:8000';

const TOKEN_KEY = 'cashflow_auth_token';

export function getAuthToken() {
  return localStorage.getItem(TOKEN_KEY);
}

export function setAuthToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token);
  } else {
    localStorage.removeItem(TOKEN_KEY);
  }
}

function buildHeaders(extra = {}) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...extra,
  };
  const token = getAuthToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }
  return headers;
}

let lapsedNoticeShown = false;

function detailRow(label, value) {
  const row = document.createElement('div');
  row.style.cssText = 'display:flex;gap:0.75rem;justify-content:space-between;padding:0.4rem 0';
  const dt = document.createElement('span');
  dt.style.cssText = 'color:var(--text-muted, #5b6b80)';
  dt.textContent = label;
  const dd = document.createElement('strong');
  dd.style.cssText = 'text-align:right;word-break:break-word';
  dd.textContent = value;
  row.append(dt, dd);
  return row;
}

/**
 * A 402 used to bounce the shop to billing.html so they could buy their way
 * back in. Self-serve billing is gone, so that redirect now lands on a page
 * that cannot help them — a dead end. Say what happened, say who fixes it, and
 * show the details the operator will ask for, without leaving the page.
 *
 * Styled inline rather than through css/styles.css so the notice cannot be
 * defeated by a page that never loaded the stylesheet; theme variables are read
 * with fallbacks for the same reason.
 */
async function showSubscriptionLapsed(body) {
  if (lapsedNoticeShown || !document.body) return;
  lapsedNoticeShown = true;

  const overlay = document.createElement('div');
  overlay.id = 'subscription-lapsed';
  overlay.setAttribute('role', 'alertdialog');
  overlay.setAttribute('aria-labelledby', 'subscription-lapsed-title');
  overlay.style.cssText =
    'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;' +
    'padding:1.25rem;background:rgba(8,12,20,0.72);backdrop-filter:blur(2px);' +
    'font-family:var(--font, system-ui, sans-serif);overflow:auto';

  const card = document.createElement('div');
  card.style.cssText =
    'max-width:34rem;width:100%;background:var(--surface, #ffffff);color:var(--text, #101826);' +
    'border:1px solid var(--border, #dde3ec);border-radius:var(--radius, 12px);' +
    'box-shadow:0 4px 24px rgba(15,23,42,0.25);padding:1.75rem';

  const title = document.createElement('h2');
  title.id = 'subscription-lapsed-title';
  title.style.cssText = 'font-size:1.35rem;margin-bottom:0.75rem';
  title.textContent =
    body?.code === 'trial_expired' ? 'Your free trial has ended' : 'Your subscription has ended';

  const message = document.createElement('p');
  message.id = 'subscription-lapsed-message';
  message.style.cssText = 'line-height:1.6;margin-bottom:1.25rem';
  message.textContent =
    'Sales, cash flow and reports are paused until it is renewed. ' +
    'Billing is handled for you, so there is nothing to buy here — ' +
    'please contact the administrator to have your shop reactivated.';

  const details = document.createElement('div');
  details.id = 'subscription-lapsed-details';
  details.style.cssText =
    'background:var(--surface-2, #eef2f7);border:1px solid var(--border, #dde3ec);' +
    'border-radius:10px;padding:0.75rem 1rem;font-size:0.95rem';

  const hint = document.createElement('p');
  hint.style.cssText = 'margin-top:0.75rem;color:var(--text-muted, #5b6b80);font-size:0.9rem';
  hint.textContent = 'Quote these details when you get in touch.';

  const logout = document.createElement('button');
  logout.type = 'button';
  logout.id = 'subscription-lapsed-logout';
  logout.textContent = 'Log out';
  logout.style.cssText =
    'margin-top:1.25rem;padding:0.6rem 1.1rem;border:1px solid var(--border, #dde3ec);' +
    'border-radius:8px;background:var(--surface-2, #eef2f7);color:var(--text, #101826);' +
    'font:inherit;cursor:pointer';
  logout.addEventListener('click', () => {
    setAuthToken(null);
    localStorage.removeItem('cashflow_auth_user');
    window.location.href = 'login.html';
  });

  card.append(title, message, details, hint, logout);
  overlay.append(card);
  document.body.append(overlay);

  // Ungated on purpose — it is the only endpoint left that can name the
  // account now that /api/shop sits behind the gate that just fired.
  try {
    const state = await apiGet('/api/billing/subscription');
    const account = state?.account || {};
    if (account.shop?.name) details.append(detailRow('Shop', account.shop.name));
    if (account.shop?.id) details.append(detailRow('Shop ID', String(account.shop.id)));
    if (account.name) details.append(detailRow('Account', account.name));
    if (account.email) details.append(detailRow('Email', account.email));
    if (state?.trial?.ends_at && state?.trial?.expired) {
      details.append(detailRow('Trial ended', new Date(state.trial.ends_at).toLocaleDateString()));
    }
    if (state?.subscription?.ends_at) {
      details.append(
        detailRow('Expired on', new Date(state.subscription.ends_at).toLocaleDateString())
      );
    }
  } catch {
    // The instruction to contact the administrator stands on its own.
  }

  if (!details.childElementCount) {
    details.remove();
    hint.remove();
  }
}

/**
 * @param {string} path e.g. "/api/health"
 * @param {RequestInit} [options]
 */
export async function apiFetch(path, options = {}) {
  const url = `${API_BASE_URL}${path.startsWith('/') ? path : `/${path}`}`;
  const response = await fetch(url, {
    credentials: 'include',
    ...options,
    headers: buildHeaders(options.headers),
  });

  const contentType = response.headers.get('content-type') || '';
  const isJson = contentType.includes('application/json');
  const body = isJson ? await response.json().catch(() => null) : await response.text();

  if (!response.ok) {
    if (response.status === 401 && typeof window !== 'undefined') {
      const onAuthPage = /login\.html|signup\.html/i.test(window.location.pathname);
      if (!onAuthPage && !path.includes('/api/login') && !path.includes('/api/register')) {
        setAuthToken(null);
        const next = encodeURIComponent(window.location.pathname.split('/').pop() || 'index.html');
        window.location.href = `login.html?next=${next}`;
      }
    }
    if (response.status === 402 && typeof window !== 'undefined') {
      const needsSubscription =
        body && (body.code === 'subscription_required' || body.code === 'trial_expired');
      if (needsSubscription) {
        showSubscriptionLapsed(body);
      }
    }
    const error = new Error((body && body.message) || `Request failed (${response.status})`);
    error.status = response.status;
    error.body = body;
    throw error;
  }

  return body;
}

export function apiGet(path) {
  return apiFetch(path, { method: 'GET' });
}

export function apiPost(path, data) {
  return apiFetch(path, {
    method: 'POST',
    body: JSON.stringify(data ?? {}),
  });
}

export function apiPut(path, data) {
  return apiFetch(path, {
    method: 'PUT',
    body: JSON.stringify(data ?? {}),
  });
}

export function apiDelete(path) {
  return apiFetch(path, { method: 'DELETE' });
}

/** Call once on app boot to verify API connectivity. */
export async function checkHealth() {
  return apiGet('/api/health');
}
