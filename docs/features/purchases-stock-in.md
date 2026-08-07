# Purchases — Stock In

## What it is

The delivery van at the back door. A wholesaler drops fifty kilos of atta and a
peti of Coke, hands over a paper bill, and takes some money now and the rest next
round. This screen is where that gets typed up: what arrived, what it cost, who
brought it, and what the shop still owes for it.

It is also where `products.cost` actually comes from. Before it existed the cost
was whatever someone last typed into the catalogue by hand, so most sale lines
carried no cost at all and every profit figure the app reported read close to
zero.

## How it works

### Suppliers

A minimal address book — name, phone, address, notes — scoped to the shop.
The name is unique per shop, because two rows for the same wholesaler split one
running balance in half and the shopkeeper has no way to tell which is which.
Search matches name **or** phone: a wholesaler is as often remembered by their
number as by their name ("the atta man" is not what got written in the name
field).

Suppliers have no policy of their own — they exist only to be attached to a
purchase — so the shop-ownership check is made in the controller
(`assertOwned()`), and they ride on `PurchasePolicy` for permissions.

A delivery may be booked with **no** supplier; those still total up in the
payables breakdown under "No supplier", because the shop owes that money either
way.

### Booking a delivery in

`PurchaseService::create()` runs everything in one transaction with the products
`lockForUpdate()`-ed, for the same reason `SaleService` does: the count and the
cost are both read, arithmetic is done on them, and the result is written back. A
sale rung up mid-delivery would otherwise read a stock figure the receipt was
about to overwrite, and the shelf count would lose whichever write landed second.

Quantities and unit costs arrive quoted in each product's own `price_unit` — the
shop buys "50 kg at Rs 180/kg" — and are divided down to base units **once**,
here, before anything is stored. The browser computes its line total the same way
(`priceToBase(cost) × toBase(quantity)`) rather than multiplying the two quoted
figures, which would agree most of the time and disagree on the awkward ones: Rs
1,000 a darjan is Rs 83.3333 a piece.

A zero unit cost is allowed — a free sack thrown in with an order is real, and it
genuinely drags the average cost of that product down. A zero *quantity* is not.

Line items snapshot name, `unit_type`, `base_unit` and `price_unit` for the same
reason a sale line does: renaming atta or switching it from kilos to packets
cannot be allowed to rewrite last month's delivery note.

`reference` (`P-000123`) is back-filled from the row id straight after insert —
unique and ordered without a per-user counter to race on.

### Weighted-average cost

`PurchaseService::weightedAverageCost()` blends the new cost into the old rather
than replacing it:

```
newCost = (oldQty × oldCost + inQty × inCost) / (oldQty + inQty)
```

Ghee and atta move in price every month in Pakistan, and a shop is almost never
selling only the newest sack. Overwriting the cost the moment a delivery lands
would price the twenty kilos already on the shelf at whatever the last van
charged: a price rise makes the shop look like it is losing money on stock it
bought cheaply, and a price drop invents a margin that was never earned.
Weighting by quantity means the recorded cost always describes the goods actually
standing in the shop.

With nothing left to blend against — no old cost, a zero-or-negative count (an
offline sale can overdraw it), or an untracked product — the new cost simply
becomes the cost.

Untracked products still get their cost updated even though there is no count to
raise: the shop paid that price, and skipping it would leave the margin on a
loose-count item stuck at whatever was first typed in.

Two lines for the same product on one delivery share the **same** in-memory
`Product` instance, so the second blends into the first's average rather than
averaging against the count as it stood before the van arrived.

Stock goes on via a `restock` `stock_movements` row noting the purchase
reference — the same audited path everything else uses.

### Paying the bill

Stock arrives long before it is paid for: the van leaves the goods and the money
follows on the next round. `purchases.amount_paid` and `payment_status`
(`paid` / `partial` / `unpaid`) track that, and `purchase_payments` is the history
behind it — the mirror image of `sale_payments`.

The deposit handed to the van driver is written as the **first** instalment
("Paid on delivery"), so `amount_paid` can be reconstructed from its own rows and
the invoice never opens showing a paid figure with nothing behind it.

**`amount_paid` is re-derived, never incremented.** `syncPaymentTotals()` does

```php
$purchase->forceFill(['amount_paid' => round((float) $purchase->payments()->sum('amount'), 2)]);
```

A running total that is added to drifts the first moment anything goes wrong — a
retried request, a row removed by hand — and once it has drifted there is nothing
left to pull it back. Recomputing from the history means the invoice can only ever
say what its own payments say.

The sale side does **not** currently work this way — `SalePaymentService::settle()`
increments `sales.paid_amount` rather than re-deriving it from `sale_payments`.
Both writes happen under a row lock so neither can race, but only the purchase
side is self-correcting.

`recordPayment()` locks the purchase for the whole transaction: the owner
settling the bill on his phone while the clerk settles it at the counter would
otherwise both read the same outstanding balance, both pass the over-payment
check, and the shop would pay twice. Over-payment is **refused**, not carried as
a credit with the wholesaler — the app has nowhere to hold money a supplier owes
back, so absorbing the difference would simply lose it.

