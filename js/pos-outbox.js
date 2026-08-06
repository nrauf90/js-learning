/**
 * Offline outbox sync for POS sales and refunds.
 */
import { apiPost } from './api.js';
import {
  addOutboxItem,
  getPendingOutbox,
  markOutboxSynced,
} from './pos-db.js';

let syncing = false;

export async function enqueueSale(salePayload) {
  return addOutboxItem({ type: 'sale', payload: salePayload });
}

export async function enqueueRefund(refundPayload) {
  return addOutboxItem({ type: 'refund', payload: refundPayload });
}

export async function syncOutbox() {
  if (syncing || !navigator.onLine) return { synced: 0, failed: 0 };
  syncing = true;

  let synced = 0;
  let failed = 0;

  try {
    const pending = await getPendingOutbox();
    const sales = pending.filter((i) => i.type === 'sale').map((i) => i.payload);
    const refunds = pending.filter((i) => i.type === 'refund').map((i) => i.payload);

    if (sales.length) {
      try {
        await apiPost('/api/pos/sales/sync', { sales });
        for (const item of pending.filter((i) => i.type === 'sale')) {
          await markOutboxSynced(item.id);
          synced++;
        }
      } catch {
        failed += sales.length;
      }
    }

    if (refunds.length) {
      try {
        await apiPost('/api/pos/refunds/sync', { refunds });
        for (const item of pending.filter((i) => i.type === 'refund')) {
          await markOutboxSynced(item.id);
          synced++;
        }
      } catch {
        failed += refunds.length;
      }
    }
  } finally {
    syncing = false;
  }

  return { synced, failed };
}

export function initOutboxSync(onStatusChange) {
  const run = async () => {
    const result = await syncOutbox();
    if (onStatusChange) onStatusChange(result);
  };

  window.addEventListener('online', run);
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
      if (event.data?.type === 'SYNC_OUTBOX') run();
    });
  }

  run();
  return run;
}
