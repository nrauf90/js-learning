/**
 * Drains the offline sale outbox.
 *
 * Every queued sale carries the `client_uuid` it was minted with, and the API
 * treats that as an idempotency key — so replaying a sale the server already
 * stored returns the original instead of creating a second one. That is what
 * makes "retry until it sticks" safe here.
 */
import { queuedSales, removeQueued, updateQueued, queuedCount } from './offline-db.js';

/** Give up on a sale after this many failed attempts and park it for review. */
export const MAX_ATTEMPTS = 8;

/**
 * Decide what to do with a sync attempt, from its HTTP status alone.
 *
 * Pure, so the retry policy can be tested without a network or a database —
 * see tests/sync.test.js. `status` is null for a network-level failure.
 *
 * @returns {'ok' | 'retry' | 'drop' | 'halt'}
 */
export function classifySyncOutcome(status) {
  // No response at all: still offline, or the request timed out. Always safe
  // to retry — the uuid means a sale that did land will not be duplicated.
  if (status === null || status === undefined) return 'retry';

  if (status >= 200 && status < 300) return 'ok';

  // Auth and subscription problems are not fixable by retrying, and burning
  // through attempts would silently discard real sales. Stop the whole drain
  // and let the user resolve it.
  if (status === 401 || status === 403 || status === 402) return 'halt';

  if (status === 408 || status === 429 || status >= 500) return 'retry';

  // Any other 4xx means the payload itself is wrong (a deleted product, a
  // malformed field). Retrying forever would block every sale behind it.
  if (status >= 400) return 'drop';

  return 'retry';
}

/**
 * @param {(sale: object) => Promise<{ status: number|null, body?: object }>} send
 * @returns {Promise<{ synced: number, dropped: number, remaining: number, halted: boolean }>}
 */
export async function flushOutbox(send) {
  const pending = await queuedSales();
  let synced = 0;
  let dropped = 0;
  let halted = false;

  for (const sale of pending) {
    let result;
    try {
      result = await send(sale.payload);
    } catch {
      result = { status: null };
    }

    const outcome = classifySyncOutcome(result.status);

    if (outcome === 'ok') {
      await removeQueued(sale.client_uuid);
      synced += 1;
      continue;
    }

    if (outcome === 'halt') {
      halted = true;
      break;
    }

    if (outcome === 'drop') {
      await removeQueued(sale.client_uuid);
      dropped += 1;
      continue;
    }

    const attempts = (sale.attempts || 0) + 1;

    if (attempts >= MAX_ATTEMPTS) {
      // Park rather than delete: a sale that never syncs is money the shop
      // took, and silently dropping it is worse than leaving it visible.
      await updateQueued(sale.client_uuid, { attempts, parked: true });
      dropped += 1;
      continue;
    }

    await updateQueued(sale.client_uuid, { attempts, last_error: result.status ?? 'network' });

    // Stop on the first retryable failure. If the connection is down, the rest
    // will fail identically — no point hammering through the whole queue.
    break;
  }

  return { synced, dropped, remaining: await queuedCount(), halted };
}

/**
 * Flush now, then again whenever the browser regains connectivity or the tab
 * is brought back to the foreground (a till often sleeps between customers).
 *
 * @returns {() => void} unsubscribe
 */
export function startAutoSync(flush) {
  const run = () => {
    if (navigator.onLine) flush();
  };

  const onVisible = () => {
    if (document.visibilityState === 'visible') run();
  };

  window.addEventListener('online', run);
  document.addEventListener('visibilitychange', onVisible);

  run();

  return () => {
    window.removeEventListener('online', run);
    document.removeEventListener('visibilitychange', onVisible);
  };
}
