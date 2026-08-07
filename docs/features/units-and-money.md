# Units and money

## What it is

Half a kiryana shop is weighed out of a sack — atta, chawal, daal, chini, ghee,
sabzi — and the other half is counted off a shelf. A customer asks for "ek pao
daal" (a quarter kilo) as often as they ask for "pachaas ka daal" (fifty rupees
of it). This is the rule set that lets both questions be answered exactly, in the
words the shopkeeper already uses, without the arithmetic drifting by a paisa.

It also settles two money questions that run through the whole app: shop money is
**PKR** and subscription money is **USD**, and a till ticket is charged in **whole
rupees**, because paisa coins have not circulated in Pakistan for years.

## How it works

### Base units

Every price and every quantity is stored in one canonical **base unit** per kind
of goods:

| `unit_type` | base unit | what it covers |
|---|---|---|
| `each` | `pc` | packets, bottles, soap, batteries |
| `weight` | `g` | atta, daal, chini, sabzi |
| `volume` | `ml` | oil, milk, ghee |

`price_unit` — `pc`, `dozen`, `g`, `kg`, `ml`, `l` — is **display and data-entry
only**. It is the unit the shopkeeper quotes in ("Rs 250 per kg"), and it is
converted on the way in and back out again on the way to the screen. `base_unit`
is derived from `unit_type` on the server and is never taken from the request:
a client that got it wrong would restate the value of everything on the shelf.

Each unit carries a `factor` — how many base units one of it holds. `kg` is
1000 g, `dozen` is 12 pc, `l` is 1000 ml.

### Why per-gram and not per-kilo

Storing the price per **base** unit is what makes both counter questions exact:

```
"Ek pao daal"     → 250 g × Rs 0.25/g = Rs 62.50
"Pachaas ka daal" → Rs 50 ÷ Rs 0.25/g = 200 g
```

Holding Rs 250/kg and dividing instead loses paisa on every cheap-per-gram line.
Namak at Rs 40/kg is Rs 0.04/g, which rounds to **zero** at two decimal places —
so the money columns were widened when this landed:

| constant | value | why |
|---|---|---|
| `Unit::QUANTITY_DP` | 3 | a milligram on a gram scale — finer than any shop scale, and enough for "Rs 50 of daal" to divide out without the money drifting |
| `Unit::PRICE_DP` | 4 | Rs 40/kg is Rs 0.04/g |

Products, sale items, purchase items and stock movements all use `decimal(12, …)`
sized from these two constants, and the Eloquent casts are tied to the same
constants so a cast cannot quietly round tighter than the schema stores.

### Which price units are offered

`Unit::codesForType()` restricts the price unit to the kind of goods. Quoting
daal in litres is a slip of the finger, not a business decision, so the API
rejects it. `ProductController` re-derives the allowed set from whatever
`unit_type` the request leaves the product on, so a plain price edit is still
checked against the product's own kind.

Changing `unit_type` on an existing product **requires the price to be restated**
(`price` is `required_with:unit_type` on update) — Rs 250 a packet is not Rs 250
a kilo, and rereading the old figure underneath the new unit would silently
reprice the shelf.

### Whole-rupee settlement

`SaleService::create()` computes the exact total, rounds it half-up to a whole
rupee, and records the difference in `sales.rounding_adjustment` (a signed
`decimal(6,2)` — rounding can only ever move a ticket by less than a rupee):

```
$exactTotal = round($subtotal - $discount, 2);
$total      = round($exactTotal);
$rounding   = round($total - $exactTotal, 2);
```

