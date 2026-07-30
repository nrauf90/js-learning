# QA issues (offline harness)

Failures from the **token-free** Playwright harness land here.

## How to run

```bash
# After finishing a milestone — also re-runs all earlier ones (regression)
npm run qa:milestone -- M1
npm run qa:milestone -- M2
```

Requires API + DB working (`php artisan migrate`). The harness starts `:8000` and `:3000` if they are not already up.

## Bug files

| Location | Meaning |
|----------|---------|
| `docs/issues/M2/BUG-001-….md` | Bug found while QA’ing through M2 |
| Screenshot `docs/issues/M2/shot-….png` | Optional failure screenshot |

Each bug MD includes: reproduce steps, expected vs actual, test file, links to milestone/tasks.

## Workflow

1. Complete milestone tasks.
2. Run `npm run qa:milestone -- Mx`.
3. If red → fix bugs under `docs/issues/Mx/`, then re-run.
4. Set bug **Status** to `closed` when fixed.
5. Only then start the next milestone.

## Index

| Bug | Milestone | Status | Title |
|-----|-----------|--------|-------|
| [BUG-001](./M2/BUG-001-unauthenticated-api-user-returns-401.md) | M2 | closed | unauthenticated /api/user returns 401 |
| [BUG-002](./M2/BUG-002-no-dashboard-after-login.md) | M2 | closed | no dashboard after login; missing shared nav |
| [BUG-001](./M3/BUG-001-guest-is-redirected-from-cashflow-page-to-login.md) | M3 | closed | guest redirect URL assertion (cleanUrls) |
| [BUG-002](./M3/BUG-002-logged-in-user-can-add-an-expense-in-the-ui.md) | M3 | closed | option visibility in Playwright |
| [BUG-001](./M4/BUG-001-income-entries-and-category-reports.md) | M4 | closed | income categories + income/expense/yearly reports |
| [BUG-004](./M3/BUG-004-signup-form-creates-session-and-redirects.md) | M3 | open | signup form creates session and redirects |
| [BUG-005](./M3/BUG-005-login-form-works-for-existing-user.md) | M3 | open | login form works for existing user |
| [BUG-002](./M4/BUG-002-logged-in-user-can-add-an-income-entry-in-the-ui.md) | M4 | open | logged-in user can add an income entry in the UI |
| [BUG-002](./M4/BUG-002-api-crud-for-cash-entries.md) | M4 | open | API CRUD for cash entries |
| [BUG-003](./M4/BUG-003-weekly-and-monthly-report-apis-aggregate-entries.md) | M4 | open | weekly and monthly report APIs aggregate entries |
| [BUG-004](./M4/BUG-004-logged-in-user-sees-report-summary-and-chart.md) | M4 | open | logged-in user sees report summary and chart |
| [BUG-002](./M4/BUG-002-weekly-and-monthly-report-apis-aggregate-entries.md) | M4 | open | weekly and monthly report APIs aggregate entries |
| [BUG-001](./M6/BUG-001-sandbox-checkout-activates-subscription-via-api.md) | M6 | closed | sandbox checkout activates subscription via API |
| [BUG-002](./M6/BUG-002-logged-in-user-can-complete-sandbox-checkout-in-ui.md) | M6 | closed | logged-in user can complete sandbox checkout in UI |
| [BUG-001](./M7/BUG-001-login-form-works-for-existing-user.md) | M7 | closed | login form works for existing user |
