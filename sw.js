/**
 * Service worker for the till — the piece that makes "offline" mean
 * "keep selling" rather than "this page cannot load".
 *
 * Strategy:
 *  - same-origin GETs: cache-first; misses are fetched and added to the
 *    cache, which covers the .woff2 files fonts.css pulls without listing
 *    them all here;
 *  - /api/ calls and non-GET requests: straight to the network. A cached
 *    product read would be stale and a replayed POST a lie — the offline
 *    outbox in IndexedDB (js/offline-db.js, drained by js/sync.js) owns sale
 *    replay, not this worker.
 *
 * Bump CACHE when the shell list changes; activate deletes every older cache.
 */
const CACHE = 'galla-till-v1';

/* The till's own dependency graph — enough to open pos.html and sell with no
   connection. Anything else is runtime-cached as it is visited. */
const SHELL = [
  'pos.html',
  'manifest.webmanifest',
  'favicon.svg',
  'css/styles.css',
  'js/api.js',
  'js/cart.js',
  'js/offline-db.js',
  'js/pos.js',
  'js/receipt.js',
  'js/sw-register.js',
  'js/sync.js',
  'js/theme.js',
  'js/units.js',
  'vendor/fonts/fonts.css',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.addAll(SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) => Promise.all(names.filter((n) => n !== CACHE).map((n) => caches.delete(n))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;

  event.respondWith(
    caches.match(request).then(
      (hit) =>
        hit ||
        fetch(request).then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
          }
          return response;
        })
    )
  );
});
