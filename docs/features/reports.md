# Reports

## What it is

Five views of the shop's money, on one screen with a mode switcher:

| mode | question it answers |
|---|---|
| Weekly / Monthly / Yearly | what came in and went out of the cash ledger |
| Profit | "Aaj kitna kamaya?" — the trading account |
| Cash position | "Paisa kahan hai?" — drawer, udhaar, or stock on the shelf |

Any of them can be exported to PDF.

## How it works

### The cash-ledger views

`/api/reports/weekly|monthly|yearly` all funnel into `ReportAggregator::aggregate()`,
which reads **`cash_entries` only**. Three grouped SQL aggregates — totals by type,
totals by category, totals by day — rather than pulling every matching row into
PHP, so a user's whole history no longer has to be loaded into memory.

Weeks run Monday–Sunday, matching the sales list and the till's stats endpoint.

The response carries `total_income`, `total_expense`, `net`,
`income_by_category`, `expense_by_category`, a legacy `by_category` alias (the
expense rows, kept for older callers) and `by_day`.

Because sales no longer post individual `cash_entries` rows, what shows up here
from the till is the day book's **float and closing count**, not the takings. See
[day-book.md](./day-book.md).

### Profit & loss

`GET /api/reports/profit` is the trading account as a shopkeeper reads it:

```
Sales (net of refunds)
− Cost of goods sold
= Gross profit
− Wastage
− Expenses
= Net profit
```

Sales come from the **sale headers** (`SUM(total - refunded_amount)`), so this line
is the same money the sales list and the till's stats endpoint report and the
owner can reconcile it against the drawer. Cost of goods sold comes from the
**lines**, where the cost actually lives.

The two only subtract cleanly once uncosted takings are removed:

```
gross_profit = sales_net − unknown_cost_revenue − cogs
```

so the statement reads `sales − cogs = gross profit` exactly whenever every line
has a cost, and the shortfall is spelled out rather than booked as profit whenever
one does not. A line with a null `unit_cost` has an *unknown* margin, not a zero
one — see [sales-history.md](./sales-history.md) and `App\Support\SaleProfit`.

`gross_margin_pct` is measured against **every** rupee taken, not just the costed
ones: a shop with half its cost prices missing should see the margin sag until it
fills them in, rather than a flattering number drawn from the half it happens to
have recorded.

Ticket discounts need no special handling — `sales.total` is already net of them,
so a discount comes out of the margin, which is where it comes from in real life.

**Wastage** sits below the gross line with the overheads, not inside cost of goods
*sold*. It is summed from two places at once — `stock_movements.cost_value` for
stock written off the shelf, and `cash_entries` filed under the `wastage`
category — and neither is left in `expenses`, so the loss appears once however the
shop happened to record it. A `count_correction` is deliberately excluded: the
goods were never there, so the shop is only correcting its own book.

**Expenses** excludes `till-float`, `till-close` and `wastage`. Counting the float
as an expense would wipe out a day of profit every day.

Two breakdowns come with it. `by_product` is the top 10 sellers ranked by
**takings rather than units** — half the shelf is sold by weight, so "3000" of atta
and "3" of cola are not comparable quantities, while rupees always are — grouped by
product id *and* the snapshotted line name, so a deleted product still reports
under the name it was sold as instead of collapsing into one nameless row.
`by_category` is the same margin rolled up the way the shop is laid out, via a
**left** join so a line whose product was deleted keeps its takings in the total,
under "Uncategorised".

Every breakdown row carries `has_unknown_cost`, and the screen prints "cost not
recorded" instead of a margin percentage when it is set.

### Cash position

`GET /api/reports/cash-position` is deliberately a different question from profit.
A shop can trade at a healthy margin all month and still have an empty drawer
because the takings are sitting in udhaar or on the shelf. The three figures are
the three places the money can be:

| figure | source |
|---|---|
| `cash_in_drawer` | today's opening float + `DayBookService::cashPosition()` net — the till's own figure, not a second implementation of it |
| `receivable` | `SUM(total − refunded_amount − paid_amount)` over sales where that is positive and `payment_status <> 'paid'` |
| `stock_at_cost` | `SUM(stock_quantity × cost)` over tracked products, **including inactive ones** — taking a line off the till screen does not take the sacks off the shelf |

