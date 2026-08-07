# Khata (udhaar) — the customer credit ledger

## What it is

The notebook by the till, as a screen. Who owes the shop money, how long they
have owed it, and what happens when they walk in and put some cash on the
counter. A mohalla store runs on credit; without this the shop knows it took Rs
40,000 of goods out of the door on tick and has no way to say who has it.

## How it works

### A customer is a page, not a string

Sales already carried `customer_name` and `customer_phone`, which is enough to
print on a slip but not enough to answer "who owes me what": to a string
comparison "Ali", "Ali bhai" and "Ali Raza" are three different debtors. A
`customers` row is the **person**; the columns on the sale stay as the snapshot of
who the ticket was rung up for, and survive the page being renamed or deleted
(`sales.customer_id` is `nullOnDelete`).

Each shop keeps its own khata — two shops' "Ali Raza" are two different men, and
neither may see the other's balance.

### Nothing stores a balance

Every figure on this screen is derived. `Customer::OUTSTANDING_SQL` is

```sql
sales.total - sales.refunded_amount - sales.paid_amount
```

and rows are always filtered to `> 0` before it is summed, which applies the
per-sale floor that `Sale::outstandingAmount()`'s `max(0, …)` applies. An
over-refunded sale is money the shop owes back; letting it net against another
sale's debt would understate what is collectable.

This mirrors `Sale::outstandingAmount()` exactly — including the way a refund
shrinks the debt — so the khata screen and the sale screen can never disagree
about what is owed. It is an expression rather than a stored column for the same
reason `sales.paid_amount` is reconciled from `sale_payments`: a cached balance
is a balance that eventually drifts.

### Resolving a walk-in to a page

`SaleService::resolveCustomer()` is the only place this happens — the till never
creates a customer itself. Matching is deliberately conservative:

1. **A phone number wins outright.** It is the only thing a shop can really
   identify a person by.
2. **A bare name may only claim a page that has no number on it yet.** If a
   number was given and an existing "Ali" already has a different one, a new page
   is opened.
3. A page learns a number the first time one is given.

Merging "Ali" (0300…) with "Ali" (0345…) would put one man's debt on another
man's page, which is far worse than two rows the owner can tidy up by hand.
`customers.phone` is unique per shop, so the phone lookup can never be a coin
toss.

Only a **debt** opens a page. A cash sale rung up under a name is just a named
receipt — there is nothing to chase, so opening a customer for it would fill the
khata with people who owe nothing.

### Credit limits

`credit_limit` is nullable, and null means **no ceiling was ever set** — the
normal case in a mohalla store, and emphatically not the same as a limit of zero,
which refuses the customer any credit at all. `creditAvailable()` returns null
for "no limit" rather than a number.

A limit the shopkeeper wrote down is a decision, not a hint, so
`SaleService::assertWithinCreditLimit()` **refuses** the sale rather than flagging
it, and the message names the figures:

> Ali Raza has a khata limit of Rs 5,000.00 and already owes Rs 4,200.00. This
> sale would take them to Rs 5,700.00.

"Credit limit exceeded" tells the person at the counter nothing they can act on;
they need to know how much to collect before the goods can go out.

### Aging

`GET /api/customers/outstanding` buckets each debtor's balance by how long the
sale has been sitting, in the bands a shopkeeper actually thinks in:
`days_0_30`, `days_31_60`, `days_61_90`, `days_90_plus`. Rs 5,000 owed since last
week and Rs 5,000 owed since March are completely different problems.

The list is ordered **newest debt first** — the customer who bought yesterday is
the one most likely to be back tomorrow — and the aging buckets are what surface
the old debts that ordering pushes down.

The totals and the aging tiles are computed **before** the search filter is
applied: they answer "what is the shop owed", which does not become a smaller
number because a name was typed into the filter box. Only the rows are filtered
and then paged.

Paging happens in PHP rather than in SQL here, because the balance, the age spread
and the ordering are all worked out from one grouped aggregate over the open
sales — the rows have to exist before they can be ranked. The database work is
unchanged; what shrinks is the page the browser has to render.

Two implementation notes: the whole thing is one grouped aggregate (a shop with
400 names would otherwise pay 400 balance queries), and the buckets are compared
against pre-computed cut-off timestamps rather than a date-difference function,
because MySQL and the SQLite used by the test suite spell that function
differently. Cut-offs are taken at `startOfDay()` so a debt does not become a
month old at the hour it was rung up.

### Taking a payment

The customer walks in with Rs 2,000 and no idea which ticket it is for.
`SalePaymentService::settleOldestFirst()` spreads it across their unpaid sales
**oldest first**, which is simply how the notebook is worked and is also what
keeps the aging report honest.

Every allocation goes through `settle()` rather than writing `sale_payments`
directly, so the instalment rows, each sale's `payment_status` and — crucially —
the day book all behave exactly as they would if the money had been taken sale by
sale at the till.

The whole set of open sales is `lockForUpdate()`-ed before anything is totalled.
A second till taking money from the same customer at the same moment would
otherwise allocate against balances about to move underneath it, and between them
the shop would over-collect.

Paying more than is owed is **refused**, not carried as a credit: the app has
nowhere to hold money the shop owes back, and silently absorbing it would lose
the difference. The 422 names the exact balance, which is the only thing that
tells the cashier what to type instead.

One `paid_at` stamp is taken for the whole lump sum rather than per allocation —
a loop that straddled a second boundary would split one handful of notes into two
payments on the customer's page.

