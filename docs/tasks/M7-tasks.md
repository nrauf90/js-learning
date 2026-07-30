# M7 — Subscription gating tasks

Depends on M6 complete.

- [ ] **M7-T1** `EnsureSubscribed` middleware — check active subscription on user
- [ ] **M7-T2** Apply middleware to cash-entries and reports API route groups
- [ ] **M7-T3** Return JSON `{ "message": "Subscription required", "code": "subscription_required" }` with HTTP 402
- [ ] **M7-T4** `api.js` — on 402, redirect to `billing.html`
- [ ] **M7-T5** Billing page shows current plan status and renewal date
- [ ] **M7-T6** PHPUnit tests for middleware (active, expired, none)
