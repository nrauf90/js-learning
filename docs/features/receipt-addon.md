# Receipt scanner add-on

## What it is

A paid extra: photograph a supplier's bill or a utility receipt and have the
expense filled in for you. It is **sold but not built** — the billing, the
entitlement and the upsell are all real; the endpoint behind it returns
`501 Coming soon`.

Not to be confused with [receipts-printing.md](./receipts-printing.md), which is
the thermal slip the till prints and which is fully working.

## How it works

### What exists

**Billing.** `receipt_addon` is a plan in `config/billing.php` — $5/month by
default, `requires_base: true`, with its own `PADDLE_PRICE_RECEIPT_ADDON` price
id. `POST /api/billing/checkout` accepts it and **422s** if the user has no active
base subscription: an add-on with nothing to add on to is not a purchase.

**Entitlement.** Add-ons live in their own table, `subscription_addons`
(`user_id`, `provider`, `external_id`, `addon_key`, `status`, `ends_at`,
`cancel_at_period_end`), separate from `subscriptions`. `SubscriptionService`
routes a `receipt_addon` plan to `syncAddon()` on the webhook path and to
`activateLocalAddon()` in sandbox mode, and `addonStatuses()` reports

```json
{ "receipt": { "active": false, "ends_at": null } }
```

on `GET /api/billing/subscription`. `isActive()` follows the same rule as a
subscription: an `ACTIVE_STATUSES` status **and** `ends_at` in the future.

**The upsell.** `cashflow.html` shows a "scan a receipt" pitch when the entry type
is *expense* and the add-on is not active, and an "active" panel instead once it
is. Both are hidden for income entries. It carries **no link to a checkout** —
add-ons are switched on by the platform admin, so pointing a shop at a checkout it
cannot use would be a dead end dressed up as an action; the pitch says to contact
the administrator instead. `billing.html` (admin-only) shows an add-on card whose
badge reads "Coming soon" until the add-on is active.

**Storage.** `cash_entries.receipt_path` exists on the table and is returned in the
entry payload. Nothing ever writes it.

### What does not exist

`ReceiptController::upload()` is four lines:

```php
return response()->json(['message' => 'Coming soon'], 501);
```

There is no file handling, no OCR, no parsing, no link back to a cash entry, and
no UI that posts to it.

## Screens / files

| Layer | File |
|---|---|
| Upsell | `cashflow.html` `#receipt-upsell` / `#receipt-active`; `js/cashflow.js` — `renderReceiptUpsell()`, `loadAddonStatus()` |
| Billing card | `billing.html` `#receipt-addon-card`; `js/billing.js` — `renderAddonCard()` |
| API stub | `backend/app/Http/Controllers/Api/ReceiptController.php` |
| Entitlement | `backend/app/Services/Billing/SubscriptionService.php` — `syncAddon()`, `activateLocalAddon()`, `currentAddon()`, `addonStatuses()` |
| Model | `backend/app/Models/SubscriptionAddon.php` |
| Migrations | `2026_07_30_200000_create_subscription_addons_table.php`, `2026_08_06_100001_add_provider_fields_to_subscriptions_table.php` (adds `provider`, `external_id`, `cancel_at_period_end` to add-ons) |
| Tests | `backend/tests/Feature/ReceiptAddonTest.php` |
| E2E | `e2e/tests/m8-receipt-addon.spec.js` |

## API endpoints

| Method | Path | What it does |
|---|---|---|
| POST | `/api/receipts/upload` | Returns `501 { "message": "Coming soon" }` |
| GET | `/api/billing/subscription` | Includes `addons.receipt.{active, ends_at}` |
| POST | `/api/billing/checkout` | **Admin only.** Accepts `plan: "receipt_addon"`; 422 without a base subscription |

## Permissions & gating

- `POST /api/receipts/upload` is behind `auth:sanctum` **and** the `subscribed`
  gate, so it 402s before it even gets to say 501 for an unsubscribed account.
- Nothing checks the add-on entitlement itself — there is no
  `EnsureAddonActive` middleware, because there is nothing yet to protect.

## Edge cases & known limits

- **The whole feature is a scaffold.** A customer who buys the add-on gets an
  active entitlement flag and a changed badge, and nothing else.
- **`cash_entries.receipt_path` is dead weight** until an upload path exists.
- **No add-on cancel path.** `POST /api/billing/cancel` only looks at
  `currentSubscription()`, which filters to `monthly`/`yearly` — an add-on can only
  be cancelled through the Paddle portal.
- **The billing page shows the add-on card but has no buy button for it**;
  `renderPlans()` filters to `monthly` and `yearly`, so the only way to reach
  `plan: "receipt_addon"` is by calling the checkout endpoint directly — and that
  endpoint is now admin-only, so a shop cannot buy the add-on at all.
- **There is no hand-grant path for add-ons.** `POST /api/admin/subscriptions`
  only accepts `monthly` or `yearly`, so with self-serve checkout closed there is
  no working way to switch the add-on on for a shop.
- Backlog milestone M23 in `docs/README.md` ("Finish the receipt-upload add-on")
  is the one that would build this; it is not started. Note that the M23 slot was
  also used for the POS + Paddle work, so the two share a number.
