/**
 * Placeholder home screen.
 *
 * Its only job is to prove the session is real — it reads the cached user, then
 * confirms the token against the API — so a device build can be verified end to
 * end before any till screen exists.
 */

import { apiGet, apiPost, getAuthToken, setAuthToken } from './api.js';
import { hideNativeSplash } from './native.js';
import { initTheme } from './theme.js';

const USER_KEY = 'cashflow_auth_user';
const USER_FETCHED_KEY = 'cashflow_auth_user_at';
const LOGIN = 'login.html';

const greeting = document.getElementById('home-greeting');
const alertBox = document.getElementById('home-alert');
const logoutBtn = document.getElementById('logout');

function cachedUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
  } catch {
    return null;
  }
}

function clearSession() {
  setAuthToken(null);
  localStorage.removeItem(USER_KEY);
  localStorage.removeItem(USER_FETCHED_KEY);
}

if (!getAuthToken()) {
  window.location.replace(LOGIN);
} else {
  initTheme();
  hideNativeSplash();

  /* Show the cached name immediately so the screen is never blank, then
     confirm against the API — a token can be revoked or expired (Sanctum
     expires them at 30 days) while the cache still looks valid. */
  const cached = cachedUser();
  if (cached?.name) greeting.textContent = `Khush aamdeed, ${cached.name}.`;

  apiGet('/api/user')
    .then((user) => {
      if (user?.name) greeting.textContent = `Khush aamdeed, ${user.name}.`;
      localStorage.setItem(USER_KEY, JSON.stringify(user));
      localStorage.setItem(USER_FETCHED_KEY, String(Date.now()));
    })
    .catch((err) => {
      if (err?.status === 401) {
        clearSession();
        window.location.replace(LOGIN);
        return;
      }
      /* Offline or the server is down. The cached name still stands, so say
         what is stale rather than throwing the user back to a login screen
         they cannot complete without a connection. */
      alertBox.hidden = false;
      alertBox.textContent = 'Server se raabta nahi hua — mehfooz tafseelat dikha rahe hain.';
    });

  logoutBtn.addEventListener('click', async () => {
    logoutBtn.disabled = true;
    try {
      await apiPost('/api/logout', {});
    } catch {
      /* A logout that cannot reach the server still has to clear the device:
         leaving the token behind would keep the shop signed in on a handset
         that was just handed to someone else. */
    } finally {
      clearSession();
      window.location.replace(LOGIN);
    }
  });
}
