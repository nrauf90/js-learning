# POS — the till

## What it is

The screen the shopkeeper works all day. Tap or scan items onto a ticket, weigh
out the loose goods, take the money, print a slip. It is the only place a sale is
created, and it is deliberately the busiest screen in the app: everything else —
stock, khata, reports, the day book — is downstream of what happens here.

Two things make it a Pakistani till rather than a generic one. Half the shelf is
sold by weight or by rupee amount rather than by the piece, so tapping a sack of
daal opens a keypad instead of adding "1". And goods routinely leave the shop
before the money does, so **udhaar** (credit) has its own button rather than
being an option buried in a dropdown.

## How it works

### The day gate

The till will not sell into a day that has not been opened. `pos.js` reads
`GET /api/day-book/current` on boot and after every sale; if a day book exists
and is not open, a gate covers the screen with either the opening-float form or
the closed-day notice, and both the "Complete sale" and "Save as Udhaar" buttons
are disabled.

The gate is hard, not advisory. A sale rung into a day nobody opened cannot be
reconciled against a drawer count, and a warning a cashier can click past is
exactly how a shift ends up unreconcilable. A closed day is barred the same way —
reopening it would strand the count already filed.

There is **one** exception: if the lookup never answered, `dayBook` stays `null`,
the gate stays down and the failure is reported in the alert strip. There are
still customers at the counter, and losing a day's reconciliation beats shutting
the shop. See `applyDayGate()`.

### Catalogue, categories and search

The whole active catalogue is paged into memory on boot
(`GET /api/products?active=1&per_page=200`, up to 25 pages) and rendered as
tiles. Search then never touches the network: `filterProducts()` flips the
`hidden` property on tiles that already exist, matching a lower-cased haystack of
name + SKU + barcode. The category rail is built from
`GET /api/product-categories`, with counts derived from the in-memory list rather
than the API's `products_count` — that one also counts inactive products the till
never shows.

Pressing Enter in the search box means "a scanner just submitted a full code": it
calls `GET /api/products/lookup?code=…` for an exact SKU/barcode match and puts
the item straight on the ticket. A miss leaves the text in the box as an ordinary
search term.

### The weigh keypad

Tapping a `weight` or `volume` product opens the keypad (`openWeighPad`) instead
of adding a unit, because there is no such thing as "one" of a sack of daal. It
has two tabs and works in both directions:

| tab | you type | it shows |
|---|---|---|
| By weight / By volume | 250 (g) or 0.25 (kg) | `= Rs 62.50` |
| By amount | Rs 50 | `Weigh out 200 g` |

Quick chips carry the weights a counter actually calls out — Pao, Adha kg, 3 Pao,
1 kg, 2 kg, 5 kg — and the amount tab carries Rs 20/50/100/200/500/1000
(`QUICK_WEIGHTS`, `QUICK_VOLUMES`, `QUICK_AMOUNTS` in `js/units.js`).

Details that matter: switching g→kg keeps the *weight*, not the digits (the
cashier meant the same bag); switching tabs clears the pad, because the digits
meant grams a moment ago and rupees now; the typed value is kept as a raw string
so a half-finished "1." is not rounded away before the next digit. Re-weighing an
existing line **replaces** its quantity where a fresh tap accumulates — the bag
went back on the scale.

The amount→weight conversion is deliberately **not** snapped to a scale step: the
customer asked for fifty rupees of daal and that is what the ticket must say, so
the money stays exact and the weight carries the remainder.

### The ticket

`js/cart.js` holds the ticket, free of DOM and network so the arithmetic that
decides what a customer is charged can be unit-tested (`tests/cart.test.js`).
Quantities are floats in the product's base unit. Adding a product already on the
ticket bumps its quantity rather than creating a second line, which is what a
repeatedly-scanned barcode should do.

The item count is not a sum of quantities: one packet plus 250 g of daal is not
251 of anything, so measured goods count as one item per line and only counted
goods contribute their pieces.

Totals: subtotal → discount (clamped to `[0, subtotal]`) → rounded half-up to a
whole rupee, with the remainder shown as a signed "Rounding" row whenever it is
non-zero. See [units-and-money.md](./units-and-money.md).

### Settling in cash or a wallet

The payment dropdown offers the six methods that settle **now**: cash, card,
EasyPaisa, JazzCash, bank transfer, other. Cash shows an "Amount received" box
and a live change-due line; everything else shows a reference field for the
wallet/bank transaction id, which lands in `sales.payment_reference`.

