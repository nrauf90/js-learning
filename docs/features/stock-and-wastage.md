# Stock movements and wastage

## What it is

The audited ledger behind every count in the shop. Nothing changes
`products.stock_quantity` without leaving a row here saying what moved, why, and
what the balance was afterwards — so "where did ten kilos of atta go" always has
an answer.

It is also where wastage becomes a number. Sabzi wilts, doodh turns and bread goes
hard; every shop throws something away most days, and until this existed that loss
left the shelf without leaving a figure anywhere.

## How it works

### The four ways stock moves

| path | `type` | who |
|---|---|---|
| A sale | `sale` | `SaleService::create()` |
| A refund | `refund` | `SaleService::refund()` |
| A delivery booked in | `restock` | `PurchaseService::receive()` |
| A manual correction | `restock` or `adjustment` | `POST /api/products/{id}/stock` |
| Opening stock on a new product | `initial` | `ProductController::store()` |

`PUT /api/products/{id}` deliberately drops `stock_quantity`, so there is no fifth
path. This is one of the app's locked architecture decisions.

### The row

```
user_id, product_id, sale_id?, type, reason?, quantity_delta, balance_after, cost_value?, note?
```

`quantity_delta` is **signed** — negative for sales and write-offs, positive for
restocks — and both it and `balance_after` are `decimal(12,3)` in base units.

`balance_after` is denormalised on purpose: the history can then be read back
without replaying every prior movement, and *the reason a count drifted* is
usually the question, which needs the running balance.

Every write rounds at `Unit::QUANTITY_DP` rather than only on display. A busy shop
puts thousands of decimal deltas through this line, and unrounded float addition
would leave the count creeping away from the shelf with nothing to pull it back.

`sale_id` is `nullOnDelete`, so deleting a sale (which nothing does today) would
leave the movement intact.

### Typed reasons

`StockMovement::REASONS`:

| reason | counts as wastage? |
|---|---|
| `damaged` | yes |
| `expired` | yes |
| `spoiled` | yes |
| `theft` | yes |
| `sample` (given away or used in the shop) | yes |
| `count_correction` | **no** |

Typed rather than free text because the note cannot be totalled: "kharab ho gaya",
"gir gaya" and "kharab" are the same loss written three ways, and the whole point
of asking is to be able to add the year up by cause.

`count_correction` is excluded from `WASTAGE_REASONS` deliberately. A recount is
not a loss — the goods were never there, so the shop is only correcting its own
book, and totalling it as wastage would charge the same rupees twice.

A reason is **required whenever the delta is negative** and not asked for when it
is positive. Stock leaving without a sale is either a loss or a bad count, and a
shopkeeper cannot tell those apart at the end of the month from a free-text note.
Adding stock needs no excuse — a delivery explains itself, and asking would only
slow the one path that is always benign.

The dropdown opens on a blank "Choose one…" rather than a pre-selected reason:
defaulting to "Damaged" would file every hurried write-off under the wrong cause,
and a wastage report is only worth reading if the causes are true.

### `cost_value`

Frozen at the moment of the write:

```php
StockMovement::valueAtCost($delta, $product->cost)  // round(abs($delta) * $cost, 2)
```

Reading it back off the product later would revalue last month's spoilage at this
month's cost, and the P&L would move every time a supplier raised a price.

It is **unsigned** — the direction already lives in `quantity_delta`, and a
wastage report reads "Rs 4,300 thrown away this month", never minus that. A null
cost yields a null `cost_value`: inventing a zero would report a free loss rather
than an unknown one, and the profit report counts those separately as
`wastage_uncosted`.

### The write itself

`ProductController::adjustStock()` wraps both writes in **one** transaction so the
product stays row-locked while the movement is labelled. `SaleService::adjustStock()`
owns the audited write and knows nothing about wastage; without the outer lock a
sale on the same product could slip its own movement in between, and the reason
would be stamped on the wrong row.

