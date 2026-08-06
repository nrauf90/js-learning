import { API_BASE_URL, apiPost, getAuthToken, setAuthToken } from './api.js';
import { initNav } from './nav.js';
import { initTheme } from './theme.js';

const USER_KEY = 'cashflow_auth_user';

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
  const next = params.get('next') || 'dashboard.html';
  window.location.href = next;
}

async function handleGoogleCodeFromUrl() {
  const params = new URLSearchParams(window.location.search);
  const code = params.get('google_code');
  const error = params.get('error');

  if (error) {
    showAlert('Google sign-in failed. Try again or use email/password.');
    return;
  }

  if (!code) return;

  // The redirect carries a short-lived, single-use code — never the bearer
  // token itself — which is exchanged here for the real token via POST so
  // it never sits in the URL/browser history.
  window.history.replaceState({}, '', window.location.pathname);
  try {
    const data = await apiPost('/api/auth/google/exchange', { code });
    saveSession(data.token, data.user);
    redirectAfterAuth();
  } catch {
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
initNav();
wireGoogleButton();
wireLoginForm();
wireSignupForm();
handleGoogleCodeFromUrl();

if (getAuthToken() && !new URLSearchParams(window.location.search).has('token')) {
  if (document.getElementById('login-form') || document.getElementById('signup-form')) {
    redirectAfterAuth();
  }
}