### `received_by_name` is not `recorded_by`

`sale_payments` carries both:

| column | meaning |
|---|---|
| `recorded_by` | the login that typed the instalment in (FK to `users`, `nullOnDelete`) |
| `received_by_name` | free text — the person who physically took the cash |

In a mohalla shop these are routinely different people: the owner's son works the
counter on the owner's session, a delivery boy collects at the customer's door
and hands the notes over later. When a payment is disputed the question is always
"who did you give it to", and only the second column can answer it. It is free
text rather than a foreign key because most of these people have no login at all.

It defaults to **nobody** rather than to the logged-in cashier: a guessed name on
a disputed payment is worse than a blank one, because it reads as a record of who
took it. Blank is stored as `null`, not `''`, so "nobody wrote a name down" and
"the name is empty" cannot read differently on the page.

### The ledger page

`GET /api/customers/{id}/ledger` returns the notebook page read top to bottom:
every credit sale, every rupee taken against it, and any refund, in the order they
happened, with the running balance after each row.

Ties are broken by kind — a deposit taken at the till shares its timestamp with
the sale, so the charge has to land before the money against it or the page reads
as though the customer paid in advance.

Only the part of a refund that actually **cancelled a debt** appears. If the goods
were already paid for, the money went back over the counter — a till movement,
not a khata one — and showing it here would close the page on a balance the
customer does not owe.

The same response carries a second view: `payments`, newest first, each with the
balance it left behind. That answers the question the customer is actually
standing there asking — "I paid you two thousand last week, what is left?" A lump
sum stored as one row per ticket it cleared is folded back into a single line
naming every ticket, because the customer handed over one amount and that is the
amount they will argue about. Both views are built from one pass over the same
entries, so the history and the statement can never quote different balances.

## Screens / files

| Layer | File |
|---|---|
| Page | `customers.html` (sidebar label **Khata**) |
| Controller | `js/customers.js` |
| Till dialog | `js/pos.js` — `openUdhaar()`, `submitUdhaar()`, `#pos-udhaar` |
| API | `backend/app/Http/Controllers/Api/CustomerController.php`, `SaleController::storePayment()` |
| Services | `backend/app/Services/Pos/SalePaymentService.php`, `SaleService::resolveCustomer()` / `assertWithinCreditLimit()` |
| Models | `Customer`, `Sale`, `SalePayment` |
| Policy | `backend/app/Policies/CustomerPolicy.php` |
| Migrations | `2026_08_08_100002_create_customers_table.php`, `2026_08_07_100004_create_sale_payments_table.php`, `2026_08_08_100005_add_received_by_name_to_sale_payments_table.php` |
| Tests | `backend/tests/Feature/CustomerKhataTest.php` |

The screen has two views: **Owes money** (default — the question the page exists
to answer) and **Everyone** (the address book behind it). Both are paginated
25 at a time and both pass the search term to the API. Clicking a row opens a
dialog with the facts, the payment history, the running statement and the payment
form.

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/customers` | The whole khata, paginated (`search`, `active`, `page`, `per_page` — default 50, max 200) |
| GET | `/api/customers/outstanding` | Debtors only, paginated (`search`, `page`, `per_page`), with shop-wide totals and aging buckets |
| POST | `/api/customers` | Open a khata page by hand |
| GET | `/api/customers/{customer}` | One page |
| PUT | `/api/customers/{customer}` | Edit name, phone, address, credit limit, notes, active flag |
| GET | `/api/customers/{customer}/ledger` | Statement + payment history + customer facts |
| POST | `/api/customers/{customer}/payments` | Lump sum, allocated oldest-first |
| POST | `/api/sales/{sale}/payments` | Instalment against one specific sale |

Payment body: `amount` (required), `method` (required, from
`Sale::SETTLEMENT_METHODS` — `credit` is absent on purpose, settling a debt with
more credit moves no money), `reference`, `note`, `received_by_name`.

The response names which tickets the money landed on, so the shopkeeper can read
back "that clears the 14th and half of the 20th".

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate on every endpoint.
- `CustomerPolicy` scopes on `dataOwnerId()`, not `id`: the khata belongs to the
  shop owner, so staff reach their own shop's debtors and nobody reaches another
  shop's. Comparing against `id` would hide every page from the cashier who
  opened it.
- `create` returns true for everyone — udhaar is collected by whoever is behind
  the counter when the customer walks in, not only by the owner. Same for
  `settle`, scoped to the shop.
- There is **no delete**. A khata page can be marked inactive; it cannot be
  removed through the API.

## Edge cases & known limits

- **A page whose customer row was deleted** still has its sales, but there is
  nobody left to chase, so it is filtered out of the outstanding list.
- **Search is a parameter on both list endpoints, never a filter over what came
  back.** On a paged list, filtering the page on screen would hide every match
  that happens to sit on another one.
- **Aging is measured from "now"**, so the response carries `as_of` and is only
  true as of when it was asked.
- **Only `payment_method = 'credit'` sales appear on the statement.** A sale that
  ended up partially paid by some other route would not show up on the ledger
  page, though its balance would still count towards the customer's total via
  `OUTSTANDING_SQL`.
- **Payments cannot be reversed.** There is no endpoint to void a `sale_payments`
  row; a mistake has to be corrected some other way.
- The credit-limit check is **skipped for offline replays** — the goods already
  left the shop and the debt is already real, so refusing it on sync would not
  un-give the credit, only lose the record of it.
