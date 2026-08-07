import { apiGet, apiPut, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

const USER_KEY = 'cashflow_auth_user';
const USER_FETCHED_KEY = 'cashflow_auth_user_at';

/** Both writes here come straight from the API, so they refresh the shell's cache. */
function cacheUser(user) {
  localStorage.setItem(USER_KEY, JSON.stringify(user));
  localStorage.setItem(USER_FETCHED_KEY, String(Date.now()));
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('profile.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('profile-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearAlert() {
  const el = document.getElementById('profile-alert');
  if (!el) return;
  el.hidden = true;
  el.textContent = '';
}

function firstValidationError(err, fallback) {
  const errors = err?.body?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }
  return err?.message || fallback;
}

async function loadUser() {
  const data = await apiGet('/api/user');
  const user = data?.user;
  if (!user) return;
  document.getElementById('profile-name').value = user.name || '';
  document.getElementById('profile-email').value = user.email || '';
  cacheUser(user);
}

function wireProfileForm() {
  document.getElementById('profile-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    clearAlert();

    const name = document.getElementById('profile-name').value.trim();
    if (!name) {
      showAlert('Please enter your name.');
      return;
    }

    const btn = document.getElementById('profile-save');
    btn.disabled = true;
    try {
      const data = await apiPut('/api/user/profile', { name });
      if (data?.user) {
        cacheUser(data.user);
        initShell({ current: 'profile' });
      }
      showAlert('Profile updated.', 'success');
    } catch (err) {
      showAlert(firstValidationError(err, 'Failed to update profile.'));
    } finally {
      btn.disabled = false;
    }
  });
}

function wirePasswordForm() {
  document.getElementById('password-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    clearAlert();

    const current = document.getElementById('current-password').value;
    const next = document.getElementById('new-password').value;
    const confirm = document.getElementById('confirm-password').value;

    if (next.length < 8) {
      showAlert('New password must be at least 8 characters.');
      return;
    }
    if (next !== confirm) {
      showAlert('New password and confirmation do not match.');
      return;
    }

    const btn = document.getElementById('password-save');
    btn.disabled = true;
    try {
      await apiPut('/api/user/password', {
        current_password: current,
        password: next,
        password_confirmation: confirm,
      });
      document.getElementById('password-form').reset();
      showAlert('Password updated successfully.', 'success');
    } catch (err) {
      showAlert(firstValidationError(err, 'Failed to update password.'));
    } finally {
      btn.disabled = false;
    }
  });
}

async function boot() {
  initTheme();
  initShell({ current: 'profile' });
  if (!requireAuth()) return;

  wireProfileForm();
  wirePasswordForm();

  try {
    await loadUser();
  } catch (err) {
    showAlert(err.message || 'Failed to load your profile.');
  }
}

boot();
