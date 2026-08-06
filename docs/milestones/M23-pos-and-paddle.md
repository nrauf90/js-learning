# M23 — Point of sale, Paddle billing, tax calculator removal

**Status:** code complete, backend suite **not yet run** (see Blockers)
**Date:** 2026-08-06

Three connected changes that move the product from "free tax calculator + expense tracker"
to "point of sale + cash flow, sold as a subscription internationally".

---

## 1. Billing: JazzCash + EasyPaisa → Paddle

### Why

The local wallets tie the product to PKR and to buyers who have JazzCash/EasyPaisa accounts.
Paddle is a merchant of record: it handles global cards, sales tax/VAT registration and
remittance, and pays out to Pakistan via Payoneer. Lemon Squeezy was the other candidate and
was rejected — Stripe is folding it into Stripe Managed Payments, and its payout options for
Pakistan come down to bank wire (PayPal is unavailable to individuals there).

### Removed

| File | Note |
|------|------|
| `app/Services/Billing/JazzCashGateway.php` | 119 lines |
| `app/Services/Billing/EasyPaisaGateway.php` | 106 lines |
| `app/Services/Billing/GatewayResolver.php` | single provider now |
| `app/Contracts/PaymentGateway.php` | replaced by `BillingProvider` |
| `app/Http/Controllers/Api/BillingCallbackController.php` | 4 IPN/return actions |
| `resources/views/billing/gateway-redirect.blade.php` | the M12 Blade exception, no longer needed |
| 5 routes | 4 IPN/return + the signed `gateway-redirect` |
| `.billing-providers` / `.billing-provider` CSS + the provider picker in `js/billing.js` | |

### Added

- **`app/Contracts/BillingProvider.php`** — hosted-checkout shape. Two deliberate differences
  from the old contract: checkout returns a **URL** rather than a form of merchant credentials,
  and `verifyWebhook()` takes the **raw body string**, because Paddle signs the exact bytes it
  sent and any parse/re-encode round trip breaks the HMAC.
- **`PaddleClient`** — customers, transactions, portal sessions, cancel. Not retried: these are
  non-idempotent creates and Paddle has no idempotency key on them.
- **`PaddleGateway`** — `Paddle-Signature` verification (`ts=…;h1=…`, HMAC-SHA256 over
  `ts:body`), with a timestamp tolerance and **fail-closed** behaviour when unconfigured.
- **`PaddleWebhookController`** — one endpoint. Claims each event through a unique index on
  `(provider, event_id)` before handling it, and **releases the claim if handling throws**, so
  Paddle's retry gets a real second attempt instead of being deduped against a row that was
  never processed. All `subscription.*` events route through one sync path.
- **`BillingProviderException`** — renders 503 with a generic message; provider error bodies
  are logged server-side because they name the seller account.
- Endpoints: `POST /api/billing/portal` (single-use hosted portal link), `POST /api/billing/cancel`
  (cancel at period end).

### Schema

| Migration | Adds |
|-----------|------|
| `…_add_paddle_customer_to_users_table` | `paddle_customer_id` (unique, **not** fillable) |
| `…_add_provider_fields_to_subscriptions_table` | `provider`, `external_id`, `external_price_id`, `renews_at`, `trial_ends_at`, `canceled_at`, `cancel_at_period_end`, `paused_at` |
| `…_add_provider_fields_to_payments_table` | `external_transaction_id`, `external_subscription_id`, `invoice_number`, `refunded_amount` |
| `…_create_webhook_events_table` | event dedup log |

### Behaviour changes worth knowing

- **Subscription periods are set, not extended.** `SubscriptionService::activateSubscription()`
  used to add an interval per completed payment. Under a provider that owns the billing period,
  that double-counts on every renewal webhook, so the sync path now writes
  `ends_at` from `current_billing_period.ends_at`. The old additive path survives as
  `activateLocally()`, reachable only in local test mode.
- **`past_due` keeps access.** Paddle retries a failed renewal over several days; cutting access
  on the first failure punishes an expired card. Access still ends at `ends_at`.
- **Statuses are now Paddle's**, including the single-l `canceled`. `AdminController`'s status
  validation previously accepted `cancelled`, which no code path ever wrote.
- **Currency is USD.** Paddle does not support PKR. Defaults are `$5/mo` and `$54/yr`
  (env-overridable) — see the note under Follow-ups.
- **Local test mode** (`BILLING_SANDBOX=true`) now means "skip Paddle entirely"; the old
  sandbox-complete endpoint survives as `completeTestPayment` so the Playwright harness needs
  no live credentials. `phpunit.xml` and `scripts/qa-milestone.mjs` now set the flag explicitly —
  without it, checkout in a credential-less environment previously died with a `TypeError`.

### Dashboard setup required before this works

1. Create the products/prices, set `PADDLE_PRICE_MONTHLY` / `_YEARLY` / `_RECEIPT_ADDON`.
2. Set a **default payment link** under Checkout → Checkout settings. Without it Paddle returns
   a transaction with no `checkout.url` and checkout fails with a clear 503.
3. Set the default success URL to `<FRONTEND_URL>/billing.html?status=success`.
4. Create a notification destination pointing at `POST /api/billing/paddle/webhook`, subscribe
   it to `transaction.completed`, `transaction.payment_failed`, `subscription.*` and
   `adjustment.created`, and copy its `pdl_ntfset_…` secret into `PADDLE_WEBHOOK_SECRET`.
