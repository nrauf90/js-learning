# M30 — Multi-currency support

**Phase:** 7 (P3 polish) · **Depends on:** M29

**Goal:** Support entries in a currency other than PKR, for users who
earn/travel abroad, while keeping PKR as the default.

## Scope

- `currency` field on `cash_entries` (default `PKR`); user default
  currency preference.
- FX conversion for display/report totals (static/admin-managed rate
  table to start; no live FX API dependency required).
- Frontend: currency selector on the entry form; converted totals shown
  alongside original-currency amounts.

## Tasks

See [M30 tasks](../tasks/M30-tasks.md).

## Exit criteria

- [ ] Entries can be recorded in a non-PKR currency
- [ ] Reports/dashboard totals convert to the user's default currency for aggregation
- [ ] Existing PKR-only users see no behavior change
- [ ] PHPUnit + E2E coverage
