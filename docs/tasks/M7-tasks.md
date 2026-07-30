# M7 — Subscription gating tasks

Depends on M6 complete.

- [x] **M7-T1** `EnsureSubscribed` middleware — check active subscription on user
- [x] **M7-T2** Apply middleware to cash-entries and reports API route groups
- [x] **M7-T3** Return JSON `{ "message": "Subscription required", "code": "subscription_required" }` with HTTP 402
- [x] **M7-T4** `api.js` — on 402, redirect to `billing.html`
- [x] **M7-T5** Billing page shows current plan status and renewal date
- [x] **M7-T6** PHPUnit tests for middleware (active, expired, none)

## Completion log

### M7-T1 — done 2026-07-30

**Modified files**
- `backend/app/Http/Middleware/EnsureSubscribed.php` — checks `SubscriptionService::currentSubscription()`
- `backend/bootstrap/app.php` — registers `subscribed` middleware alias

**QA notes**
1. `php artisan test --filter=SubscriptionGateTest` — middleware tests pass.

### M7-T2 — done 2026-07-30

**Modified files**
- `backend/routes/api.php` — wraps cash-entries + reports in `middleware('subscribed')`

**QA notes**
1. Categories and billing routes remain outside the gate (logged-in users can browse plans).

### M7-T3 — done 2026-07-30

**Modified files**
- `backend/app/Http/Middleware/EnsureSubscribed.php` — 402 JSON with `subscription_required` code

**QA notes**
1. `GET /api/cash-entries` without subscription → 402 + exact message/code.

### M7-T4 — done 2026-07-30

**Modified files**
- `js/api.js` — redirects cashflow/reports pages to `billing.html` on 402
- `js/dashboard.js` — checks subscription first; shows upsell instead of redirect

**QA notes**
1. Log in without subscription → open `/cashflow.html` → lands on billing.
2. Dashboard stays put with “View plans” link.

### M7-T5 — done 2026-07-30

**Modified files**
- `js/billing.js` — active plan shows renewal date label

**QA notes**
1. After sandbox checkout, billing page shows “Renewal date: …”.

### M7-T6 — done 2026-07-30

**Modified files**
- `backend/tests/Feature/SubscriptionGateTest.php` — active, expired, none, categories public
- `backend/tests/Concerns/CreatesSubscribedUser.php` — test helper trait
- `backend/tests/Feature/CashEntryTest.php` — subscribe users in gated tests
- `backend/tests/Feature/ReportTest.php` — subscribe users in gated tests
- `e2e/helpers/qa-auth.js` — `registerSubscribedUser` for E2E
- `e2e/tests/m7-subscription-gate.spec.js` — 5 E2E tests
- `e2e/tests/m3-cashflow.spec.js`, `e2e/tests/m4-reports.spec.js` — use subscribed users
- `package.json` — `qa:m7` script

**QA notes**
1. `npm run qa:milestone -- M7` — 32 tests pass (regression M1–M7).
