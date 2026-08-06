/**
 * IndexedDB store backing the offline till.
 *
 * Three stores:
 *  - `products` mirrors the catalog so the till can keep selling with no
 *    connection, and carries a locally-adjusted stock count.
 *  - `outbox` holds sales rung up while offline, keyed by the `client_uuid`
 *    the till mints before queueing. That key is what makes the eventual POST
 *    idempotent.
 *  - `meta` holds small scalars (last catalog sync).
 *
 * localStorage would have been simpler but is synchronous and capped at ~5MB —
 * a day of queued sales on a busy counter can exceed that, and blocking the
 * main thread mid-sale is exactly what a till must not do.
 */

const DB_NAME = 'cashflow-pos';
const DB_VERSION = 1;
const STORE_PRODUCTS = 'products';
const STORE_OUTBOX = 'outbox';
const STORE_META = 'meta';

let dbPromise = null;

export function isSupported() {
  return typeof indexedDB !== 'undefined';
}

function openDb() {
  if (dbPromise) return dbPromise;

  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_PRODUCTS)) {
        db.createObjectStore(STORE_PRODUCTS, { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains(STORE_OUTBOX)) {
        const outbox = db.createObjectStore(STORE_OUTBOX, { keyPath: 'client_uuid' });
        outbox.createIndex('queued_at', 'queued_at');
      }
      if (!db.objectStoreNames.contains(STORE_META)) {
        db.createObjectStore(STORE_META, { keyPath: 'key' });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });

  return dbPromise;
}

function tx(db, store, mode) {
  return db.transaction(store, mode).objectStore(store);
}

function done(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

/* ------------------------------------------------------------- catalogue */

export async function cacheProducts(products) {
  const db = await openDb();
  const transaction = db.transaction(STORE_PRODUCTS, 'readwrite');
  const store = transaction.objectStore(STORE_PRODUCTS);

  // Full replace: a product deleted server-side must disappear from the till,
  // and merging would leave it sellable forever.
  store.clear();
  products.forEach((product) => store.put(product));

  await new Promise((resolve, reject) => {
    transaction.oncomplete = resolve;
    transaction.onerror = () => reject(transaction.error);
  });

  await setMeta('products_synced_at', new Date().toISOString());
}

export async function cachedProducts() {
  const db = await openDb();
  return done(tx(db, STORE_PRODUCTS, 'readonly').getAll());
}

/**
 * Apply a local stock movement so the cached catalog stays believable between
 * syncs. The server remains the source of truth; this only stops the till from
 * showing "10 in stock" after the cashier has sold all ten offline.
 */
export async function adjustCachedStock(productId, delta) {
  const db = await openDb();
  const store = tx(db, STORE_PRODUCTS, 'readwrite');
  const product = await done(store.get(productId));

  if (!product || !product.track_stock) return;

  product.stock_quantity += delta;
  product.low_stock =
    product.low_stock_threshold > 0 && product.stock_quantity <= product.low_stock_threshold;
  store.put(product);
}

/* ----------------------------------------------------------------- outbox */

export async function queueSale(sale) {
  const db = await openDb();
  await done(tx(db, STORE_OUTBOX, 'readwrite').put(sale));
}

export async function queuedSales() {
  const db = await openDb();
  const all = await done(tx(db, STORE_OUTBOX, 'readonly').getAll());
  // Replay in the order they were rung up, so sale references end up roughly
  // matching the sequence the cashier saw.
  return all.sort((a, b) => String(a.queued_at).localeCompare(String(b.queued_at)));
}

export async function queuedCount() {
  const db = await openDb();
  return done(tx(db, STORE_OUTBOX, 'readonly').count());
}

export async function removeQueued(clientUuid) {
  const db = await openDb();
  await done(tx(db, STORE_OUTBOX, 'readwrite').delete(clientUuid));
}

export async function updateQueued(clientUuid, patch) {
  const db = await openDb();
  const store = tx(db, STORE_OUTBOX, 'readwrite');
  const existing = await done(store.get(clientUuid));
  if (!existing) return;
  store.put({ ...existing, ...patch });
}

/* ------------------------------------------------------------------- meta */

export async function setMeta(key, value) {
  const db = await openDb();
  await done(tx(db, STORE_META, 'readwrite').put({ key, value }));
}

export async function getMeta(key) {
  const db = await openDb();
  const row = await done(tx(db, STORE_META, 'readonly').get(key));
  return row?.value ?? null;
}
