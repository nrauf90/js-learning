# M35 — Kiryana pack tasks

Depends on M23 (point of sale) complete.

- [x] **M35-T1** Weighed goods: store price/stock per canonical base unit (`pc`/`g`/`ml`), `price_unit` for display only; two-way till keypad (by weight and by rupee amount)
- [x] **M35-T2** Purchases / Stock In: suppliers, purchase documents, weighted-average cost so `unit_cost` is finally populated
- [x] **M35-T3** Profit & cash-position reports: one shared margin definition, `GET /api/reports/profit` + `/api/reports/cash-position`
- [x] **M35-T4** Shop expense categories: retire the personal-finance leftovers via `is_active` instead of deleting them
- [x] **M35-T5** Customer khata (udhaar): credit sales, 30/60/90 aging, running statement, oldest-first payment allocation, credit limits
- [x] **M35-T6** Wastage, expiry & pack size: typed wastage reasons valued at cost, expiring-soon filter, carton→piece pack size
- [x] **M35-T7** Whole-rupee settlement: round the total half-up, record the remainder in `rounding_adjustment`
- [x] **M35-T8** Udhaar at the till: all six settled-now payment methods, plus a separate "Save as Udhaar" button and customer dialog so credit sales are reachable at all
- [x] **M35-T9** Khata partial payments with history: `received_by_name`, a payments array on the ledger, and a "Payments received" card
- [x] **M35-T10** Supplier invoice payments: `purchase_payments`, a "What I owe suppliers" card, and a full-width delivery detail view

## Completion log

### M35-T1 — done 2026-08-07

Half a kiryana shop is scooped out of a sack, but every quantity in the system
was an integer — none of it could be rung up.

**Modified files**
- `backend/app/Support/Unit.php` — new. The single table of units: `pc`, `dozen`, `g`, `kg`, `ml`, `l`, each with the base unit it reduces to and the factor. Base-unit quantities are held to 3 dp (a milligram — far finer than any shop scale) so "Rs 50 of daal" divides out without the money drifting.
- `js/units.js` — new. The same table and the same conversions on the frontend, so the till and the API cannot disagree about what 250 g costs.
- `backend/database/migrations/2026_08_07_110000_add_units_of_sale_to_catalogue.php` — new. `unit_type` / `price_unit` on products and sale items, and **widens `price`/`cost`/`unit_price`/`unit_cost` to `decimal(12,4)`**: namak at Rs 40/kg is Rs 0.04/g and collapses to zero at 2 dp. No existing row changed value — a piece-priced product was already stored per base unit.
- `backend/app/Models/Product.php`, `SaleItem.php`, `StockMovement.php` — float quantity casts, unit accessors, display helpers.
- `backend/app/Services/Pos/SaleService.php` — quantities and stock checks are floats end to end.
- `backend/app/Http/Controllers/Api/ProductController.php` — accepts a quoted price in `price_unit` and stores the per-base-unit price; returns `display_stock` / `stock_label` so the UI never has to do the conversion itself.
- `backend/app/Http/Controllers/Api/SaleController.php` — float line quantities in and out.
- `js/cart.js`, `js/pos.js`, `pos.html` — the two-way keypad: enter a weight and get the money, or enter the money and get the weight. Quick chips use counter vocabulary (Pao / Adha kg / 3 Pao) rather than "0.25 kg".
- `js/products.js`, `products.html` — unit picker; prices are entered and shown in the unit the shopkeeper quotes in.
- `js/receipt.js` — prints the quoted unit, not grams.
- `css/styles.css` — keypad and unit-chip styles.
- `js/offline-db.js` — the offline cache stores the same base-unit shape, so a queued offline sale prices identically once it syncs.
- `backend/app/Services/ActivityLogger.php` — `stockAdjusted()` took `int $from, int $to` and silently truncated 1500.5 g to 1500 in the audit trail; now `float`.
- `backend/tests/Feature/WeighedGoodsTest.php` — new, 19 tests.
- `tests/units.test.js` — new, 32 tests. `tests/cart.test.js` — extended for float quantities.

