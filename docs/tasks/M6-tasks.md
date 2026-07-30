# M6 — Billing tasks

Depends on M2 complete; full E2E needs JazzCash/EasyPaisa sandbox keys (local sandbox works without keys).

- [x] **M6-T1** `config/billing.php` — monthly 500, yearly 5400, addon 500
- [x] **M6-T2** Migration: `subscriptions` table
- [x] **M6-T3** Migration: `payments` table
- [x] **M6-T4** `PaymentGateway` interface + `JazzCashGateway` class
- [x] **M6-T5** `EasyPaisaGateway` class
- [x] **M6-T6** `GET /api/billing/plans` — list plans with prices
- [x] **M6-T7** `GET /api/billing/subscription` — current user status
- [x] **M6-T8** `POST /api/billing/checkout` — plan + provider → payment initiation payload
- [x] **M6-T9** JazzCash return + IPN routes — verify hash, activate subscription
- [x] **M6-T10** EasyPaisa return + IPN routes — same
- [x] **M6-T11** Frontend `billing.html` + `js/billing.js`
- [x] **M6-T12** Document required `.env` keys for both gateways in `backend/README.md`

## Completion log

### M6-T1 — done 2026-07-30

**Modified files**
- `backend/config/billing.php` — plan amounts (500/5400/500), gateway settings, sandbox flag

**QA notes**
1. `GET http://127.0.0.1:8000/api/billing/plans` — monthly `500`, yearly `5400` in JSON.

### M6-T2 — done 2026-07-30

**Modified files**
- `backend/database/migrations/2026_07_30_190000_create_subscriptions_table.php` — user subscriptions with plan, status, ends_at

**QA notes**
1. `cd backend && php artisan migrate` — subscriptions table created.

### M6-T3 — done 2026-07-30

**Modified files**
- `backend/database/migrations/2026_07_30_190001_create_payments_table.php` — payment records with provider, reference, payload

**QA notes**
1. Migrate creates payments table linked to users/subscriptions.

### M6-T4 — done 2026-07-30

**Modified files**
- `backend/app/Contracts/PaymentGateway.php` — gateway interface
- `backend/app/Services/Billing/JazzCashGateway.php` — checkout form + HMAC hash verify
- `backend/app/Services/Billing/GatewayResolver.php` — resolves provider implementation

**QA notes**
1. `php artisan test --filter=BillingTest` — JazzCash IPN test passes with valid sandbox payload.

### M6-T5 — done 2026-07-30

**Modified files**
- `backend/app/Services/Billing/EasyPaisaGateway.php` — checkout form + hash verify

**QA notes**
1. Checkout accepts `provider: easypaisa`; callback routes registered in `routes/api.php`.

### M6-T6 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/BillingController.php` — `plans()` action
- `backend/routes/api.php` — public `GET /api/billing/plans`

**QA notes**
1. E2E `m6-billing.spec.js` — "plans endpoint is public" passes without auth.

### M6-T7 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/BillingController.php` — `subscription()` action
- `backend/app/Models/User.php` — subscriptions relation

**QA notes**
1. After sandbox complete, `GET /api/billing/subscription` returns active monthly plan.

### M6-T8 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/BillingController.php` — `checkout()` + `sandboxComplete()`
- `backend/app/Services/Billing/SubscriptionService.php` — payment creation + activation

**QA notes**
1. `POST /api/billing/checkout` with Bearer token returns sandbox checkout URL when `BILLING_SANDBOX=true`.
2. `php artisan test --filter=BillingTest` — 7 tests pass.

### M6-T9 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/BillingCallbackController.php` — JazzCash return + IPN
- `backend/routes/api.php` — JazzCash callback routes

**QA notes**
1. BillingTest "jazzcash ipn activates pending payment" passes.
2. Return redirects to `{FRONTEND_URL}/billing.html?status=…`.

### M6-T10 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/BillingCallbackController.php` — EasyPaisa return + IPN
- `backend/routes/api.php` — EasyPaisa callback routes

**QA notes**
1. Routes registered; same activation flow as JazzCash via `SubscriptionService`.

### M6-T11 — done 2026-07-30

**Modified files**
- `billing.html` — plan picker, provider picker, subscription status
- `js/billing.js` — loads plans, checkout, sandbox complete in UI
- `js/nav.js` — Billing link for logged-in users
- `css/styles.css` — billing page styles
- `e2e/tests/m6-billing.spec.js` — billing E2E tests
- `package.json` — `qa:m6`, lint includes `billing.js`

**QA notes**
1. Log in → `/billing.html` → checkout → "Active" subscription shown.
2. Guest redirected to login.

### M6-T12 — done 2026-07-30

**Modified files**
- `backend/README.md` — billing API table + `.env` keys for JazzCash/EasyPaisa/sandbox

**QA notes**
1. Read `backend/README.md` billing section for required env vars before going live.
