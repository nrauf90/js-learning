# Day book — opening float, closing count, variance

## What it is

The two moments that bracket a shop's day: putting a float in the drawer in the
morning, and counting what is in it at night. The difference between what the
till says should be there and what was actually counted is the **variance**, and
it is the one number that tells a shopkeeper whether the day added up.

It is also the *only* thing individual sales contribute to the cash ledger. Sales
no longer post a `cash_entries` row each; the float and the closing count are the
shop's whole day as far as cash flow is concerned.

## How it works

### One day book per shop per day

`day_balances` is unique on `(user_id, business_date)`. The business date is
derived from the **server clock** (`now()->toDateString()`) and never from request
input — a spoofable business date would let a till post today's takings into a day
that is already closed and reconciled.

The row is filed under the shop **owner** (`dataOwnerId()`) and stamped with
whichever account actually turned the key (`opened_by` / `closed_by`, both
`nullOnDelete` so removing a staff account leaves the history intact). The day
book belongs to the shop; the drawer is a person's responsibility.

### Opening

`POST /api/day-book/open` is **idempotent by necessity**: the till prompts for a
float whenever it loads without one, and two terminals opening the shop at the
same moment must end up sharing one day book rather than racing to create two
floats. The lookup handles the common case; the unique index is the real
guarantee, and whoever loses the race joins the day book the winner just created
(the response then carries `already_open: true` and the winner's float is the one
that counts).

Opening a day that has already been **closed** is refused.

### What should be in the drawer

`DayBookService::cashPosition()` answers "what has actually passed over the
counter today", split into what reached the drawer and what did not:

- **Cash sales** contribute what was collected on them, minus anything itemised
  as an instalment (below), so a same-day down payment is not counted twice.
- **Instalments paid in cash** on *any* credit sale — including sales from earlier
  days — count on the day they were handed over, because that is when the notes
  land in the drawer.
- **Refunds** on cash sales come back out, attributed to the day of the refund
  rather than the day of the sale, for the same reason.

Card, wallet and bank-transfer takings settle elsewhere and move the drawer by
nothing. A sale handed over on credit puts nothing in the till at all until the
customer comes back.

`collectedAmount()` handles a wrinkle: `paid_amount` is authoritative once sales
can go out on credit, but rows settled outright at the till never itemise it — so
a `paid` sale with nothing recorded against it is treated as having collected its
total.

### Closing

`POST /api/day-book/close` takes the counted amount, locks the day row for the
whole transaction (two cashiers hitting "close" together would otherwise both see
an open day and post two closing entries), and **freezes** `expected_amount`:

```
expected = opening_amount + cashPosition(net)
```

It is stored rather than derived on read so a refund or a late sync tomorrow
cannot quietly rewrite yesterday's variance.

`variance = closing_amount − expected_amount`. Positive means the drawer holds
more cash than the till accounted for. The till spells it out rather than showing
a signed number — "Rs −450" read at speed is easy to take for another total, and
the sign is the entire message:

> Short by Rs 450 — less cash than expected.

The variance is previewed **live while the count is still editable**: a transposed
digit costs nothing to fix there and is unarguable once the day is filed.

### The two cash entries

Opening books an **expense** (money leaving the owner to sit in the drawer) and
closing books an **income** (the drawer emptied back out) against two internal
categories:

| slug | name | kind |
|---|---|---|
| `till-float` | Till Float | expense |
| `till-close` | Till Close | income |

The pair nets to exactly the day's cash gain without counting the float twice.
Both category rows are created on **first use** rather than seeded — a shop that
never opens a day book has no business seeing them in its category picker — and
`ExpenseCategory::INTERNAL_SLUGS` keeps them out of `scopeSelectable()` anyway. A
shopkeeper hand-filing an expense under "Till float" would land it in the drawer
reconciliation and silently throw the day's variance out.

The `day_balances` row keeps `opening_entry_id` and `closing_entry_id` so a later
correction can find and amend them.

A float of zero posts **no** entry at all — an empty drawer is a real opening, but
`cash_entries.amount` is positive-only everywhere else in the app, so there is
simply nothing to post.

The profit report excludes both slugs from its `expenses` line: they are not
spending, they are the same rupees leaving the owner's hand in the morning and
coming back at night, and counting the float as an expense would wipe out a day
of profit every day.

## Screens / files

| Layer | File |
|---|---|
| Till UI | `pos.html` — `#pos-day-gate`, `#pos-day-close`, the day strip in the topbar |
| Controller | `js/pos.js` — `loadDayBook()`, `applyDayGate()`, `submitOpenDay()`, `submitCloseDay()`, `renderClosePreview()` |
| API | `backend/app/Http/Controllers/Api/DayBalanceController.php` |
| Service | `backend/app/Services/Pos/DayBookService.php` |
| Model | `backend/app/Models/DayBalance.php` |
| Policy | `backend/app/Policies/DayBalancePolicy.php` |
| Migration | `2026_08_07_100005_create_day_balances_table.php` |
| Tests | `backend/tests/Feature/DayBookTest.php` |

## API endpoints

| Method | Path | What it does |
|---|---|---|
| GET | `/api/day-book` | Past days, newest first, paginated (25, max 100) |
| GET | `/api/day-book/current` | Today: `is_open`, `is_closed`, float, `expected_cash`, cash takings, cash refunds, sales total and count |
| POST | `/api/day-book/open` | `opening_amount` — 201 on create, 200 with `already_open: true` otherwise |
| POST | `/api/day-book/close` | `closing_amount` — freezes `expected_amount` and files the variance |

Amounts are bounded at 999,999,999.99, the same ceiling as a cash entry.

## Permissions & gating

- `auth:sanctum` + the `subscribed` gate.
- `DayBalancePolicy::viewAny()` and `create()` are open — any account working the
  till can open the day.
- `view` and `close` are scoped to `dataOwnerId()`: staff read and close their
  shop owner's day book, never their own, because the drawer they work belongs to
  the shop. Every query is already scoped that way, so the policy is the second
  lock on the same door — it holds even if a future endpoint resolves a day book
  by id.
- Closing a day that was never opened is a validation error rather than an
  authorisation one, so the cashier gets a message they can act on.

## Edge cases & known limits

- **There is no screen for `GET /api/day-book`.** The endpoint returns the
  paginated history with variances, but no page in the app renders it — the till
  only ever shows today. Past days are only visible through the API.
- **A day cannot be reopened.** Once closed, both open and close are refused for
  that date; the till shows the closed notice and the gate stays down.
- **A day cannot be corrected.** There is no endpoint to amend a filed count, and
  no reason/note field for explaining a variance.
- **Nothing closes a day automatically.** A shop that forgets to close simply
  leaves the day open, and the next day's `business_date` differs so a new row is
  created — the old one stays open forever with no `expected_amount`.
- **`expected_cash` on `/current` is live**, not the frozen figure; the stored
  `expected_amount` is only written at closing time.
- **`shop_id` on the row is best-effort** — `$user->shop_id ?: $user->ownedShop?->id`
  — so a shop admin who has not saved their shop details yet files day books with
  a null `shop_id`.
- **The variance is not reported anywhere else.** It does not reach the reports
  screen, the dashboard or the activity log.