**QA notes**
1. `cd backend && php artisan test --filter=WeighedGoodsTest` → 19 passed.
2. `npm test` → 70 passed (units + cart).
3. Open <http://localhost:3000/products.html>, add "Daal" at **Rs 250 per kg**. Confirm the list shows Rs 250/kg, not Rs 0.25.
4. Open <http://localhost:3000/pos.html>, tap Daal → keypad opens. Enter `250` in the **grams** field → line reads **Rs 62.50**. Switch to the **amount** field, enter `50` → line reads **200 g**.
5. Tap the **Pao** chip → 250 g, Rs 62.50. Switch the keypad unit g↔kg and confirm the weight is preserved, not re-read as a new number.
6. Sell it and check `products.html` — the sack is 250 g lighter, and `activity.html` logs the adjustment with a decimal, not a truncated integer.

### M35-T2 — done 2026-08-07

Without a purchase path `unit_cost` stays null on every line, and profit reads
as near-zero however much the shop sells.

**Modified files**
- `backend/database/migrations/2026_08_08_100000_create_purchases_tables.php` — new. `suppliers`, `purchases`, `purchase_items`.
- `backend/app/Models/Supplier.php`, `Purchase.php`, `PurchaseItem.php` — new.
- `backend/app/Services/Pos/PurchaseService.php` — new. Blends cost as a weighted average, `((oldQty × oldCost) + (newQty × newCost)) ÷ (oldQty + newQty)`: ghee and atta move in price monthly and a shop is never selling only the newest sack, so last-cost would misprice most of what is on the shelf. Stock moves only through the audited `stock_movements` path (locked decision 8), inside the same transaction as the purchase.
- `backend/app/Http/Controllers/Api/PurchaseController.php`, `backend/app/Policies/PurchasePolicy.php` — new.
- `backend/routes/api.php` — `GET/POST /api/purchases`, `GET /api/purchases/{purchase}`, behind `auth:sanctum` + `subscribed`.
- `purchases.html`, `js/purchases.js` — new. Supplier, date, lines with quantity in the product's own quoted unit and a cost per that unit.
- `js/shell.js` — **Stock In** in the sidebar.
- `backend/tests/Feature/PurchaseTest.php` — new, 20 tests.

**QA notes**
1. `cd backend && php artisan test --filter=PurchaseTest` → 20 passed.
2. Open <http://localhost:3000/purchases.html>. With Daal at 10 kg @ Rs 200/kg already in stock, record a delivery of 10 kg @ Rs 240/kg.
3. Expect stock 20 kg and cost **Rs 220/kg** — the blend, not Rs 240.
4. Check `products.html` shows the new cost, and that the delivery left a `stock_movements` row (visible on `activity.html`) rather than writing `stock_quantity` directly.
5. Sell 1 kg and confirm the sale line now carries a `unit_cost` — this is what makes M35-T3 possible.

### M35-T3 — done 2026-08-07

`ReportAggregator` read `cash_entries` only — it contained **zero** references
to sales. Since `DayBookService` replaced the per-sale `cash_entry` with a
per-day float/close pair, reports could not see the shop's takings at all.

**Modified files**
- `backend/app/Support/SaleProfit.php` — new. The one definition of margin, as SQL fragments consumed by *both* the sales list and the reports screen. A shop shown two different profits for the same day trusts neither.
- `backend/app/Services/ReportAggregator.php` — profit and cash-position aggregation over `sale_items`. Revenue on a line with no `unit_cost` is reported as **uncosted**, separately and explicitly, rather than counted as profit — an unrecorded cost means the margin is *unknown*, not zero.
- `backend/app/Http/Controllers/Api/ReportController.php` — `profit()` and `cashPosition()`. Wastage arrives from both ends (stock write-offs valued at cost, and hand-written wastage cash entries) and is excluded from `expenses` so it counts exactly once.
- `backend/routes/api.php` — `GET /api/reports/profit`, `GET /api/reports/cash-position`.
- `reports.html`, `js/reports.js` — P&L block (revenue, cost of goods, gross profit, uncosted revenue, wastage, expenses, net) and a cash-position block.
- `backend/tests/Feature/ProfitReportTest.php` — new, 19 tests.

