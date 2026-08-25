/**
 * The two halves of "I forgot my password" — the request form and the form the
 * emailed link lands on. One module because they share the alert plumbing and
 * the error shape, and because each is a single form; splitting them would mean
 * two files that are mostly the same twenty lines.
 *
 * Which half runs is decided by which form is on the page.
 */
import { apiPost } from './api.js';
import { initNav } from './nav.js';
import { initTheme } from './theme.js';

function el(id) {
  return document.getElementById(id);
}

function showAlert(message, type = 'error') {
  const box = el('auth-alert');
  if (!box) return;
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearAlert() {
  const box = el('auth-alert');
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
}

/** Laravel returns {errors: {field: [msg]}} on 422 and {message} otherwise. */
function firstError(err, fallback) {
  const errors = err?.body?.errors;
  if (errors) {
    const first = Object.values(errors).flat()[0];
    if (first) return first;
  }
  return err?.body?.message || err?.message || fallback;
}

/* ------------------------------------------------------ request a new link */

function wireForgotForm() {
  const form = el('forgot-form');
  if (!form) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearAlert();

    const submit = el('forgot-submit');
    submit.disabled = true;

    try {
      const data = await apiPost('/api/password/forgot', {
        email: form.email.value.trim(),
      });

      // The API answers the same way whether or not the address is on file, so
      // there is nothing here to branch on — and nothing to leak. The form is
      // cleared so a shared counter phone does not keep the address on screen.
      showAlert(data?.message || 'If that email belongs to an account, a reset link is on its way.', 'success');
      form.reset();
    } catch (err) {
      showAlert(firstError(err, 'Could not send the reset link. Please try again.'));
    } finally {
      submit.disabled = false;
    }
  });
}

/* ------------------------------------------------- land on the emailed link */

/**
 * Reads the token and address out of the link and takes them straight back out
 * of the address bar.
 *
 * The fragment is where the mailer puts them (see AppServiceProvider), because
 * a fragment is never sent to a server — it stays out of the web host's access
 * logs and out of Referer headers, and it survives static hosts that rewrite
 * /page.html to /page and drop the query string doing it.
 *
 * The query string is still read as a fallback: a link mailed before the format
 * changed, or one mangled by a mail client that strips fragments, should still
 * work rather than dead-end.
 *
 * Either way replaceState drops it before the page settles, so the token never
 * reaches browser history and a screenshot of this page does not hand the reset
 * link to whoever sees the screenshot.
 */
function readLinkParams() {
  const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  const query = new URLSearchParams(window.location.search);

  const token = fragment.get('token') || query.get('token') || '';
  const email = fragment.get('email') || query.get('email') || '';

  if (token || email) {
    window.history.replaceState({}, '', window.location.pathname);
  }

  return { token, email };
}

function wireResetForm() {
  const form = el('reset-form');
  if (!form) return;

  const { token, email } = readLinkParams();

  form.email.value = email;

  if (!token) {
    // Nothing can be done on this page without one, so say so rather than
    // letting the shopkeeper type a password and fail on submit.
    showAlert('This page needs the link from your reset email. Please open that link, or request a new one.');
    el('reset-submit').disabled = true;
    return;
  }

  // Only pre-filled addresses are locked. If the link arrived without one, the
  // shopkeeper has to be able to type it.
  if (!email) form.email.readOnly = false;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearAlert();

    if (form.password.value !== form.password_confirmation.value) {
      showAlert('The two passwords do not match.');
      return;
    }

    const submit = el('reset-submit');
    submit.disabled = true;

    try {
      await apiPost('/api/password/reset', {
        token,
        email: form.email.value.trim(),
        password: form.password.value,
        password_confirmation: form.password_confirmation.value,
      });

      // The API has just revoked every token this account had, so there is no
      // session to carry forward — the shopkeeper logs in with the new one.
      showAlert('Password updated. Taking you to the log in screen…', 'success');
      form.reset();
      setTimeout(() => {
        window.location.href = 'login.html';
      }, 1500);
    } catch (err) {
      showAlert(firstError(err, 'Could not set that password. Please request a new link.'));
      submit.disabled = false;
    }
  });
}

initTheme();
initNav();
wireForgotForm();
wireResetForm();
