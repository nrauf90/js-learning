# M16 — Cash-flow search, filters & date-range history

**Phase:** 5 (P1 features) · **Depends on:** M15

**Goal:** Replace the single-day-only entry view with a real history list:
search, filter by type/category, and a date range, with pagination.

## Scope

- Backend: extend `CashEntryController::index` to accept `from`/`to`,
  `type`, `category_id`, and `q` (note search) query params, paginated.
- Frontend: a multi-day history view on `cashflow.html` (beyond "today"),
  with filter/search controls and a date-range picker.

## Tasks

See [M16 tasks](../tasks/M16-tasks.md).

## Exit criteria

- [ ] API supports date-range + type + category + text filters, paginated
- [ ] UI lets a user browse/search/filter past entries, not just today
- [ ] Existing single-day flows (add/edit/delete for today) keep working
- [ ] PHPUnit + E2E coverage for the new query params and UI
