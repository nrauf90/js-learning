# M6 — Billing tasks

Depends on M2 complete; full E2E needs JazzCash/EasyPaisa sandbox keys.

- [ ] **M6-T1** `config/billing.php` — monthly 500, yearly 5400, addon 500
- [ ] **M6-T2** Migration: `subscriptions` table
- [ ] **M6-T3** Migration: `payments` table
- [ ] **M6-T4** `PaymentGateway` interface + `JazzCashGateway` class
- [ ] **M6-T5** `EasyPaisaGateway` class
- [ ] **M6-T6** `GET /api/billing/plans` — list plans with prices
- [ ] **M6-T7** `GET /api/billing/subscription` — current user status
- [ ] **M6-T8** `POST /api/billing/checkout` — plan + provider → payment initiation payload
- [ ] **M6-T9** JazzCash return + IPN routes — verify hash, activate subscription
- [ ] **M6-T10** EasyPaisa return + IPN routes — same
- [ ] **M6-T11** Frontend `billing.html` + `js/billing.js`
- [ ] **M6-T12** Document required `.env` keys for both gateways in `backend/README.md`
