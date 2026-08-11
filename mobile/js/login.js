/**
 * Mobile login.
 *
 * Reuses `js/api.js` — the same client the web app posts through — rather than
 * `js/auth.js`, which wires the desktop header and nav at import time and
 * expects DOM this screen does not have. The API contract is shared; only the
 * UI wiring is local.
 */

import { apiPost, getAuthToken, setAuthToken } from './api.js';
import { currentTheme, hideNativeSplash, syncStatusBar } from './native.js';

const USER_KEY = 'cashflow_auth_user';
const USER_FETCHED_KEY = 'cashflow_auth_user_at';
const HOME = 'home.html';

const form = document.getElementById('login-form');
const submit = document.getElementById('login-submit');
const alertBox = document.getElementById('auth-alert');
const emailInput = document.getElementById('email');
const passwordInput = document.getElementById('password');

function showAlert(message) {
  alertBox.hidden = false;
  alertBox.textContent = message;
}

function clearAlert() {
  alertBox.hidden = true;
  alertBox.textContent = '';
  emailInput.removeAttribute('aria-invalid');
  passwordInput.removeAttribute('aria-invalid');
}

function setBusy(busy) {
  submit.disabled = busy;
  submit.dataset.busy = String(busy);
}

/**
 * Laravel returns `{ errors: { field: [msg] } }` on a 422 and a bare `message`
 * on everything else. Joining the flattened values keeps multi-field failures
 * readable in the one alert strip this screen has room for.
 */
function errorText(err) {
  const body = err?.body;
  if (body?.errors) {
    const messages = Object.values(body.errors).flat();
    if (messages.length) return messages.join(' ');
  }
  if (body?.message) return body.message;
  /* A failed fetch has no body at all — on a phone that is nearly always the
     connection rather than the credentials, and saying so avoids sending the
     shopkeeper off to reset a password that was never wrong. */
  return 'Could not reach the server. Check your connection and try again.';
}

function saveSession(token, user) {
  setAuthToken(token);
  if (user) {
    /* Stamped so the app shell treats this as a fresh read and does not spend
       the first navigation after login re-fetching what we just received. */
    localStorage.setItem(USER_KEY, JSON.stringify(user));
    localStorage.setItem(USER_FETCHED_KEY, String(Date.now()));
  }
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  clearAlert();

  const email = emailInput.value.trim();
  const password = passwordInput.value;

  if (!email || !password) {
    showAlert('Enter your email and password.');
    (email ? passwordInput : emailInput).setAttribute('aria-invalid', 'true');
    (email ? passwordInput : emailInput).focus();
    return;
  }

  setBusy(true);
  try {
    const data = await apiPost('/api/login', { email, password });
    saveSession(data.token, data.user);
    /* replace(), not href: the back gesture from the app must not land on a
       login screen the user has already passed through. */
    window.location.replace(HOME);
  } catch (err) {
    showAlert(errorText(err));
    passwordInput.value = '';
    passwordInput.focus();
  } finally {
    setBusy(false);
  }
});

/* Landing here with a live session — via a deep link, or the back gesture —
   should not ask for a password again. */
if (getAuthToken()) {
  window.location.replace(HOME);
} else {
  syncStatusBar(currentTheme());
  /* Covers the direct-load case: boot.js normally hides the splash before it
     routes, but this page can also be opened as the first document. */
  hideNativeSplash();
}
