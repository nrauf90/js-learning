import { API_BASE_URL, apiPost, getAuthToken, setAuthToken } from './api.js';

const THEME_KEY = 'tax-calculator-theme';
const USER_KEY = 'cashflow_auth_user';

function applyTheme(theme) {
  const root = document.documentElement;
  const toggle = document.getElementById('theme-toggle');
  root.setAttribute('data-theme', theme);
  if (!toggle) return;
  toggle.setAttribute('aria-pressed', String(theme === 'light'));
  toggle.setAttribute(
    'aria-label',
    theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme'
  );
}

function initTheme() {
  const stored = localStorage.getItem(THEME_KEY);
  if (stored === 'light' || stored === 'dark') {
    applyTheme(stored);
  } else {
    applyTheme(window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  }

  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    applyTheme(next);
    localStorage.setItem(THEME_KEY, next);
  });
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('auth-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
}

function clearAlert() {
  const el = document.getElementById('auth-alert');
  if (!el) return;
  el.hidden = true;
  el.textContent = '';
}

function saveSession(token, user) {
  setAuthToken(token);
  if (user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  }
}

function formatValidationErrors(body) {
  if (!body?.errors) return body?.message || 'Request failed';
  return Object.values(body.errors).flat().join(' ');
}

function redirectAfterAuth() {
  const params = new URLSearchParams(window.location.search);
  const next = params.get('next') || 'index.html';
  window.location.href = next;
}

async function handleGoogleTokenFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const token = params.get('token');
  const error = params.get('error');

  if (error) {
    showAlert('Google sign-in failed. Try again or use email/password.');
    return;
  }

  if (!token) return;

  saveSession(token, null);
  try {
    const { apiGet } = await import('./api.js');
    const data = await apiGet('/api/user');
    saveSession(token, data.user);
    window.history.replaceState({}, '', window.location.pathname);
    redirectAfterAuth();
  } catch {
    setAuthToken(null);
    showAlert('Could not complete Google sign-in. Please try again.');
  }
}

function wireGoogleButton() {
  const btn = document.getElementById('google-login');
  if (!btn) return;
  btn.href = `${API_BASE_URL}/api/auth/google/redirect`;
}

function wireLoginForm() {
  const form = document.getElementById('login-form');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearAlert();
    const submit = document.getElementById('login-submit');
    submit.disabled = true;

    try {
      const data = await apiPost('/api/login', {
        email: form.email.value.trim(),
        password: form.password.value,
      });
      saveSession(data.token, data.user);
      redirectAfterAuth();
    } catch (err) {
      showAlert(formatValidationErrors(err.body) || err.message);
    } finally {
      submit.disabled = false;
    }
  });
}

function wireSignupForm() {
  const form = document.getElementById('signup-form');
  if (!form) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearAlert();
    const submit = document.getElementById('signup-submit');
    submit.disabled = true;

    try {
      const data = await apiPost('/api/register', {
        name: form.name.value.trim(),
        email: form.email.value.trim(),
        password: form.password.value,
        password_confirmation: form.password_confirmation.value,
      });
      saveSession(data.token, data.user);
      redirectAfterAuth();
    } catch (err) {
      showAlert(formatValidationErrors(err.body) || err.message);
    } finally {
      submit.disabled = false;
    }
  });
}

export async function logout() {
  try {
    if (getAuthToken()) {
      const { apiPost: post } = await import('./api.js');
      await post('/api/logout', {});
    }
  } catch {
    // ignore network errors on logout
  } finally {
    setAuthToken(null);
    localStorage.removeItem(USER_KEY);
  }
}

export function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
  } catch {
    return null;
  }
}

initTheme();
wireGoogleButton();
wireLoginForm();
wireSignupForm();
handleGoogleTokenFromUrl();

if (getAuthToken() && !new URLSearchParams(window.location.search).has('token')) {
  // Already logged in on auth pages → go home
  if (document.getElementById('login-form') || document.getElementById('signup-form')) {
    // stay; user may want to switch accounts
  }
}
