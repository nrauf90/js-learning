# M3 — Daily cash flow tasks

Depends on M2 complete.

- [x] **M3-T1** Migration: `expense_categories` (id, name, slug, icon optional, is_system)
- [x] **M3-T2** Seeder: Car Maintenance, Petrol, Car Wash, Grocery, Utilities, Food, Transport, Health, Entertainment, Other
- [x] **M3-T3** Migration: `cash_entries` (user_id, category_id, type, amount, entry_date, note, receipt_path nullable)
- [x] **M3-T4** `CashEntry` model + policy (user owns row)
- [x] **M3-T5** `GET /api/categories` — list categories
- [x] **M3-T6** `GET /api/cash-entries?date=YYYY-MM-DD` — list for day
- [x] **M3-T7** `POST /api/cash-entries` — create
- [x] **M3-T8** `PUT /api/cash-entries/{id}` — update
- [x] **M3-T9** `DELETE /api/cash-entries/{id}` — delete
- [x] **M3-T10** Frontend `cashflow.html` — date picker, add form, entry list
- [x] **M3-T11** Frontend `js/cashflow.js` — CRUD via API
- [x] **M3-T12** PHPUnit tests for cash entry CRUD + authorization

## Completion log

### M3-T1 — done 2026-07-30

**Modified files**
- `backend/database/migrations/2026_07_30_130323_create_expense_categories_table.php`

**QA notes**
1. `cd backend && php artisan migrate`
2. Table `expense_categories` exists with `name`, `slug`, `icon`, `is_system`.

### M3-T2 — done 2026-07-30

**Modified files**
- `backend/database/seeders/ExpenseCategorySeeder.php`
- `backend/database/seeders/DatabaseSeeder.php`

**QA notes**
1. `php artisan db:seed`
2. `GET /api/categories` (auth) returns 10 categories including Petrol, Grocery.

### M3-T3 — done 2026-07-30

**Modified files**
- `backend/database/migrations/2026_07_30_130324_create_cash_entries_table.php`

**QA notes**
1. Migrate; confirm `cash_entries` has `user_id`, `category_id`, `type`, `amount`, `entry_date`, `note`, `receipt_path`.

### M3-T4 — done 2026-07-30

**Modified files**
- `backend/app/Models/CashEntry.php`, `ExpenseCategory.php`
- `backend/app/Policies/CashEntryPolicy.php`
- `backend/app/Http/Controllers/Controller.php` — `AuthorizesRequests`
- `backend/app/Models/User.php` — `cashEntries()` relation

**QA notes**
1. Policy allows only owner update/delete.

### M3-T5 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/CategoryController.php`
- `backend/routes/api.php` — `GET /api/categories`

**QA notes**
1. Authenticated `GET /api/categories` → `{ categories: [...] }`.

### M3-T6 / M3-T7 / M3-T8 / M3-T9 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/CashEntryController.php`
- `backend/routes/api.php` — cash-entries CRUD

**QA notes**
1. CRUD with Sanctum bearer token.
2. List supports `?date=YYYY-MM-DD`.

### M3-T10 / M3-T11 — done 2026-07-30

**Modified files**
- `cashflow.html`, `js/cashflow.js`, `css/styles.css`

**QA notes**
1. Log in → open `/cashflow.html` (or `/cashflow` via clean URLs).
2. Add/edit/delete expense for selected date; totals update.

### M3-T12 — done 2026-07-30

**Modified files**
- `backend/tests/Feature/CashEntryTest.php`
- `e2e/tests/m3-cashflow.spec.js`

**QA notes**
1. `php artisan test --filter=CashEntryTest` — 7 passed.
2. `npm run qa:milestone -- M3` — 13 passed (M1–M3 regression).
