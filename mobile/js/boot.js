/**
 * App entry point — decides where the user lands, then uncovers the screen.
 *
 * Two splashes are in play. The *native* one (Capacitor) covers the gap before
 * the webview has painted anything. The in-app `#splash` div covers the gap
 * after that, while this module works out whether there is a session. Both are
 * the same navy, so the handoff between them is invisible; hiding the native
 * one first and routing afterwards would show a bare page mid-decision.
 */

import { getAuthToken } from './api.js';
import { currentTheme, hideNativeSplash, syncStatusBar } from './native.js';

/* Where a signed-in user goes. `home.html` is a deliberate placeholder — it
   proves the session round-trip and is the seam the mobile till and khata
   screens get built behind. */
const HOME = 'home.html';
const LOGIN = 'login.html';

/* The splash is not a loading bar — it is there so the app does not flash a
   half-built screen. Below roughly this it reads as a glitch rather than a
   deliberate opening, so a fast device still holds it briefly. */
const MIN_SPLASH_MS = 600;

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function boot() {
  const startedAt = Date.now();

  await syncStatusBar(currentTheme());

  let target = LOGIN;
  try {
    target = getAuthToken() ? HOME : LOGIN;
  } catch {
    /* A webview with storage disabled cannot hold a session, so the only
       honest destination is the login screen. */
    target = LOGIN;
  }

  const elapsed = Date.now() - startedAt;
  if (elapsed < MIN_SPLASH_MS) await wait(MIN_SPLASH_MS - elapsed);

  /* Hide the native splash *before* navigating, or the new document paints
     underneath it and the fade reveals a page that has already moved on. */
  await hideNativeSplash();

  window.location.replace(target);
}

boot();
