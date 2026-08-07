# Offline selling and sync

## What it is

The intended behaviour: when the shop's connection drops mid-shift, the till keeps
selling from a cached catalogue, queues each sale locally, and replays the queue
when the line comes back — without ever creating the same sale twice.

**This is half-built.** The server side is complete and tested. The browser side
exists as two finished modules that **nothing imports**, and `pos.html` does not
load them. As it stands today, a till with no connection cannot sell at all: the
first failed request surfaces in the alert strip and the ticket stays on screen.

## How it works

### What is actually wired up (the server)

`sales` carries three columns for this:

| column | purpose |
|---|---|
| `client_uuid` | idempotency key minted by the till **before** the sale is sent |
| `is_offline` | flag, kept rather than inferred, because it changes how stock conflicts are handled on sync |
| `sold_at` | when the sale really happened, not when the queue drained |

`POST /api/sales` accepts `client_uuid`, `offline` and `sold_at` (bounded to the
last 30 days and not in the future). Replay safety is two-layered:

1. `SaleController::store()` looks the uuid up first and returns the existing sale
   if it finds one. The network dropping mid-POST is the normal case, not an edge
   case.
2. The unique index on `(user_id, client_uuid)` is the real guarantee. Two replays
   that race past the lookup collide there, and the loser catches the
   `UniqueConstraintViolationException`, re-reads, and returns the same success the
   winner got. Only a genuine failure to find the row afterwards produces a 409.

An **offline sale is allowed to overdraw stock.** The goods left the shop and the
money changed hands; rejecting the sale on sync because another terminal sold the
same last unit would lose a real transaction. So `resolveLines()` is called with
`allowNegativeStock: true`, which skips both the stock check and the `is_active`
check — ownership and existence are still enforced — and the resulting negative
count becomes the signal to recount.

For the same reason, the **credit-limit check is skipped** for offline replays.
Refusing the sale would not un-give the credit, only lose the record of it.

`sale_items.refunded_quantity` and the whole refund path work identically for a
synced offline sale.

The sales list shows "Recorded offline" under the reference for any sale with
`is_offline` set.

### What exists but is not wired up (the browser)

**`js/offline-db.js`** — an IndexedDB store with three object stores:

| store | keyPath | holds |
|---|---|---|
| `products` | `id` | a mirror of the catalogue with a locally-adjusted stock count |
| `outbox` | `client_uuid` | sales rung up while offline, indexed on `queued_at` |
| `meta` | `key` | small scalars, e.g. `products_synced_at` |

IndexedDB rather than `localStorage` because the latter is synchronous and capped
around 5 MB — a day of queued sales on a busy counter can exceed that, and
blocking the main thread mid-sale is exactly what a till must not do.

`cacheProducts()` does a **full replace**, not a merge: a product deleted
server-side must disappear from the till, and merging would leave it sellable
forever. `adjustCachedStock()` coerces both sides to `Number` before adding —
the API serialises decimals as strings, and `"1.5" += 0.25` would cache `"1.50.25"`
and quietly make the count nonsense — and rounds to milligram precision so a run
of offline weighed sales cannot drift the cached figure.

**`js/sync.js`** — the drain. `classifySyncOutcome(status)` is a pure function so
the retry policy can be tested without a network or a database:

| status | outcome | why |
|---|---|---|
| none (network failure) | `retry` | still offline; the uuid means a sale that did land will not be duplicated |
| 2xx | `ok` | remove from the outbox |
| 401 / 402 / 403 | `halt` | not fixable by retrying, and burning attempts would silently discard real sales — stop the whole drain and let the user resolve it |
| 408 / 429 / 5xx | `retry` | transient |
| any other 4xx | `drop` | the payload itself is wrong (a deleted product, a malformed field); retrying forever would block every sale behind it |

`flushOutbox()` stops on the **first** retryable failure — if the connection is
down the rest will fail identically — and parks a sale rather than deleting it
after `MAX_ATTEMPTS` (8). A sale that never syncs is money the shop took, and
silently dropping it is worse than leaving it visible.

`startAutoSync()` flushes now, on `window.online`, and on
`visibilitychange` back to visible — a till often sleeps between customers.

## Screens / files

| Layer | File | Status |
|---|---|---|
| Local store | `js/offline-db.js` | written, **imported by nothing** |
| Drain + retry policy | `js/sync.js` | written, **imported by nothing** |
| Till | `js/pos.js` | does **not** import either; no uuid is minted, no sale is queued |
| API | `backend/app/Http/Controllers/Api/SaleController.php` | complete |
| Service | `backend/app/Services/Pos/SaleService.php` | complete |
| Migration | `2026_08_06_120000_add_offline_and_refund_fields_to_sales_table.php` | applied |
| Backend tests | `backend/tests/Feature/PosTest.php` | cover uuid replay and negative-stock sync |

`js/sync.js` refers to `tests/sync.test.js` in a comment; that file does not
exist. `tests/` holds `cart.test.js` and `units.test.js` only.

## API endpoints

| Method | Path | Offline-specific behaviour |
|---|---|---|
| POST | `/api/sales` | Accepts `client_uuid`, `offline`, `sold_at`; replay-safe; skips stock and credit-limit checks when `offline` is true |

There is no bulk-sync endpoint — the queue is drained one sale at a time through
the ordinary create endpoint, which is what makes the idempotency key sufficient.

## Permissions & gating

Identical to an online sale: `auth:sanctum` plus the `subscribed` gate. That is
precisely why 401 and 402 are classified as `halt` rather than `drop` — an expired
token or a lapsed subscription would otherwise consume the retry budget and throw
away real sales.

## Edge cases & known limits

- **The feature is not reachable.** No page loads `offline-db.js` or `sync.js`,
  `pos.js` never mints a `client_uuid`, and there is no service worker or app
  manifest — so the app does not load at all without a connection. Backlog
  milestone M33 ("Offline / PWA support") is the one that would finish this.
- **No offline queue means no offline UI**: there is no queued-sales badge, no
  "you are offline" banner, and no way to see or retry a parked sale.
- **Offline sales can push stock negative** by design. Nothing surfaces that as an
  alert; it shows up as a negative count on the products screen.
- **`PurchaseService::weightedAverageCost()` handles a negative count** by
  treating the new cost as the cost outright, which is the right call but does
  mean an overdrawn product loses its blended history on the next delivery.
- **`sold_at` is trusted within a 30-day window.** A till with a wrong clock will
  file sales on the wrong day, and the day book's business date is server-derived,
  so the two can disagree.
- **Offline replays skip the `is_active` check**, so a product taken off the till
  can still arrive on a queued sale.
- `is_offline` is surfaced in the sales list and in the sale payload, but no report
  distinguishes offline from online takings.
