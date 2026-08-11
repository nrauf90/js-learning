/**
 * Mobile login.
 *
 * Reuses `js/api.js` — the same client the web app posts through — rather than
 * `js/auth.js`, which wires the desktop header and nav at import time and
 * expects DOM this screen does not have. The API contract is shared; only the
 * UI wiring is local.
 */

import { apiPost, getAuthToken, setAuthToken } from './api.js';
import { hideNativeSplash } from './native.js';
import { initTheme } from './theme.js';

const USER_KEY = 'cashflow_auth_user';
const USER_FETCHED_KEY = 'cashflow_auth_user_at';
const HOME = 'home.html';

const form = document.getElementById('login-form');
const submit = document.getElementById('login-submit');
const alertBox = document.getElementById('auth-alert');
const emailInput = document.getElementById('email');
const passwordInput = document.getElementById('password');
const passwordToggle = document.getElementById('password-toggle');

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
  /* data-busy drives the spinner visually; aria-busy is what tells a screen
     reader that the button is working rather than simply unresponsive. */
  submit.setAttribute('aria-busy', String(busy));
}

/**
 * Show/hide the password.
 *
 * Focus is restored and the caret put back at the end, because changing an
 * input's `type` drops focus in every browser — without this the keyboard
 * closes on each tap and the shopkeeper has to tap back into the field.
 */
passwordToggle?.addEventListener('click', () => {
  const showing = passwordInput.type === 'text';
  passwordInput.type = showing ? 'password' : 'text';
  passwordToggle.setAttribute('aria-pressed', String(!showing));
  passwordToggle.setAttribute('aria-label', showing ? 'Password dikhayein' : 'Password chhupayein');
  passwordInput.focus();
  const end = passwordInput.value.length;
  passwordInput.setSelectionRange(end, end);
});

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
     shopkeeper off to reset a password that was never wrong. Said in the
     product's own voice, because this is the one message a user hits when they
     are already frustrated. */
  return 'Server se raabta nahi ho saka. Apna internet check karke dobara koshish karein.';
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
    showAlert('Email aur password dono likhein.');
    /* Focus the field that is actually empty, so the keyboard opens on the one
       needing input rather than making the user hunt for it. */
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
  initTheme();
  /* Covers the direct-load case: boot.js normally hides the splash before it
     routes, but this page can also be opened as the first document. */
  hideNativeSplash();
  /* Not autofocused: on a phone that throws the keyboard up over the card the
     instant the screen appears, hiding the logo and half the form before the
     user has decided what they are looking at. */
}
