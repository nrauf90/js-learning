# Sales history

## What it is

Every ticket the shop has rung up, what it took, what it made on it, and what is
still owed. It is also where a receipt gets reprinted, where a refund is worked
out, and where money is collected against one specific sale rather than against a
customer's whole khata.

## How it works

### The list

`GET /api/sales` is paginated (25 a page, max 100), newest first, tie-broken on
id. Filters: `period` (`today` / `week` / `month` / `custom` with `date_from` +
`date_to`), a single `date`, `category_id`, `payment_status` and `status`.

Week is **Monday–Sunday explicitly**, matching the weekly report and the stats
endpoint, so the same sale never lands in two different weeks depending on which
screen asked.

The category filter is an `EXISTS` rather than a join, so a ticket with three
matching lines is still one row in the list.

Each row carries `total`, `refunded_amount`, `paid_amount`, `outstanding_amount`,
the payment method, both statuses, the customer, an items count and a profit
object.

### Two statuses, deliberately

| column | tracks | values |
|---|---|---|
| `status` | the goods | `completed`, `partially_refunded`, `refunded` |
| `payment_status` | the money | `paid`, `partial`, `pending` |

A sale can be fully delivered and still unpaid, or refunded while a credit
balance is open. The list shows both as separate badges.

`Sale::resolvePaymentStatus()` is the single place the three payment statuses are
decided, so the till, the settle endpoint and refunds cannot disagree about what
"partial" means.

### Profit, and how honest it is

`App\Support\SaleProfit` holds the margin SQL in one place. The sales list, the
till's stats endpoint and the reports screen all answer "kitna bacha?" from the
same expressions rather than from copies that drift apart the first time one of
them is corrected — a shop shown two different profits for the same day trusts
neither.

A line with no `unit_cost` has an **unknown** margin, not a zero one. Folding
those lines in would quietly report the whole sale price as profit, so they are
excluded from the margin and reported separately:

```
profit = { amount, is_complete, unknown_cost_revenue }
```

`is_complete: false` means at least one line had no recorded cost, `amount` is the
margin on the rest, and `unknown_cost_revenue` is the takings it says nothing
about. The screen renders that honestly (`profitParts()` in `js/sales.js`):
"Unknown" when *nothing* had a cost, and an asterisked partial figure with a
tooltip otherwise. The asterisk carries the warning visually and a
screen-reader-only `.profit-note` repeats it, since a `title` attribute is never
announced.

Profit for a whole page of sales is one grouped aggregate, not one query per row.

### Stats tiles

`GET /api/sales/stats` returns takings, sale count and profit for five windows —
today, this week, this month, last month, this year — in **two** queries. Every
figure is a conditional `SUM` in SQL, so a year of sales is answered without
loading the year into memory, and the two queries are bounded by the narrowest
range covering every window so they scan the `(user_id, sold_at)` index.

`last_month` uses `subMonthNoOverflow()`: on the 31st, plain `subMonth()` would
land in the month before last for the short months.

Money handed back is not takings, so the total is `SUM(total - refunded_amount)`.
That needs no status filter — a fully refunded sale nets to zero on its own — but
the *count* does, or a refunded sale would still look like a sale.

### Refunds

`POST /api/sales/{sale}/refund` reverses the whole sale, or specific line
quantities when `items` is given. Quantities are in base units, so half a kilo of
the two kilos sold comes back as `500`.

Inside one transaction: each requested quantity is clamped to what is still
outstanding on that line, stock goes back on the shelf through the audited
`stock_movements` path (with the product row-locked), and `refunded_quantity` is
bumped on the line.

A ticket-level discount belongs proportionally to every line, so a partial refund
returns the **discounted** share, not list price:
`ratio = total / subtotal`. Any rounding drift from line-by-line refunds is
absorbed into the last one, so a fully refunded sale nets to exactly zero.

Goods that came back are no longer collectable, so `payment_status` is
recomputed — an open credit balance shrinks with the refund instead of chasing the
customer for stock they already returned.

The "return the lot" case carries a 0.0005 tolerance, because the till sends back
its own float and it need not match the stored decimal to the last bit. Refusal
messages spell the remainder in the unit the line was sold in — a cashier reads
"only 250 g left to refund", never "only 0.25".

