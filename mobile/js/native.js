/**
 * Thin access layer over the Capacitor bridge.
 *
 * Plugins are reached through the injected `window.Capacitor.Plugins` global
 * rather than by importing '@capacitor/splash-screen' and friends. That is
 * deliberate: this project ships vanilla ES modules with no bundler, and a bare
 * package specifier does not resolve in a browser. The global is what the
 * native bridge injects anyway, so nothing is lost.
 *
 * Every helper is a no-op in a plain browser, so `npm run mobile:serve` can
 * preview the same files with no native runtime present.
 */

/** True inside the Android/iOS webview, false in a desktop browser preview. */
export function isNative() {
  return Boolean(window.Capacitor?.isNativePlatform?.());
}

function plugin(name) {
  return window.Capacitor?.Plugins?.[name] || null;
}

/**
 * Hide the native splash.
 *
 * `launchAutoHide` is false in capacitor.config.json, so the splash stays up
 * until this is called — the app decides when it is actually ready rather than
 * racing a fixed timer, which on a slow device uncovers a half-painted screen.
 */
export async function hideNativeSplash() {
  const splash = plugin('SplashScreen');
  if (!splash) return;
  try {
    await splash.hide({ fadeOutDuration: 200 });
  } catch {
    /* Never let a cosmetic call block boot. */
  }
}

/**
 * Match the status bar to the current theme.
 *
 * Capacitor's `Style.Dark` means *dark content on a light bar*, which reads
 * backwards; it is passed as a literal here with this note rather than an
 * imported enum so the inversion is visible at the call site.
 */
export async function syncStatusBar(theme) {
  const bar = plugin('StatusBar');
  if (!bar) return;
  try {
    await bar.setStyle({ style: theme === 'dark' ? 'DARK' : 'LIGHT' });
    if (window.Capacitor?.getPlatform?.() === 'android') {
      await bar.setBackgroundColor({ color: theme === 'dark' ? '#060a14' : '#f4f7fb' });
    }
  } catch {
    /* iOS rejects setBackgroundColor; nothing here is load-bearing. */
  }
}

/** The theme currently painted by theme-boot.js. */
export function currentTheme() {
  return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
}
