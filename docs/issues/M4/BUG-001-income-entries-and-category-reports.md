# BUG-001 - income entries used expense categories; reports lacked income breakdown

- **Milestone:** M4
- **Found during:** user feedback
- **Date:** 2026-07-30
- **Status:** closed
- **Resolution:** Added `kind` on categories (income vs expense), income source categories in seeder, category filter on cash-flow form, API validation, `income_by_category` / `expense_by_category` in reports, yearly report endpoint, dual charts on reports page.

## How to reproduce (before fix)

1. Log in and open Cash Flow.
2. Set type to Income — category dropdown still listed expense names (Grocery, Petrol, …).
3. Reports showed expense chart only; no income-by-source or yearly view.

## Expected

- Income entries use income categories (Salary, Freelance, …).
- Monthly/weekly/yearly reports show income by source and expenses by category.

## Actual (before fix)

- Single category list for all entry types.
- Reports aggregated income total but not by category; no yearly report.

## Links

- `docs/tasks/M4-tasks.md`
- `backend/database/seeders/ExpenseCategorySeeder.php`
- `reports.html`, `js/reports.js`, `js/cashflow.js`
