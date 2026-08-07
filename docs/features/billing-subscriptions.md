# Billing and subscriptions

## What it is

What the shop pays to use the app. A 7-day free trial from the moment the account
is created, then a monthly or yearly subscription.

**Shops do not buy their own subscription.** Checkout, the Paddle customer portal
and cancellation all sit behind the `admin` middleware: the platform operator
onboards a shop, grants it a subscription and renews it by hand. A shop that
reached checkout could pay for a subscription nobody asked it to buy. What a shop
still sees is whether it *has* access, how long is left, and — when the answer is
no — the account details to quote when asking the operator to renew.

Where money does change hands, it goes through **Paddle** as merchant of record,
in **USD**, because Paddle cannot charge PKR. Everything the shop itself deals in
stays PKR. See [units-and-money.md](./units-and-money.md).

There is also a receipt-scanner add-on, priced and purchasable but not built —
see [receipt-addon.md](./receipt-addon.md).

## How it works

### The trial

There is no trial record. `SubscriptionService::trialEndsAt()` is simply

```php
$user->created_at->copy()->addDays(config('billing.trial_days', 7))
```

`hasAccess()` is `currentSubscription() !== null || now() < trialEndsAt()`. That
runs on every gated request, so the trial branch is inlined rather than delegated
to `isOnTrial()`, which would re-run the identical subscription query.

`trialStatus()` returns `{ active, ends_at, days_remaining, expired }` from one
lookup, and drives the countdown banner on the dashboard and the billing page.

### The gate

`EnsureSubscribed` sits on the whole shop API — cash entries, products, sales,
purchases, customers, the day book, shop and staff, activity, uploads and reports.
It returns **402** with a `code` of `trial_expired` or `subscription_required`.

Crucially, **the subscription belongs to the shop, not to each person working the
till**. `EnsureSubscribed::payingAccount()` resolves a staff member to their shop
owner. A staff account has its own `created_at` and so its own 7-day trial clock,
which would otherwise lapse a week after hiring and lock a paid-up shop's cashier
out mid-shift. It falls back to the user when the owner cannot be resolved, so a
broken shop link degrades to per-account behaviour rather than locking the account
out entirely.

That method is deliberately `public static`, and `BillingController::subscription()`
calls it too: reporting per-account there told a paid shop's cashier their trial
had expired while the API served them normally.

Auth, profile, `GET /api/categories` and `GET /api/billing/subscription` are all
outside the gate — a lapsed shop must still be able to log in and find out who to
call.

### What a lapsed shop sees

A 402 used to bounce the browser to `billing.html` so the shop could buy its way
back in. With self-serve billing closed that redirect lands on a page which cannot
help them, so `js/api.js` now renders an in-page `alertdialog` instead
(`showSubscriptionLapsed()`): what happened, who fixes it, the account details to
quote, and a Log out button. It is shown once per page load and styled **inline**
rather than through `css/styles.css`, so the notice cannot be defeated by a page
that never loaded the stylesheet; theme variables are read with fallbacks for the
same reason.

The details come from `GET /api/billing/subscription`, whose `account` block
(`name`, `email`, `shop.{id, name, phone}`) exists precisely for this — the only
other way to identify the account is `GET /api/shop`, which sits behind the very
gate that just fired.

### Checkout (operator only)

`POST /api/billing/checkout` creates a `payments` row in `pending` with a locally
generated `txn_ref` (`CF-XXXXXXXXXXXX`), then asks Paddle for a transaction and
returns its hosted checkout URL. The browser navigates there.

The `custom_data` sent to Paddle carries `user_id`, `payment_id`, `txn_ref` and
`plan`, and is echoed back on every `transaction.*` and `subscription.*` webhook —
that is how the webhook finds our own pending row **without trusting anything the
browser sent back**.

The receipt add-on requires an active base subscription and 422s without one.

If no `price_id` is configured for the plan, checkout fails with a clear
"not available for purchase yet" message rather than a Paddle error.

Note that the payment and the resulting subscription are created against
**`$request->user()`** — the operator running the checkout — not against the shop
they meant to pay for. In practice a shop is activated with the hand-grant
endpoint below instead.

### Granting access by hand

`POST /api/admin/subscriptions` is how a shop actually gets access now. It takes
`user_id` **or** `email`, a `plan` (`monthly` | `yearly`) and an optional
`ends_at`, and either extends the shop's existing subscription or creates one.

Two details worth knowing:

- It extends from **whatever the shop still has left**, not from today, so
  renewing a few days early never throws away days already granted.
- An explicit `ends_at` is taken as `endOfDay()`. Taking the bare date would cut
  the shop off at midnight — a day earlier than the operator picked, and the sort
  of thing nobody notices until the till locks.

A hand-granted row is `provider: 'manual'` with **no `external_id`**, and that
absence is what keeps the Paddle portal and cancel controls off it.

