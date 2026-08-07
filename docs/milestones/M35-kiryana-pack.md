# M35 — Kiryana pack (weighed goods, purchases, profit & khata)

**Phase:** 8 (grocery domain) · **Depends on:** M23

**Goal:** Make the till usable in a real Pakistani kiryana shop — sell goods
by weight *and* by rupee amount, know what the stock cost, know what the day
actually earned, and keep track of who owes money.

## Why this milestone exists

The POS built in M23 assumed four things about a product: it is a countable
piece, its cost is known, it is paid for in full at the counter, and the total
is exact to the paisa. A kiryana shop breaks all four.

- Half the shop is scooped out of a sack. Every quantity in the system was an
  integer, so none of that could be rung up at all.
- Nothing ever wrote `unit_cost`, because stock only arrived through a manual
  adjustment. With no cost, profit reads as near-zero.
- `ReportAggregator` read `cash_entries` only — it contained **zero**
  references to sales. Since `DayBookService` replaced the per-sale
  `cash_entry` with a per-day float/close pair, reports could not see sales.
- Regulars buy on udhaar, and paisa coins do not circulate, so a paisa-precise
  total means the drawer never reconciles.

## Scope

- **T1 Weighed goods.** Price and stock stored per canonical **base** unit
  (`pc` / `g` / `ml`); `price_unit` (kg, litre, dozen) is display only. Till
  keypad works both directions: by weight ("ek pao daal" → 250 g → Rs 62.50)
  and by amount ("pachaas ka daal" → Rs 50 → 200 g).
- **T2 Purchases / Stock In.** Suppliers, purchase documents, and a
  weighted-average cost that finally populates `unit_cost`.
- **T3 Profit & cash-position reports.** One shared definition of margin
  (`App\Support\SaleProfit`) consumed by both the sales list and reports.
- **T4 Shop expense categories.** Retire the personal-finance leftovers
  (Car Wash, Petrol, Entertainment) without orphaning existing entries.
- **T5 Customer khata (udhaar).** Who-owes-me list, 30/60/90 aging, running
  statement, oldest-first payment allocation, credit limits.
- **T6 Wastage, expiry, pack size.** Typed wastage reasons valued in rupees,
  expiry tracking with an expiring-soon filter, carton→piece pack size.
- **T7 Whole-rupee settlement.** Round the total half-up and record the
  remainder instead of dropping it.
- **T8 Udhaar at the till.** All six settled-now payment methods, plus a
  separate "Save as Udhaar" button and customer dialog — without which the
  credit path built in T5 was unreachable from `pos.html` at all.
- **T9 Khata partial payments with history.** `received_by_name`, a payments
  array on the ledger, and a "Payments received" card showing what was still
  owed after each.
- **T10 Supplier invoice payments.** `purchase_payments`, a "What I owe
  suppliers" card, and a full-width delivery detail view — the mirror of the
  khata for the other side of the book.

## Second wave (after shop-owner testing)

T1–T7 were built and verified first. The shop owner then tested the app and
reported real bugs, and the root cause of two of them was a single gap:
`pos.html` exposed only **3** of the 7 supported payment methods and had **no
customer field and no paid-amount field at all**. The entire udhaar path — API,
service, policy, 28 passing tests — was **unreachable from the till**. Every
sale fell through the settled-in-full branch and was written `paid`, and a
khata customer could never have a sale attached to them.

That is the milestone's own lesson: a green backend suite says the API is
right, not that anyone can reach it. T8–T10 close the gap and extend it to the
supplier side.

## Tasks

See [M35 tasks](../tasks/M35-tasks.md).

## Exit criteria

- [x] A product priced per kg can be sold by weight and by rupee amount, and both give the same money
- [x] Stock arriving through Stock In sets `unit_cost` as a weighted average across old and new stock
- [x] `GET /api/reports/profit` and `/api/reports/cash-position` return figures that include POS sales
- [x] Revenue with no recorded cost is reported as *uncosted*, never counted as profit
- [x] A credit sale opens a khata page; payments clear the oldest debt first
- [x] Spoiled stock cannot be written off without a reason, and the loss is valued at cost
- [x] The till total is a whole rupee on screen and on the receipt, and the two never disagree
- [x] All six settled-now payment methods are selectable at the till, with a reference field for the non-cash ones
- [x] A sale can be put on the book from `pos.html`, with or without a deposit, and a blank deposit lands as `pending` — never `paid`
- [x] The khata records partial payments with who physically took the money, and shows what was still owed after each
- [x] A delivery can be part-paid, and "What I owe suppliers" is the working mirror of "who owes me"
- [x] `npm run qa:m35` passes the full M1→M35 regression

## Decisions worth keeping

- **Prices are stored per base unit, not per quoted unit.** Namak at
  Rs 40/kg is Rs 0.04/g, which collapses to zero at two decimal places, so
  `products.price` / `cost` / `sale_items.unit_price` widened to
  `decimal(12,4)`. No existing row changed value — a piece-priced product was
  already "per base unit", the base just now has a name.
- **Weighted average cost**, `((oldQty × oldCost) + (newQty × newCost)) ÷
  (oldQty + newQty)`. Ghee and atta move in price monthly and a shop is never
  selling only the newest sack, so last-cost would misprice most of what is on
  the shelf.
- **Uncosted revenue is reported, not guessed.** A `sale_item` with a null
  `unit_cost` has an *unknown* margin, not a zero one; folding it in would
  report the whole sale price as profit.
- **Wastage counts once.** It arrives from two ends — stock write-offs valued
  at cost, and hand-written wastage cash entries — so it is excluded from
  `expenses` in the P&L.
