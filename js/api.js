/**
 * JSON API client for the Laravel backend.
 * Set window.API_BASE_URL before loading this script to override the default.
 */

export const API_BASE_URL =
  typeof window !== 'undefined' && window.API_BASE_URL
    ? window.API_BASE_URL
    : 'http://localhost:8000';

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