`subscriptionUpdate()` can only edit a row that already exists, which is why the
store endpoint had to be added: a shop onboarded through `shopAdminStore()` has
none, and was stuck on the trial with no way off it.

### Webhooks are the only source of truth

`POST /api/billing/paddle/webhook` is the **only** thing that moves subscription
state. The browser's post-checkout redirect back to `billing.html?status=…` is
cosmetic, and the page says "your subscription will appear shortly" rather than
claiming it is already live.

The route is unauthenticated by necessity — the `Paddle-Signature` HMAC over the
raw body *is* the authentication — and throttled at 120/minute so a flood of
unsigned junk cannot pin the app. Paddle's own delivery rate stays well under
that and it retries anything it gets a 429 for.

Verification (`PaddleGateway::verifyWebhook`) parses `ts=<unix>;h1=<hex>`, rejects
a timestamp outside `PADDLE_WEBHOOK_TOLERANCE` (default 300 s) to blunt replays,
and compares `hash_hmac('sha256', "$ts:$rawBody", $secret)` with `hash_equals`. It
**fails closed** when the secret is unset — the whole point of the check is that
an unsigned request can otherwise activate a paid subscription for free. The raw
bytes are used, because anything that round-trips through
`json_decode`/`encode` will not verify.

Deduplication is a `webhook_events` table with a unique `(provider, event_id)`
index. The insert *claims* the event: two concurrent deliveries race there and
exactly one wins. If the handler then throws, the claim row is **deleted** so
Paddle's retry gets a real second attempt instead of being deduped against a row
that was never processed.

Handled events:

| event | effect |
|---|---|
| `subscription.*` (any) | one sync path — every subscription event carries the full entity, so created/updated/activated/trialing/past_due/paused/resumed/canceled are all covered, including types Paddle adds later |
| `transaction.completed` | mark the payment completed; create the row first if this is a renewal Paddle raised itself |
| `transaction.payment_failed` | mark the payment failed |
| `adjustment.created` / `.updated` | record a refund amount against the payment |

`syncSubscription()` **sets** `ends_at` rather than extending it: the provider owns
the billing period, and adding an interval on every renewal webhook would push the
end date further out on each retry. It upserts on `(provider, external_id)` so a
redelivered webhook updates the same row, and it back-fills `subscription_id` on
any payments that arrived before we knew the subscription id.

An unmapped `price_id` grants the base monthly plan and logs a warning, rather
than dropping a paid subscription on the floor.

### Access rules

`Subscription::ACTIVE_STATUSES` is `active`, `trialing`, `past_due`, and
`isActive()` additionally requires `ends_at` in the future.

`past_due` is included **on purpose**: Paddle retries a failed renewal over
several days, and cutting access on the first failure punishes people for an
expired card. Access still ends when `ends_at` passes.

`canceled` is excluded — Paddle keeps a cancelling subscription `active` with a
`scheduled_change` until the period actually ends, so by the time the status flips
the customer is genuinely done. `cancel_at_period_end` is derived from
`scheduled_change.action === 'cancel'` and drives the "Cancels on …" line on the
billing page.

### Portal and cancel

`POST /api/billing/portal` mints a short-lived, **single-use** link into Paddle's
hosted customer portal, where the customer updates their card, downloads invoices
and cancels. It is minted per request because Paddle forbids caching these URLs.
404 if the user has no Paddle customer yet.

`POST /api/billing/cancel` tells Paddle to cancel at period end and touches **no**
local state — the resulting `subscription.updated` webhook does that. It 422s for
a subscription with no `external_id` (a locally activated one), which is the
honest answer: there is nothing at Paddle to cancel.

### Sandbox mode

`config('billing.sandbox')` must be turned on explicitly (`BILLING_SANDBOX=true`).
When on, checkout skips Paddle entirely and returns `checkout: { sandbox: true }`;
the frontend then calls `POST /api/billing/sandbox/complete/{payment}`, which marks
the payment complete and activates a `provider: 'manual'` subscription locally.
That is what lets local development and the Playwright harness reach subscribed
state without live credentials.

It is off by default and 404s when off, because a publicly reachable instance with
it on lets anyone grant themselves a subscription. Locally activated subscriptions
have no `external_id`, so the billing page hides the portal and cancel buttons
(`managed: false`).

`POST /api/qa/expire-trial` backdates the account's `created_at` past the trial
window and is registered **only** in `local` and `testing`.

## Screens / files