Leaving "Amount received" empty on a cash sale is normal — the exact note handed
over is rarely typed — and the sale settles in full. The receipt says so
explicitly rather than leaving it assumed (`renderReceiptState`).

### Udhaar — goods on the book

**Credit is deliberately not in the dropdown.** It gets its own `#pos-credit`
"Save as Udhaar" button and its own dialog, because taking goods on the book is a
different act from taking money and must not be a mis-click.

The customer field is a **combobox**: one box that is both the search and the
name. Typing filters the khata (`GET /api/customers?search=…`, debounced 250 ms)
and drops a listbox of matching pages under it, each showing the name, the number
and what they already owe. Picking one collapses the box into a "picked" card —
who the debt lands on, what they owe, and how much of their limit is left, with a
**Change** button that hands the name back to be edited rather than an empty field
to retype.

The keyboard works the way a combobox should: ↑/↓ move the highlight (wrapping),
Enter takes the highlighted page, the first Escape closes the list and the second
closes the dialog. Four details are load-bearing:

- **Nothing is pre-armed.** The highlight starts at −1 after every search, because
  a cashier typing a brand new name and hitting Enter would otherwise file the
  debt against whichever regular happened to share a prefix.
- **Search responses are sequenced.** Once the cashier types faster than the
  network answers, an older response would overwrite the newer list under a cursor
  that is about to press Enter, so only the newest request may render.
- **The box sits outside the `<form>`**, so a scanner's Enter cannot post the sale;
  in the box Enter only ever takes the highlighted page.
- **The panel swallows `mousedown`.** Without it the box blurs, the list
  collapses, and the click aimed at an option lands on whatever the panel was
  covering.

What happens if nobody is picked is said in a **standing note** below the box
rather than inside the dropdown — this is the path most udhaar customers arrive
by, and it must not depend on a floating panel being open to be readable. It
reads "No khata page for "Bilal" — this sale opens a new one", or "Pick a page
above, or leave it and a new one opens", and never passes judgement on text the
khata has not been asked about yet: mid-typing it would say "no page for Bil" at a
customer who has one under "Bilal".

If the khata cannot be reached at all, the note says so and the sale still goes
through — the name in the box finds or opens the page server-side.

The dialog also takes an optional deposit with its own method.

Three rules are load-bearing here:

- **The till never calls `POST /api/customers`.** It posts `customer_name` and
  `customer_phone`; `SaleService::resolveCustomer()` owns page resolution so the
  till and the khata screen cannot diverge about who a debt belongs to. Picking a
  page from the dropdown only supplies the name and number the sale posts and
  shows the balance — no customer id is ever sent, because that would give the
  till a second way to decide who a debt belongs to and the two would eventually
  disagree.
- **A blank deposit is legitimate.** The whole ticket goes on the book and the
  sale is written `pending`, never `paid`.
- **An unnamed debt is refused** on both sides — there is nobody to chase.

An empty box asks nothing of the khata and shows nothing, so the dialog goes back
to its shortest rather than listing every page in the shop.

### What the server does with it

`SaleService::create()` runs the whole thing in one transaction with the products
`lockForUpdate()`. Two cashiers selling the last unit at the same moment is the
normal failure mode for a POS; without the lock both reads see stock of 1, both
writes succeed, and the shelf ends on −1.

Inside the transaction:

1. Duplicate product ids are merged **first**, so scanning the same barcode three
   times checks stock once against a quantity of 3.
2. Each line is checked for ownership, existence, `is_active` and stock.
3. Totals, discount cap, whole-rupee rounding.
4. `paid_amount` = the total, unless the method is `credit`, in which case it is
   the deposit.
5. The credit limit is checked **before a single row is written**, so a refused
   sale moves no stock and records no debt.
6. The sale is inserted, then `reference` is back-filled from the row id
   (`S-000123`) — unique and ordered without a per-user counter to race on — and
   `payment_status` is resolved.
7. A credit deposit is written as the first `sale_payments` row, so
   `paid_amount` always adds up from its own history. A sale settled in full at
   the counter gets **no** payment row: the sale itself is the record.
8. Each line is snapshotted onto `sale_items` (name, unit triple, unit price,
   `unit_cost` copied off the product) and stock is moved through
   `applyStockDelta()`, which writes a `stock_movements` row with the running
   balance.

`status` tracks the goods (`completed` / `partially_refunded` / `refunded`);
`payment_status` tracks the money (`paid` / `partial` / `pending`). A credit sale
is a completed sale that happens to still be owed for.

### Closing the day

