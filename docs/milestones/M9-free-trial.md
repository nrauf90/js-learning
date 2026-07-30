# M9 — 7-day free trial

**Phase:** 2  
**Goal:** New users get **7 days** of full cash-flow and reports access after signup. When the trial ends, prompt them to subscribe (same gating as M7).

## Deliverables

- `billing.trial_days` config (default 7)
- Trial-aware access check in `EnsureSubscribed` middleware
- Trial status in `GET /api/billing/subscription`
- Dashboard / billing UI showing days remaining
- E2E + PHPUnit coverage

## Tasks

See [M9 tasks](../tasks/M9-tasks.md).

## Exit criteria

- [x] New user can use cash flow & reports without paying during trial
- [x] User past 7 days without subscription gets 402 / billing redirect
- [x] Tax calculator stays free
- [x] Trial countdown visible in UI