The drawer, the debts and the stock are all **as of now** — a balance has no date
range, only a moment. Only the takings split (`by_payment_method`) honours the
requested period, because that is a flow and not a balance.

`stock_uncosted_products` counts products in stock with no cost price, because the
valuation is only as complete as the cost prices behind it. The screen prints a
warning line when it is non-zero, and the profit view does the same for
`unknown_cost_lines`.

Every payment method is listed with a zero rather than omitted, so the shape does
not change with the day and the UI never has to guess what is missing.

### Period handling

`/reports/profit` and `/reports/cash-position` accept **four** shapes, so the same
endpoints answer both the from/to picker and the weekly/monthly/yearly buttons the
page has always had:

- `from` + `to` (both required together)
- `start` → the Monday–Sunday week containing it
- `year` (+ optional `month`)
- nothing at all → this month, which is what an owner opening the screen cold is
  asking about

`sold_at` is a timestamp while reports are asked for in whole days, so the range is
widened to `00:00:00`–`23:59:59`: a sale rung up at 8pm on the closing date belongs
to the period the owner asked for.

### PDF export

`downloadPdf()` builds a jsPDF document from `pdfPlan()`, which is assembled from
**the rendered rows** — the same `pnlRows()` / `cashRows()` / `marginRow()` helpers
the screen uses — so the export cannot say something the screen does not. Charts
are captured off the live canvases with `toDataURL()`; a capture failure skips the
image rather than aborting the export.

Charts are Chart.js doughnuts, rebuilt from scratch on every load because Chart.js
keeps a live handle on the canvas and a second chart on the same one renders on
top of the first. They also re-render when the theme is toggled, since their text
colour is read from a CSS custom property.

## Screens / files

| Layer | File |
|---|---|
| Page | `reports.html` |
| Controller | `js/reports.js` |
| API | `backend/app/Http/Controllers/Api/ReportController.php` |
| Aggregation | `backend/app/Services/ReportAggregator.php` |
| Margin SQL | `backend/app/Support/SaleProfit.php` |
| Drawer figure | `backend/app/Services/Pos/DayBookService.php` |
| Tests | `backend/tests/Feature/ReportTest.php`, `ProfitReportTest.php`, `SaleReportingTest.php` |
| E2E | `e2e/tests/m4-reports.spec.js` |

Chart.js and jsPDF both load from a CDN in `reports.html`; the PDF button reports a
clear message if jsPDF failed to load.

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/reports/weekly?start=` | Cash ledger for the Monday–Sunday week containing `start` |
| GET | `/api/reports/monthly?year=&month=` | Cash ledger for a month |
| GET | `/api/reports/yearly?year=` | Cash ledger for a year |
| GET | `/api/reports/profit` | Trading account + top sellers + by category |
| GET | `/api/reports/cash-position` | Drawer, receivable, stock, takings by method |

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate.
- The ledger views authorise on `CashEntryPolicy::viewAny` and read
  **`$request->user()->id`** — a staff member sees their own manual entries.
- Profit and cash position authorise on `SalePolicy::viewAny` and read
  **`dataOwnerId()`** — staff see their shop owner's takings, not their own empty
  ledger.

That split is a real inconsistency, inherited from cash entries being per-account
while everything else is per-shop. See [cash-flow.md](./cash-flow.md).

## Edge cases & known limits

- **The ledger views cannot see the shop's takings.** They read `cash_entries`
  only, and a sale no longer writes one — so "income" there is the day book's
  closing count, not the day's sales. The profit view is the one that reads
  `sale_items`.
- **`by_category` is duplicated** in the ledger response as both
  `expense_by_category` and the older `by_category` alias.
- **Wastage double-counting is prevented, not detected.** A shop that both adjusts
  stock *and* files a cash expense for the same spoiled sabzi will have it counted
  twice, because the two sources are simply added.
- **`stock_at_cost` silently under-reports** when products have no cost price;
  `stock_uncosted_products` is the only signal, and `wastage_uncosted` plays the
  same role for write-offs with no cost.
- **No comparisons.** There is no month-over-month or year-over-year view
  (backlog milestone M24).
- **Top sellers is capped at 10** and is not configurable.
- **PDF only** — no CSV or Excel export (backlog M18).
- No cashier, supplier or customer dimension: the profit view breaks down by
  product and by category only.
