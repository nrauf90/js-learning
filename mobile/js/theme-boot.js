/*
 * Paints the theme before first paint.
 *
 * A classic blocking script rather than a module: modules are deferred, so the
 * browser would paint the light default first and a dark-mode user would see a
 * white flash before the attribute landed. Same reasoning as the web app's M13
 * fix — the difference is that on a phone the flash is full-screen.
 *
 * Light is the default when nothing is stored and the OS has not asked for
 * dark, matching css/mobile.css where light lives on bare :root.
 */
(function applyStoredTheme() {
  var stored = null;
  try {
    stored = localStorage.getItem('theme');
  } catch (e) {
    /* Private mode or a locked-down webview — fall through to the OS. */
  }

  var theme =
    stored === 'dark' || stored === 'light'
      ? stored
      : window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light';

  document.documentElement.setAttribute('data-theme', theme);
})();
