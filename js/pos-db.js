/**
 * IndexedDB wrapper for offline POS till.
 */
const DB_NAME = 'cashflow-pos';
const DB_VERSION = 1;

function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onerror = () => reject(req.error);
    req.onsuccess = () => resolve(req.result);
    req.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains('products')) {
        db.createObjectStore('products', { keyPath: 'id' });
      }
      if (!db.objectStoreNames.contains('outbox')) {
        const outbox = db.createObjectStore('outbox', { keyPath: 'id', autoIncrement: true });
        outbox.createIndex('type', 'type', { unique: false });
        outbox.createIndex('status', 'status', { unique: false });
      }
      if (!db.objectStoreNames.contains('sales')) {
        db.createObjectStore('sales', { keyPath: 'client_sale_id' });
      }
    };
  });
}

async function withStore(storeName, mode, fn) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(storeName, mode);
    const store = tx.objectStore(storeName);
    const result = fn(store);
    tx.oncomplete = () => resolve(result);
    tx.onerror = () => reject(tx.error);
  });
}

export async function cacheProducts(products) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction('products', 'readwrite');
    const store = tx.objectStore('products');
    store.clear();
    products.forEach((p) => store.put(p));
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
  });
}

export async function getCachedProducts() {
  return withStore('products', 'readonly', (store) => {
    return new Promise((resolve, reject) => {
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  });
}

export async function addOutboxItem(item) {
  return withStore('outbox', 'readwrite', (store) => {
    return new Promise((resolve, reject) => {
      const req = store.add({ ...item, status: 'pending', created_at: new Date().toISOString() });
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  });
}

export async function getPendingOutbox() {
  return withStore('outbox', 'readonly', (store) => {
    return new Promise((resolve, reject) => {
      const req = store.index('status').getAll('pending');
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  });
}

export async function markOutboxSynced(id) {
  return withStore('outbox', 'readwrite', (store) => {
    return new Promise((resolve, reject) => {
      const getReq = store.get(id);
      getReq.onsuccess = () => {
        const item = getReq.result;
        if (!item) {
          resolve();
          return;
        }
        item.status = 'synced';
        store.put(item);
      };
      getReq.onerror = () => reject(getReq.error);
    });
  });
}

export async function saveLocalSale(sale) {
  return withStore('sales', 'readwrite', (store) => {
    store.put(sale);
  });
}

export async function getLocalSales() {
  return withStore('sales', 'readonly', (store) => {
    return new Promise((resolve, reject) => {
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
  });
}

export function generateId() {
  return crypto.randomUUID();
}
