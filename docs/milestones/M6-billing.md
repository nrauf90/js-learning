# M6 — Subscriptions: JazzCash & EasyPaisa

**Phase:** 2  
**Goal:** Users subscribe at PKR 500/month or PKR 5,400/year (10% off) via JazzCash or EasyPaisa.

## Deliverables

- `config/billing.php` pricing constants
- `subscriptions`, `payments` tables
- `PaymentGateway` interface + JazzCash + EasyPaisa implementations
- Checkout API + IPN/return callback handlers
- `billing.html` + `js/billing.js`

## Tasks

See [M6 tasks](../tasks/M6-tasks.md).

## Exit criteria

- [x] User can initiate checkout for monthly or yearly plan
- [x] Successful callback activates subscription with correct `ends_at`
- [x] Payment records stored with provider reference and payload