**QA notes**
1. `cd backend && php artisan test --filter=ProfitReportTest` → 19 passed.
2. `curl -H "Authorization: Bearer $TOKEN" "http://localhost:8000/api/reports/profit?from=2026-08-01&to=2026-08-31"` → revenue, cost, gross profit, uncosted revenue.
3. Same for `/api/reports/cash-position`.
4. Open <http://localhost:3000/reports.html>. Sell a costed product and an uncosted one; confirm the uncosted sale's revenue appears on the **uncosted** line and is *not* added to gross profit.
5. Write off spoiled stock (M35-T6) **and** file a wastage cash entry, then confirm the wastage total counts each once and neither appears under `expenses`.

### M35-T4 — done 2026-08-07

The seeded categories were a personal-finance list (Car Wash, Petrol,
Entertainment). A shop does not file its month against those.

**Modified files**
- `backend/database/migrations/2026_08_08_100001_add_is_active_to_expense_categories_table.php` — new. Retire, don't delete: entries already filed against "Car Wash" still need a name to render.
- `backend/database/seeders/ExpenseCategorySeeder.php` — shop categories seeded; the personal-finance ones marked inactive.
- `backend/app/Models/ExpenseCategory.php` — two bugs. `scopeSelectable()` never checked `is_active`, so retired categories were still served to the picker; and `is_active` was missing from `$fillable`, so retiring one by mass assignment silently did nothing (the attribute was dropped and the column default put it back).
- `backend/tests/Feature/CashEntryTest.php` — regression test. **A unit test could not have caught the `scopeSelectable` bug**: a fresh test database has no legacy rows, so the expected category count was right either way. It only shows on a database that already had them — i.e. every real shop upgrading — and it was caught by the live Playwright run. The new test manufactures a legacy inactive "Car Wash" row first, then asserts it is absent from the picker.

**QA notes**
1. `cd backend && php artisan test --filter=CashEntryTest` → passes, including the legacy-row regression.
2. On a database that **already had** the old seed data (not a fresh one): `cd backend && php artisan db:seed --class=ExpenseCategorySeeder`, then open <http://localhost:3000/cashflow.html> and confirm Car Wash / Petrol / Entertainment are gone from the category picker.
3. Confirm an existing entry previously filed under Car Wash still renders its category name in the list and on `reports.html` — it was retired, not deleted.

### M35-T5 — done 2026-08-07

Regulars buy on udhaar. Without a khata the shop keeps it in a notebook and
the ledger is wrong by the amount of the notebook.

**Modified files**
- `backend/database/migrations/2026_08_08_100002_create_customers_table.php` — new. Name, phone, credit limit, balance.
- `backend/app/Models/Customer.php`, `backend/app/Http/Controllers/Api/CustomerController.php`, `backend/app/Policies/CustomerPolicy.php` — new. Customer matching is deliberately conservative: an exact phone number wins, and a bare name may only claim a page that has no number on it yet — two Alis with different numbers stay two people, because merging them is not reversible from the UI.
- `backend/app/Models/Sale.php`, `backend/app/Services/Pos/SaleService.php` — a sale can be settled short and the remainder booked to a customer.
- `backend/app/Services/Pos/SalePaymentService.php` — payments clear the **oldest** debt first, which is how a shopkeeper settles a khata by hand.
- `backend/app/Http/Controllers/Api/SaleController.php` — credit sales.
- `backend/routes/api.php` — `GET/POST /api/customers`, `GET /api/customers/outstanding`, `GET/PUT /api/customers/{customer}`, `GET /api/customers/{customer}/ledger`, `POST /api/customers/{customer}/payments`.
- `customers.html`, `js/customers.js` — new. Who-owes-me list with 30/60/90 aging buckets, and a per-customer running statement.
- `js/shell.js` — **Khata** in the sidebar.
- `backend/tests/Feature/CustomerKhataTest.php` — new, 28 tests.