- **Retire, don't delete.** Personal-finance categories go inactive via
  `is_active` so the `cash_entries` already filed against them keep a name.
- **`count_correction` is deliberately not a wastage reason.** Goods that were
  never on the shelf were never paid for twice; counting them as loss would
  double-charge the shop for its own miscount.
- **Customer matching is conservative.** An exact phone number wins; a bare
  name may only claim a page that has no number on it yet. Two Alis with
  different numbers stay two people.
- **Stock still only moves through the audited path** (locked decision 8) —
  purchases post `stock_movements` rows like everything else.
- **Credit is not a payment method in the dropdown.** It gets its own button
  (`#pos-credit`, "Save as Udhaar"). Taking goods on the book is a different
  act from taking money, and must not be reachable by a mis-click.
- **The till never creates customers.** `SaleService::resolveCustomer` owns
  page resolution, so the till's matching rule and the khata screen's cannot
  drift apart.
- **`received_by_name` is not `recorded_by`.** The login that types a payment
  in and the person who physically took the cash are routinely different
  people, and only the second answers "who took my money" when a payment is
  disputed.
- **Paid totals are re-derived, never incremented.** Both `sales.amount_paid`
  and `purchases.amount_paid` come from `SUM(amount)` over the payment rows on
  every change, so the stored total cannot drift from the history it
  summarises. A deposit typed at Stock In therefore becomes a payment row.
- **The delivery detail view is a page, not a dialog.** `.pos-modal-card` caps
  at 520px, and this screen is read side by side with the supplier's paper
  bill.

## Bugs found and fixed

The most valuable part of this record. Bugs 1–5 were introduced or exposed by
the move to float quantities, 6 was pre-existing and unrelated, and 7–9 were
found in the second wave after the shop owner tested the app.

1. **Every refund filed as partial.** `SaleService::refund()` tested
   `remainingQuantity() === 0`. Once quantities are floats that is always
   false (`0.0 === 0` is `false` in PHP), so no sale could ever reach
   `refunded` — including a full refund of every line. Now `<= 0`.
2. **The audit trail truncated weights.** `ActivityLogger::stockAdjusted()`
   took `int $from, int $to`, silently logging a 1500.5 g adjustment as 1500.
   Now `float`, and it carries the wastage `reason` — "adjustment" on its own
   answers nothing about whether ten kilos were sold, spoiled or miscounted.
3. **A bogus movement on every new product.** `ProductController::store()`
   guarded on `$product->stock_quantity !== 0`. Under a decimal cast that
   compares `"0.000" !== 0`, which is always true, so every product created
   with no stock still wrote a zero-quantity `stock_movements` row.
4. **Retired categories were still offered.** `ExpenseCategory::scopeSelectable()`
   never checked `is_active`. **Unit tests could not catch this** — a fresh
   test database has no legacy rows, so the expected count was right either
   way. It only shows on a database that already had them, i.e. every real
   shop upgrading. Caught by the live Playwright run; the regression test in
   `CashEntryTest.php` now manufactures a legacy row first.
5. **Retiring a category did nothing.** `is_active` was missing from
   `ExpenseCategory::$fillable`, so the mass-assignment update dropped the
   attribute and the column default put the category straight back.
6. **Pre-existing, unrelated:** author `display` rules outrank the browser's
   `[hidden] { display: none }`, so `products.html`'s "Cancel edit" button was
   permanently visible. Fixed for `.btn`, `.auth-google` and `.admin-btn`.
7. **Validation stripped `received_by_name`.** `SaleService` read the field and
   `SaleController::store` passed its whole validated array through, but the
   rules block never listed it — so `$request->validate()` removed it first.
   The feature existed and did nothing. Fixed on both the sale-creation and the
   per-sale settle rule blocks; the test that had deliberately bypassed HTTP
   now goes through the real endpoint, which is what would have caught it.
8. **The customer hit list collapsed to zero height.** `.pos-modal-card` is a
   **column flex container**, so in a dialog taller than the card every row
   *shrinks* instead of the card scrolling. The list was still "visible" to the
   DOM, so Playwright clicked it and hit the search box underneath. Fixed with
   `display: block` on `.pos-udhaar-card`.
9. **`.pos-day-figures` sets `display: flex`, which defeats `[hidden]`** — the
   same class of bug as #6, already documented in `css/styles.css` at the
   `.btn[hidden]` block. Added `.pos-day-figures[hidden] { display: none; }`.

## Known follow-ups (not fixed here)

- `PurchasePolicy` has no `settle` method; purchase payments authorise with
  `view` + `create`, so the customer and supplier sides of the book are **not
  symmetric on permissions** — `SalePolicy` gates settlement explicitly.
- `CustomerController` makes no `ActivityLogger` calls, so khata edits —
  including credit-limit changes — leave no audit trail, unlike everything
  else that touches money or stock.
- `docs/README.md`'s M23 row still collides with `M23-pos-and-paddle.md`, and
  its header still advertises the removed tax calculator and the
  JazzCash/EasyPaisa PKR pricing.

## Status: done (2026-08-07)

All 10 tasks complete — see [M35 tasks](../tasks/M35-tasks.md) for the
completion log.

| Suite | Result |
|-------|--------|
| `cd backend && php artisan test` | **365 passed** (1,702 assertions) |
| `npm test` | **70 passed** |
| `npm run qa:m35` | **80 passed** (full M1→M35 regression, both dev servers live) |
| `npm run lint` | 32 modules OK |
| `cd backend && ./vendor/bin/pint --test` | clean on all touched files |
