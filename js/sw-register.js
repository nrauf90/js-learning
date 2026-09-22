/**
 * Registers the till's service worker (sw.js, site root).
 *
 * A plain script rather than a module so any page can opt in with one tag and
 * older browsers simply skip it — without a worker the app behaves exactly as
 * it did before. Registration waits for `load`: the page's own assets matter
 * more than the worker's install fetches.
 */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  });
}