5. The API key needs `customer_portal_session.write` or portal URLs come back empty.

---

## 2. Tax calculator removed

Deleted `calculator.html`, `js/app.js`, `js/tax-calculator.js`, `js/tax-slabs.js`,
`js/deductions.js`, `js/landing.js`, `js/number-format.js`, `js/share-export.js`, their four
unit-test files, and `e2e/tests/m5-tax.spec.js`. `index.html` was rewritten around the POS
pitch and now loads a new `js/home.js` (chrome only). `js/nav.js` and `js/shell.js` lost the
calculator link and gained Sell/Products. M5 is out of the QA harness rotation.

Two deliberate leftovers: `js/theme.js` still uses the `tax-calculator-theme` localStorage key,
and `e2e/tests/m13-theme.spec.js` asserts on it. Renaming would silently reset every existing
user's theme preference for no benefit.

`package.json`'s `lint` script — which hand-listed all 25 JS modules and had to be edited on
every add/remove — was replaced by `scripts/lint-js.mjs`, which walks `js/`.

---

## 3. Point of sale

### Schema

| Table | Purpose |
|-------|---------|
| `product_categories` | per-seller (unlike the shared, system-seeded `expense_categories`) |
| `products` | price, cost, optional SKU/barcode (unique **per user**), `track_stock`, `stock_quantity`, `low_stock_threshold` |
| `sales` | `reference`, totals, payment method, tendered/change, status, `cash_entry_id` |
| `sale_items` | name/price **snapshotted**, so receipts survive a rename, reprice or delete |
| `stock_movements` | signed delta + denormalised `balance_after` (`initial`/`sale`/`refund`/`restock`/`adjustment`) |

### `SaleService`

Everything in one transaction with the products `lockForUpdate()`. Two cashiers selling the
last unit simultaneously is the normal failure mode for a POS; without the lock both reads see
stock of 1 and both writes succeed. Duplicate lines are **merged before** the stock check, so
three scans of the same barcode check once against a quantity of 3.

Each sale posts an income `cash_entry` under the `sales` category — that is the whole
integration with the existing app; dashboard, reports and exports needed no changes. A refund
restores stock and **deletes** that entry rather than offsetting it, because `cash_entries.amount`
is a positive-only column everywhere else. A fully discounted sale records no entry at all.

`reference` is derived from the row id (`S-000123`) immediately after insert — unique and ordered
with no per-user counter to race on.

### API

`GET/POST /api/products`, `GET /api/products/lookup?code=` (exact barcode/SKU — one round trip
for a scanner), `GET/PUT/DELETE /api/products/{id}`, `POST /api/products/{id}/stock`,
`GET/POST/PUT/DELETE /api/product-categories`, `GET/POST /api/sales`,
`GET /api/sales/today`, `GET /api/sales/{id}`, `POST /api/sales/{id}/refund`.
All behind `auth:sanctum` + `subscribed`.

### UI

- **`pos.html` / `js/pos.js`** — search-or-scan (Enter does an exact lookup and drops the item
  straight on the ticket), tappable product grid, ticket with quantity steppers, discount, cash
  tendered with live change, receipt panel with print.
- **`products.html` / `js/products.js`** — catalog CRUD, low-stock filter, stock adjustment,
  categories.
- **`js/cart.js`** — ticket state and money maths, deliberately free of DOM and network so the
  arithmetic that decides what a customer is charged is unit-testable.

---

## Tests

| Suite | State |
|-------|-------|
| `tests/cart.test.js` | **22 passing** — totals, discount clamping, change, stock limits, line merging |
| `npm run lint` | **22 modules OK** |
| `backend/tests/Feature/PosTest.php` | 24 tests written, **not run** |
| `backend/tests/Feature/BillingTest.php` | rewritten to 20 tests, **not run** |
| `e2e/tests/m23-pos.spec.js` | 6 specs written, **not run** |

One real bug was caught by the new unit tests: `money()` originally used `toFixed(2)`, which
rounds 1.005 **down** because the float is really 1.00499…, under-charging a paisa on every such
line. Now epsilon-nudged half-up rounding.

---

## Blockers

**The backend suite has never been executed.** `backend/vendor/` does not exist and
`composer install` fails: this PHP 8.3 build has the `openssl` extension disabled, which Composer
needs for TLS. To finish:

```bash
# enable extension=openssl in C:\Program Files\PHP\current\php.ini
cd backend
composer install
php artisan migrate
php artisan db:seed --class=ExpenseCategorySeeder   # adds the "Sales" income category
php artisan test
```

`SaleService` creates the `sales` category via `firstOrCreate` as a backstop, so an unseeded
database still sells — the seeder just keeps the name/icon consistent.

---

## Follow-ups

- **Pricing is probably wrong.** PKR 500/mo ≈ $1.80, and Paddle takes 5% + $0.50 — a 33% cut.
  The $5 default still gives up 15%. $9+ is the realistic floor.
- **No sales tax on POS sales.** Deliberately out of scope; adding it means a `tax_amount`
  column on `sales` and per-product rates.
- **Refunds are whole-sale only** — no partial/line-level refunds.
- **`adjustment.created`** is handled for refunds; other adjustment actions are ignored.
- **Admin panel has no POS views** — no products or sales screens under `admin*.html`.
- **No offline mode.** A till that stops working when the connection drops is a real limitation
  for the target market; worth its own milestone.
