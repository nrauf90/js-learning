# M9 — 7-day free trial tasks

Depends on M7 complete.

- [x] **M9-T1** `config/billing.php` — `trial_days` => 7
- [x] **M9-T2** `SubscriptionService` — trial ends_at, `hasAccess()`, `trialStatus()`
- [x] **M9-T3** Update `EnsureSubscribed` middleware to allow active trial
- [x] **M9-T4** Expose trial in `GET /api/billing/subscription` JSON
- [x] **M9-T5** Dashboard + billing UI — trial banner / days remaining
- [x] **M9-T6** PHPUnit + E2E tests; update M7 regression for expired trial

## Completion log

### M9-T1 — done 2026-07-30

**Modified files**
- `backend/config/billing.php` — `trial_days` (env `BILLING_TRIAL_DAYS`, default 7)

**QA notes**
1. Override with `BILLING_TRIAL_DAYS=7` in `.env` if needed.

### M9-T2 — done 2026-07-30

**Modified files**
- `backend/app/Services/Billing/SubscriptionService.php` — `trialEndsAt()`, `isOnTrial()`, `hasAccess()`, `trialStatus()`

**QA notes**
1. Trial starts at user `created_at`, ends after 7 days.

### M9-T3 — done 2026-07-30

**Modified files**
- `backend/app/Http/Middleware/EnsureSubscribed.php` — uses `hasAccess()`; returns `trial_expired` code when trial ended

**QA notes**
1. New users pass middleware without subscription during trial.

### M9-T4 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/BillingController.php` — `trial` object on subscription response

**QA notes**
1. `GET /api/billing/subscription` → `{ trial: { active, ends_at, days_remaining, expired } }`.

### M9-T5 — done 2026-07-30

**Modified files**
- `js/dashboard.js` — trial banner + full access during trial
- `js/billing.js` — free trial / trial ended messaging
- `js/api.js` — redirect on `trial_expired`
- `billing.html` — 7-day trial note on plan section

**QA notes**
1. Dashboard shows “Free trial: N days remaining” for trial users.

### M9-T6 — done 2026-07-30

**Modified files**
- `backend/tests/Feature/FreeTrialTest.php` — 5 PHPUnit tests
- `backend/app/Http/Controllers/Api/QaController.php` — `POST /api/qa/expire-trial` (local/testing E2E)
- `e2e/tests/m9-free-trial.spec.js` — 5 E2E tests
- `e2e/tests/m7-subscription-gate.spec.js` — updated for expired trial
- `e2e/helpers/qa-auth.js` — `expireTrial()` helper
- `e2e/playwright.config.js`, `scripts/qa-milestone.mjs`, `package.json` — M9 in harness
- `backend/README.md` — `BILLING_TRIAL_DAYS` documented

**QA notes**
1. `npm run qa:milestone -- M9` — 40 tests pass.