**QA notes**
1. `cd backend && php artisan test --filter=CustomerKhataTest` → 28 passed.
2. On <http://localhost:3000/pos.html>, ring up Rs 500, tender Rs 200 and put Rs 300 on khata under a new customer with a phone number.
3. Open <http://localhost:3000/customers.html> — the customer appears owing Rs 300 in the current (0–30) bucket.
4. Add a second credit sale, then record a part-payment. Confirm it clears the **older** debt first on the statement.
5. Set a credit limit below the balance and try another credit sale — it must be refused.
6. Add a second customer with the same name but a different phone and confirm they stay two separate pages.

### M35-T6 — done 2026-08-07

Stock that spoils, expires or walks out is a real cost. Before this it was
just an unexplained "adjustment".

**Modified files**
- `backend/database/migrations/2026_08_08_100003_add_wastage_expiry_and_pack_size_to_catalogue.php` — new. `reason` and `cost_value` on `stock_movements`, `expiry_date` and `pack_size` on `products`.
- `backend/app/Models/StockMovement.php` — typed `WASTAGE_REASONS` (damaged, expired, spoiled, theft, sample) and `cost_value`, so a loss is quantified in rupees rather than in units. **`count_correction` is deliberately excluded** from `WASTAGE_REASONS`: goods that were never on the shelf were never paid for twice, and counting a miscount as loss would charge the shop for its own arithmetic.
- `backend/app/Models/Product.php` — expiry and pack size; expiring-soon scope.
- `backend/app/Http/Controllers/Api/ProductController.php` — the adjust endpoint requires a reason for a write-off and values it at the product's current cost. Also fixes a guard that read `$product->stock_quantity !== 0` — under a decimal cast that compares `"0.000" !== 0`, which is always true, so **every** newly created product wrote a bogus zero-quantity `stock_movements` row.
- `products.html`, `js/products.js` — reason picker on write-off, expiry date field, expiring-soon filter and badge, pack size for breaking a carton into pieces.
- `backend/app/Services/ActivityLogger.php` — carries the wastage `reason` into the audit trail; "adjustment" on its own answers nothing.
- `css/styles.css` — `.pos-expiry-badge`; and a **pre-existing, unrelated** fix: author `display` rules outrank the browser's `[hidden] { display: none }`, so `products.html`'s "Cancel edit" button was permanently visible. Now handled for `.btn`, `.auth-google` and `.admin-btn`.
- `backend/tests/Feature/WastageTest.php` — new, 19 tests.

**QA notes**
1. `cd backend && php artisan test --filter=WastageTest` → 19 passed.
2. Open <http://localhost:3000/products.html>, write off 2 kg of Daal. Confirm the form refuses to submit without a reason, and that the recorded loss is valued at cost in rupees.
3. Set an expiry date a week out; confirm the product shows the expiry badge and appears under the expiring-soon filter.
4. Create a brand-new product with zero stock, then check `activity.html` — there must be **no** stock movement logged for it.
5. Edit a product and press **Cancel edit** — the button must disappear afterwards (it used to stay visible forever).
6. Confirm the write-off shows on `reports.html` under wastage, once, and not under expenses.

### M35-T7 — done 2026-08-07

Paisa coins do not circulate. A paisa-precise total means the drawer never
reconciles at close.

**Modified files**
- `backend/database/migrations/2026_08_08_100004_add_rounding_adjustment_to_sales.php` — new.
- `backend/app/Services/Pos/SaleService.php` — the total rounds half-up to the rupee and the remainder is written to `rounding_adjustment` rather than dropped, so the day's takings still add up against the line items. Change is computed from the **rounded** total, which is the number the customer actually hands over.
- `backend/app/Models/Sale.php`, `backend/app/Http/Controllers/Api/SaleController.php` — expose the adjustment.
- `js/cart.js`, `js/pos.js`, `pos.html` — the frontend rounds with the identical rule, so the screen and the receipt cannot differ. (`money()` already used epsilon-nudged half-up rounding from M23; the rupee rounding sits on top of it.)
- `js/receipt.js` — prints the rounding line when it is non-zero, so a customer can see why Rs 62.50 was charged as Rs 63.

