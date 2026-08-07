# Products — the catalogue

## What it is

Everything the shop sells: name, price, what it cost, how much is on the shelf,
what unit it is sold in, whether it goes off, and how it is bought in. This is
where the till gets its tiles from, and where the cost prices that make every
profit figure meaningful actually live.

## How it works

### A product row

| Column | Notes |
|---|---|
| `user_id` | the **shop owner**, not the person who typed it — see `dataOwnerId()` |
| `product_category_id` | nullable, `nullOnDelete` |
| `name` | max 160 |
| `unit_type` | `each` \| `weight` \| `volume` |
| `base_unit` | `pc` \| `g` \| `ml` — derived from `unit_type`, never taken from the request |
| `price_unit` | `pc` \| `dozen` \| `g` \| `kg` \| `ml` \| `l` — display and data entry only |
| `sku`, `barcode` | optional, unique **per shop** (`(user_id, sku)` / `(user_id, barcode)`) so two shops may use the same code; NULLs are exempt |
| `image_path` | relative path on the `public` disk — see [catalogue-images.md](./catalogue-images.md) |
| `price` | per **base** unit, `decimal(12,4)` |
| `cost` | per base unit, nullable — null means the shop never recorded what it paid |
| `track_stock` | off means sellable without a count |
| `stock_quantity`, `low_stock_threshold` | base units, `decimal(12,3)` |
| `pack_size`, `pack_label` | base units + a word ("Peti", "Bori") |
| `expiry_date`, `track_expiry` | the date on the packet and whether to warn about it |
| `is_active` | off hides it from the till without deleting it |

### Everything is typed in the price unit

The form is filled in the unit the shop quotes in; everything underneath the API
works in base units. `ProductController::toBaseUnits()` converts once, on the way
in — price, cost, opening stock, low-stock threshold and pack size all divide (or
multiply) by the unit's factor there. Converting in the browser as well would be
a second opinion about the same number and the two would drift.

The form labels rewrite themselves as the price unit changes — "Price (PKR per
kg)", "Opening stock (kg)", "Pack size (kg in one pack)" — because "Price 250" and
"Opening stock 12.5" are both ambiguous otherwise, and the shopkeeper has no way
to tell that the till is holding the figures per gram underneath. See
[units-and-money.md](./units-and-money.md) for why.

The payload sends both figures back: `price` (per base) and `display_price` (per
quoted unit), and likewise for cost, stock and pack size. `unit_price_label`
("Rs 250 / kg") and `stock_label` ("1.5 kg") are the API's own words, and the
screens print those rather than reconstructing them.

### Stock only moves through an audited path

`PUT /api/products/{id}` **deliberately ignores `stock_quantity`**
(`unset($validated['stock_quantity'])`). The only ways a count moves are:

- a sale or refund (`SaleService`),
- a delivery booked in (`PurchaseService` — see
  [purchases-stock-in.md](./purchases-stock-in.md)),
- the adjust endpoint (`POST /api/products/{id}/stock`).

Each writes a `stock_movements` row carrying the signed delta **and the running
balance after it**, so the history can be read back without replaying every prior
movement — the reason a count drifted is usually the question, and that needs the
running balance. See [stock-and-wastage.md](./stock-and-wastage.md).

Opening stock is the exception, and it is still audited: creating a product with
a non-zero count files an `initial` movement labelled "Opening stock", so the
history explains the current balance from the very first row rather than starting
mid-air. The comparison is `(float) $product->stock_quantity !== 0.0` — stock is a
decimal now, and `"0.000" !== 0` in PHP, so a strict comparison filed a bogus
movement for every new product.

### Wastage, expiry and pack size

Three things a Pakistani grocery needs that a generic catalogue does not:

**Wastage.** Sabzi wilts, doodh turns, bread goes hard. The stock dialog asks
*why* whenever the delta is negative, from a fixed list —
`damaged`, `expired`, `spoiled`, `theft`, `sample`, `count_correction` — because
the whole point of asking is to be able to add the year up by cause, and "kharab
ho gaya", "spoiled" and "bad" are three spellings of one number. Adding stock
needs no excuse; a delivery explains itself. The dialog prices the loss live as
it is typed, because a shopkeeper writing off "3" has no feel for what that costs.

`count_correction` is deliberately **not** counted as wastage
(`StockMovement::WASTAGE_REASONS` omits it): goods that were never on the shelf
were never paid for twice.

**Expiry.** A date plus a `track_expiry` flag, off by default — most of the shelf
has no date worth chasing, and a warning shown on everything is a warning the
shopkeeper learns to ignore. Typing a date *is* the request to be warned about
it, so both the browser and `toBaseUnits()` turn the flag on when a date is
entered and the flag was not sent. `EXPIRY_SOON_DAYS = 30` is used for both the
badge and the default window of the `expiring` filter, because a shelf that warns
at 30 days and lists at 7 teaches the shopkeeper that the two disagree. A packet
dated the 10th is good *all day* on the 10th — `isExpired()` is `days < 0`.

**Pack size.** A peti of 24 Coke is bought whole and sold one bottle at a time.
`pack_size` is held in base units like every other quantity, so the peti of 24 and
the 50 kg bori are the same column, and it is typed in the quoted unit ("2"
against a dozen price, "24" against a piece price — both land on 24 pieces). It
is a **buying** fact only: nothing prices off it, so a wrong pack size can never
mis-charge a customer. `packLabelText()` renders "1 Peti = 24 pc", and returns
null for a pack of one — printing "1 Pack = 1 pc" under most of the catalogue
would be a line of noise.

