# M7 — Subscription gating

**Phase:** 2  
**Goal:** Cash-flow and reports require an active base subscription. Tax calculator stays free.

## Deliverables

- `EnsureSubscribed` middleware on cash-flow and report API routes
- `402 Payment Required` or structured JSON error for frontend upsell
- Frontend redirects to billing when subscription inactive

## Tasks

See [M7 tasks](../tasks/M7-tasks.md).

## Exit criteria

- [x] Unsubscribed user gets blocked from cash-flow/reports APIs
- [x] Subscribed user has full access until `ends_at`
- [x] Tax calculator unaffected