Nothing is written to the cash ledger on a refund: individual sales no longer post
there, so there is no income entry to shrink. The drawer sees it through
[the day book](./day-book.md) instead, attributed to the day of the refund.

### Collecting against one sale

`POST /api/sales/{sale}/payments` records an instalment against a credit sale. The
sale is locked for the whole transaction — two cashiers settling the same debt at
two tills would otherwise both read the same outstanding balance, both pass the
over-payment check, and the customer would be charged twice for the same rupees.

`method` excludes `credit`: settling a debt with more credit moves no money.
Over-payment is refused with the exact balance named. The form is prefilled with
the full balance, since settling in full is the common case and it is also the
largest amount the API will accept.

### The reprint

Opening a row shows the same thermal slip `js/receipt.js` printed at the counter,
with `showPayments: true` and a "Sales Receipt (Copy)" label — a reprint that does
not match the paper the customer is holding is worse than no reprint at all. The
roll width comes from the same persisted setting the till uses. See
[receipts-printing.md](./receipts-printing.md).

## Screens / files

| Layer | File |
|---|---|
| Page | `sales.html` |
| Controller | `js/sales.js` |
| Slip | `js/receipt.js` |
| API | `backend/app/Http/Controllers/Api/SaleController.php` |
| Services | `backend/app/Services/Pos/SaleService.php` (refunds), `SalePaymentService.php` (settlement) |
| Margin SQL | `backend/app/Support/SaleProfit.php` |
| Models | `Sale`, `SaleItem`, `SalePayment` |
| Policy | `backend/app/Policies/SalePolicy.php` |
| Migrations | `2026_08_06_110002_create_sales_table.php`, `..._110003_create_sale_items_table.php`, `2026_08_06_120000_add_offline_and_refund_fields_to_sales_table.php`, `2026_08_07_100003_add_credit_fields_to_sales_table.php` |
| Tests | `backend/tests/Feature/PosTest.php`, `SaleReportingTest.php` |

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/sales` | Paginated, filtered list with per-sale profit |
| POST | `/api/sales` | Ring up a sale — see [pos-till.md](./pos-till.md) |
| GET | `/api/sales/today` | Counter summary: count, takings, refunds, outstanding, split by method |
| GET | `/api/sales/stats` | Five reporting windows with takings and profit |
| GET | `/api/sales/{sale}` | One sale with lines and payments |
| POST | `/api/sales/{sale}/refund` | Whole or partial refund |
| POST | `/api/sales/{sale}/payments` | Instalment against this sale |

`/sales/today` and `/sales/stats` are registered **above** `/sales/{sale}` in
`routes/api.php`, or model binding would swallow them.

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate.
- `SalePolicy` scopes `view`, `refund` and `settle` on `dataOwnerId()`: sales
  belong to the shop owner, so staff reach their own shop's takings and nobody
  reaches another shop's. Comparing against `id` would hide every sale from the
  staff who rang it up.
- `viewAny` and `create` are open — every account can work the till and read the
  shop's own sales.
- There is **no** extra permission on refunding. Any account that can sell can
  also refund.

## Edge cases & known limits

- **`GET /api/sales/{sale}` has no `payment_reference`-based lookup** and no
  search by customer name; filtering is by period, category and status only.
- **A refunded sale cannot be re-refunded**, but a *partially* refunded one can be
  topped up until every line is exhausted.
- **`remainingQuantity()` rounds at `QUANTITY_DP`** so a fully refunded line lands
  on exactly zero — the check that tells a sale it is fully refunded. Before that
  rounding existed, `=== 0` was always false for floats and no sale could ever
  reach `refunded`.
- **The sales list shows no cashier.** `sale_payments.recorded_by` records who
  took an instalment, but the sale header does not record who rang it up.
- **`sales.cash_entry_id` still exists** and is always null in current code. It is
  a leftover from when each sale posted an income `cash_entry`; the day book
  replaced that with a per-day float/close pair.
- **`sales.paid_amount` is incremented, not re-derived** from `sale_payments`
  (unlike `purchases.amount_paid`). It is written under a row lock, so it cannot
  race, but it is not self-correcting if a payment row is ever removed by hand.
- **No export.** The sales list cannot be downloaded; only the reports screen has
  a PDF export.
- **Sales cannot be deleted or edited** — refund is the only mutation.