### Filters

`GET /api/products` supports `search` (exact barcode/SKU first, then a name
`LIKE`), `category_id`, `active`, `low_stock` and `expiring` (+ `expiring_days`).
`low_stock` requires `track_stock` and a threshold above zero. `expiring` sweeps
up what has already gone off as well, because a bottle that expired last week is
the most urgent thing on the shelf.

The catalogue screen exposes search, a low-stock toggle and an expiring toggle,
and pages the list 25 at a time (the API defaults to 50 and caps at 200). Deleting
the last product on a page steps back rather than leaving an empty one. Changing a
filter or the search term resets to page 1.

The till does **not** page: it holds the whole active catalogue in memory so
search never touches the network. See [pos-till.md](./pos-till.md).

### Deleting

`DELETE /api/products/{id}` is a hard delete of the row, plus its image. Past
`sale_items` and `purchase_items` keep their name/price snapshot and simply lose
`product_id`, so receipts and reports survive.

## Screens / files

| Layer | File |
|---|---|
| Page | `products.html` |
| Controller | `js/products.js` |
| Units | `js/units.js` |
| API | `backend/app/Http/Controllers/Api/ProductController.php` |
| Services | `SaleService::adjustStock()`, `CatalogImageStore`, `ActivityLogger` |
| Models | `Product`, `StockMovement`, `ProductCategory` |
| Policy | `backend/app/Policies/ProductPolicy.php` |
| Migrations | `2026_08_06_110001_create_products_table.php`, `2026_08_07_100002_add_image_path_to_products_and_categories.php`, `2026_08_07_110000_add_units_of_sale_to_catalogue.php`, `2026_08_08_100003_add_wastage_expiry_and_pack_size_to_catalogue.php` |
| Tests | `backend/tests/Feature/PosTest.php`, `WeighedGoodsTest.php`, `WastageTest.php`, `CatalogImageTest.php` |
| Demo data | `backend/database/seeders/GroceryCatalogSeeder.php` — 23 categories, 202 products with images. Deliberately **not** called by `DatabaseSeeder`; run it explicitly. |

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/products` | Paginated list with filters |
| POST | `/api/products` | Create (accepts `stock_quantity` as opening stock) |
| GET | `/api/products/lookup?code=` | Exact SKU/barcode match, active only; 404 on a miss |
| GET | `/api/products/{product}` | One product |
| PUT | `/api/products/{product}` | Update; `stock_quantity` is ignored |
| DELETE | `/api/products/{product}` | Delete the row and its image |
| POST | `/api/products/{product}/stock` | Audited stock adjustment |
| POST | `/api/products/{product}/image` | Multipart picture upload |
| DELETE | `/api/products/{product}/image` | Remove the picture |

`POST /api/products/{id}/stock` body: `quantity_delta` (in the product's price
unit, non-zero), `type` (`restock` \| `adjustment`), `reason` (required when the
delta is negative), `note`.

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate on everything.
- `ProductPolicy::viewAny()` / `view()` are open — a cashier without the
  catalogue permission still has to find things to sell.
- `create` / `update` / `delete` require the catalogue permission, phrased as
  "everyone except ungranted staff" (`! $user->isStaff() || $user->canManageCatalogue()`)
  rather than a straight `canManageCatalogue()` call: `role` carries a
  database-level default, so a `User` instance created in-process and never read
  back has a null role, which would otherwise read as "not a shop admin" and lock
  an owner out of their own products.
- Ownership matches `dataOwnerId()` **or** the caller's own id — the second is for
  rows created before the account joined a shop.
- Assigning a category re-checks that the category belongs to the same shop;
  `exists:product_categories,id` alone would let one shop file products under
  another shop's category and leak its name back through the product list.
- Every create, update, delete and stock adjustment writes an
  [activity log](./activity-log.md) row.

## Edge cases & known limits

- **Changing `unit_type` forces the price to be restated.** `price` is
  `required_with:unit_type` on update, because Rs 250 a packet is not Rs 250 a
  kilo. Cost, stock and thresholds are *not* forced, so a unit change without a
  cost change leaves the old cost reinterpreted under the new unit.
- **An adjustment smaller than one base unit is refused.** `not_in:0` only
  catches a literal zero; after conversion a tiny delta rounds away and would
  file a movement that moved no stock.
- **An adjustment cannot take stock below zero** through this endpoint, but a
  sale replayed from the offline queue deliberately can.
- **`cost` has no history.** It is a single running weighted average maintained
  by `PurchaseService`; there is no record of what it was last month other than
  the `unit_cost` frozen on each sale line.
- **A product with no `cost` sells fine** — the sale line's `unit_cost` is null
  and the profit for that line is reported as *unknown*, never as the whole sale
  price. See [reports.md](./reports.md).
- **Untracked products (`track_stock` off)** are always sellable, never appear in
  the low-stock filter, and are excluded from the stock valuation in the
  cash-position report.
- **Search and filters run server-side** on the catalogue screen but **in memory**
  on the till, which holds the whole active catalogue.
- Deleting a product is permanent — there is no trash or restore (that is
  backlog milestone M22).