`purchase_payments.paid_by_name` is free text for whoever actually handed the
money over, distinct from `recorded_by` (the login that typed it in). The owner
types the entry up in the evening; the boy at the counter paid the van driver at
noon — and it is his name the supplier will quote back. Same reasoning as
`sale_payments.received_by_name`; see [khata-udhaar.md](./khata-udhaar.md).

### What I owe suppliers

`GET /api/purchases/outstanding` is the mirror of the khata's "who owes me": every
unpaid or part-paid invoice, **oldest first** (that is the one the supplier will
ask about, and the order the money would be paid out in anyway), plus a per-supplier
breakdown grouped by **name** so no-supplier deliveries still total up under one
heading instead of vanishing.

### The invoice view

Opening a delivery swaps the list for a full-width in-page view rather than a
dialog. That is deliberate: `.pos-modal-card` caps at 520px, and this screen is
read with the supplier's paper bill lying next to the keyboard, so the line items,
the money and the payment history all need to be on one page at once.

The payment history is computed with the running balance walked forward in the
order the money actually moved, then **reversed** for display. Computing it down
a reversed list would print the wrong figure against every row but the last.

## Screens / files

| Layer | File |
|---|---|
| Page | `purchases.html` (sidebar label **Stock In**) |
| Controller | `js/purchases.js` |
| API | `backend/app/Http/Controllers/Api/PurchaseController.php` |
| Service | `backend/app/Services/Pos/PurchaseService.php` |
| Models | `Purchase`, `PurchaseItem`, `PurchasePayment`, `Supplier` |
| Policy | `backend/app/Policies/PurchasePolicy.php` |
| Migrations | `2026_08_08_100000_create_purchases_tables.php`, `2026_08_08_100006_create_purchase_payments_table.php` |
| Tests | `backend/tests/Feature/PurchaseTest.php`, `PurchasePaymentTest.php` |

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/suppliers` | This shop's wholesalers (`search` matches name or phone) |
| POST | `/api/suppliers` | Add one |
| PUT | `/api/suppliers/{supplier}` | Edit one |
| GET | `/api/purchases` | Paginated deliveries (`supplier_id`, `payment_status`, `date_from`, `date_to`, `page`, `per_page`) |
| GET | `/api/purchases/outstanding` | Unpaid + part-paid invoices, totals, per-supplier breakdown |
| POST | `/api/purchases` | Book a delivery in |
| GET | `/api/purchases/{purchase}` | One invoice with lines, supplier and payments |
| POST | `/api/purchases/{purchase}/payments` | Pay something off it |

`POST /api/purchases` body: `supplier_id`, `invoice_number`, `purchase_date`,
`discount_amount`, `amount_paid`, `deposit_method`, `paid_by_name`, `note`,
`items[]` (`product_id`, `quantity`, `unit_cost` — both in the product's price
unit).

`purchase_date` is bounded by `before_or_equal:tomorrow` rather than `today`
because the app runs in UTC and the shop lives five hours ahead: between 7pm and
midnight UTC the shopkeeper's own "today" is already the next date, and rejecting
it would make the screen unusable each evening. The browser builds the default
date from local `getFullYear()/getMonth()/getDate()` for the same reason —
`toISOString()` would give the UTC date, which is yesterday for the first five
hours of every Pakistani morning.

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate.
- `PurchasePolicy::viewAny()` is open; `view()` is scoped to `dataOwnerId()`.
- `create` requires the **catalogue** permission — booking a delivery reprices the
  catalogue and moves stock, so it is catalogue work rather than till work.
  Suppliers ride on the same permission.
- Paying an invoice authorises **twice**: `view` (the shop boundary) and
  `create` (the catalogue permission). Settling a bill is the other half of the
  same job, so the clerk who may not receive stock may not pay for it either.
- Filing a delivery under another shop's supplier is refused by an explicit
  ownership check, not just `exists:suppliers,id`, which would leak that
  wholesaler's name back through the purchase list.

## Edge cases & known limits

- **A delivery cannot be edited or deleted.** There is no `PUT` or `DELETE` on a
  purchase, so a mis-typed cost is permanent and has already been blended into
  the weighted average.
- **The weighted average cannot be unwound.** Correcting a bad cost means
  adjusting the product's `cost` by hand on the catalogue screen, which is not
  audited the way a delivery is.
- **Suppliers cannot be deleted.** Only created and edited.
- **`GET /api/suppliers` is not paginated** and takes only `search`.
- **`/purchases/outstanding` is not paginated either** — every open invoice comes
  back in one response, and the summary tiles name only the top four suppliers.
- **No goods-received check.** The quantity typed is trusted; there is no
  expected-vs-received comparison against an order, because there are no orders.
- **A purchase writes nothing to the cash ledger.** Paying a supplier does not
  create a `cash_entries` expense, so the profit report's `expenses` line does not
  include stock bought unless the shopkeeper also files it by hand under "Stock
  Purchase". Cost of goods sold reaches the P&L through `sale_items.unit_cost`
  instead, which is the correct treatment — but it does mean the drawer and the
  supplier payments are tracked in two separate places.
- The purchase-payment method list is `Sale::SETTLEMENT_METHODS`, so both sides of
  the shop's book name money the same way; `credit` is absent for the same reason
  it is absent there.