The "Close day" button re-reads the day book first (the expected figure has to
include anything rung up on another terminal), shows the expected drawer contents
broken down, previews the variance live as the count is typed, and then files it.
See [day-book.md](./day-book.md).

## Screens / files

| Layer | File |
|---|---|
| Page | `pos.html` |
| Controller | `js/pos.js` |
| Ticket maths | `js/cart.js` |
| Units + keypad quantities | `js/units.js` |
| Receipt slip | `js/receipt.js` |
| API | `backend/app/Http/Controllers/Api/SaleController.php`, `ProductController.php`, `DayBalanceController.php`, `CustomerController.php` |
| Service | `backend/app/Services/Pos/SaleService.php` |
| Models | `Sale`, `SaleItem`, `SalePayment`, `Product`, `StockMovement`, `Customer` |
| Migrations | `2026_08_06_110002_create_sales_table.php`, `..._110003_create_sale_items_table.php`, `2026_08_07_100003_add_credit_fields_to_sales_table.php`, `..._100004_create_sale_payments_table.php`, `2026_08_08_100004_add_rounding_adjustment_to_sales.php` |
| E2E | `e2e/tests/m23-pos.spec.js`, `e2e/tests/m35-grocery.spec.js` |

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/products` | Catalogue for the tiles (`active`, `per_page`, `page`) |
| GET | `/api/products/lookup?code=` | Exact SKU/barcode match for the scanner; 404 on a miss |
| GET | `/api/product-categories` | The category rail |
| POST | `/api/sales` | Ring up a sale |
| GET | `/api/day-book/current` | Is the day open, and what should be in the drawer |
| POST | `/api/day-book/open` | Record the opening float |
| POST | `/api/day-book/close` | File the counted drawer |
| GET | `/api/customers` | Khata search inside the udhaar dialog |

`POST /api/sales` body: `items[]` (`product_id`, `quantity` in base units),
`discount_amount`, `payment_method`, `amount_tendered`, `payment_reference`,
`note`, and for credit `customer_name`, `customer_phone`, `paid_amount`,
`deposit_method`, `received_by_name`. Offline fields — `client_uuid`, `offline`,
`sold_at` — are accepted but nothing in the shipped till sends them; see
[offline-sync.md](./offline-sync.md).

## Permissions & gating

- `auth:sanctum` bearer token; `pos.js` redirects to `login.html?next=pos.html`
  without one.
- The `subscribed` middleware covers every endpoint above. For a staff account
  the subscription checked is the **shop owner's**, not their own — a staff
  account has its own `created_at` and so its own 7-day trial clock, which would
  otherwise lapse a week after hiring and lock a paid-up shop's cashier out
  mid-shift (`EnsureSubscribed::payingAccount()`).
- `SalePolicy::create()` returns true for everyone: working the till is the one
  thing every account can do.
- Staff sell against their **shop owner's** catalogue and the takings belong to
  the owner — `User::dataOwnerId()` is used everywhere instead of `id`.
- Selling does **not** require the catalogue permission. A cashier without
  `can_manage_products` can still find things to sell; the permission bites on
  writes to the catalogue (`ProductPolicy`).

## Edge cases & known limits

- **Sale creation is idempotent on `client_uuid`** and races on the unique
  `(user_id, client_uuid)` index are converted into the same success the winner
  got — but the till never mints a uuid, so this path is currently only exercised
  by tests.
- **The receipt heading is the logged-in user's name**, read from the cached
  `/api/user` payload — *not* the shop record. `shops.name`, `logo_path` and
  `receipt_footer` exist and are editable on the Shop screen, but the printed
  slip does not read them. See [receipts-printing.md](./receipts-printing.md).
- **A tendered amount below the total is refused** for cash, both in the browser
  and in `SaleService`. Overpaying is fine and produces change.
- **Discount is ticket-level only** — there is no per-line discount, and a
  ticket discount is spread proportionally across lines when a partial refund is
  worked out.
- **Stock is checked, not reserved.** Two tills can both pass the browser-side
  check; the server's row lock is what actually decides, and the loser gets a
  "only N left in stock" validation error naming the remaining amount in the
  unit the line is sold in.
- **The catalogue is loaded once on boot** and refreshed after each sale. A
  product added on another device mid-shift will not appear until the next sale
  or a page reload.
- **Only the first 5,000 products** load (25 pages × 200). A larger catalogue
  silently truncates.
- The udhaar dialog cannot be dismissed while a sale is in flight — it is where a
  refusal is reported, and closing it would swallow the reason.