**QA notes**
1. `cd backend && php artisan test --filter=PosTest` → passes, including the rounding cases.
2. `npm test` → `tests/cart.test.js` covers the frontend rounding.
3. On <http://localhost:3000/pos.html> ring up 250 g of Daal at Rs 250/kg → line Rs 62.50, **total Rs 63**, rounding +Rs 0.50.
4. Tender Rs 100 → change **Rs 37**, not Rs 37.50.
5. Print the receipt and confirm the printed total matches the screen exactly.
6. Check `GET /api/sales/{id}` returns `rounding_adjustment: 0.50` — the paisa is recorded, not lost.

### M35-T8 — done 2026-08-07

Found by the shop owner testing the app. `pos.html` offered **3** of the 7
supported payment methods and had **no customer field and no paid-amount field
at all**, so the entire udhaar path — which the backend fully supported and had
28 passing tests for (M35-T5) — was **unreachable from the till**. Every sale
fell through the settled-in-full branch and was written `paid`, and a khata
customer could never have a sale attached to them. This is the root cause of
two of the reported bugs.

**Modified files**
- `pos.html` — all six settled-now methods (cash, card, easypaisa, jazzcash, bank_transfer, other), with a payment-reference field revealed for the non-cash ones. **Credit is deliberately not in the dropdown**: it gets its own `#pos-credit` "Save as Udhaar" button beside "Complete sale". Taking goods on the book is a different act from taking money, and must not be reachable by a mis-click on a dropdown. Adds the `#pos-udhaar` dialog.
- `js/pos.js` — the dialog searches the khata via `GET /api/customers?search=`, showing each candidate's balance, credit limit and **limit remaining before this ticket is added**, so the shopkeeper sees the consequence before committing. It also accepts a new name + phone. Optional deposit (amount + `deposit_method`); a **blank deposit is legitimate** and records the whole ticket as `pending` — never `paid`. The till never calls `POST /api/customers`: `SaleService::resolveCustomer` owns page resolution, so the till's matching and the khata screen's matching cannot diverge (see the conservative phone/name rule in M35-T5).
- `js/receipt.js` — the confirmation now states plainly whether the sale settled, part-settled or went wholly on the book. Previously it read the same either way.
- `css/styles.css` — udhaar dialog and result-list styles. Two bug fixes: `.pos-modal-card` is a **column flex container**, so in a dialog taller than the card every row *shrinks* rather than the card scrolling — the customer hit list collapsed to zero height while still counting as visible to the DOM, and Playwright clicked straight through it into the search box underneath (`display: block` on `.pos-udhaar-card`). And `.pos-day-figures` sets `display: flex`, which defeats `[hidden]` — the same class of bug as #6 in the milestone doc, already documented in `css/styles.css` above the `.btn[hidden], .auth-google[hidden], .admin-btn[hidden]` block.
- `e2e/tests/m35-grocery.spec.js` — 3 new specs (9 → 12).

No backend changes were needed. The API was already correct; only the till could not reach it.

**QA notes**
1. `npm run qa:m35` → 80 passed.
2. Open <http://localhost:3000/pos.html>, ring up a ticket and open the payment method dropdown — expect **six** options, and no "credit" among them.
3. Pick `easypaisa` — a payment-reference field appears. Pick `cash` — it disappears.
4. Press **Save as Udhaar**. Type a few letters of an existing khata customer; the hit list shows their balance, credit limit and what limit is left. Click one — the click must land on the customer, not the search box behind it.
5. Leave the deposit blank and save. `GET /api/sales/{id}` must show status `pending` with the full total outstanding — **not** `paid`.
6. Repeat with a part deposit and confirm the receipt says the sale part-settled, naming both figures.
7. Open <http://localhost:3000/customers.html> — the customer is there with the new debt, created by `SaleService::resolveCustomer`, not by a `POST /api/customers` from the till.