The delta is quoted in the product's own `price_unit` — "2" against atta priced by
the kilo is two kilos off the sack, not two grams — and converted before it
touches the count. `not_in:0` only catches a literal zero, so a figure smaller
than one base unit is caught separately with "That amount is too small to change
the count."

An adjustment that would take stock below zero is refused, rounded before the test
so writing off the exact remaining stock is not read as a hair below zero.

The activity log records the same event with the **person** attached, which
`stock_movements` has no column for. See [activity-log.md](./activity-log.md).

### The stock dialog prices the loss live

`renderStockPreview()` in `js/products.js` shows what the adjustment does in both
stock and rupees as it is typed: "Stock becomes 12 kg — Rs 1,800 off the shelf at
cost." The money is the point. A shopkeeper writing off "3" has no feel for what
that costs, and wastage only ever gets looked at once somebody can see it adding
up.

The reason field is hidden entirely when the delta is positive.

### Where wastage surfaces

`GET /api/reports/profit` sums `cost_value` over `WASTAGE_REASONS` in the period
**plus** any `cash_entries` filed under the `wastage` expense category, and
excludes both from the general `expenses` line — a shop that writes off "Rs 500 of
sabzi" as a cash expense means exactly the same thing, and the loss should appear
once however it was recorded. See [reports.md](./reports.md).

## Screens / files

| Layer | File |
|---|---|
| Stock dialog | `products.html` `#stock-modal`; `js/products.js` — `openStockDialog()`, `renderStockPreview()`, `submitStock()` |
| API | `ProductController::adjustStock()` |
| Services | `SaleService::applyStockDelta()` / `adjustStock()`, `PurchaseService::receive()` |
| Model | `backend/app/Models/StockMovement.php` |
| Migrations | `2026_08_06_110004_create_stock_movements_table.php`, `2026_08_07_110000_add_units_of_sale_to_catalogue.php` (decimal widening), `2026_08_08_100003_add_wastage_expiry_and_pack_size_to_catalogue.php` (reason + cost_value) |
| Tests | `backend/tests/Feature/WastageTest.php`, `PosTest.php`, `PurchaseTest.php` |

## API endpoints

| Method | Path | What it does |
|---|---|---|
| POST | `/api/products/{product}/stock` | `quantity_delta` (price unit, non-zero), `type` (`restock` \| `adjustment`), `reason` (required if negative), `note` |

Every other movement is a side effect of a sale, refund or purchase.

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate.
- The adjust endpoint authorises on `ProductPolicy::update`, so it needs the
  catalogue permission — a till-only cashier cannot write stock off.
- Sales and refunds move stock without that permission, because they go through
  `SalePolicy` instead.

## Edge cases & known limits

- **There is no screen for the movement history.** The table is written on every
  change and read by the profit report, but no page lists a product's movements —
  the running balance the schema goes out of its way to preserve is not visible
  anywhere in the UI. The activity log shows *catalogue* changes, which is a
  different (and smaller) set.
- **No API to read movements either.** There is no `GET /api/stock-movements`.
- **`cost_value` is only set by the adjust endpoint.** Sales, refunds, restocks and
  the opening movement all leave it null, so the column answers "what did we throw
  away" and nothing else.
- **Wastage can be double-counted** if a shop both adjusts stock and files a cash
  expense for the same spoiled goods; the report simply adds the two sources.
- **A write-off on a product with no cost price is silently worth zero.** Only the
  `wastage_uncosted` count in the profit report says the figure is short.
- **Offline sales can push a count negative** and no movement type marks that as
  exceptional. See [offline-sync.md](./offline-sync.md).
- **`type` and `reason` are independent.** A negative `adjustment` always carries a
  reason; a `restock` never does, even a negative one is impossible by validation.
- Deleting a product cascades its movements away (`product_id` is
  `cascadeOnDelete`), so the history does **not** survive the product — unlike sale
  and purchase lines, which keep their snapshot.
