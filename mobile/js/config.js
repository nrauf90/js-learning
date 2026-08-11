/*
 * Mobile runtime config. A classic script, not a module, and loaded *before*
 * api.js: api.js reads window.API_BASE_URL once at import time, so setting it
 * from a module would land after the value had already been captured.
 *
 * ── Set NATIVE_API before building an APK ─────────────────────────────────
 * `127.0.0.1` is the phone itself, not your laptop, so the web app's default
 * cannot work in a native build. Point NATIVE_API at whichever this build is
 * for:
 *
 *   Android emulator       http://10.0.2.2:8000      (the host loopback alias)
 *   Device on your Wi-Fi   http://192.168.x.x:8000   (your machine's LAN IP;
 *                          `php artisan serve --host=0.0.0.0` to accept it)
 *   iOS simulator          http://127.0.0.1:8000     (shares the host network)
 *   Production             https://api.your-domain.com
 *
 * Cleartext HTTP to a LAN IP is fine for development — `allowMixedContent` is
 * on in capacitor.config.json — but a release build must be HTTPS: Android
 * blocks cleartext by default from API 28, and a bearer token over plain HTTP
 * on shop Wi-Fi is readable by anyone on it.
 */
(function configureApi() {
  var NATIVE_API = 'http://10.0.2.2:8000';

  /*
   * `npm run mobile:serve` opens these same files in a desktop browser, where
   * the emulator alias resolves to nothing. Detecting the bridge rather than
   * hardcoding one value means the preview talks to the local backend and the
   * APK talks to NATIVE_API, with no edit between the two.
   */
  var isNative = Boolean(window.Capacitor && window.Capacitor.isNativePlatform &&
    window.Capacitor.isNativePlatform());

  window.API_BASE_URL = isNative ? NATIVE_API : 'http://127.0.0.1:8000';
})();