### M35-T9 — done 2026-08-07

A khata is settled in handfuls, not in one payment, and "who took my money" is
the first question asked when one goes missing.

**Modified files**
- `backend/database/migrations/2026_08_08_100005_add_received_by_name_to_sale_payments_table.php` — new. `received_by_name` is **deliberately distinct from `recorded_by`**: the login that types a payment in and the person who physically took the cash are routinely different people (the son on the counter, the boy collecting at the door), and only the second answers the question when a payment is disputed.
- `backend/app/Models/SalePayment.php` — `received_by_name`.
- `backend/app/Services/Pos/SalePaymentService.php` — carries `received_by_name` onto every allocation. Also fixes a bug: `settleOldestFirst()` took a fresh `now()` per allocation, so one handful of notes that happened to span a second boundary split into two entries in the history and read as two separate payments. It now stamps the whole lump sum once and passes that `paid_at` down.
- `backend/app/Services/Pos/SaleService.php`, `backend/app/Http/Controllers/Api/SaleController.php` — see bug #7 in the milestone doc: `SaleService` read `received_by_name` and `SaleController::store` passed its whole validated array through, but the rules block never listed the field, so `$request->validate()` stripped it before anything saw it. The feature existed and did nothing. Fixed on **both** the sale-creation and the per-sale settle rule blocks.
- `backend/app/Models/Customer.php`, `backend/app/Http/Controllers/Api/CustomerController.php` — `GET /api/customers/{id}/ledger` gained a `payments` array: method, reference, received-by, the sales each payment was allocated against, and `balance_after`.
- `customers.html`, `js/customers.js` — a "Payments received" card (When / Received by / Paid / Left owing, newest first) and a live "what will remain" line, so a partial payment is visible **before** it is committed rather than being discovered afterwards.
- `backend/tests/Feature/CustomerKhataTest.php` — extended. The test that had deliberately bypassed HTTP to exercise `received_by_name` now goes through the real endpoint, which is what would have caught bug #7.

**QA notes**
1. `cd backend && php artisan test --filter=CustomerKhataTest` → passes.
2. On <http://localhost:3000/customers.html>, open a customer owing Rs 2,000 and take a Rs 500 payment, filling **Received by** with a different name from the logged-in user.
3. Before submitting, confirm the "what will remain" line reads Rs 1,500.
4. After submitting, the "Payments received" card shows the row with that received-by name and `Left owing` Rs 1,500.
5. `curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/customers/1/ledger` → the `payments` array carries `received_by_name` (this is the field validation used to strip) and a `balance_after` per row.
6. Take one lump sum large enough to clear two old tickets at once and confirm it appears as **one** payment in the history, not two.

### M35-T10 — done 2026-08-07

The khata answers "who owes me". Nothing answered "what do I owe", even though
Stock In already recorded a deposit against a delivery.