| Layer | File |
|---|---|
| Page | `billing.html` |
| Controller | `js/billing.js` |
| API | `backend/app/Http/Controllers/Api/BillingController.php`, `PaddleWebhookController.php`, `QaController.php` |
| Services | `backend/app/Services/Billing/SubscriptionService.php`, `PaddleGateway.php`, `PaddleClient.php` |
| Middleware | `backend/app/Http/Middleware/EnsureSubscribed.php` |
| Models | `Subscription`, `SubscriptionAddon`, `Payment`, `WebhookEvent`, `User` |
| Config | `backend/config/billing.php` |
| Migrations | `2026_07_30_190000_create_subscriptions_table.php`, `..._190001_create_payments_table.php`, `..._200000_create_subscription_addons_table.php`, `2026_08_06_100000..100003_*` |
| Tests | `backend/tests/Feature/BillingTest.php`, `SubscriptionGateTest.php`, `FreeTrialTest.php`, `ReceiptAddonTest.php` |
| E2E | `e2e/tests/m6-billing.spec.js`, `m7-subscription-gate.spec.js`, `m9-free-trial.spec.js` |

## API endpoints

| Method | Path | Auth | What it does |
|---|---|---|---|
| GET | `/api/billing/plans` | no | Plan list + currency + provider label |
| POST | `/api/billing/paddle/webhook` | signature | The only path that moves *provider* subscription state |
| GET | `/api/billing/subscription` | bearer | Subscription, trial status, add-on states and the `account` block |
| POST | `/api/billing/checkout` | bearer + **admin** | `plan` = `monthly` \| `yearly` \| `receipt_addon` |
| POST | `/api/billing/portal` | bearer + **admin** | Single-use Paddle portal URL |
| POST | `/api/billing/cancel` | bearer + **admin** | Cancel at period end |
| POST | `/api/billing/sandbox/complete/{payment}` | bearer + **admin** | Sandbox only; 404 otherwise |
| POST | `/api/admin/subscriptions` | bearer + **admin** | Grant or extend a shop's subscription by hand |
| PUT | `/api/admin/subscriptions/{subscription}` | bearer + **admin** | Edit `status` / `ends_at` |
| POST | `/api/qa/expire-trial` | bearer | local/testing only |

Default prices (display only — Paddle is the authority for what is actually
charged): monthly $5, yearly $54, receipt add-on $5/month. Keeping these in step
with the prices behind the Paddle price IDs is a manual job; a mismatch means the
UI quotes a number the checkout does not honour.

## Permissions & gating

- Everything except `/plans` and the webhook needs a bearer token.
- `GET /api/billing/subscription` is the **only** billing endpoint a shop still
  reaches. Checkout, portal, cancel and the sandbox completion are all behind
  `admin`.
- None of it is behind `EnsureSubscribed` — a lapsed account must still be able to
  find out that it is lapsed.
- `billing.html` additionally checks `is_admin` in the browser and redirects a
  shop owner or staff member to `dashboard.html`. The server-side `admin`
  middleware is the real block; that check only spares them a purchase flow the
  API would refuse. Billing has also been removed from both the sidebar
  (`js/shell.js`) and the public nav (`js/nav.js`), so no link leads there at all.
- `payments.payload` and `webhook_events.payload` are both in `$hidden`: the raw
  gateway payload is internal only and must never reach the browser or the admin
  API, because it can contain gateway-internal fields.
- `paddle_customer_id` is written only through `User::setPaddleCustomerId()`.

## Edge cases & known limits

- **JazzCash and EasyPaisa are gone.** Both gateway classes, the resolver, the
  `PaymentGateway` contract, the IPN/return routes and the server-rendered
  redirect page were deleted when Paddle landed. `payments.provider` may still
  read `jazzcash`/`easypaisa` on historical rows, and `subscriptions.provider`
  defaults to `manual` for rows that predate the switch — those have no external
  counterpart to sync against.
- **`payments.currency` still defaults to `PKR` at the database level**, though
  everything written since the switch sets it from `config('billing.currency')`.
- **A subscription is per user, not per shop.** The gate resolves staff to their
  owner, but the subscription row itself hangs off the owner's `user_id`; there is
  no seat count and no per-shop billing entity.
- **The Paddle checkout path is now largely vestigial.** It bills whoever is
  logged in — the operator — so activating a customer through it would put the
  subscription on the wrong account. In practice `POST /api/admin/subscriptions`
  is the working path, and it never touches Paddle at all.
- **Hand-granted subscriptions never renew themselves.** There is no reminder,
  no expiry warning to the operator, and no job that sweeps lapsing shops — the
  shop finds out when the till locks and the lapsed notice appears.
- **The billing page still renders a plan picker and a Subscribe button**, which
  only a platform admin can reach — and no navigation links to it, so even they
  have to type the URL.
- **The add-on is sold but not delivered.** `receipt_addon` can be purchased and
  reports as active; the endpoint behind it returns 501.
- **No proration, no plan switching** in the app — a customer changing plans goes
  through the Paddle portal, and whatever comes back on the webhook is what the
  local row becomes.
- **No dunning emails or in-app warnings** beyond the `past_due` note on the
  billing page.
- **`activateLocally()` extends additively** (unlike the webhook path), because it
  has no provider period to copy. That is sandbox-only behaviour.
