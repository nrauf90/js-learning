# BUG-004 - Payments admin views referenced a non-existent `gateway` column

- **Milestone:** M11
- **Found during:** review of admin panel built externally
- **Date:** 2026-07-30
- **Status:** closed — use `provider` (the real `payments` column) everywhere

## How to reproduce (pre-fix)

1. Log in as admin, open `/admin.html` (Recent Payments) or `/admin-payments.html`.
2. Call `GET /api/admin/payments?gateway=jazzcash`.

## Expected

The Gateway column shows `JAZZCASH` / `EASYPAISA`; the `?gateway=` filter
returns only matching payments.

## Actual (pre-fix)

The `payments` table column is `provider` (see
`backend/database/migrations/2026_07_30_190001_create_payments_table.php`
and `App\Models\Payment`), not `gateway`:

- `js/admin.js` and `js/admin-payments.js` read `payment.gateway`,
  which is always `undefined` — the column always rendered as `—`.
- `AdminController::payments()` filtered with
  `->where('gateway', ...)`, which would throw an "unknown column" SQL
  error the first time the filter was actually used.

## Evidence

- Regression test: `backend/tests/Feature/AdminPanelTest.php::test_admin_can_filter_payments_by_gateway`

## Links

- `docs/milestones/M11-admin-panel.md`
- `docs/tasks/M11-tasks.md`
- Issues index: `docs/issues/README.md`
