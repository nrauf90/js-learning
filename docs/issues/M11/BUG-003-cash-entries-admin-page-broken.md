# BUG-003 - Admin "Cash Entries" page called the wrong endpoint with the wrong field names

- **Milestone:** M11
- **Found during:** review of admin panel built externally
- **Date:** 2026-07-30
- **Status:** closed — fixed endpoint + field names in `js/admin-entries.js`

## How to reproduce (pre-fix)

1. Log in as admin, open `/admin-entries.html`.

## Expected

The table lists cash entries across all users (user, category, amount,
type, note, date).

## Actual (pre-fix)

- `js/admin-entries.js` called `GET /api/admin/entries`, which doesn't
  exist (404) — the real route is `GET /api/admin/cash-entries`
  (`backend/routes/api.php`).
- Even if the endpoint were correct, the renderer read `e.kind` and
  `e.description`, but the `CashEntry` model exposes `type` and `note`
  — so the "Kind" and "Description" columns would always render blank.

## Evidence

- Regression test: `backend/tests/Feature/AdminPanelTest.php::test_admin_can_list_cash_entries_across_users`

## Links

- `docs/milestones/M11-admin-panel.md`
- `docs/tasks/M11-tasks.md`
- Issues index: `docs/issues/README.md`