`js/cart.js`'s `computeTotals()` does the identical thing in the browser
(`Math.round` is half-up and matches PHP's `round()`), which is what stops the
till screen and the saved sale disagreeing by a rupee. The remainder is recorded
rather than dropped, so a shopkeeper checking the arithmetic on a receipt does
not find a gap with no name.

`money()` in `cart.js` nudges by `Number.EPSILON` before rounding: prices like
1.005 are stored as 1.00499… and both `toFixed(2)` and a plain
`Math.round(n * 100) / 100` round them *down*, under-charging by a paisa on every
such line.

### Two currencies, deliberately

| money | currency | formatted by |
|---|---|---|
| product prices, sales, cash entries, khata, purchases | PKR | `formatRs()` in `js/pos.js`, `js/products.js`, `js/sales.js`, `js/customers.js`, `js/purchases.js`, `js/reports.js`, `js/cashflow.js`; `receiptNum()` in `js/receipt.js` |
| subscription plans, payments, admin revenue | USD (`config('billing.currency')`) | `formatMoney()` in `js/billing.js`, `js/admin.js`, `js/admin-payments.js` |

Paddle cannot charge PKR, which is the whole reason for the split. The two are
never formatted with the same helper.

### Display helpers

Anything a shopkeeper reads is spelled in the unit they say out loud, not the
unit stored:

- `Unit::formatQuantity(1500, 'weight')` → `"1.5 kg"`; below 1000 it stays in
  grams. Counted goods carry **no** suffix — "10 in stock", not "10 pc in stock".
- `Unit::formatUnitPrice(0.25, 'kg')` → `"Rs 250 / kg"`.
- The product payload carries both figures: `price` (per base unit, what the till
  multiplies by) and `display_price` (what the shopkeeper is shown and types).
  Same split for `cost`/`display_cost`, `stock_quantity`/`display_stock`, and
  `pack_size`/`display_pack_size`.

Sale and purchase lines **snapshot** `unit_type`, `base_unit` and `price_unit`, so
a receipt reprinted next year still reads "250 g" even if the product has since
been switched to selling by the packet.

## Screens / files

| Layer | File |
|---|---|
| Server unit table | `backend/app/Support/Unit.php` |
| Browser unit table | `js/units.js` (kept in step by hand — see below) |
| Ticket arithmetic + rounding | `js/cart.js` |
| Conversion on write | `ProductController::toBaseUnits()`, `PurchaseService::resolveLines()`, `ProductController::adjustStock()` |
| Schema | `2026_08_07_110000_add_units_of_sale_to_catalogue.php`, `2026_08_08_100004_add_rounding_adjustment_to_sales.php` |
| Unit tests | `tests/units.test.js`, `tests/cart.test.js` |

`js/units.js` is a hand-maintained mirror of `Unit.php` rather than something
fetched from the API: the till has to price a line while offline, and a unit
table that arrives over the network is a unit table that can be missing exactly
when the shop is busiest.

## API endpoints

None of its own — this is a rule set the other features are built on. It shows up
in every product, sale and purchase payload as the `unit_type` / `base_unit` /
`price_unit` triple and the paired raw/display figures.

## Permissions & gating

Not applicable directly. The endpoints that apply these rules sit behind auth and
the subscription gate like the rest of the shop API.

## Edge cases & known limits

- **The two unit tables can drift.** Nothing checks that `js/units.js` still
  matches `Unit::UNITS`; keeping them in step is a manual discipline, accepted
  deliberately so the till works offline.
- **A weighed product must have a price above zero.** `js/products.js` refuses
  one: "pachaas ka daal" divides money by the rate, so a zero rate makes the most
  common counter request unanswerable. A free *counted* item is fine.
- **Quantity floor is 0.001 base units** on both sale and refund lines. Anything
  finer rounds away, and `SaleService` rejects a line that rounds to zero rather
  than booking a free item.
- **Float comparisons carry deliberate slack.** `Product::hasStockFor()` allows
  0.0005, and refunds allow the same, so weighing out the last 1.5 kg of a 1.5 kg
  sack is not refused because two floats disagree in the fifteenth place.
- **`Unit::fromBase()` rounds to 6 dp**, which is finer than `QUANTITY_DP`. It is
  a display conversion, so the extra places only ever show up in a form box the
  shopkeeper is about to overwrite.
- The rounding remainder is stored per sale but is **not** reported anywhere as a
  total — there is no "rounding gained/lost this month" figure.
