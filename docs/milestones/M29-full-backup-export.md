# M29 — Full account backup export

**Phase:** 7 (P3 polish) · **Depends on:** M28

**Goal:** Give users a complete, portable backup of their data (broader
than the M18 CSV export of just entries).

## Scope

- Backend export-everything endpoint: entries, categories, budgets
  (M17), goals (M26), accounts (M20) as a JSON bundle or ZIP.
- Frontend: "Export my data" action on the profile/settings page.

## Tasks

See [M29 tasks](../tasks/M29-tasks.md).

## Exit criteria

- [ ] User can download a single file containing all of their data
- [ ] Export only ever contains the requesting user's own data
- [ ] PHPUnit + E2E coverage