**Modified files**
- `backend/database/migrations/2026_08_08_100006_create_purchase_payments_table.php`, `backend/app/Models/PurchasePayment.php` — new, mirroring `sale_payments` / `SalePayment` so both sides of the shop's book read the same way and one mental model covers both screens.
- `backend/app/Models/Purchase.php` — `payments()` orders by `paid_at` **then `id`**. `paid_at` is only second-precision, and without the tiebreak two payments taken in the same second print their running balances against the wrong rows.
- `backend/app/Services/Pos/PurchaseService.php` — `recordPayment()` mirrors `SalePaymentService::settle()`: row-locked, over-payment refused with the exact balance in the message, and `amount_paid` **re-derived from `SUM(amount)` on every change rather than incremented**, so the stored total cannot drift from the history it is supposed to summarise. The deposit typed at Stock In becomes the first payment row for the same reason — otherwise `amount_paid` could not be reconstructed from the rows.
- `backend/app/Http/Controllers/Api/PurchaseController.php`, `backend/routes/api.php` — `GET /api/purchases/outstanding` and `POST /api/purchases/{purchase}/payments`.
- `purchases.html`, `js/purchases.js` — a "What I owe suppliers" card (the mirror of the khata's "who owes me"), and a **full-width in-page delivery detail view**. Deliberately **not a dialog**: `.pos-modal-card` caps at 520px and this screen is read side by side with the supplier's paper bill. It shows the invoice header, the goods with quantities in the shop's own units (a sack reads "50 kg", not 50000 — see M35-T1), totals, paid/outstanding, a record-payment form prefilled with the balance, and payment history with a correct chronological running balance.
- `backend/tests/Feature/PurchaseTest.php` — extended for the deposit-as-payment-row change. `backend/tests/Feature/PurchasePaymentTest.php` — new.

**QA notes**
1. `cd backend && php artisan test --filter=PurchasePaymentTest` and `--filter=PurchaseTest` → both pass.
2. `curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/purchases/outstanding`.
3. Open <http://localhost:3000/purchases.html>. Record a Rs 10,000 delivery with a Rs 3,000 deposit — the "What I owe suppliers" card shows Rs 7,000.
4. Click into the delivery. The detail view is **full width, in page**, not a dialog. Quantities read in the shop's units (50 kg, not 50000 g).
5. The deposit is already the first row in payment history, with a running balance of Rs 7,000.
6. Record Rs 4,000 more; balance Rs 3,000. Then try to pay Rs 5,000 — refused, and the message names the exact Rs 3,000 balance.
7. Record two payments in quick succession (same second) and confirm the running balances still line up with the right rows.
8. Directly edit `purchase_payments` in the DB and re-open the delivery — `amount_paid` re-derives from the rows rather than staying stale.

## Milestone QA — 2026-08-07

Cross-cutting integration and harness wiring for the whole pack:

- `backend/routes/api.php` — purchases, customers and the two new report endpoints, all behind `auth:sanctum` + `subscribed`.
- `js/shell.js` — sidebar entries for **Stock In** and **Khata**.
- `backend/app/Services/ActivityLogger.php` — float stock quantities and the wastage `reason`.
- `css/styles.css` — `.pos-expiry-badge`, keypad/unit-chip styles, and the `[hidden]` specificity fix.
- `backend/app/Models/ExpenseCategory.php` — `is_active` in `$fillable` and in `scopeSelectable()`.
- `e2e/tests/m35-grocery.spec.js` — new, **12 specs**: the quoted rate, both keypad directions, the g↔kg switch, a counted product needing no keypad, a weighed sale taking weight off the sack, a delivery blending cost, a credit sale opening a khata page, a spoiled write-off requiring a reason, and (added in M35-T8) the six payment methods, the udhaar dialog's customer search, and a blank-deposit credit sale landing as `pending`.
- `e2e/playwright.config.js`, `scripts/qa-milestone.mjs` — `M35` registered in `MILESTONE_ORDER` and `SPEC_BY_MILESTONE`. `package.json` — `qa:m35` shortcut.
- `backend/tests/Feature/CashEntryTest.php` — regression test for the retired-category bug.

Verified results:

1. `cd backend && php artisan test` → **365 passed** (1,702 assertions).
2. `npm test` → **70 passed**.
3. `npm run qa:m35` → **80 passed** (full M1→M35 regression, both dev servers live).
4. `npm run lint` → 32 modules OK.
5. `cd backend && ./vendor/bin/pint --test` → clean on all touched files.

## Known follow-ups (not fixed here)

- **`PurchasePolicy` has no `settle` method.** Purchase payments authorise with `view` + `create`, so the customer and supplier sides of the book are **not symmetric on permissions** — `SalePolicy` gates settlement explicitly, `PurchasePolicy` does not.
- **`CustomerController` makes no `ActivityLogger` calls**, so khata edits — including credit-limit changes, which directly control how much a customer can take on the book — leave no audit trail. Everything else that touches money or stock does.
- **`docs/README.md`'s M23 row still collides** with `docs/milestones/M23-pos-and-paddle.md` (two different milestones share the number; only the receipt-upload one is in the index table), and the file's header still advertises the removed tax calculator and the JazzCash/EasyPaisa PKR pricing.
