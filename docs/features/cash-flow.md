# Cash flow — manual entries

## What it is

The money that moves through the shop without passing over the till: rent, bijli,
staff salary, transport, the owner taking cash out for himself, a bit of income
from somewhere other than the counter. One entry per thing, filed under a
category, dated, with an optional note.

It is the oldest feature in the app and the one the dashboard and the
weekly/monthly/yearly reports are built on.

## How it works

### An entry

`cash_entries` is `user_id` + `category_id` + `type` (`income` \| `expense`) +
`amount` + `entry_date` + optional `note` and `receipt_path`. Amounts are
`decimal(14,2)`, must be greater than zero and are capped at 999,999,999.99.

The **type must match the category's `kind`**. A category is either an income
category or an expense one, and `assertCategoryMatchesType()` refuses a
mismatch — otherwise "Rent" could hold income and the reports would be nonsense.

`receipt_path` exists on the table and comes back in the payload, but nothing
writes it yet; see [receipt-addon.md](./receipt-addon.md).

### Categories

`expense_categories` is a **single shared, system-seeded table**, not per shop —
unlike [product categories](./product-categories.md), which each shop owns. The
seeded list is what a kiryana shop actually spends money on:

| kind | slugs |
|---|---|
| expense | `stock-purchase`, `rent`, `utilities` (Bijli/Gas), `staff-salary`, `transport`, `packaging`, `wastage`, `repairs`, `chanda-zakat`, `owner-drawings`, `other` |
| income | `sales` (Shop Sales), `other-income` |

Slugs are written out in the seeder rather than derived from the name, because the
slug is the identity a `cash_entries` row is already attached to and the name is
only a label. That is what let "Utilities" become "Bijli/Gas (Utilities)" without
breaking a single existing entry — the concept did not change, only the words a
shopkeeper recognises it by.

**Retirement, not deletion.** The original seed was a personal-finance list — Car
Maintenance, Petrol, Car Wash, Entertainment — and a kiryana store files none of
it. Those rows could not simply be deleted: `cash_entries.category_id` is a
restricted foreign key, so a delete either fails outright or, if the constraint
were relaxed, orphans real money the shopkeeper entered by hand. `is_active`
is the retirement instead. A deactivated category keeps naming the entries already
filed under it and simply stops being offered for new ones, which is reversible —
deletion is not. `ExpenseCategorySeeder::retireEverythingElse()` deactivates every
`is_system` row not in the current lists, and re-running the seeder brings one back.

`ExpenseCategory::scopeSelectable()` is what the picker reads: it excludes retired
categories **and** `INTERNAL_SLUGS` (`till-float`, `till-close`). Those two are
real rows created on first till open/close, and their names must still render on
the entries they own, but a shopkeeper hand-filing an expense under "Till float"
would land it in the drawer reconciliation and silently throw the day's variance
out. See [day-book.md](./day-book.md).

### `owner-drawings`

Cash the owner takes out for himself. Not a cost of trading, but it leaves the
drawer, so it has to be filed somewhere or the day will never reconcile.

### `wastage`

The cash-ledger side of throwing stock away, for a shop that writes off "Rs 500 of
sabzi" by hand instead of adjusting stock. The profit report moves it off the
general expenses line and onto its own wastage line so it cannot be counted twice
alongside `stock_movements`-based write-offs. See
[stock-and-wastage.md](./stock-and-wastage.md).

### The screen

`cashflow.html` works one **day** at a time. A date picker at the top drives both
the form's `entry_date` and the list; totals for that day (income, expense, net)
sit above the entries. The category dropdown reloads whenever the income/expense
toggle changes, so only categories of the right kind are offered. Edit refills the
form; delete confirms first.

The list is paginated (25 a page from the screen; the API defaults to 50 and caps
at 100) with the same Previous/Next control the other list screens use. Deleting
the last entry on a page steps back rather than leaving an empty one.

The response carries a **`totals`** block — income, expense and net — summed over
every matching row rather than over the page. Those three figures are the reason
the screen is opened, and adding up only what fits on one page would quietly
understate a busy day.

## Screens / files

| Layer | File |
|---|---|
| Page | `cashflow.html` |
| Controller | `js/cashflow.js` |
| API | `backend/app/Http/Controllers/Api/CashEntryController.php`, `CategoryController.php` |
| Models | `backend/app/Models/CashEntry.php`, `ExpenseCategory.php` |
| Policy | `backend/app/Policies/CashEntryPolicy.php` |
| Seeder | `backend/database/seeders/ExpenseCategorySeeder.php` |
| Migrations | `2026_07_30_130323_create_expense_categories_table.php`, `..._130324_create_cash_entries_table.php`, `2026_07_30_180000_add_kind_to_expense_categories_table.php`, `2026_08_08_100001_add_is_active_to_expense_categories_table.php` |
| Tests | `backend/tests/Feature/CashEntryTest.php` |
| E2E | `e2e/tests/m3-cashflow.spec.js` |

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/categories` | Selectable categories, optionally filtered by `kind` |
| GET | `/api/cash-entries` | Paginated list (`date`, `page`, `per_page`) with a period-wide `totals` block |
| POST | `/api/cash-entries` | Create |
| PUT | `/api/cash-entries/{cashEntry}` | Update |
| DELETE | `/api/cash-entries/{cashEntry}` | Delete |

`GET /api/categories` is the one shop endpoint that sits **outside** the
subscription gate — it is registered above the `subscribed` group and needs only a
bearer token.

## Permissions & gating

- `auth:sanctum` on everything; the `subscribed` gate on all four `/cash-entries`
  routes.
- `CashEntryPolicy` scopes on **`$user->id`**, not `dataOwnerId()`. Cash entries
  are the one part of the shop that is genuinely per-account: a staff member's
  entries are their own and are invisible to the owner, and vice versa.
- Categories are global and read-only through this API. Only a platform admin can
  create or edit one, through the [admin panel](./admin-panel.md).

## Edge cases & known limits

- **Per-account scoping is inconsistent with the rest of the app.** Products,
  sales, purchases, the khata and the day book are all scoped to
  `dataOwnerId()`; cash entries are scoped to `id`. The day book writes its
  float/close entries under `dataOwnerId()`, so a staff member who opens the till
  files those under the owner while their own manual entries stay under
  themselves. The reports endpoints inherit the split: `/reports/weekly|monthly|yearly`
  read `$request->user()->id`, while `/reports/profit` and `/reports/cash-position`
  read `dataOwnerId()`.
- **The screen only ever shows one day.** There is no month view, no search and no
  filtering by category or type (backlog milestone M16). Clearing the date is not
  offered either, so the un-dated "everything" branch of the API is only reachable
  by the dashboard.
- **No CSV import or export** (backlog M18), **no recurring entries** (M19), **no
  budgets** (M17), **no soft delete** (M22).
- **A shop cannot add its own category.** The list is fixed unless a platform
  admin edits it, which affects every shop on the install.
- **Deleting a category is blocked while entries reference it** — the admin
  endpoint refuses with a count. Retiring is the intended route.
- **Stock bought from a supplier does not appear here** unless the shopkeeper
  files it by hand under "Stock Purchase". Purchases are tracked separately; see
  [purchases-stock-in.md](./purchases-stock-in.md).
