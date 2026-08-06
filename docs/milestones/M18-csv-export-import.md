# M18 — CSV export & import

**Phase:** 5 (P1 features) · **Depends on:** M17

**Goal:** Let users export their cash entries to CSV and import entries
from a CSV file, on top of the existing PDF report export.

## Scope

- Backend export endpoint: stream the current user's entries as CSV
  (respecting the M16 filters where useful).
- Backend import endpoint: parse an uploaded CSV, validate rows, map to
  categories, report per-row errors.
- Frontend: export/import controls on the cashflow page.

## Tasks

See [M18 tasks](../tasks/M18-tasks.md).

## Exit criteria

- [ ] User can download all (or filtered) entries as CSV
- [ ] User can upload a CSV and have valid rows imported, with clear
      per-row error reporting for invalid ones
- [ ] Import is scoped to the authenticated user only
- [ ] PHPUnit + E2E coverage
