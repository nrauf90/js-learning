# M3 — Daily cash flow tasks

Depends on M2 complete.

- [ ] **M3-T1** Migration: `expense_categories` (id, name, slug, icon optional, is_system)
- [ ] **M3-T2** Seeder: Car Maintenance, Petrol, Car Wash, Grocery, Utilities, Food, Transport, Health, Entertainment, Other
- [ ] **M3-T3** Migration: `cash_entries` (user_id, category_id, type, amount, entry_date, note, receipt_path nullable)
- [ ] **M3-T4** `CashEntry` model + policy (user owns row)
- [ ] **M3-T5** `GET /api/categories` — list categories
- [ ] **M3-T6** `GET /api/cash-entries?date=YYYY-MM-DD` — list for day
- [ ] **M3-T7** `POST /api/cash-entries` — create
- [ ] **M3-T8** `PUT /api/cash-entries/{id}` — update
- [ ] **M3-T9** `DELETE /api/cash-entries/{id}` — delete
- [ ] **M3-T10** Frontend `cashflow.html` — date picker, add form, entry list
- [ ] **M3-T11** Frontend `js/cashflow.js` — CRUD via API
- [ ] **M3-T12** PHPUnit tests for cash entry CRUD + authorization
