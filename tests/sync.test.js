import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { MAX_ATTEMPTS, classifySyncOutcome, flushOutbox } from '../js/sync.js';

/**
 * flushOutbox's real outbox lives in IndexedDB, which node:test has none of —
 * and mock.module() is still behind a flag this npm script does not pass. The
 * drain loop takes its store as a parameter instead, so these fakes stand in
 * for js/offline-db.js and exercise the real retry/drop/halt policy.
 */
function fakeOutbox(records = []) {
  const box = new Map(records.map((r) => [r.client_uuid, { ...r }]));

  return {
    box,
    async queuedSales() {
      return [...box.values()].sort((a, b) =>
        String(a.queued_at).localeCompare(String(b.queued_at))
      );
    },
    async removeQueued(uuid) {
      box.delete(uuid);
    },
    async updateQueued(uuid, patch) {
      if (box.has(uuid)) box.set(uuid, { ...box.get(uuid), ...patch });
    },
    async queuedCount() {
      return box.size;
    },
  };
}

function record(uuid, over = {}) {
  return {
    client_uuid: uuid,
    payload: { client_uuid: uuid, items: [{ product_id: 1, quantity: 1 }] },
    queued_at: '2026-01-01T10:00:00.000Z',
    attempts: 0,
    ...over,
  };
}

describe('classifySyncOutcome', () => {
  it('retries when there was no response at all', () => {
    assert.equal(classifySyncOutcome(null), 'retry');
    assert.equal(classifySyncOutcome(undefined), 'retry');
  });

  it('treats any 2xx as synced', () => {
    assert.equal(classifySyncOutcome(200), 'ok');
    assert.equal(classifySyncOutcome(201), 'ok');
    assert.equal(classifySyncOutcome(299), 'ok');
  });

  it('halts the drain on auth and subscription failures', () => {
    assert.equal(classifySyncOutcome(401), 'halt');
    assert.equal(classifySyncOutcome(402), 'halt');
    assert.equal(classifySyncOutcome(403), 'halt');
  });

  it('retries on timeouts, rate limits and server errors', () => {
    assert.equal(classifySyncOutcome(408), 'retry');
    assert.equal(classifySyncOutcome(429), 'retry');
    assert.equal(classifySyncOutcome(500), 'retry');
    assert.equal(classifySyncOutcome(503), 'retry');
  });

  it('drops other 4xx — the payload itself is wrong', () => {
    assert.equal(classifySyncOutcome(400), 'drop');
    assert.equal(classifySyncOutcome(404), 'drop');
    assert.equal(classifySyncOutcome(422), 'drop');
  });

  it('retries anything else, including redirects', () => {
    assert.equal(classifySyncOutcome(301), 'retry');
    assert.equal(classifySyncOutcome(0), 'retry');
  });
});

describe('flushOutbox', () => {
  it('sends every queued sale and removes the ones that land', async () => {
    const outbox = fakeOutbox([
      record('a', { queued_at: '2026-01-01T10:00:00.000Z' }),
      record('b', { queued_at: '2026-01-01T10:01:00.000Z' }),
    ]);
    const sent = [];

    const result = await flushOutbox(async (payload) => {
      sent.push(payload.client_uuid);
      return { status: 201, body: {} };
    }, outbox);

    assert.deepEqual(sent, ['a', 'b']);
    assert.deepEqual(result, { synced: 2, dropped: 0, remaining: 0, halted: false });
    assert.equal(outbox.box.size, 0);
  });

  it('treats a thrown send as a network failure and stops the drain', async () => {
    const outbox = fakeOutbox([record('a'), record('b')]);
    const sent = [];

    const result = await flushOutbox(async (payload) => {
      sent.push(payload.client_uuid);
      throw new TypeError('fetch failed');
    }, outbox);

    // The second sale is never attempted — if the connection is down the rest
    // would fail identically.
    assert.deepEqual(sent, ['a']);
    assert.equal(result.synced, 0);
    assert.equal(result.remaining, 2);

    const first = outbox.box.get('a');
    assert.equal(first.attempts, 1);
    assert.equal(first.last_error, 'network');
  });

  it('stops the drain on the first retryable status, recording the code', async () => {
    const outbox = fakeOutbox([record('a'), record('b')]);
    const sent = [];

    const result = await flushOutbox(async (payload) => {
      sent.push(payload.client_uuid);
      return { status: 500 };
    }, outbox);

    assert.deepEqual(sent, ['a']);
    assert.equal(result.remaining, 2);
    assert.equal(outbox.box.get('a').last_error, 500);
  });

  it('drops a sale the server rejects and moves on to the next', async () => {
    const outbox = fakeOutbox([record('a'), record('b')]);
    const statuses = { a: 422, b: 200 };

    const result = await flushOutbox(
      async (payload) => ({ status: statuses[payload.client_uuid] }),
      outbox
    );

    assert.equal(result.synced, 1);
    assert.equal(result.dropped, 1);
    assert.equal(result.remaining, 0);
    assert.equal(outbox.box.size, 0);
  });

  it('halts on an auth failure and leaves the queue untouched', async () => {
    const outbox = fakeOutbox([record('a'), record('b')]);
    const sent = [];

    const result = await flushOutbox(async (payload) => {
      sent.push(payload.client_uuid);
      return { status: 401 };
    }, outbox);

    assert.deepEqual(sent, ['a']);
    assert.equal(result.halted, true);
    assert.equal(result.remaining, 2);
    // Neither record is removed or bumped — retrying cannot fix a dead token.
    assert.equal(outbox.box.get('a').attempts, 0);
  });

  it('parks a sale that has exhausted its attempts instead of deleting it', async () => {
    const outbox = fakeOutbox([record('a', { attempts: MAX_ATTEMPTS - 1 })]);

    const result = await flushOutbox(async () => ({ status: 503 }), outbox);

    assert.equal(result.dropped, 1);
    assert.equal(result.remaining, 1);
    const parked = outbox.box.get('a');
    assert.equal(parked.attempts, MAX_ATTEMPTS);
    assert.equal(parked.parked, true);
  });

  it('reports an empty queue as nothing to do', async () => {
    const outbox = fakeOutbox([]);
    let called = 0;

    const result = await flushOutbox(async () => {
      called += 1;
      return { status: 200 };
    }, outbox);

    assert.equal(called, 0);
    assert.deepEqual(result, { synced: 0, dropped: 0, remaining: 0, halted: false });
  });
});
